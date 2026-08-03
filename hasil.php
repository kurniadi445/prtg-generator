<?php

/**
 * Halaman hasil laporan — tampilan pohon (treeview) empat tingkat:
 *
 *     Template  >  Pelanggan  >  Tahun  >  Berkas
 *
 * Menggantikan tampilan lama yang hanya satu tingkat (folder pelanggan).
 * Folder pelanggan yang masih berada langsung di bawah jobs/ (hasil versi
 * lama, sebelum penataan per template) tetap ditampilkan, dikelompokkan
 * di cabang "Arsip lama".
 */

require_once 'helpers.php';
require_once 'database.php';

$labelTemplate = daftarTemplate();

/**
 * Apakah folder ini sebuah folder template, atau folder pelanggan versi lama?
 *
 * Dua syarat harus terpenuhi: namanya terdaftar sebagai kunci template, DAN
 * tidak ada .docx langsung di dalamnya. Syarat kedua penting supaya folder
 * pelanggan yang kebetulan bernama sama dengan kunci template tidak salah baca.
 */
function folderTemplate(string $dir, string $nama, array $labelTemplate): bool
{
    return isset($labelTemplate[$nama]) && !(glob($dir . '/*.docx') ?: []);
}

/**
 * Baca satu folder pelanggan menjadi simpul pohon, dikelompokkan per tahun.
 */
function simpulPelanggan(string $dir, string $pathRelatif): ?array
{
    $daftar = glob($dir . '/*.docx') ?: [];

    if (!$daftar) {
        return null;
    }

    $tahun  = [];
    $ukuran = 0;

    foreach ($daftar as $path) {
        $nama = basename($path);
        $besar = (int) filesize($path);
        $ukuran += $besar;

        // Nama berkas berpola "YYYY-MM - NAMA.docx"; kalau tidak cocok,
        // berkas tetap ditampilkan di kelompok "Lainnya".
        $kunciTahun = preg_match('/^(\d{4})-\d{2}\b/', $nama, $c) ? $c[1] : 'Lainnya';

        $tahun[$kunciTahun]['files'][] = [
            'nama'   => $nama,
            'path'   => $pathRelatif . '/' . $nama,
            'ukuran' => formatBytes($besar),
            'waktu'  => date('d/m/Y H:i', (int) filemtime($path)),
            'url'    => jobFileUrl('jobs/' . $pathRelatif . '/' . $nama),
        ];

        $tahun[$kunciTahun]['ukuran'] = ($tahun[$kunciTahun]['ukuran'] ?? 0) + $besar;
    }

    // Tahun terbaru di atas; berkas terbaru di atas.
    krsort($tahun);

    $anak = [];

    foreach ($tahun as $th => $isi) {
        usort($isi['files'], fn($a, $b) => strcmp($b['nama'], $a['nama']));

        $anak[] = [
            'tahun'  => $th,
            'jumlah' => count($isi['files']),
            'ukuran' => formatBytes($isi['ukuran']),
            'files'  => $isi['files'],
        ];
    }

    return [
        'nama'   => basename($dir),
        'path'   => $pathRelatif,
        'jumlah' => count($daftar),
        'bytes'  => $ukuran,
        'ukuran' => formatBytes($ukuran),
        'anak'   => $anak,
        'cari'   => strtolower(basename($dir) . ' ' . implode(' ', array_map('basename', $daftar))),
    ];
}

/**
 * Susun seluruh pohon dari folder jobs/.
 */
function pindaiPohon(array $labelTemplate): array
{
    $pohon = [];
    $lama  = [];

    foreach (glob('jobs/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $nama = basename($dir);

        if (folderTemplate($dir, $nama, $labelTemplate)) {
            $anak = [];

            foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $dirPel) {
                $simpul = simpulPelanggan($dirPel, $nama . '/' . basename($dirPel));

                if ($simpul) {
                    $anak[] = $simpul;
                }
            }

            if ($anak) {
                usort($anak, fn($a, $b) => strcmp($a['nama'], $b['nama']));
                $pohon[$nama] = [
                    'kunci' => $nama,
                    'label' => $labelTemplate[$nama],
                    'path'  => $nama,
                    'anak'  => $anak,
                ];
            }

            continue;
        }

        // Folder pelanggan versi lama, langsung di bawah jobs/.
        $simpul = simpulPelanggan($dir, $nama);

        if ($simpul) {
            $lama[] = $simpul;
        }
    }

    ksort($pohon);
    $pohon = array_values($pohon);

    if ($lama) {
        usort($lama, fn($a, $b) => strcmp($a['nama'], $b['nama']));

        $pohon[] = [
            'kunci' => '',
            'label' => 'Arsip lama (belum ditata per template)',
            'path'  => '',
            'anak'  => $lama,
        ];
    }

    // Hitung ringkasan tiap cabang template.
    foreach ($pohon as &$t) {
        $t['jumlah'] = array_sum(array_column($t['anak'], 'jumlah'));
        $t['bytes']  = array_sum(array_column($t['anak'], 'bytes'));
        $t['ukuran'] = formatBytes($t['bytes']);
        $t['cari']   = strtolower($t['label'] . ' ' . implode(' ', array_column($t['anak'], 'cari')));
    }

    unset($t);

    return $pohon;
}

// ---------------------------------------------------------------------
// Aksi hapus (pola POST lalu redirect / PRG)
// ---------------------------------------------------------------------

/**
 * Lepaskan rujukan ke berkas yang baru saja dihapus.
 *
 * Dua tempat yang menyimpan rujukan:
 *   job_files    - riwayat berkas per job; barisnya ikut dihapus
 *   sla_bulanan  - kolom file_docx; HANYA dikosongkan, barisnya dipertahankan
 *
 * Rekap SLA sengaja tidak ikut terhapus. Justru itu gunanya angka SLA
 * dipindahkan ke database: dokumen Word boleh dibuang untuk melegakan
 * penyimpanan, riwayat uptime-nya tetap bisa dibaca di sla.php.
 *
 * Kegagalan di sini tidak fatal — berkas fisiknya sudah terhapus.
 *
 * @param array $pathPenuh path relatif berikut awalan jobs/, mis.
 *                         'jobs/idt/PT ABC/2026-07 - PT ABC.docx'
 */
function lepasRujukanBerkas(array $pathPenuh): void
{
    $pathPenuh = array_values(array_unique(array_filter($pathPenuh)));

    if (!$pathPenuh) {
        return;
    }

    $nama  = array_values(array_unique(array_map('basename', $pathPenuh)));
    $bd    = null;

    try {
        $bd = db();

        $tandaPath = implode(',', array_fill(0, count($pathPenuh), '?'));
        $tandaNama = implode(',', array_fill(0, count($nama), '?'));

        // Baris lama (sebelum kolom `path` ada) hanya bisa dicocokkan lewat nama berkas.
        $bd->prepare(
            "DELETE FROM job_files WHERE path IN ($tandaPath) OR (path IS NULL AND filename IN ($tandaNama))"
        )->execute(array_merge($pathPenuh, $nama));
    } catch (Throwable $e) {
        // abaikan
    }

    try {
        if ($bd === null) {
            $bd = db();
        }

        $tandaPath = implode(',', array_fill(0, count($pathPenuh), '?'));

        $bd->prepare("UPDATE sla_bulanan SET file_docx = NULL WHERE file_docx IN ($tandaPath)")
            ->execute($pathPenuh);
    } catch (Throwable $e) {
        // Tabel sla_bulanan mungkin belum dibuat; bukan masalah di sini.
    }
}

/**
 * Kumpulkan .docx dengan periode SEBELUM $sebelum ('YYYY-MM'), di seluruh pohon.
 *
 * Periode dibaca dari awalan nama berkas ("YYYY-MM - NAMA.docx"). Berkas yang
 * namanya tidak berpola itu sengaja dilewati — lebih baik tertinggal daripada
 * terhapus karena salah tebak.
 *
 * @return array [path fisik => ['rel' => path relatif jobs/..., 'ukuran' => int]]
 */
function docxSebelumPeriode(string $sebelum, string $templateF, array $labelTemplate): array
{
    $hasil = [];
    $akar  = realpath('jobs');

    if ($akar === false) {
        return $hasil;
    }

    foreach (kumpulkanDocx($akar) as $fisik => $rel) {
        $nama = basename($rel);

        if (!preg_match('/^(\d{4}-\d{2})\b/', $nama, $c) || $c[1] >= $sebelum) {
            continue;
        }

        if ($templateF !== '') {
            // Segmen pertama path relatif adalah kunci template, kecuali untuk
            // arsip lama yang masih berada langsung di bawah jobs/.
            $segmen = explode('/', str_replace('\\', '/', $rel));
            $milik  = (count($segmen) > 2 && isset($labelTemplate[$segmen[0]])) ? $segmen[0] : '';

            if ($milik !== $templateF) {
                continue;
            }
        }

        $hasil[$fisik] = ['rel' => $rel, 'ukuran' => (int) filesize($fisik)];
    }

    ksort($hasil);

    return $hasil;
}

// ---------------------------------------------------------------------
// Aksi hapus (pola POST lalu redirect / PRG)
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = $_POST['aksi'] ?? '';
    $rel  = (string) ($_POST['path'] ?? '');

    $lempar = fn(string $k, string $v) => header('Location: hasil.php?' . $k . '=' . urlencode($v));

    // --- Pembersihan massal berdasarkan periode ---
    if ($aksi === 'hapus_periode') {
        $sebelum   = (string) ($_POST['sebelum'] ?? '');
        $templateF = (string) ($_POST['template'] ?? '');

        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $sebelum)) {
            $lempar('err', 'Periode batas tidak valid.');
            exit;
        }

        if ($templateF !== '' && !isset($labelTemplate[$templateF])) {
            $templateF = '';
        }

        $sasaran = docxSebelumPeriode($sebelum, $templateF, $labelTemplate);

        $jumlah = 0;
        $bebas  = 0;
        $dibuang = [];

        foreach ($sasaran as $fisik => $info) {
            if (@unlink($fisik)) {
                $jumlah++;
                $bebas += $info['ukuran'];
                $dibuang[] = 'jobs/' . $info['rel'];
            }
        }

        lepasRujukanBerkas($dibuang);
        rapikanFolderKosong(realpath('jobs'));

        $lempar('ok', $jumlah . ' berkas sebelum ' . $sebelum . ' dihapus, '
            . formatBytes($bebas) . ' dibebaskan.');
        exit;
    }

    // --- Aksi yang bekerja pada satu simpul pohon ---
    $target = $rel === '' ? false : jobsPathAman($rel);

    if ($target === false) {
        $lempar('err', 'Path tidak valid.');
    } elseif (($aksi === 'hapus_folder' || $aksi === 'hapus_cabang') && is_dir($target)) {
        // Keduanya bekerja sama: buang seluruh .docx di dalamnya, termasuk
        // subfolder. Bedanya hanya letak tombolnya — cabang template berisi
        // banyak pelanggan sekaligus, jadi konfirmasinya lebih tegas.
        $isi   = kumpulkanDocx($target);
        $bebas = 0;

        foreach (array_keys($isi) as $fisik) {
            $bebas += (int) filesize($fisik);
        }

        $n = hapusFolderDocx($target);

        $awalan = trim(str_replace('\\', '/', $rel), '/');

        lepasRujukanBerkas(array_map(
            fn($r) => 'jobs/' . $awalan . '/' . $r,
            array_values($isi)
        ));

        @rmdir($target);
        rapikanFolderKosong(realpath('jobs'));

        $lempar('ok', $n . ' berkas dihapus dari "' . basename($target) . '", '
            . formatBytes($bebas) . ' dibebaskan.');
    } elseif ($aksi === 'hapus_tahun' && is_dir($target)) {
        $tahun = (string) ($_POST['tahun'] ?? '');

        if (!preg_match('/^\d{4}$/', $tahun)) {
            $lempar('err', 'Tahun tidak valid.');
            exit;
        }

        $n      = 0;
        $bebas  = 0;
        $dibuang = [];
        $awalan = trim(str_replace('\\', '/', $rel), '/');

        foreach (glob($target . '/' . $tahun . '-*.docx') ?: [] as $fisik) {
            $besar = (int) filesize($fisik);

            if (@unlink($fisik)) {
                $n++;
                $bebas += $besar;
                $dibuang[] = 'jobs/' . $awalan . '/' . basename($fisik);
            }
        }

        lepasRujukanBerkas($dibuang);
        rapikanFolderKosong(realpath('jobs'));

        $lempar('ok', $n . ' berkas tahun ' . $tahun . ' dihapus, '
            . formatBytes($bebas) . ' dibebaskan.');
    } elseif ($aksi === 'hapus_file' && is_file($target)) {
        if (strtolower(pathinfo($target, PATHINFO_EXTENSION)) !== 'docx') {
            $lempar('err', 'Hanya berkas .docx yang boleh dihapus.');
        } else {
            $bebas = (int) filesize($target);

            @unlink($target);
            lepasRujukanBerkas(['jobs/' . trim(str_replace('\\', '/', $rel), '/')]);
            rapikanFolderKosong(realpath('jobs'));

            $lempar('ok', 'Berkas dihapus, ' . formatBytes($bebas) . ' dibebaskan.');
        }
    } else {
        $lempar('err', 'Aksi tidak dikenal atau target tidak ditemukan.');
    }

    exit;
}

// ---------------------------------------------------------------------
// Pratinjau pembersihan massal (dipanggil lewat GET, belum menghapus apa pun)
// ---------------------------------------------------------------------
$praSebelum  = (string) ($_GET['sebelum'] ?? '');
$praTemplate = (string) ($_GET['tpl'] ?? '');
$pratinjau   = null;

if ($praSebelum !== '' && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $praSebelum)) {
    if ($praTemplate !== '' && !isset($labelTemplate[$praTemplate])) {
        $praTemplate = '';
    }

    $sasaran = docxSebelumPeriode($praSebelum, $praTemplate, $labelTemplate);

    $pratinjau = [
        'sebelum'  => $praSebelum,
        'template' => $praTemplate,
        'jumlah'   => count($sasaran),
        'ukuran'   => array_sum(array_column($sasaran, 'ukuran')),
        'contoh'   => array_slice(array_column($sasaran, 'rel'), 0, 12),
    ];
}

$pohon       = is_dir('jobs') ? pindaiPohon($labelTemplate) : [];
$totalFile   = array_sum(array_column($pohon, 'jumlah'));
$totalUkuran = array_sum(array_column($pohon, 'bytes'));

$ikonHapus = '<svg class="ikon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>';

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
        .bar { align-items: baseline; display: flex; flex-wrap: wrap; gap: 14px; justify-content: space-between; margin: 0 auto 6px; max-width: 1240px; }
        .bar h2 { margin: 0; }
        a.nav { color: var(--biru); font-size: 14px; text-decoration: none; }
        a.nav:hover { text-decoration: underline; }
        .ringkas { color: var(--redup); font-size: 13px; margin: 0 auto 16px; max-width: 1240px; }

        .flash { border-radius: 8px; font-size: 14px; margin: 0 auto 16px; max-width: 1240px; padding: 12px 16px; }
        .flash.ok  { background: #e7f6ec; border: 1px solid #b6e3c4; color: var(--hijau); }
        .flash.err { background: #fdeaec; border: 1px solid #f3c2c7; color: var(--merah); }

        .kotak { background: #fff; border: 1px solid var(--garis); border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,.06); margin: 0 auto; max-width: 1240px; overflow: hidden; }
        .cari-bar { align-items: center; border-bottom: 1px solid var(--garis); display: flex; gap: 10px; padding: 0 14px 0 0; }
        #cari { border: 0; flex: 1; font-size: 14px; padding: 14px 18px; }
        #cari:focus { outline: none; }
        .cari-bar button { background: #eef1f5; border: 1px solid var(--garis); border-radius: 6px; color: var(--teks); cursor: pointer; font-size: 12px; padding: 6px 10px; }
        .cari-bar button:hover { background: #e2e6ec; }

        summary { align-items: center; cursor: pointer; display: flex; gap: 10px; list-style: none; }
        summary::-webkit-details-marker { display: none; }
        summary:hover { background: #f7f9fc; }
        .panah { color: var(--redup); flex: none; transition: transform .15s; }
        details[open] > summary .panah { transform: rotate(90deg); }
        .nm { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .jm { color: var(--redup); flex: none; font-size: 12px; white-space: nowrap; }

        /* Tingkat 1 — template */
        details.tpl { border-bottom: 1px solid var(--garis); }
        details.tpl:last-of-type { border-bottom: 0; }
        details.tpl > summary { background: #f2f5f9; font-size: 15px; font-weight: bold; padding: 13px 18px; }
        details.tpl > summary .kunci { background: #dde4ec; border-radius: 4px; color: #4a5563; font-size: 11px; font-weight: normal; padding: 2px 7px; }

        /* Tingkat 2 — pelanggan */
        details.pel { border-top: 1px solid #eef0f3; }
        details.pel > summary { font-size: 14px; padding: 11px 18px 11px 40px; }
        details.pel > summary .nm { font-weight: bold; }

        /* Tingkat 3 — tahun */
        details.thn { border-top: 1px solid #f4f6f8; }
        details.thn > summary { color: #55606d; font-size: 13px; padding: 8px 18px 8px 64px; }

        /* Tingkat 4 — berkas */
        .file { align-items: center; border-top: 1px solid #f4f6f8; display: flex; gap: 10px; padding: 9px 18px 9px 88px; }
        .file .isi { flex: 1; min-width: 0; }
        .file .nm2 { font-size: 13px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .file .meta { color: var(--redup); font-size: 11px; }

        a.zip, a.unduh { background: #eef1f5; border: 1px solid var(--garis); border-radius: 6px; color: var(--teks); flex: none; font-size: 12px; padding: 5px 10px; text-decoration: none; white-space: nowrap; }
        a.zip:hover, a.unduh:hover { background: var(--biru); border-color: var(--biru); color: #fff; }
        a.zip-semua { background: var(--biru); border-radius: 6px; color: #fff; font-size: 13px; font-weight: bold; padding: 7px 14px; text-decoration: none; white-space: nowrap; }
        a.zip-semua:hover { background: var(--biru-tua); }

        .ikon { flex: none; height: 13px; vertical-align: -2px; width: 13px; }
        button.hapus { align-items: center; background: #fff; border: 1px solid #f0c2c7; border-radius: 6px; color: var(--merah); cursor: pointer; display: inline-flex; flex: none; padding: 6px 8px; }
        button.hapus:hover { background: var(--merah); border-color: var(--merah); color: #fff; }
        form.inline { display: inline; margin: 0; }

        .toolbar { background: #fbfcfd; border-top: 1px solid #f2f4f7; padding: 7px 18px 7px 64px; }
        .btn-hapus-folder { align-items: center; background: #fff; border: 1px solid #f0c2c7; border-radius: 6px; color: var(--merah); cursor: pointer; display: inline-flex; font-size: 12px; gap: 6px; padding: 5px 10px; }
        .btn-hapus-folder:hover { background: var(--merah); border-color: var(--merah); color: #fff; }

        details.simpan { background: #fff; border: 1px solid var(--garis); border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,.06); margin: 0 auto 18px; max-width: 1240px; }
        details.simpan > summary { align-items: center; cursor: pointer; display: flex; font-size: 14px; font-weight: bold; gap: 10px; list-style: none; padding: 14px 18px; }
        details.simpan > summary::-webkit-details-marker { display: none; }
        details.simpan > summary:hover { background: #f7f9fc; }
        details.simpan .isi-panel { border-top: 1px solid var(--garis); padding: 16px 18px; }
        details.simpan .baris-form { align-items: flex-end; display: flex; flex-wrap: wrap; gap: 12px; }
        details.simpan label.kecil { color: var(--redup); display: block; font-size: 12px; margin-bottom: 4px; }
        details.simpan input[type=month], details.simpan select { border: 1px solid var(--garis); border-radius: 6px; font-size: 14px; padding: 8px 10px; }
        .btn-abu { background: #eef1f5; border: 1px solid var(--garis); border-radius: 6px; color: var(--teks); cursor: pointer; font-size: 14px; padding: 9px 16px; text-decoration: none; }
        .btn-abu:hover { background: #e2e6ec; }
        .btn-merah { background: var(--merah); border: 1px solid var(--merah); border-radius: 6px; color: #fff; cursor: pointer; font-size: 14px; font-weight: bold; padding: 9px 16px; }
        .btn-merah:hover { background: #b52a37; }
        .pratinjau { background: #fff8e6; border: 1px solid #f0dfae; border-radius: 8px; color: #8a6d1a; font-size: 13px; line-height: 1.7; margin-top: 14px; padding: 14px 16px; }
        .pratinjau ul { margin: 8px 0 0; padding-left: 20px; }
        .pratinjau li { font-family: Consolas, monospace; font-size: 12px; }

        .kosong { color: var(--redup); font-size: 14px; padding: 30px 18px; text-align: center; }
        #tak-ada { display: none; }
    </style>
</head>
<body>
<div class="bar">
    <h2>Hasil Laporan</h2>
    <span>
        <?php if ($totalFile): ?>
            <a class="zip-semua" href="unduh.php"
               title="Unduh <?= $totalFile ?> berkas (<?= formatBytes($totalUkuran) ?>) sebagai satu ZIP">⬇ Unduh Semua</a>
            &nbsp;&nbsp;
        <?php endif; ?>
        <a class="nav" href="index.php">← Generator</a>
        &nbsp;·&nbsp;
        <a class="nav" href="sla.php">Rekap SLA</a>
        &nbsp;·&nbsp;
        <a class="nav" href="pelanggan.php">Kelola Pelanggan</a>
    </span>
</div>
<div class="ringkas">
    <?= count($pohon) ?> template ·
    <?= array_sum(array_map(fn($t) => count($t['anak']), $pohon)) ?> pelanggan ·
    <?= $totalFile ?> berkas · <?= formatBytes($totalUkuran) ?>
</div>

<?php if ($ok !== ''): ?>
    <div class="flash ok"><?= htmlspecialchars($ok) ?></div>
<?php endif; ?>
<?php if ($err !== ''): ?>
    <div class="flash err"><?= htmlspecialchars($err) ?></div>
<?php endif; ?>

<?php if ($totalFile): ?>
<details class="simpan" <?= $pratinjau ? 'open' : '' ?>>
    <summary>
        <span>🧹</span>
        <span style="flex:1;">Kelola penyimpanan — hapus laporan lama sekaligus</span>
        <span style="color:var(--redup);font-size:12px;font-weight:normal;">Total terpakai: <?= formatBytes($totalUkuran) ?></span>
    </summary>

    <div class="isi-panel">
        <form method="get" class="baris-form">
            <div>
                <label class="kecil" for="sebelum">Hapus laporan dengan periode sebelum</label>
                <input id="sebelum" name="sebelum" type="month" required
                       value="<?= htmlspecialchars($praSebelum) ?>">
            </div>

            <div>
                <label class="kecil" for="tpl">Batasi ke template</label>
                <select id="tpl" name="tpl">
                    <option value="">Semua template</option>
                    <?php foreach ($labelTemplate as $k => $label): ?>
                        <option value="<?= htmlspecialchars($k) ?>" <?= $k === $praTemplate ? 'selected' : '' ?>>
                            <?= htmlspecialchars($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button class="btn-abu" type="submit">Lihat yang akan dihapus</button>
        </form>

        <?php if ($pratinjau !== null): ?>
            <?php if ($pratinjau['jumlah'] === 0): ?>
                <div class="pratinjau">
                    Tidak ada berkas dengan periode sebelum <b><?= htmlspecialchars($pratinjau['sebelum']) ?></b>
                    <?= $pratinjau['template'] === '' ? '' : ' pada template <b>' . htmlspecialchars($labelTemplate[$pratinjau['template']]) . '</b>' ?>.
                </div>
            <?php else: ?>
                <div class="pratinjau">
                    <b><?= $pratinjau['jumlah'] ?> berkas</b> akan dihapus,
                    membebaskan <b><?= formatBytes($pratinjau['ukuran']) ?></b>.
                    Rekap SLA-nya <b>tidak</b> ikut terhapus — riwayat uptime tetap bisa dibaca di halaman Rekap SLA.

                    <ul>
                        <?php foreach ($pratinjau['contoh'] as $c): ?>
                            <li><?= htmlspecialchars($c) ?></li>
                        <?php endforeach; ?>
                        <?php if ($pratinjau['jumlah'] > count($pratinjau['contoh'])): ?>
                            <li>… dan <?= $pratinjau['jumlah'] - count($pratinjau['contoh']) ?> berkas lainnya</li>
                        <?php endif; ?>
                    </ul>
                </div>

                <form method="post" style="margin-top:14px;"
                      onsubmit="return confirm('Hapus <?= $pratinjau['jumlah'] ?> berkas (<?= formatBytes($pratinjau['ukuran']) ?>)? Tindakan ini tidak bisa dibatalkan.');">
                    <input type="hidden" name="aksi" value="hapus_periode">
                    <input type="hidden" name="sebelum" value="<?= htmlspecialchars($pratinjau['sebelum'], ENT_QUOTES) ?>">
                    <input type="hidden" name="template" value="<?= htmlspecialchars($pratinjau['template'], ENT_QUOTES) ?>">
                    <button class="btn-merah" type="submit">
                        Hapus <?= $pratinjau['jumlah'] ?> berkas sekarang
                    </button>
                    <a class="btn-abu" href="hasil.php" style="margin-left:8px;">Batal</a>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</details>
<?php endif; ?>

<div class="kotak">
    <?php if (!$pohon): ?>
        <div class="kosong">Belum ada laporan yang dihasilkan.</div>
    <?php else: ?>
        <div class="cari-bar">
            <input id="cari" type="text" placeholder="🔍 Cari template, pelanggan, atau nama berkas..." autocomplete="off">
            <button type="button" id="buka">Buka semua</button>
            <button type="button" id="tutup">Tutup semua</button>
        </div>

        <div id="daftar">
            <?php foreach ($pohon as $t): ?>
                <details class="tpl" data-cari="<?= htmlspecialchars($t['cari'], ENT_QUOTES) ?>">
                    <summary>
                        <span class="panah">▸</span>
                        <span>🗂️</span>
                        <span class="nm"><?= htmlspecialchars($t['label']) ?></span>
                        <?php if ($t['kunci'] !== ''): ?>
                            <span class="kunci"><?= htmlspecialchars($t['kunci']) ?></span>
                        <?php endif; ?>
                        <span class="jm"><?= count($t['anak']) ?> pelanggan · <?= $t['jumlah'] ?> berkas · <?= htmlspecialchars($t['ukuran']) ?></span>
                        <?php if ($t['path'] !== ''): ?>
                            <a class="zip" href="unduh.php?path=<?= rawurlencode($t['path']) ?>"
                               onclick="event.stopPropagation()" title="Unduh seluruh cabang ini sebagai ZIP">⬇ ZIP</a>
                        <?php endif; ?>
                    </summary>

                    <?php if ($t['path'] !== ''): ?>
                        <div class="toolbar" style="padding-left:40px;">
                            <form method="post" class="inline"
                                  onsubmit="return confirm('HAPUS SELURUH CABANG INI?\n\nTemplate: <?= htmlspecialchars(addslashes($t['label'])) ?>\n<?= count($t['anak']) ?> pelanggan, <?= $t['jumlah'] ?> berkas, <?= htmlspecialchars($t['ukuran']) ?>\n\nRekap SLA tidak ikut terhapus. Tindakan ini tidak bisa dibatalkan.');">
                                <input type="hidden" name="aksi" value="hapus_cabang">
                                <input type="hidden" name="path" value="<?= htmlspecialchars($t['path'], ENT_QUOTES) ?>">
                                <button type="submit" class="btn-hapus-folder"><?= $ikonHapus ?>Hapus seluruh cabang ini (<?= $t['jumlah'] ?> berkas · <?= htmlspecialchars($t['ukuran']) ?>)</button>
                            </form>
                        </div>
                    <?php endif; ?>

                    <?php foreach ($t['anak'] as $p): ?>
                        <details class="pel" data-cari="<?= htmlspecialchars($p['cari'], ENT_QUOTES) ?>">
                            <summary>
                                <span class="panah">▸</span>
                                <span>📁</span>
                                <span class="nm"><?= htmlspecialchars($p['nama']) ?></span>
                                <span class="jm"><?= $p['jumlah'] ?> berkas · <?= htmlspecialchars($p['ukuran']) ?></span>
                                <a class="zip" href="unduh.php?path=<?= rawurlencode($p['path']) ?>"
                                   onclick="event.stopPropagation()" title="Unduh folder ini sebagai ZIP">⬇ ZIP</a>
                            </summary>

                            <div class="toolbar">
                                <form method="post" class="inline"
                                      onsubmit="return confirm('Hapus SEMUA <?= $p['jumlah'] ?> berkas milik pelanggan ini? Tindakan ini tidak bisa dibatalkan.');">
                                    <input type="hidden" name="aksi" value="hapus_folder">
                                    <input type="hidden" name="path" value="<?= htmlspecialchars($p['path'], ENT_QUOTES) ?>">
                                    <button type="submit" class="btn-hapus-folder"><?= $ikonHapus ?>Hapus semua berkas pelanggan ini</button>
                                </form>
                            </div>

                            <?php foreach ($p['anak'] as $th): ?>
                                <details class="thn">
                                    <summary>
                                        <span class="panah">▸</span>
                                        <span>📅</span>
                                        <span class="nm">Tahun <?= htmlspecialchars($th['tahun']) ?></span>
                                        <span class="jm"><?= $th['jumlah'] ?> berkas · <?= htmlspecialchars($th['ukuran']) ?></span>
                                    </summary>

                                    <?php if (ctype_digit((string) $th['tahun'])): ?>
                                        <div class="toolbar" style="padding-left:88px;">
                                            <form method="post" class="inline"
                                                  onsubmit="return confirm('Hapus <?= $th['jumlah'] ?> berkas tahun <?= htmlspecialchars($th['tahun']) ?> milik pelanggan ini (<?= htmlspecialchars($th['ukuran']) ?>)?');">
                                                <input type="hidden" name="aksi" value="hapus_tahun">
                                                <input type="hidden" name="path" value="<?= htmlspecialchars($p['path'], ENT_QUOTES) ?>">
                                                <input type="hidden" name="tahun" value="<?= htmlspecialchars($th['tahun'], ENT_QUOTES) ?>">
                                                <button type="submit" class="btn-hapus-folder"><?= $ikonHapus ?>Hapus tahun <?= htmlspecialchars($th['tahun']) ?></button>
                                            </form>
                                        </div>
                                    <?php endif; ?>

                                    <?php foreach ($th['files'] as $f): ?>
                                        <div class="file">
                                            <span>📄</span>
                                            <div class="isi">
                                                <div class="nm2"><?= htmlspecialchars($f['nama']) ?></div>
                                                <div class="meta"><?= htmlspecialchars($f['ukuran']) ?> · <?= htmlspecialchars($f['waktu']) ?></div>
                                            </div>
                                            <a class="unduh" href="<?= $f['url'] ?>" download>Unduh</a>
                                            <form method="post" class="inline" onsubmit="return confirm('Hapus berkas ini?');">
                                                <input type="hidden" name="aksi" value="hapus_file">
                                                <input type="hidden" name="path" value="<?= htmlspecialchars($f['path'], ENT_QUOTES) ?>">
                                                <button type="submit" class="hapus" title="Hapus berkas"><?= $ikonHapus ?></button>
                                            </form>
                                        </div>
                                    <?php endforeach; ?>
                                </details>
                            <?php endforeach; ?>
                        </details>
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
        const templates = Array.from(document.querySelectorAll('details.tpl'));
        const takAda = document.getElementById('tak-ada');

        cari.addEventListener('input', function () {
            const q = this.value.trim().toLowerCase();
            let tampil = 0;

            templates.forEach(tpl => {
                let adaAnak = 0;

                // Saring dulu di tingkat pelanggan, baru simpulkan tingkat template.
                tpl.querySelectorAll('details.pel').forEach(pel => {
                    const cocok = q === '' || pel.dataset.cari.includes(q);
                    pel.style.display = cocok ? '' : 'none';
                    pel.open = cocok && q !== '';
                    if (cocok) { adaAnak++; }
                });

                tpl.style.display = adaAnak ? '' : 'none';
                if (adaAnak) {
                    tampil++;
                    tpl.open = q !== '';
                }
            });

            takAda.style.display = tampil === 0 ? 'block' : 'none';
        });

        const semua = sel => Array.from(document.querySelectorAll(sel));

        document.getElementById('buka').addEventListener('click', () => {
            semua('#daftar details').forEach(d => { d.open = true; });
        });

        document.getElementById('tutup').addEventListener('click', () => {
            semua('#daftar details').forEach(d => { d.open = false; });
        });
    }
</script>
</body>
</html>
