<?php

/**
 * Template konfigurasi aplikasi.
 *
 * Cara pakai:
 *   1. Salin file ini menjadi `config.php`
 *   2. Sesuaikan nilainya dengan lingkungan Anda
 *
 * `config.php` berisi kredensial asli dan TIDAK ikut di-commit (lihat .gitignore).
 */

return [

    // Koneksi database MySQL / MariaDB
    'db' => [
        'host'    => 'localhost',
        'name'    => 'prtg_generator',
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],

    // Kredensial & endpoint server PRTG
    'prtg' => [
        'base_url' => 'https://prtg.inti.net.id/',
        'username' => 'isi-username-prtg',
        'password' => 'isi-password-prtg',
    ],

    // Pengaturan pembuatan laporan Word
    'report' => [
        // URL basis aplikasi ini di web server, dipakai Browsershot untuk render HTML
        'app_base_url' => 'http://localhost/prtg-generator',
        'watermark'    => 'img/watermark.png',
        'prepared_by'  => ['name' => 'Nama Penyusun', 'title' => 'Jabatan Penyusun'],
        'approved_by'  => ['name' => 'Nama Penyetuju', 'title' => 'Jabatan Penyetuju'],
    ],
];
