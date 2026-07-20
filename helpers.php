<?php

/**
 * Fungsi bantu yang dipakai bersama beberapa halaman.
 */

/**
 * Bersihkan nama pelanggan agar aman dipakai sebagai nama folder.
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
