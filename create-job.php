<?php

require 'database.php';

$dari          = $_POST['dari'] ?? '';
$sampai        = $_POST['sampai'] ?? '';
$pelanggan     = (array) ($_POST['pelanggan'] ?? []);
$rekapDowntime = isset($_POST['rekap_downtime']) ? 1 : 0;

$formatBulan = '/^\d{4}-(0[1-9]|1[0-2])$/';

// --- Validasi ---
$error = null;
$pelanggan = array_values(array_filter($pelanggan, fn($id) => $id !== ''));

if (!preg_match($formatBulan, $dari) || !preg_match($formatBulan, $sampai)) {
    $error = 'Periode belum dipilih atau formatnya salah.';
} elseif ($sampai < $dari) {
    $error = 'Bulan "Sampai" tidak boleh sebelum "Dari".';
} elseif (count($pelanggan) === 0) {
    $error = 'Pilih minimal satu pelanggan.';
}

$dibuat = [];

if ($error === null) {
    $bd = db();

    // Ambil nama pelanggan untuk ditampilkan.
    $tanda   = implode(',', array_fill(0, count($pelanggan), '?'));
    $qNama   = $bd->prepare("SELECT id, nama FROM pelanggan WHERE id IN ($tanda)");
    $qNama->execute($pelanggan);

    $namaMap = [];
    foreach ($qNama->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $namaMap[$r['id']] = $r['nama'];
    }

    $insert = $bd->prepare("
        INSERT INTO jobs (id, bulan_mulai, bulan_akhir, pelanggan, rekap_downtime)
        VALUES (?, ?, ?, ?, ?)
    ");

    foreach ($pelanggan as $idPelanggan) {
        $jobId = uniqid();
        $insert->execute([$jobId, $dari, $sampai, $idPelanggan, $rekapDowntime]);

        $dibuat[] = [
            'jobId' => $jobId,
            'nama'  => $namaMap[$idPelanggan] ?? '(pelanggan tidak dikenal)',
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta content="initial-scale=1, width=device-width" name="viewport">
    <title>Job Dibuat — PRTG Generator</title>
    <style>
        :root {
            --biru: #007bff; --biru-tua: #0062cc; --garis: #d9dde3;
            --abu: #f4f4f9; --teks: #2b2f36; --redup: #8a909a;
            --hijau: #198754; --merah: #dc3545;
        }
        * { box-sizing: border-box; }
        body { background: var(--abu); color: var(--teks); font-family: Arial, Helvetica, sans-serif; margin: 0; padding: 24px 16px; }
        .bar { align-items: baseline; display: flex; gap: 14px; justify-content: space-between; margin: 0 auto 18px; max-width: 640px; }
        .bar h2 { margin: 0; }
        a.nav { color: var(--biru); font-size: 14px; text-decoration: none; }
        a.nav:hover { text-decoration: underline; }
        .kartu { background: #fff; border: 1px solid var(--garis); border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,.06); margin: 0 auto 18px; max-width: 640px; padding: 20px 22px; }

        .banner { align-items: center; border-radius: 8px; display: flex; gap: 12px; font-size: 15px; padding: 14px 16px; }
        .banner.ok  { background: #e7f6ec; border: 1px solid #b6e3c4; color: var(--hijau); }
        .banner.err { background: #fdeaec; border: 1px solid #f3c2c7; color: var(--merah); }
        .banner .kar { font-size: 22px; }

        .ringkas { color: var(--redup); font-size: 13px; margin-top: 14px; }

        .job { align-items: center; border: 1px solid var(--garis); border-radius: 8px; display: flex; gap: 12px; margin-top: 10px; padding: 12px 14px; }
        .job .isi { flex: 1; min-width: 0; }
        .job .nama { font-size: 14px; font-weight: bold; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .job .id { color: var(--redup); font-size: 12px; }
        .job a { background: var(--biru); border-radius: 6px; color: #fff; flex: none; font-size: 13px; font-weight: bold; padding: 8px 14px; text-decoration: none; }
        .job a:hover { background: var(--biru-tua); }

        .tombol-baris { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 4px; }
        .tombol { border-radius: 8px; font-size: 14px; font-weight: bold; padding: 11px 18px; text-decoration: none; }
        .tombol.primer { background: var(--biru); color: #fff; }
        .tombol.primer:hover { background: var(--biru-tua); }
        .tombol.abu { background: #eef1f5; border: 1px solid var(--garis); color: var(--teks); }
        .tombol.abu:hover { background: #e2e6ec; }
    </style>
</head>
<body>
<div class="bar">
    <h2>Buat Laporan</h2>
    <span>
        <a class="nav" href="index.php">← Generator</a>
        &nbsp;·&nbsp;
        <a class="nav" href="hasil.php">Hasil Laporan</a>
    </span>
</div>

<?php if ($error !== null): ?>
    <div class="kartu">
        <div class="banner err">
            <span class="kar">⚠️</span>
            <span><?= htmlspecialchars($error) ?></span>
        </div>
        <div class="tombol-baris" style="margin-top:16px;">
            <a class="tombol primer" href="index.php">← Kembali &amp; perbaiki</a>
        </div>
    </div>
<?php else: ?>
    <div class="kartu">
        <div class="banner ok">
            <span class="kar">✅</span>
            <span><b><?= count($dibuat) ?></b> job berhasil dibuat dan masuk antrean.</span>
        </div>
        <div class="ringkas">
            Periode <b><?= htmlspecialchars($dari) ?></b> &rarr; <b><?= htmlspecialchars($sampai) ?></b>
            · Rekap downtime: <b><?= $rekapDowntime ? 'Ya' : 'Tidak' ?></b>
        </div>

        <?php foreach ($dibuat as $d): ?>
            <div class="job">
                <div class="isi">
                    <div class="nama"><?= htmlspecialchars($d['nama']) ?></div>
                    <div class="id">Job ID: <?= htmlspecialchars($d['jobId']) ?></div>
                </div>
                <a href="status.php?id=<?= htmlspecialchars($d['jobId'], ENT_QUOTES) ?>">Cek Status →</a>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="kartu">
        <div class="tombol-baris">
            <a class="tombol primer" href="index.php">Buat lagi</a>
            <a class="tombol abu" href="hasil.php">Lihat semua hasil</a>
        </div>
    </div>
<?php endif; ?>
</body>
</html>
