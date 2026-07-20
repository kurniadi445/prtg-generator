<?php

require 'database.php';

header('Content-Type: application/json');

$id = $_GET['id'] ?? '';

if ($id === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Parameter id wajib diisi']);
    exit;
}

$bd = db();

$perintah = $bd->prepare('SELECT * FROM jobs WHERE id = ?');
$perintah->execute([$id]);

$job = $perintah->fetch(PDO::FETCH_ASSOC);

if ($job === false) {
    http_response_code(404);
    echo json_encode(['error' => 'Job tidak ditemukan']);
    exit;
}

echo json_encode($job, JSON_PRETTY_PRINT);
