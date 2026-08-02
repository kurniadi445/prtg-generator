<?php

/**
 * Kendali worker dari browser.
 *
 *   worker-control.php?aksi=status   -> JSON status worker + antrean
 *   worker-control.php?aksi=start    -> nyalakan worker sebagai proses latar
 *   worker-control.php?aksi=stop     -> minta worker berhenti setelah job ini
 *
 * KEAMANAN: 'start' menjalankan proses di server, jadi endpoint ini hanya
 * melayani permintaan dari mesin yang sama. Aplikasi ini tidak punya login;
 * kalau suatu saat dibuka ke jaringan kantor, endpoint ini harus diberi
 * autentikasi lebih dulu atau dimatikan.
 */

require_once 'database.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const DETAK_BERKAS      = 'tmp/worker.json';
const HENTI_BERKAS      = 'tmp/worker.stop';
const DETAK_KEDALUWARSA = 15;

$lokal = ['127.0.0.1', '::1'];
$asal  = $_SERVER['REMOTE_ADDR'] ?? '';

$jawab = function (array $data, int $kode = 200): void {
    http_response_code($kode);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
};

/**
 * Status worker berdasarkan heartbeat + isi antrean.
 */
$statusWorker = function () use ($lokal, $asal): array {
    $detak = is_file(DETAK_BERKAS)
        ? json_decode((string) file_get_contents(DETAK_BERKAS), true)
        : null;

    $hidup = is_array($detak)
        && !empty($detak['last_seen'])
        && (time() - (int) $detak['last_seen']) <= DETAK_KEDALUWARSA;

    $antre = 0;
    $proses = 0;

    try {
        $baris = db()->query("
            SELECT status, COUNT(*) AS jumlah
            FROM jobs
            WHERE status IN ('queued', 'processing')
            GROUP BY status
        ")->fetchAll(PDO::FETCH_KEY_PAIR);

        $antre  = (int) ($baris['queued'] ?? 0);
        $proses = (int) ($baris['processing'] ?? 0);
    } catch (Throwable $e) {
        // biarkan 0; status worker tetap bisa dilaporkan
    }

    return [
        'berjalan'    => $hidup,
        'pid'         => $hidup ? ($detak['pid'] ?? null) : null,
        'kegiatan'    => $hidup ? ($detak['status'] ?? null) : null,
        'job_id'      => $hidup ? ($detak['job_id'] ?? null) : null,
        'uptime'      => $hidup && !empty($detak['started_at'])
            ? time() - (int) $detak['started_at']
            : null,
        'detak_umur'  => is_array($detak) && !empty($detak['last_seen'])
            ? time() - (int) $detak['last_seen']
            : null,
        'berhenti_diminta' => is_file(HENTI_BERKAS),
        'antre'       => $antre,
        'diproses'    => $proses,
        'boleh_kendali' => in_array($asal, $lokal, true),
    ];
};

$aksi = $_GET['aksi'] ?? 'status';

// --- status: boleh diakses dari mana saja -------------------------------
if ($aksi === 'status') {
    $jawab($statusWorker());
}

// --- start / stop: hanya dari mesin yang sama ---------------------------
if (!in_array($asal, $lokal, true)) {
    $jawab([
        'ok'    => false,
        'pesan' => 'Menyalakan atau mematikan worker hanya bisa dari komputer server.',
    ], 403);
}

if ($aksi === 'stop') {
    $status = $statusWorker();

    if (!$status['berjalan']) {
        $jawab(['ok' => false, 'pesan' => 'Worker memang sedang tidak berjalan.'] + $status);
    }

    if (!is_dir('tmp')) {
        mkdir('tmp', 0777, true);
    }

    file_put_contents(HENTI_BERKAS, (string) time(), LOCK_EX);

    $jawab([
        'ok'    => true,
        'pesan' => 'Perintah berhenti dikirim. Worker keluar setelah job yang sedang berjalan selesai.',
    ] + $statusWorker());
}

if ($aksi === 'start') {
    $status = $statusWorker();

    if ($status['berjalan']) {
        $jawab(['ok' => false, 'pesan' => 'Worker sudah berjalan (PID ' . $status['pid'] . ').'] + $status);
    }

    $php = config('worker')['php_binary'] ?? PHP_BINARY;

    if (!is_file($php)) {
        $jawab([
            'ok'    => false,
            'pesan' => "Berkas PHP tidak ditemukan: $php. Sesuaikan config('worker')['php_binary'].",
        ], 500);
    }

    $skrip = __DIR__ . DIRECTORY_SEPARATOR . 'worker.php';
    $log   = __DIR__ . DIRECTORY_SEPARATOR . 'worker.log';

    if (stripos(PHP_OS_FAMILY, 'Windows') !== false) {
        // start /B melepaskan proses dari sesi Apache, sehingga worker
        // tetap hidup setelah permintaan HTTP ini selesai.
        $perintah = 'cmd /c start /B "" ' . escapeshellarg($php) . ' '
            . escapeshellarg($skrip) . ' >> ' . escapeshellarg($log) . ' 2>&1';
    } else {
        $perintah = escapeshellarg($php) . ' ' . escapeshellarg($skrip)
            . ' >> ' . escapeshellarg($log) . ' 2>&1 &';
    }

    pclose(popen($perintah, 'r'));

    // Beri waktu worker menulis heartbeat pertamanya sebelum status dibaca.
    for ($i = 0; $i < 20; $i++) {
        usleep(250000);

        $status = $statusWorker();

        if ($status['berjalan']) {
            $jawab(['ok' => true, 'pesan' => 'Worker dinyalakan (PID ' . $status['pid'] . ').'] + $status);
        }
    }

    $jawab([
        'ok'    => false,
        'pesan' => 'Worker tidak merespons dalam 5 detik. Periksa worker.log.',
    ] + $statusWorker(), 500);
}

$jawab(['ok' => false, 'pesan' => 'Aksi tidak dikenal.'], 400);
