<?php

/**
 * Kendali worker dari browser.
 *
 *   worker-control.php?aksi=status   -> JSON status worker + antrean
 *   worker-control.php?aksi=start    -> nyalakan worker sebagai proses latar
 *   worker-control.php?aksi=stop     -> minta worker berhenti setelah job ini
 *
 * KEAMANAN: 'start' menjalankan proses di server. Daftar IP yang boleh
 * menyalakan/mematikan worker diatur di config('worker')['kendali_ip'].
 * Bawaannya hanya localhost. Isi '*' untuk membuka ke semua alamat.
 *
 * Perlu diingat: aplikasi ini tidak punya login sama sekali, jadi siapa pun
 * yang bisa membuka halamannya juga bisa menghapus job dan berkas laporan.
 * Kalau sekarang berada di server bersama, pengamanan yang sebenarnya
 * berguna adalah memasang autentikasi di depan seluruh aplikasi.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/worker-heartbeat.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/**
 * Alamat pemanggil, dinormalkan. PHP melaporkan klien IPv4 di soket IPv6
 * sebagai '::ffff:192.168.1.10'; awalan itu dibuang agar cocok dengan
 * daftar di config.
 */
function alamatAsal(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    if (stripos($ip, '::ffff:') === 0) {
        $ip = substr($ip, 7);
    }

    return $ip;
}

/**
 * Boleh menyalakan/mematikan worker dari alamat ini?
 */
function bolehKendali(string $asal): bool
{
    $daftar = config('worker')['kendali_ip'] ?? ['127.0.0.1', '::1'];

    if (in_array('*', $daftar, true)) {
        return true;
    }

    return in_array($asal, $daftar, true);
}

$asal = alamatAsal();

$jawab = function (array $data, int $kode = 200): void {
    http_response_code($kode);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
};

/**
 * Status worker berdasarkan heartbeat + isi antrean.
 */
$statusWorker = function () use ($asal): array {
    $detak = detakBaca();
    $hidup = detakHidup($detak);

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
        'boleh_kendali' => bolehKendali($asal),
        'ip_anda'       => $asal,
    ];
};

$aksi = $_GET['aksi'] ?? 'status';

// --- status: boleh diakses dari mana saja -------------------------------
if ($aksi === 'status') {
    $jawab($statusWorker());
}

// --- start / stop: hanya dari mesin yang sama ---------------------------
if (!bolehKendali($asal)) {
    $jawab([
        'ok'    => false,
        'pesan' => "Alamat $asal tidak diizinkan mengendalikan worker. "
            . "Tambahkan ke config('worker')['kendali_ip'].",
    ], 403);
}

if ($aksi === 'stop') {
    $status = $statusWorker();

    if (!$status['berjalan']) {
        $jawab(['ok' => false, 'pesan' => 'Worker memang sedang tidak berjalan.'] + $status);
    }

    $folder = dirname(HENTI_BERKAS);

    if (!is_dir($folder)) {
        mkdir($folder, 0777, true);
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
