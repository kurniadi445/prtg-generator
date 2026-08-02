<?php

/**
 * Worker pemroses antrean.
 *
 * Bisa dijalankan lewat worker.bat / start-worker-hidden.vbs seperti biasa,
 * atau dinyalakan dari browser lewat worker-control.php.
 *
 * Tiga hal yang membuatnya bisa dipantau dan dikendalikan:
 *
 *   1. Heartbeat  - status ditulis ke tmp/worker.json setiap putaran, jadi
 *                   halaman lain bisa tahu worker hidup atau tidak.
 *   2. Instance tunggal - menolak jalan bila sudah ada worker lain yang
 *                   heartbeat-nya masih segar. Mencegah dua worker mengambil
 *                   job yang sama.
 *   3. Berhenti rapi - bila berkas tmp/worker.stop muncul, worker keluar
 *                   setelah job yang sedang berjalan selesai. Lebih aman
 *                   daripada mematikan proses di tengah pembuatan dokumen.
 */

require_once 'database.php';
require_once 'generate-report.php';

const DETAK_BERKAS      = 'tmp/worker.json';
const HENTI_BERKAS      = 'tmp/worker.stop';
const DETAK_KEDALUWARSA = 15;   // detik; lebih tua dari ini dianggap mati

if (!is_dir('tmp')) {
    mkdir('tmp', 0777, true);
}

/**
 * Baca heartbeat terakhir. Kembalikan null bila tidak ada atau rusak.
 */
function bacaDetak(): ?array
{
    if (!is_file(DETAK_BERKAS)) {
        return null;
    }

    $isi = json_decode((string) file_get_contents(DETAK_BERKAS), true);

    return is_array($isi) ? $isi : null;
}

/**
 * Apakah heartbeat ini masih dianggap hidup?
 */
function workerHidup(?array $detak): bool
{
    if (!$detak || empty($detak['last_seen'])) {
        return false;
    }

    return (time() - (int) $detak['last_seen']) <= DETAK_KEDALUWARSA;
}

/**
 * Tulis heartbeat. $status: 'idle' atau 'processing'.
 */
function tulisDetak(string $status, ?string $jobId, int $mulai): void
{
    file_put_contents(DETAK_BERKAS, json_encode([
        'pid'        => getmypid(),
        'status'     => $status,
        'job_id'     => $jobId,
        'started_at' => $mulai,
        'last_seen'  => time(),
    ], JSON_PRETTY_PRINT), LOCK_EX);
}

// --- Penjaga instance tunggal -------------------------------------------
$detakLama = bacaDetak();

if (workerHidup($detakLama)) {
    echo "Worker lain sudah berjalan (PID {$detakLama['pid']}). Keluar.\n";
    exit(0);
}

// Sisa perintah berhenti dari sesi sebelumnya jangan sampai terbawa.
if (is_file(HENTI_BERKAS)) {
    unlink(HENTI_BERKAS);
}

$mulaiSesi = time();

// Hapus heartbeat saat proses berakhir, apa pun sebabnya, supaya status
// tidak tertinggal sebagai "berjalan" padahal sudah mati.
register_shutdown_function(function () {
    if (is_file(DETAK_BERKAS)) {
        @unlink(DETAK_BERKAS);
    }
});

$bd = db();

echo 'Worker aktif (PID ' . getmypid() . ")...\n";

tulisDetak('idle', null, $mulaiSesi);

while (true) {

    // Permintaan berhenti diperiksa di awal putaran, bukan di tengah job.
    if (is_file(HENTI_BERKAS)) {
        unlink(HENTI_BERKAS);
        echo "Perintah berhenti diterima. Worker keluar.\n";
        exit(0);
    }

    $job = $bd->query("SELECT * FROM jobs WHERE status = 'queued' ORDER BY created_at LIMIT 1")
        ->fetch(PDO::FETCH_ASSOC);

    if (!$job) {
        tulisDetak('idle', null, $mulaiSesi);
        sleep(2);

        continue;
    }

    $bd->prepare("UPDATE jobs SET status = 'processing' WHERE id = ?")->execute([$job['id']]);

    tulisDetak('processing', $job['id'], $mulaiSesi);

    try {
        $files = generateReportRange(
            $job['bulan_mulai'],
            $job['bulan_akhir'],
            $job['pelanggan'],
            $job['id'],
            $job['rekap_downtime']
        );

        foreach ($files as $file) {
            $bd->prepare('INSERT INTO job_files (job_id, filename) VALUES (?, ?)')
                ->execute([$job['id'], $file]);
        }

        $bd->prepare("UPDATE jobs SET status = 'done', finished_at = NOW() WHERE id = ?")
            ->execute([$job['id']]);
    } catch (Throwable $e) {
        $bd->prepare("UPDATE jobs SET status = 'failed', error = ?, finished_at = NOW() WHERE id = ?")
            ->execute([$e->getMessage(), $job['id']]);
    }

    tulisDetak('idle', null, $mulaiSesi);
}
