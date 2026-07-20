<?php

require 'database.php';

$dari          = $_POST['dari'] ?? '';
$sampai        = $_POST['sampai'] ?? '';
$pelanggan     = (array) ($_POST['pelanggan'] ?? []);
$rekapDowntime = isset($_POST['rekap_downtime']) ? 1 : 0;

$formatBulan = '/^\d{4}-(0[1-9]|1[0-2])$/';

if (!preg_match($formatBulan, $dari) || !preg_match($formatBulan, $sampai)) {
    die('Periode belum dipilih atau formatnya salah.');
}

if ($sampai < $dari) {
    die('Bulan "sampai" tidak boleh sebelum "dari".');
}

$pelanggan = array_filter($pelanggan, fn($id) => $id !== '');

if (count($pelanggan) === 0) {
    die('Pilih minimal satu pelanggan.');
}

$bd = db();

$perintah = $bd->prepare("
    INSERT INTO jobs
    (id, bulan_mulai, bulan_akhir, pelanggan, rekap_downtime)
    VALUES (?, ?, ?, ?, ?)
");

echo "<h3>Job berhasil dibuat</h3>";

foreach ($pelanggan as $idPelanggan) {

    $jobId = uniqid();

    $perintah->execute([
        $jobId,
        $dari,
        $sampai,
        $idPelanggan,
        $rekapDowntime
    ]);

    $jobIdAman = htmlspecialchars($jobId, ENT_QUOTES);

    echo "
        <p>
            Job ID : <b>$jobIdAman</b><br>
            <a href=\"status.php?id=$jobIdAman\">Cek Status</a>
        </p>
    ";
}
