<?php

/**
 * Alat bantu sekali pakai: rapikan hasil lama ke struktur baru.
 *
 *   sebelum : jobs/<PELANGGAN>/2026-07 - <PELANGGAN>.docx
 *   sesudah : jobs/<template>/<PELANGGAN>/2026-07 - <PELANGGAN>.docx
 *
 * Template ditentukan dari kolom `template` di tabel pelanggan, dicocokkan
 * lewat sanitizeFolderName(nama). Folder yang namanya tidak cocok dengan
 * pelanggan mana pun sengaja DIBIARKAN — akan tetap muncul di hasil.php
 * pada cabang "Arsip lama", jadi tidak ada yang hilang.
 *
 * Buka di browser: http://localhost/prtg-generator/migrasi-jobs.php
 * Halaman pertama hanya menampilkan rencana. Pemindahan baru dilakukan
 * setelah tombol ditekan.
 *
 * Setelah selesai dan hasilnya benar, berkas ini boleh dihapus.
 */

require_once 'helpers.php';
require_once 'database.php';

$jalankan = ($_SERVER['REQUEST_METHOD'] === 'POST') && isset($_POST['jalankan']);

$labelTemplate = daftarTemplate();
$templateAwal  = config('report')['default_template'] ?? 'idt';

// Peta: nama folder aman -> [template, nama asli]
$peta = [];

foreach (db()->query('SELECT nama, template FROM pelanggan')->fetchAll(PDO::FETCH_ASSOC) as $p) {
    $tpl = $p['template'] ?: $templateAwal;

    if (!isset($labelTemplate[$tpl])) {
        continue;
    }

    $peta[sanitizeFolderName($p['nama'])] = ['template' => $tpl, 'nama' => $p['nama']];
}

$rencana  = [];
$dilewati = [];

foreach (glob('jobs/*', GLOB_ONLYDIR) ?: [] as $dir) {
    $nama = basename($dir);

    // Folder template (sudah tertata) tidak diusik.
    if (isset($labelTemplate[$nama]) && !(glob($dir . '/*.docx') ?: [])) {
        continue;
    }

    $berkas = glob($dir . '/*.docx') ?: [];

    if (!$berkas) {
        continue;
    }

    if (!isset($peta[$nama])) {
        $dilewati[] = ['folder' => $nama, 'jumlah' => count($berkas), 'alasan' => 'tidak cocok dengan pelanggan mana pun'];
        continue;
    }

    $rencana[] = [
        'dari'     => $dir,
        'folder'   => $nama,
        'template' => $peta[$nama]['template'],
        'ke'       => 'jobs/' . $peta[$nama]['template'] . '/' . $nama,
        'berkas'   => $berkas,
    ];
}

$hasil = [];

if ($jalankan) {
    foreach ($rencana as $r) {
        if (!is_dir($r['ke']) && !mkdir($r['ke'], 0777, true) && !is_dir($r['ke'])) {
            $hasil[] = ['folder' => $r['folder'], 'pesan' => 'GAGAL membuat folder tujuan', 'ok' => false];
            continue;
        }

        $pindah = 0;
        $gagal  = 0;

        foreach ($r['berkas'] as $berkas) {
            $tujuan = $r['ke'] . '/' . basename($berkas);

            // Berkas yang sudah ada di tujuan tidak ditimpa; dilaporkan saja.
            if (is_file($tujuan)) {
                $gagal++;
                continue;
            }

            if (@rename($berkas, $tujuan)) {
                $pindah++;
            } else {
                $gagal++;
            }
        }

        @rmdir($r['dari']);   // hanya berhasil bila sudah kosong

        $hasil[] = [
            'folder' => $r['folder'],
            'pesan'  => $pindah . ' berkas dipindah ke ' . $r['ke']
                        . ($gagal ? ' — ' . $gagal . ' berkas dilewati (sudah ada di tujuan atau terkunci)' : ''),
            'ok'     => $gagal === 0,
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta content="initial-scale=1, width=device-width" name="viewport">
    <title>Migrasi Folder Hasil — PRTG Generator</title>
    <style>
        body { background: #f4f4f9; color: #2b2f36; font-family: Arial, Helvetica, sans-serif; margin: 0; padding: 24px 16px; }
        .kartu { background: #fff; border: 1px solid #d9dde3; border-radius: 10px; margin: 0 auto 18px; max-width: 820px; padding: 20px 22px; }
        h2, h3 { margin-top: 0; }
        code { background: #f2f5f9; border-radius: 4px; padding: 1px 5px; }
        li { font-size: 14px; margin-bottom: 6px; }
        .tombol { background: #007bff; border: 0; border-radius: 6px; color: #fff; cursor: pointer; font-size: 15px; font-weight: bold; padding: 11px 20px; }
        .tombol:hover { background: #0062cc; }
        .ok  { background: #e7f6ec; border: 1px solid #b6e3c4; border-radius: 8px; color: #198754; padding: 12px 16px; }
        .wrn { background: #fff8e6; border: 1px solid #f0dfae; border-radius: 8px; color: #8a6d1a; padding: 12px 16px; }
        a { color: #007bff; }
    </style>
</head>
<body>

<div class="kartu">
    <h2>Migrasi Folder Hasil</h2>
    <p style="font-size:14px;">
        Memindahkan hasil lama dari <code>jobs/&lt;PELANGGAN&gt;/</code>
        ke <code>jobs/&lt;template&gt;/&lt;PELANGGAN&gt;/</code>.
        Berkas hanya dipindah, tidak ada yang dihapus atau ditimpa.
    </p>
    <p style="font-size:14px;"><a href="hasil.php">← Kembali ke Hasil Laporan</a></p>
</div>

<?php if ($jalankan): ?>
    <div class="kartu">
        <h3>Hasil</h3>
        <?php if (!$hasil): ?>
            <div class="ok">Tidak ada yang perlu dipindah.</div>
        <?php else: ?>
            <ul>
                <?php foreach ($hasil as $h): ?>
                    <li><b><?= htmlspecialchars($h['folder']) ?></b> — <?= htmlspecialchars($h['pesan']) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <p style="font-size:14px;"><a href="hasil.php">Lihat hasilnya di halaman Hasil Laporan →</a></p>
    </div>
<?php else: ?>
    <div class="kartu">
        <h3>Rencana</h3>
        <?php if (!$rencana): ?>
            <div class="ok">Semua folder sudah tertata. Tidak ada yang perlu dipindah.</div>
        <?php else: ?>
            <ul>
                <?php foreach ($rencana as $r): ?>
                    <li>
                        <b><?= htmlspecialchars($r['folder']) ?></b>
                        (<?= count($r['berkas']) ?> berkas)
                        &rarr; <code><?= htmlspecialchars($r['ke']) ?></code>
                    </li>
                <?php endforeach; ?>
            </ul>
            <form method="post">
                <button class="tombol" type="submit" name="jalankan" value="1">Jalankan pemindahan</button>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($dilewati): ?>
        <div class="kartu">
            <h3>Dilewati</h3>
            <div class="wrn">
                Folder berikut tidak dipindah karena namanya tidak cocok dengan pelanggan mana pun
                di database (mungkin pelanggannya sudah dihapus atau namanya berubah).
                Berkasnya tetap aman dan tetap tampil di halaman Hasil Laporan pada cabang
                <b>Arsip lama</b>.
            </div>
            <ul style="margin-top:12px;">
                <?php foreach ($dilewati as $d): ?>
                    <li><?= htmlspecialchars($d['folder']) ?> (<?= $d['jumlah'] ?> berkas)</li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
<?php endif; ?>

</body>
</html>
