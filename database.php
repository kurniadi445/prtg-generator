<?php

/**
 * Loader konfigurasi dan koneksi database.
 *
 * config()  -> membaca config.php (di-cache), opsional ambil satu bagian.
 * db()      -> koneksi PDO tunggal (singleton) yang dipakai ulang.
 */

function config(?string $bagian = null)
{
    static $config = null;

    if ($config === null) {
        $path = __DIR__ . '/config.php';

        if (!is_file($path)) {
            throw new RuntimeException(
                'config.php tidak ditemukan. Salin config.example.php menjadi config.php lalu sesuaikan.'
            );
        }

        $config = require $path;
    }

    if ($bagian === null) {
        return $config;
    }

    return $config[$bagian] ?? null;
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $db = config('db');

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $db['host'],
        $db['name'],
        $db['charset']
    );

    $pdo = new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}
