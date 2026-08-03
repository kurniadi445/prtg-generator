<?php

/**
 * Unduh hasil laporan sebagai ZIP.
 *
 *   unduh.php                        -> seluruh isi jobs/ (struktur folder dipertahankan)
 *   unduh.php?path=idt               -> seluruh cabang satu template
 *   unduh.php?path=idt/PT%20ABC      -> satu folder pelanggan
 *   unduh.php?folder=PT%20ABC        -> bentuk lama, masih didukung (arsip lama)
 *
 * Menggantikan unduh-folder.php; berkas lama itu boleh dihapus.
 */

require_once 'helpers.php';

$gagal = function (int $kode, string $pesan): void {
    http_response_code($kode);
    header('Content-Type: text/plain; charset=utf-8');
    echo $pesan;
    exit;
};

if (realpath('jobs') === false) {
    $gagal(404, 'Folder jobs/ tidak ditemukan.');
}

// Parameter lama `folder` diterjemahkan ke `path` agar tautan lama tetap hidup.
$rel = (string) ($_GET['path'] ?? $_GET['folder'] ?? '');

// ---------------------------------------------------------------------
// Kumpulkan daftar berkas: [path fisik => nama di dalam ZIP]
// ---------------------------------------------------------------------
if ($rel === '') {
    $dirFisik = realpath('jobs');
    $namaZip  = 'laporan-' . date('Y-m-d') . '.zip';
} else {
    $dirFisik = jobsPathAman($rel);

    if ($dirFisik === false || !is_dir($dirFisik)) {
        $gagal(404, 'Folder tidak ditemukan.');
    }

    // Nama ZIP memakai seluruh segmen path supaya berkas hasil unduhan
    // tidak tertukar antar template, mis. "idt - PT ABC.zip".
    $namaZip = str_replace('/', ' - ', trim(str_replace('\\', '/', $rel), '/')) . '.zip';
}

$isi = kumpulkanDocx($dirFisik);

if (!$isi) {
    $gagal(404, 'Tidak ada berkas untuk diunduh.');
}

// Arsip besar butuh waktu; jangan diputus batas eksekusi bawaan.
@set_time_limit(0);

$tmpZip = tempnam(sys_get_temp_dir(), 'prtg') . '.zip';

$zip = new ZipArchive();

if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    $gagal(500, 'Gagal membuat arsip ZIP.');
}

foreach ($isi as $path => $namaDiZip) {
    $zip->addFile($path, $namaDiZip);
}

$zip->close();

// Buffer apa pun yang tersisa dibuang, supaya tidak mengotori isi ZIP.
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $namaZip) . '"');
header('Content-Length: ' . filesize($tmpZip));
header('Cache-Control: no-store');

readfile($tmpZip);
unlink($tmpZip);
exit;
