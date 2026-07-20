<?php

/**
 * Unduh seluruh isi satu folder pelanggan (jobs/<folder>/*.docx) sebagai ZIP.
 * Contoh: unduh-folder.php?folder=PT%20ABC
 */

$gagal = function (int $kode, string $pesan): void {
    http_response_code($kode);
    header('Content-Type: text/plain; charset=utf-8');
    echo $pesan;
    exit;
};

// Ambil hanya nama folder (basename) untuk cegah path traversal (../ dsb).
$nama = basename($_GET['folder'] ?? '');

if ($nama === '' || $nama === '.' || $nama === '..') {
    $gagal(400, 'Nama folder tidak valid.');
}

$rootReal = realpath('jobs');
$dirReal  = realpath('jobs/' . $nama);

// Pastikan folder ada dan benar-benar berada di dalam jobs/.
if ($rootReal === false || $dirReal === false || !is_dir($dirReal)
    || strncmp($dirReal, $rootReal . DIRECTORY_SEPARATOR, strlen($rootReal) + 1) !== 0) {
    $gagal(404, 'Folder tidak ditemukan.');
}

$files = glob($dirReal . '/*.docx') ?: [];

if (!$files) {
    $gagal(404, 'Tidak ada file untuk diunduh.');
}

$tmpZip = tempnam(sys_get_temp_dir(), 'prtg') . '.zip';

$zip = new ZipArchive();
if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    $gagal(500, 'Gagal membuat arsip ZIP.');
}

foreach ($files as $f) {
    $zip->addFile($f, basename($f));
}

$zip->close();

$zipName = $nama . '.zip';

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $zipName) . '"');
header('Content-Length: ' . filesize($tmpZip));
header('Cache-Control: no-store');

readfile($tmpZip);
unlink($tmpZip);
exit;
