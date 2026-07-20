<?php

require 'helpers.php';
require 'database.php';

/**
 * Hapus baris job_files yang cocok dengan nama file (non-fatal bila DB error).
 */
function bersihkanJobFiles(array $filenames): void
{
    $filenames = array_values(array_unique(array_filter($filenames)));

    if (!$filenames) {
        return;
    }

    try {
        $tanda = implode(',', array_fill(0, count($filenames), '?'));
        db()->prepare("DELETE FROM job_files WHERE filename IN ($tanda)")->execute($filenames);
    } catch (Throwable $e) {
        // abaikan; penghapusan file fisik tetap dianggap berhasil
    }
}

/**
 * Penghapusan diproses lewat POST lalu redirect (pola PRG).
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi   = $_POST['aksi'] ?? '';
    $folder = basename($_POST['folder'] ?? '');
    $file   = basename($_POST['file'] ?? '');

    $pesan = fn(string $k, string $v) => header('Location: hasil.php?' . $k . '=' . urlencode($v));

    $rootReal = realpath('jobs');
    $dirReal  = ($folder !== '' && $rootReal !== false) ? realpath('jobs/' . $folder) : false;
    $folderValid = $dirReal !== false && is_dir($dirReal)
        && strncmp($dirReal, $rootReal . DIRECTORY_SEPARATOR, strlen($rootReal) + 1) === 0;

    if (!$folderValid) {
        $pesan('err', 'Folder tidak valid.');
    } elseif ($aksi === 'hapus_folder') {
        $files = glob($dirReal . '/*.docx') ?: [];
        $n = 0;
        foreach ($files as $f) {
            if (@unlink($f)) {
                $n++;
            }
        }
        bersihkanJobFiles(array_map('basename', $files));
        @rmdir($dirReal);
        $pesan('ok', $n . ' file dihapus dari "' . $folder . '".');
    } elseif ($aksi === 'hapus_file') {
        $target = realpath('jobs/' . $folder . '/' . $file);
        $fileValid = $target !== false && is_file($target)
            && strncmp($target, $rootReal . DIRECTORY_SEPARATOR, strlen($rootReal) + 1) === 0
            && strtolower(pathinfo($target, PATHINFO_EXTENSION)) === 'docx';

        if (!$fileValid) {
            $pesan('err', 'File tidak valid.');
        } else {
            @unlink($target);
            bersihkanJobFiles([$file]);
            @rmdir($dirReal); // hapus folder bila jadi kosong
            $pesan('ok', 'File dihapus.');
        }
    } else {
        $pesan('err', 'Aksi tidak dikenal.');
    }

    exit;
}

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

$ok  = $_GET['ok']  ?? '';
$err = $_GET['err'] ?? '';
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
            --merah: #dc3545; --hijau: #198754;
        }
        * { box-sizing: border-box; }
        body { background: var(--abu); color: var(--teks); font-family: Arial, Helvetica, sans-serif; margin: 0; padding: 24px 16px; }
        .bar { align-items: baseline; display: flex; gap: 14px; justify-content: space-between; margin: 0 auto 6px; max-width: 860px; }
        .bar h2 { margin: 0; }
        a.nav { color: var(--biru); font-size: 14px; text-decoration: none; }
        a.nav:hover { text-decoration: underline; }
        .ringkas { color: var(--redup); font-size: 13px; margin: 0 auto 16px; max-width: 860px; }

        .flash { border-radius: 8px; font-size: 14px; margin: 0 auto 16px; max-width: 860px; padding: 12px 16px; }
        .flash.ok  { background: #e7f6ec; border: 1px solid #b6e3c4; color: var(--hijau); }
        .flash.err { background: #fdeaec; border: 1px solid #f3c2c7; color: var(--merah); }

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
        summary a.zip { background: #eef1f5; border: 1px solid var(--garis); border-radius: 6px; color: var(--teks); flex: none; font-size: 12px; font-weight: bold; padding: 5px 10px; text-decoration: none; white-space: nowrap; }
        summary a.zip:hover { background: var(--biru); border-color: var(--biru); color: #fff; }

        .toolbar { background: #fbfcfd; border-top: 1px solid #f2f4f7; padding: 8px 18px; }
        .btn-hapus-folder { background: #fff; border: 1px solid #f0c2c7; border-radius: 6px; color: var(--merah); cursor: pointer; font-size: 13px; padding: 6px 12px; }
        .btn-hapus-folder:hover { background: var(--merah); border-color: var(--merah); color: #fff; }

        .file { align-items: center; border-top: 1px solid #f2f4f7; display: flex; gap: 12px; padding: 10px 18px 10px 46px; }
        .file .kar { font-size: 18px; }
        .file .isi { flex: 1; min-width: 0; }
        .file .nm { font-size: 14px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .file .meta { color: var(--redup); font-size: 12px; }
        .file a.unduh { background: #eef1f5; border: 1px solid var(--garis); border-radius: 6px; color: var(--teks); flex: none; font-size: 13px; padding: 6px 14px; text-decoration: none; }
        .file a.unduh:hover { background: var(--biru); border-color: var(--biru); color: #fff; }
        .file button.hapus1 { background: #fff; border: 1px solid #f0c2c7; border-radius: 6px; color: var(--merah); cursor: pointer; flex: none; font-size: 14px; padding: 6px 10px; }
        .file button.hapus1:hover { background: var(--merah); border-color: var(--merah); color: #fff; }
        form.inline { display: inline; margin: 0; }

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

<?php if ($ok !== ''): ?>
    <div class="flash ok"><?= htmlspecialchars($ok) ?></div>
<?php endif; ?>
<?php if ($err !== ''): ?>
    <div class="flash err"><?= htmlspecialchars($err) ?></div>
<?php endif; ?>

<div class="kotak">
    <?php if (!$folders): ?>
        <div class="kosong">Belum ada laporan yang dihasilkan.</div>
    <?php else: ?>
        <input id="cari" type="text" placeholder="🔍 Cari pelanggan atau nama file..." autocomplete="off">

        <div id="daftar">
            <?php foreach ($folders as $fo):
                $namaAman = htmlspecialchars($fo['nama'], ENT_QUOTES);
                ?>
                <details class="folder" data-cari="<?= htmlspecialchars($fo['cari'], ENT_QUOTES) ?>">
                    <summary>
                        <span class="kar">📁</span>
                        <span class="nm"><?= htmlspecialchars($fo['nama']) ?></span>
                        <span class="jm"><?= $fo['jumlah'] ?> file · <?= htmlspecialchars($fo['ukuran']) ?></span>
                        <a class="zip" href="unduh-folder.php?folder=<?= rawurlencode($fo['nama']) ?>"
                           onclick="event.stopPropagation()" title="Unduh semua sebagai ZIP">⬇ ZIP</a>
                        <span class="panah">▸</span>
                    </summary>

                    <div class="toolbar">
                        <form method="post" class="inline"
                              onsubmit="return confirm('Hapus SEMUA <?= $fo['jumlah'] ?> file di folder ini? Tindakan tidak bisa dibatalkan.');">
                            <input type="hidden" name="aksi" value="hapus_folder">
                            <input type="hidden" name="folder" value="<?= $namaAman ?>">
                            <button type="submit" class="btn-hapus-folder">🗑 Hapus folder ini</button>
                        </form>
                    </div>

                    <?php foreach ($fo['files'] as $f): ?>
                        <div class="file">
                            <span class="kar">📄</span>
                            <div class="isi">
                                <div class="nm"><?= htmlspecialchars($f['nama']) ?></div>
                                <div class="meta"><?= htmlspecialchars($f['ukuran']) ?> · <?= htmlspecialchars($f['waktu']) ?></div>
                            </div>
                            <a class="unduh" href="<?= $f['url'] ?>" download>Unduh</a>
                            <form method="post" class="inline"
                                  onsubmit="return confirm('Hapus file ini?');">
                                <input type="hidden" name="aksi" value="hapus_file">
                                <input type="hidden" name="folder" value="<?= $namaAman ?>">
                                <input type="hidden" name="file" value="<?= htmlspecialchars($f['nama'], ENT_QUOTES) ?>">
                                <button type="submit" class="hapus1" title="Hapus file">🗑</button>
                            </form>
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
