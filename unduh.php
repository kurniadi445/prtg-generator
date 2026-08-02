<?php

/**
 * Unduh hasil laporan sebagai ZIP.
 *
 *   unduh.php                     -> seluruh isi jobs/ (berstruktur folder)
 *   unduh.php?folder=PT%20ABC     -> satu folder pelanggan saja (isi rata)
 *
 * Menggantikan unduh-folder.php; berkas lama itu boleh dihapus.
 */

$gagal = function (int $kode, string $pesan): void {
    http_response_code($kode);
    header('Content-Type: text/plain; charset=utf-8');
    echo $pesan;
    exit;
};

$rootReal = realpath('jobs');

if ($rootReal === false) {
    $gagal(404, 'Folder jobs/ tidak ditemukan.');
}

/**
 * Pastikan sebuah path benar-benar berada di dalam jobs/.
 */
$diDalamRoot = function (string $path) use ($rootReal): bool {
    return strncmp($path, $rootReal . DIRECTORY_SEPARATOR, strlen($rootReal) + 1) === 0;
};

$folder = isset($_GET['folder']) ? basename($_GET['folder']) : '';

// ---------------------------------------------------------------------
// Kumpulkan daftar file: [path fisik => nama di dalam ZIP]
// ---------------------------------------------------------------------
$isi = [];

if ($folder !== '') {
    // --- Satu folder pelanggan ---
    if ($folder === '.' || $folder === '..') {
        $gagal(400, 'Nama folder tidak valid.');
    }

    $dirReal = realpath('jobs/' . $folder);

    if ($dirReal === false || !is_dir($dirReal) || !$diDalamRoot($dirReal)) {
        $gagal(404, 'Folder tidak ditemukan.');
    }

    foreach (glob($dirReal . '/*.docx') ?: [] as $path) {
        $isi[$path] = basename($path);
    }

    $namaZip = $folder . '.zip';
} else {
    // --- Seluruh hasil, struktur folder dipertahankan ---
    // Path relatif dipakai di sini supaya pemisah direktorinya konsisten.
    // Mencampur hasil realpath() (backslash di Windows) dengan pola '/'
    // membuat perbandingan path selalu gagal.
    foreach (glob('jobs/*', GLOB_ONLYDIR) ?: [] as $dir) {
        foreach (glob($dir . '/*.docx') ?: [] as $path) {
            $isi[$path] = basename($dir) . '/' . basename($path);
        }
    }

    $namaZip = 'laporan-' . date('Y-m-d') . '.zip';
}

if (!$isi) {
    $gagal(404, 'Tidak ada file untuk diunduh.');
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
