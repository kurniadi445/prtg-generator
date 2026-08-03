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

    // Kredensial & endpoint PRTG bawaan.
    // Dipakai semua template yang tidak menimpanya lewat kunci 'prtg'.
    'prtg' => [
        'base_url' => 'https://prtg.example.com/',
        'username' => 'NAMA_PENGGUNA_PRTG',
        'password' => 'KATA_SANDI_PRTG',
    ],

    // Pengaturan worker pemroses antrean
    'worker' => [
        // Berkas PHP yang dipakai saat worker dinyalakan dari browser
        // (tombol di halaman Antrean). Sesuaikan dengan lokasi XAMPP Anda.
        'php_binary' => 'C:\\xampp\\php\\php.exe',

        // Alamat IP yang boleh menyalakan / mematikan worker dari browser.
        // Bawaannya hanya komputer server itu sendiri.
        //
        // Kalau aplikasi diakses lewat alamat jaringan (mis.
        // http://192.168.1.10/prtg-generator), localhost saja tidak cukup —
        // tambahkan alamat komputer yang Anda pakai, atau isi ['*'] untuk
        // membuka ke semua. Alamat Anda saat ini bisa dilihat di
        // worker-control.php?aksi=status pada kolom "ip_anda".
        //
        // Perlu diingat aplikasi ini belum punya login, jadi membuka ke '*'
        // berarti siapa pun yang bisa mengakses halaman ini bisa menyalakan
        // dan mematikan worker.
        'kendali_ip' => ['127.0.0.1', '::1'],
    ],

    // Pengaturan umum pembuatan laporan Word
    'report' => [
        // URL basis aplikasi ini di web server, dipakai Browsershot untuk render HTML
        'app_base_url' => 'http://localhost/prtg-generator',

        // Template yang dipakai bila kolom `pelanggan`.`template` kosong.
        // Harus sama dengan DEFAULT kolom tersebut di database.
        'default_template' => 'idt',

        // Apa yang dilakukan bila berkas dengan nama sama sudah ada.
        //
        //   'timpa'  berkas lama ditimpa (perilaku lama)
        //   'versi'  berkas lama dipertahankan, yang baru diberi akhiran
        //            " (2)", " (3)", dst.
        //
        // Sejak folder hasil dipisah per template
        // (jobs/<template>/<pelanggan>/), laporan ICM dan IDT untuk pelanggan
        // dan bulan yang sama TIDAK lagi saling menimpa walau nilainya 'timpa'.
        // Opsi ini hanya soal generate ulang template yang sama.
        'mode_tulis' => 'timpa',

        // Ambang SLA (Service Level Agreement) dalam persen, dipakai halaman
        // sla.php untuk mewarnai sel yang di bawah target.
        'sla_target' => 99.5,
    ],

    // ---------------------------------------------------------------------
    // Daftar template dokumen.
    //
    // Kunci array = nilai kolom `pelanggan`.`template` sekaligus nama berkas
    // di folder templates/, mis. 'idt' -> templates/idt.php
    //
    // Menambah template baru:
    //   1. tambahkan entri di sini
    //   2. buat templates/<kunci>.php yang mengembalikan closure
    //   3. arahkan pelanggan lewat kolom `template`
    //
    // Kunci yang dikenali tiap entri:
    //   label      wajib   nama yang tampil di dropdown pelanggan.php
    //   prtg       opsional  menimpa sebagian/seluruh pengaturan PRTG di atas
    //   watermark  dipakai templates/idt.php  — gambar sampul satu halaman
    //   cover      dipakai templates/icm.php  — gambar sampul satu halaman
    //   logo       dipakai templates/icm.php  — logo kecil di header lembar isi
    //   signature  blok tanda tangan; 'underline' menentukan nama digarisbawahi
    //   approval   daftar kotak centang di footer; kosongkan bila tak dipakai
    // ---------------------------------------------------------------------
    'templates' => [

        'idt' => [
            'label'     => 'PT Inti Data Telematika',
            'watermark' => 'img/watermark/idt.png',

            // Tidak ada kunci 'prtg' -> ikut pengaturan default di atas.

            'signature' => [
                'left' => [
                    'heading'   => 'Nama Perusahaan',
                    'name'      => 'Nama Penanda Tangan',
                    'title'     => 'Jabatan Penanda Tangan',
                    'underline' => true,
                ],
                'right' => [
                    'heading'   => 'Pelanggan',
                    'name'      => '.............................',
                    'title'     => '.............................',
                    'underline' => false,
                ],
            ],

            'approval' => [
                ['name' => 'Nama Penyetuju 1', 'title' => 'Jabatan Penyetuju 1'],
                ['name' => 'Nama Penyetuju 2', 'title' => 'Jabatan Penyetuju 2'],
            ],
        ],

        'icm' => [
            'label' => 'PT Intergate Cahaya Media',
            'cover' => 'img/watermark/icm.png',
            'logo'  => 'img/logo/icm.png',

            // Server PRTG berbeda untuk template ini. Kunci yang ditulis
            // menimpa default; yang tidak ditulis tetap ikut default —
            // jadi bila hanya URL-nya yang beda, cukup tulis 'base_url'.
            'prtg' => [
                'base_url' => 'https://prtg-lain.example.com/',
                'username' => 'NAMA_PENGGUNA_PRTG_LAIN',
                'password' => 'KATA_SANDI_PRTG_LAIN',
            ],

            'signature' => [
                'left' => [
                    'heading'   => 'Prepared By',
                    'name'      => 'Nama Penyusun',
                    'title'     => 'Jabatan Penyusun',
                    'underline' => true,
                ],
                'right' => [
                    'heading'   => 'Approved By',
                    'name'      => 'Nama Penyetuju',
                    'title'     => 'Jabatan Penyetuju',
                    'underline' => true,
                ],
            ],

            // Template ini tidak memakai footer approval.
        ],
    ],
];
