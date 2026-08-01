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

        // Blok tanda tangan di bagian bawah lembar ke-2
        'signature' => [
            'left' => [
                'heading' => 'Nama Perusahaan',
                'name'    => 'Nama Penanda Tangan',
                'title'   => 'Jabatan Penanda Tangan',
            ],
            'right' => [
                'heading' => 'Pelanggan',
                'name'    => '.............................',
                'title'   => '.............................',
            ],
        ],

        // Daftar kotak centang "Approval :" di footer lembar ke-2.
        // Boleh diisi berapa pun barisnya; satu entri = satu baris.
        'approval' => [
            ['name' => 'Nama Penyetuju 1', 'title' => 'Jabatan Penyetuju 1'],
            ['name' => 'Nama Penyetuju 2', 'title' => 'Jabatan Penyetuju 2'],
        ],
    ],
];
