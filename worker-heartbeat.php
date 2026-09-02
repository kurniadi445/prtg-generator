<?php

/**
 * Heartbeat worker — satu-satunya sumber kebenaran tentang "worker hidup atau tidak".
 *
 * Logika ini sebelumnya disalin di worker.php, worker-control.php, dan
 * antrean.php, masing-masing dengan ambang 15 detik yang ditulis ulang.
 * Formatnya kini punya masa berlaku yang berubah-ubah (lihat di bawah),
 * sehingga ketiga pembacanya wajib memakai aturan yang sama persis.
 *
 * MASA BERLAKU
 * ------------
 * Dulu worker dianggap mati bila heartbeat lebih tua dari 15 detik. Itu keliru:
 * selama memproses job, worker tertahan di dalam cURL dan Browsershot selama
 * puluhan detik sampai beberapa menit dan tidak sempat menulis apa pun — jadi
 * worker yang sedang bekerja justru dilaporkan mati. Akibatnya tombol "Mulai"
 * aktif kembali, worker kedua dinyalakan, dan dua worker memperebutkan job
 * yang sama.
 *
 * Karena itu worker sendiri yang menyatakan sampai kapan heartbeat-nya berlaku
 * ('berlaku_sampai'). Saat menganggur ia minta tenggang pendek supaya kematian
 * cepat terdeteksi; saat mulai langkah berat ia minta tenggang panjang.
 *
 * Path memakai __DIR__, bukan relatif terhadap direktori kerja: worker yang
 * dinyalakan dari browser mewarisi direktori kerja Apache, bukan folder
 * aplikasi, sehingga path relatif bisa menunjuk ke tempat yang salah.
 */

const DETAK_BERKAS = __DIR__ . '/tmp/worker.json';
const HENTI_BERKAS = __DIR__ . '/tmp/worker.stop';

// Tenggang saat menganggur. Worker menulis detak tiap 2 detik, jadi 15 detik
// sudah sangat longgar sekaligus membuat kematian cepat terlihat.
const DETAK_TENGGANG_IDLE = 15;

// Tenggang saat memproses job. Satu bulan laporan = login PRTG + scraping +
// render Chromium + tulis .docx; harus lebih lama dari langkah terlama.
const DETAK_TENGGANG_SIBUK = 300;

/**
 * Baca heartbeat terakhir. Kembalikan null bila tidak ada atau rusak.
 */
function detakBaca(): ?array
{
    if (!is_file(DETAK_BERKAS)) {
        return null;
    }

    $isi = json_decode((string) file_get_contents(DETAK_BERKAS), true);

    return is_array($isi) ? $isi : null;
}

/**
 * Apakah heartbeat ini masih berlaku?
 *
 * Fallback ke last_seen + tenggang idle disediakan supaya berkas heartbeat
 * dari versi lama (yang belum punya 'berlaku_sampai') tetap terbaca dan tidak
 * dianggap hidup selamanya.
 */
function detakHidup(?array $detak): bool
{
    if (!is_array($detak)) {
        return false;
    }

    if (isset($detak['berlaku_sampai'])) {
        return time() <= (int) $detak['berlaku_sampai'];
    }

    if (empty($detak['last_seen'])) {
        return false;
    }

    return time() <= (int) $detak['last_seen'] + DETAK_TENGGANG_IDLE;
}

/**
 * Apakah heartbeat yang ada di disk milik proses ini sendiri?
 *
 * Dipakai sebelum menghapus heartbeat saat keluar: worker yang menyerah karena
 * kalah dari penjaga instance tunggal tidak boleh menghapus heartbeat milik
 * worker lain yang masih bekerja.
 */
function detakMilikSaya(?array $detak): bool
{
    return is_array($detak) && (int) ($detak['pid'] ?? 0) === getmypid();
}

/**
 * Tulis heartbeat.
 *
 * @param string      $status   'idle' atau 'processing'
 * @param string|null $jobId    job yang sedang dikerjakan, bila ada
 * @param int         $mulai    kapan sesi worker ini dimulai
 * @param int         $tenggang berapa detik ke depan heartbeat ini berlaku
 */
function detakTulis(string $status, ?string $jobId, int $mulai, int $tenggang = DETAK_TENGGANG_IDLE): void
{
    $folder = dirname(DETAK_BERKAS);

    if (!is_dir($folder)) {
        mkdir($folder, 0777, true);
    }

    file_put_contents(DETAK_BERKAS, json_encode([
        'pid'            => getmypid(),
        'status'         => $status,
        'job_id'         => $jobId,
        'started_at'     => $mulai,
        'last_seen'      => time(),
        'berlaku_sampai' => time() + $tenggang,
    ], JSON_PRETTY_PRINT), LOCK_EX);
}
