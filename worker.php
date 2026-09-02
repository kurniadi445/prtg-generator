<?php

/**
 * Worker pemroses antrean.
 *
 * Bisa dijalankan lewat worker.bat / start-worker-hidden.vbs seperti biasa,
 * atau dinyalakan dari browser lewat worker-control.php.
 *
 * Tiga hal yang membuatnya bisa dipantau dan dikendalikan:
 *
 *   1. Heartbeat  - status ditulis ke tmp/worker.json, termasuk di sela-sela
 *                   job yang panjang, jadi halaman lain bisa tahu worker hidup
 *                   atau tidak. Aturannya ada di worker-heartbeat.php.
 *   2. Instance tunggal - menolak jalan bila sudah ada worker lain yang
 *                   heartbeat-nya masih berlaku. Mencegah dua worker mengambil
 *                   job yang sama.
 *   3. Berhenti rapi - bila berkas tmp/worker.stop muncul, worker keluar
 *                   setelah job yang sedang berjalan selesai. Lebih aman
 *                   daripada mematikan proses di tengah pembuatan dokumen.
 *
 * Worker ini dirancang untuk hidup berhari-hari. Yang perlu diingat saat
 * mengubahnya: apa pun yang gagal di dalam putaran tidak boleh mematikan
 * proses, karena worker yang dinyalakan dari browser tidak punya supervisor
 * yang menyalakannya kembali.
 */

// Worker yang dinyalakan dari browser mewarisi direktori kerja Apache, bukan
// folder aplikasi. Tanpa baris ini, 'vendor/autoload.php' dan folder 'jobs/'
// bisa menunjuk ke tempat yang salah.
chdir(__DIR__);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/worker-heartbeat.php';
require_once __DIR__ . '/generate-report.php';

// Kode keluar khusus saat berhenti atas permintaan. worker.bat memakainya
// untuk membedakan "diminta berhenti" dari "mati sendiri" — tanpa itu, loop
// restart di worker.bat langsung menyalakannya lagi dan tombol Stop di
// browser terlihat tidak berfungsi.
const KELUAR_DIMINTA_BERHENTI = 3;

/**
 * Catatan ke stdout, yang oleh worker.bat / worker-control.php dialihkan ke
 * worker.log. Diberi waktu supaya bisa dicocokkan dengan keluhan pengguna.
 */
function catat(string $pesan): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $pesan . "\n";
}

/**
 * Pesan error yang cukup untuk menelusuri asal masalah, bukan hanya teksnya.
 */
function pesanGalat(Throwable $e): string
{
    return get_class($e) . ': ' . $e->getMessage()
        . ' (' . $e->getFile() . ':' . $e->getLine() . ')';
}

/**
 * Ambil satu job dari antrean dan klaim secara atomik.
 *
 * SELECT lalu UPDATE tanpa syarat adalah balapan: dua proses bisa membaca job
 * yang sama sebelum salah satunya sempat menandainya. Syarat
 * "AND status = 'queued'" membuat hanya satu UPDATE yang berhasil; yang kalah
 * mendapat rowCount() = 0 dan melewatkannya.
 *
 * @return array|null null bila antrean kosong atau job keburu diambil pihak lain
 */
function ambilJob(PDO $bd): ?array
{
    $job = $bd->query("SELECT * FROM jobs WHERE status = 'queued' ORDER BY created_at LIMIT 1")
        ->fetch(PDO::FETCH_ASSOC);

    if (!$job) {
        return null;
    }

    $klaim = $bd->prepare("UPDATE jobs SET status = 'processing' WHERE id = ? AND status = 'queued'");
    $klaim->execute([$job['id']]);

    return $klaim->rowCount() === 1 ? $job : null;
}

/**
 * Tandai gagal semua job yang tertinggal berstatus 'processing'.
 *
 * Job yang worker-nya mati di tengah jalan tidak akan pernah disentuh lagi:
 * statusnya tetap 'processing' selamanya, tidak diproses ulang, dan tidak bisa
 * dibersihkan dari halaman Antrean karena job 'processing' justru dilindungi
 * dari penghapusan.
 *
 * Ditandai 'failed', bukan dikembalikan ke 'queued', karena tabel jobs belum
 * punya penghitung percobaan. Job yang secara konsisten membuat worker mati
 * akan dicoba tanpa henti bila diantrekan ulang otomatis. Ditandai gagal,
 * penyebabnya terlihat oleh pengguna dan job bisa dibuat ulang dengan sadar.
 */
function pulihkanJobTerbengkalai(PDO $bd): void
{
    $perintah = $bd->prepare(
        "UPDATE jobs SET status = 'failed', error = ?, finished_at = NOW() WHERE status = 'processing'"
    );

    $perintah->execute([
        'Worker berhenti saat job ini sedang diproses (mati mendadak, dihentikan '
        . 'paksa, atau komputer restart). Job perlu dibuat ulang.',
    ]);

    if ($perintah->rowCount() > 0) {
        catat($perintah->rowCount() . ' job terbengkalai ditandai gagal.');
    }
}

/**
 * Sambung ulang ke database, mencoba terus sampai berhasil.
 *
 * Koneksi PDO dipakai ulang selama proses hidup, sementara MySQL memutus
 * koneksi menganggur setelah wait_timeout (bawaan 8 jam). Query pertama
 * setelah itu melempar "server has gone away". Worker tidak boleh mati karena
 * ini, jadi ia menunggu dengan jeda yang makin panjang — dan tetap menulis
 * heartbeat, karena ia memang masih hidup.
 */
function sambungUlangDb(int $mulaiSesi): PDO
{
    $jeda = 5;

    while (true) {
        if (is_file(HENTI_BERKAS)) {
            unlink(HENTI_BERKAS);
            catat('Perintah berhenti diterima saat menunggu database. Worker keluar.');
            exit(KELUAR_DIMINTA_BERHENTI);
        }

        try {
            $bd = db(true);
            $bd->query('SELECT 1');

            catat('Koneksi database tersambung kembali.');

            return $bd;
        } catch (Throwable $e) {
            catat('Database tidak bisa dihubungi: ' . $e->getMessage() . " — coba lagi $jeda detik.");
        }

        detakTulis('idle', null, $mulaiSesi);
        sleep($jeda);

        $jeda = min($jeda * 2, 60);
    }
}

/**
 * Kerjakan satu job sampai selesai, lalu tandai hasilnya.
 *
 * Kegagalan pembuatan laporan ditangani di sini dan tidak dilempar keluar,
 * supaya satu job yang bermasalah tidak menjatuhkan worker.
 */
function prosesJob(PDO $bd, array $job, int $mulaiSesi): void
{
    detakTulis('processing', $job['id'], $mulaiSesi, DETAK_TENGGANG_SIBUK);

    $simpanBerkas = $bd->prepare('INSERT INTO job_files (job_id, filename, path) VALUES (?, ?, ?)');

    try {
        generateReportRange(
            $job['bulan_mulai'],
            $job['bulan_akhir'],
            $job['pelanggan'],
            $job['id'],
            $job['rekap_downtime'],
            // Dipanggil di sela langkah-langkah berat. Tanpa ini heartbeat
            // basi sepanjang job berjalan dan worker dikira mati.
            function () use ($job, $mulaiSesi): void {
                detakTulis('processing', $job['id'], $mulaiSesi, DETAK_TENGGANG_SIBUK);
            },
            // Dicatat per bulan, bukan setelah seluruh rentang selesai: kalau
            // bulan kelima gagal, empat dokumen yang sudah jadi tetap tercatat
            // dan bisa diunduh, bukan hilang dari database.
            function (string $path) use ($simpanBerkas, $job): void {
                $simpanBerkas->execute([$job['id'], basename($path), $path]);
            }
        );

        $bd->prepare("UPDATE jobs SET status = 'done', finished_at = NOW() WHERE id = ?")
            ->execute([$job['id']]);

        catat('Job ' . $job['id'] . ' selesai.');
    } catch (Throwable $e) {
        catat('Job ' . $job['id'] . ' gagal: ' . pesanGalat($e));

        $bd->prepare("UPDATE jobs SET status = 'failed', error = ?, finished_at = NOW() WHERE id = ?")
            ->execute([pesanGalat($e), $job['id']]);
    }
}

// --- Penjaga instance tunggal -------------------------------------------
$detakLama = detakBaca();

if (detakHidup($detakLama)) {
    catat("Worker lain sudah berjalan (PID {$detakLama['pid']}). Keluar.");
    exit(0);
}

// Sisa perintah berhenti dari sesi sebelumnya jangan sampai terbawa.
if (is_file(HENTI_BERKAS)) {
    unlink(HENTI_BERKAS);
}

$mulaiSesi = time();

// Hapus heartbeat saat proses berakhir, apa pun sebabnya, supaya status tidak
// tertinggal sebagai "berjalan" padahal sudah mati.
//
// Hanya bila heartbeat itu memang milik proses ini: worker yang keluar karena
// kalah dari penjaga instance tunggal, atau yang sempat menimpa heartbeat
// worker lain, tidak boleh menghapus tanda hidup milik worker yang masih
// bekerja.
register_shutdown_function(function (): void {
    if (detakMilikSaya(detakBaca())) {
        @unlink(DETAK_BERKAS);
    }
});

catat('Worker aktif (PID ' . getmypid() . ')...');

detakTulis('idle', null, $mulaiSesi);

// Koneksi pertama sengaja TIDAK memakai loop sambung-ulang. config.php yang
// hilang atau kredensial yang salah adalah masalah permanen: mencobanya
// berulang kali hanya menyembunyikan penyebabnya di dalam worker.log. Lebih
// baik keluar dengan pesan jelas dan biarkan worker.bat mencoba lagi — itu
// sekaligus menangani MySQL yang belum siap saat komputer baru menyala.
try {
    $bd = db();

    pulihkanJobTerbengkalai($bd);
} catch (Throwable $e) {
    catat('Tidak bisa memulai: ' . pesanGalat($e));
    exit(1);
}

while (true) {

    // Seluruh isi putaran dibungkus, termasuk query pengambilan job dan
    // penulisan status. Sebelumnya hanya pembuatan laporan yang dilindungi,
    // sehingga koneksi database yang putus mematikan worker tanpa jejak.
    try {
        // Permintaan berhenti diperiksa di awal putaran, bukan di tengah job.
        if (is_file(HENTI_BERKAS)) {
            unlink(HENTI_BERKAS);
            catat('Perintah berhenti diterima. Worker keluar.');
            exit(KELUAR_DIMINTA_BERHENTI);
        }

        $job = ambilJob($bd);

        if (!$job) {
            detakTulis('idle', null, $mulaiSesi);
            sleep(2);

            continue;
        }

        prosesJob($bd, $job, $mulaiSesi);

        detakTulis('idle', null, $mulaiSesi);
    } catch (Throwable $e) {
        catat('Galat pada putaran worker: ' . pesanGalat($e));

        // Koneksi disambung ulang tanpa mengasumsikan penyebabnya. Kalau
        // koneksinya sebenarnya sehat, sambung ulang hanya memboroskan satu
        // koneksi baru — jauh lebih murah daripada worker yang mati diam-diam.
        $bd = sambungUlangDb($mulaiSesi);

        // Job yang sedang dikerjakan saat galat tadi kini terbengkalai. Ini
        // ditangani terpisah karena exception di sini akan lolos dari blok
        // catch dan mematikan worker — kegagalan yang justru sedang dicegah.
        // Bila gagal, putaran berikutnya akan mencoba lagi.
        try {
            pulihkanJobTerbengkalai($bd);
        } catch (Throwable $galatPulih) {
            catat('Pemulihan job terbengkalai gagal: ' . pesanGalat($galatPulih));
        }
    }
}
