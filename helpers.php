<?php

/**
 * Fungsi bantu yang dipakai bersama beberapa halaman dan semua template.
 */

require_once __DIR__ . '/database.php';

/**
 * Bersihkan nama pelanggan agar aman dipakai sebagai nama folder/berkas.
 */
function sanitizeFolderName(string $name, int $maxLength = 100): string
{
    $name = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
    $name = preg_replace('/[<>:"\/\\\\|?*\x00-\x1F]/', '-', $name);
    $name = preg_replace('/[^A-Za-z0-9._ -]/', '-', $name);
    $name = preg_replace('/\s+/', ' ', $name);
    $name = preg_replace('/-+/', '-', $name);
    $name = trim($name, " .-");

    if ($name === '') {
        $name = 'UNKNOWN';
    }

    return mb_substr($name, 0, $maxLength);
}

/**
 * Ubah path relatif menjadi URL yang aman (tiap segmen di-encode),
 * mis. "jobs/PT ABC/2026-01 - PT ABC.docx".
 */
function jobFileUrl(string $relativePath): string
{
    $relativePath = str_replace('\\', '/', $relativePath);

    return implode('/', array_map('rawurlencode', explode('/', $relativePath)));
}

/**
 * Format ukuran byte jadi mudah dibaca (B, KB, MB, GB).
 */
function formatBytes(int $bytes): string
{
    $unit = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $nilai = $bytes;

    while ($nilai >= 1024 && $i < count($unit) - 1) {
        $nilai /= 1024;
        $i++;
    }

    return ($i === 0 ? $nilai : number_format($nilai, 1)) . ' ' . $unit[$i];
}

/**
 * Hitung jumlah bulan (inklusif) antara dua string 'YYYY-MM'.
 * Kembalikan null bila format tidak valid.
 */
function monthCountInclusive(?string $mulai, ?string $akhir): ?int
{
    if (!$mulai || !$akhir) {
        return null;
    }

    $a = DateTime::createFromFormat('Y-m-d', $mulai . '-01');
    $b = DateTime::createFromFormat('Y-m-d', $akhir . '-01');

    if (!$a || !$b || $b < $a) {
        return null;
    }

    $selisih = $a->diff($b);

    return $selisih->y * 12 + $selisih->m + 1;
}

/**
 * Style font yang dipakai berulang di laporan.
 */
function fontStyle(int $size, bool $bold = false, string $name = 'Times New Roman'): array
{
    return ['name' => $name, 'size' => $size, 'bold' => $bold];
}

/**
 * Ubah total detik menjadi teks durasi, mis. "1 jam 16 menit 5 detik".
 */
function formatDurasi(int $detik): string
{
    $bagian = [];

    $hari  = intdiv($detik, 86400);
    $jam   = intdiv($detik % 86400, 3600);
    $menit = intdiv($detik % 3600, 60);
    $sisa  = $detik % 60;

    if ($hari)  { $bagian[] = $hari . ' hari'; }
    if ($jam)   { $bagian[] = $jam . ' jam'; }
    if ($menit) { $bagian[] = $menit . ' menit'; }
    if ($sisa)  { $bagian[] = $sisa . ' detik'; }

    return $bagian ? implode(' ', $bagian) : '0 detik';
}

/**
 * Ubah path relatif di dalam jobs/ menjadi path fisik yang sudah divalidasi.
 *
 * Dipakai bersama oleh hasil.php dan unduh.php sejak folder hasil menjadi
 * bertingkat (jobs/<template>/<pelanggan>/). basename() saja tidak lagi cukup
 * untuk mencegah path traversal karena path-nya kini memang punya beberapa segmen.
 *
 * @return string|false path absolut, atau false bila tidak valid / tidak ada.
 */
function jobsPathAman(string $relatif)
{
    $root = realpath('jobs');

    if ($root === false) {
        return false;
    }

    $relatif = trim(str_replace('\\', '/', $relatif), '/');

    if ($relatif === '') {
        return $root;
    }

    foreach (explode('/', $relatif) as $segmen) {
        if ($segmen === '' || $segmen === '.' || $segmen === '..') {
            return false;
        }
    }

    $target = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relatif));

    if ($target === false) {
        return false;
    }

    if (strncmp($target, $root . DIRECTORY_SEPARATOR, strlen($root) + 1) !== 0) {
        return false;
    }

    return $target;
}

/**
 * Kumpulkan seluruh berkas .docx di dalam sebuah folder, termasuk subfolder.
 *
 * @return array [path fisik => path relatif terhadap $dasar]
 */
function kumpulkanDocx(string $dirFisik, string $dasar = ''): array
{
    $hasil = [];

    foreach (scandir($dirFisik) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $anak    = $dirFisik . DIRECTORY_SEPARATOR . $item;
        $relatif = $dasar === '' ? $item : $dasar . '/' . $item;

        if (is_dir($anak)) {
            $hasil += kumpulkanDocx($anak, $relatif);
        } elseif (strtolower(pathinfo($item, PATHINFO_EXTENSION)) === 'docx') {
            $hasil[$anak] = $relatif;
        }
    }

    return $hasil;
}

/**
 * Hapus folder beserta isinya. Hanya .docx yang dihapus sebagai berkas;
 * jenis lain sengaja dibiarkan agar tidak ada yang terhapus tak sengaja.
 *
 * @return int jumlah berkas yang berhasil dihapus
 */
function hapusFolderDocx(string $dirFisik): int
{
    $jumlah = 0;

    foreach (scandir($dirFisik) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $anak = $dirFisik . DIRECTORY_SEPARATOR . $item;

        if (is_dir($anak)) {
            $jumlah += hapusFolderDocx($anak);
            @rmdir($anak);
        } elseif (strtolower(pathinfo($item, PATHINFO_EXTENSION)) === 'docx' && @unlink($anak)) {
            $jumlah++;
        }
    }

    return $jumlah;
}

/**
 * Buang folder-folder kosong di dalam $akar, dari yang terdalam lebih dulu.
 * $akar sendiri tidak pernah dihapus.
 *
 * Dipanggil setelah penghapusan berkas, supaya jobs/ tidak menyisakan
 * kerangka folder pelanggan yang isinya sudah tidak ada.
 */
function rapikanFolderKosong(string $akar): void
{
    if (!is_dir($akar)) {
        return;
    }

    foreach (scandir($akar) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $anak = $akar . DIRECTORY_SEPARATOR . $item;

        if (is_dir($anak)) {
            rapikanFolderKosong($anak);

            // rmdir() hanya berhasil bila folder benar-benar kosong,
            // jadi tidak perlu diperiksa lagi di sini.
            @rmdir($anak);
        }
    }
}

/**
 * Daftar template yang terpasang: kunci => label, untuk dipakai di dropdown.
 */
function daftarTemplate(): array
{
    $hasil = [];

    foreach ((array) config('templates') as $kunci => $opsi) {
        $hasil[$kunci] = $opsi['label'] ?? $kunci;
    }

    return $hasil;
}
