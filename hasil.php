<?php

require 'helpers.php';

/**
 * Telusuri folder hasil (jobs/<pelanggan>/*.docx) dan kelompokkan per folder,
 * seperti tampilan folder pada Google Drive.
 */
$root = 'jobs';
$folders = [];
$totalFile = 0;
$totalUkuran = 0;

if (is_dir($root)) {
    foreach (glob($root . '/*', GLOB_ONLYDIR) as $dir) {
        $daftarFile = glob($dir . '/*.docx') ?: [];

        if (!$daftarFile) {
            continue;
        }

        $files = [];
        $ukuranFolder = 0;

        foreach ($daftarFile as $path) {
            $ukuran = filesize($path);
            $ukuranFolder += $ukuran;
            $totalUkuran += $ukuran;

            $files[] = [
                'nama'   => basename($path),
                'ukuran' => formatBytes($ukuran),
                'waktu'  => date('d/m/Y H:i', filemtime($path)),
                'url'    => jobFileUrl($path),
            ];
        }

        usort($files, fn($a, $b) => strcmp($a['nama'], $b['nama']));

        $totalFile += count($files);

        $folders[] = [
            'nama'    => basename($dir),
            'jumlah'  => count($files),
            'ukuran'  => formatBytes($ukuranFolder),
            'files'   => $files,
            'cari'    => strtolower(basename($dir) . ' ' . implode(' ', array_column($files, 'nama'))),
        ];
    }

    usort($folders, fn($a, $b) => strcmp($a['nama'], $b['nama']));
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta content="initial-scale=1, width=device-width" name="viewport">
    <title>Hasil Laporan — PRTG Generator</title>
    <style>
        :root {
            --biru: #007bff; --biru-tua: #0062cc; --garis: #d9dde3;
            --abu: #f4f4f9; --teks: #2b2f36; --redup: #8a909a;
        }
        * { box-sizing: border-box; }
        body { background: var(--abu); color: var(--teks); font-family: Arial, Helvetica, sans-serif; margin: 0; padding: 24px 16px; }
        .bar { align-items: baseline; display: flex; gap: 14px; justify-content: space-between; margin: 0 auto 6px; max-width: 860px; }
        .bar h2 { margin: 0; }
        a.nav { color: var(--biru); font-size: 14px; text-decoration: none; }
        a.nav:hover { text-decoration: underline; }
        .ringkas { color: var(--redup); font-size: 13px; margin: 0 auto 16px; max-width: 860px; }

        .kotak { background: #fff; border: 1px solid var(--garis); border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,.06); margin: 0 auto; max-width: 860px; overflow: hidden; }
        #cari { border: 0; border-bottom: 1px solid var(--garis); font-size: 14px; padding: 14px 18px; width: 100%; }
        #cari:focus { outline: none; }

        details.folder { border-bottom: 1px solid #eef0f3; }
        details.folder:last-child { border-bottom: 0; }
        summary { align-items: center; cursor: pointer; display: flex; gap: 12px; list-style: none; padding: 14px 18px; }
        summary::-webkit-details-marker { display: none; }
        summary:hover { background: #f7f9fc; }
        summary .kar { font-size: 22px; }
        summary .nm { flex: 1; font-size: 15px; font-weight: bold; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        summary .jm { color: var(--redup); font-size: 13px; white-space: nowrap; }
        summary .panah { color: var(--redup); transition: transform .2s; }
        details[open] summary .panah { transform: rotate(90deg); }

        .file { align-items: center; border-top: 1px solid #f2f4f7; display: flex; gap: 12px; padding: 10px 18px 10px 46px; }
        .file .kar { font-size: 18px; }
        .file .isi { flex: 1; min-width: 0; }
        .file .nm { font-size: 14px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .file .meta { color: var(--redup); font-size: 12px; }
        .file a { background: #eef1f5; border: 1px solid var(--garis); border-radius: 6px; color: var(--teks); flex: none; font-size: 13px; padding: 6px 14px; text-decoration: none; }
        .file a:hover { background: var(--biru); border-color: var(--biru); color: #fff; }

        .kosong { color: var(--redup); font-size: 14px; padding: 30px 18px; text-align: center; }
        #tak-ada { display: none; }
    </style>
</head>
<body>
<div class="bar">
    <h2>Hasil Laporan</h2>
    <span>
        <a class="nav" href="index.php">← Generator</a>
        &nbsp;·&nbsp;
        <a class="nav" href="pelanggan.php">Kelola Pelanggan</a>
    </span>
</div>
<div class="ringkas"><?= count($folders) ?> pelanggan · <?= $totalFile ?> file · <?= formatBytes($totalUkuran) ?></div>

<div class="kotak">
    <?php if (!$folders): ?>
        <div class="kosong">Belum ada laporan yang dihasilkan.</div>
    <?php else: ?>
        <input id="cari" type="text" placeholder="🔍 Cari pelanggan atau nama file..." autocomplete="off">

        <div id="daftar">
            <?php foreach ($folders as $fo): ?>
                <details class="folder" data-cari="<?= htmlspecialchars($fo['cari'], ENT_QUOTES) ?>">
                    <summary>
                        <span class="kar">📁</span>
                        <span class="nm"><?= htmlspecialchars($fo['nama']) ?></span>
                        <span class="jm"><?= $fo['jumlah'] ?> file · <?= htmlspecialchars($fo['ukuran']) ?></span>
                        <span class="panah">▸</span>
                    </summary>
                    <?php foreach ($fo['files'] as $f): ?>
                        <div class="file">
                            <span class="kar">📄</span>
                            <div class="isi">
                                <div class="nm"><?= htmlspecialchars($f['nama']) ?></div>
                                <div class="meta"><?= htmlspecialchars($f['ukuran']) ?> · <?= htmlspecialchars($f['waktu']) ?></div>
                            </div>
                            <a href="<?= $f['url'] ?>" download>Unduh</a>
                        </div>
                    <?php endforeach; ?>
                </details>
            <?php endforeach; ?>
            <div class="kosong" id="tak-ada">Tidak ada yang cocok.</div>
        </div>
    <?php endif; ?>
</div>

<script>
    'use strict';

    const cari = document.getElementById('cari');

    if (cari) {
        const folders = Array.from(document.querySelectorAll('.folder'));
        const takAda = document.getElementById('tak-ada');

        cari.addEventListener('input', function () {
            const q = this.value.trim().toLowerCase();
            let tampil = 0;

            folders.forEach(el => {
                const cocok = el.dataset.cari.includes(q);
                el.style.display = cocok ? '' : 'none';
                if (cocok) {
                    tampil++;
                    el.open = q !== '';   // buka otomatis saat mencari
                }
            });

            takAda.style.display = tampil === 0 ? 'block' : 'none';
        });
    }
</script>
</body>
</html>
