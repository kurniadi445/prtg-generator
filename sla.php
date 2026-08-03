<?php

/**
 * Rekap SLA (Service Level Agreement) bulanan — tampilan pivot.
 *
 *   Baris  = nama pelanggan
 *   Kolom  = Januari sampai Desember
 *   Satu tabel per tahun, tahun terbaru di atas.
 *
 * Sumber datanya tabel sla_bulanan + sla_downtime, yang diisi otomatis setiap
 * kali sebuah laporan bulanan berhasil dibuat. Jadi angka uptime bisa dilihat
 * di sini tanpa perlu membuka dokumen Word satu per satu.
 *
 * Halaman ini SENGAJA memuat seluruh data begitu dibuka, tanpa tombol
 * "Tampilkan". Penyaring di atas hanya pelengkap dan langsung diterapkan
 * begitu dipilih.
 */

require_once 'helpers.php';
require_once 'database.php';
require_once 'sla-store.php';

$labelTemplate = daftarTemplate();
$target        = slaTarget();

$templateF = (string) ($_GET['template'] ?? '');
$cariNama  = trim((string) ($_GET['q'] ?? ''));
$isiSel    = ($_GET['isi'] ?? 'uptime') === 'downtime' ? 'downtime' : 'uptime';
$rinciId   = (int) ($_GET['rinci'] ?? 0);

/**
 * Cek keberadaan tabel, supaya pesannya jelas bila SQL upgrade belum dijalankan.
 */
function tabelSlaAda(): bool
{
    try {
        db()->query('SELECT 1 FROM sla_bulanan LIMIT 1');

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

$siap = tabelSlaAda();

// Seluruh pengambilan data ada di slaAmbilData() (sla-store.php), dipakai
// bersama dengan sla-export.php supaya angka di layar dan di berkas Excel
// tidak mungkin berbeda.
$data = $siap
    ? slaAmbilData(isset($labelTemplate[$templateF]) ? $templateF : '', $cariNama)
    : ['tahunan' => [], 'tampil' => 0, 'seluruhnya' => 0];

$tahunan         = $data['tahunan'];
$totalTampil     = $data['tampil'];
$totalSeluruhnya = $data['seluruhnya'];

// ---------------------------------------------------------------------
// Rincian satu periode
// ---------------------------------------------------------------------
$rinci = null;

if ($siap && $rinciId > 0) {
    $q = db()->prepare('SELECT * FROM sla_bulanan WHERE id = ?');
    $q->execute([$rinciId]);
    $rinci = $q->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($rinci) {
        $q = db()->prepare('SELECT * FROM sla_downtime WHERE sla_id = ? ORDER BY mulai');
        $q->execute([$rinciId]);
        $rinci['episode'] = $q->fetchAll(PDO::FETCH_ASSOC);
    }
}

$namaBulan = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

/**
 * URL halaman ini dengan sebagian parameter diganti.
 */
function urlSla(array $ganti = []): string
{
    $dasar = [
        'template' => $_GET['template'] ?? '',
        'q'        => $_GET['q'] ?? '',
        'isi'      => $_GET['isi'] ?? '',
    ];

    return '?' . http_build_query(array_filter(array_merge($dasar, $ganti), fn($v) => $v !== '' && $v !== null));
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta content="initial-scale=1, width=device-width" name="viewport">
    <title>Rekap SLA — PRTG Generator</title>
    <style>
        :root {
            --biru: #007bff; --biru-tua: #0062cc; --garis: #d9dde3;
            --abu: #f4f4f9; --teks: #2b2f36; --redup: #8a909a;
            --merah: #dc3545; --hijau: #198754; --kuning: #8a6d1a;
        }
        * { box-sizing: border-box; }
        body { background: var(--abu); color: var(--teks); font-family: Arial, Helvetica, sans-serif; margin: 0; padding: 24px 16px; }
        .bar { align-items: baseline; display: flex; flex-wrap: wrap; gap: 14px; justify-content: space-between; margin: 0 auto 16px; max-width: 1240px; }
        .bar h2 { margin: 0; }
        a.nav { color: var(--biru); font-size: 14px; text-decoration: none; }
        a.nav:hover { text-decoration: underline; }

        .kartu { background: #fff; border: 1px solid var(--garis); border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,.06); margin: 0 auto 18px; max-width: 1240px; padding: 18px 20px; }
        .kartu.rapat { padding: 0; }
        .kartu h3 { margin: 0; }

        .judul-tahun { align-items: baseline; background: #f2f5f9; border-bottom: 1px solid var(--garis); display: flex; gap: 12px; padding: 12px 18px; }
        .judul-tahun h3 { font-size: 16px; }
        .judul-tahun span { color: var(--redup); font-size: 12px; }

        .gulir { overflow-x: auto; }

        .saring { align-items: flex-end; display: flex; flex-wrap: wrap; gap: 14px; }
        .saring label { color: var(--redup); display: block; font-size: 12px; margin-bottom: 4px; }
        .saring select, .saring input[type=text] { border: 1px solid var(--garis); border-radius: 6px; font-size: 14px; padding: 8px 10px; }
        .tombol { background: #eef1f5; border: 1px solid var(--garis); border-radius: 6px; color: var(--teks); cursor: pointer; font-size: 14px; padding: 8px 16px; text-decoration: none; }
        .tombol:hover { background: #e2e6ec; }
        .tombol.utama { background: var(--biru); border-color: var(--biru); color: #fff; font-weight: bold; }
        .tombol.utama:hover { background: var(--biru-tua); }

        table { border-collapse: collapse; font-size: 13px; width: 100%; }
        th, td { border-bottom: 1px solid #eef0f3; padding: 7px 8px; text-align: center; white-space: nowrap; }
        thead th { background: #fafbfc; font-size: 12px; }
        th.nama, td.nama { left: 0; max-width: 300px; min-width: 220px; overflow: hidden; position: sticky; text-align: left; text-overflow: ellipsis; }
        thead th.nama { background: #fafbfc; z-index: 2; }
        td.nama { background: #fff; font-weight: bold; z-index: 1; }
        tbody tr:hover td { background: #f7f9fc; }
        .kunci { background: #dde4ec; border-radius: 4px; color: #4a5563; font-size: 10px; font-weight: normal; margin-left: 6px; padding: 1px 6px; }

        a.sel { border-radius: 5px; display: block; padding: 4px 2px; text-decoration: none; }
        a.sel.aman  { background: #e7f6ec; color: var(--hijau); }
        a.sel.turun { background: #fdeaec; color: var(--merah); font-weight: bold; }
        a.sel:hover { outline: 2px solid var(--biru); }
        td.hampa { color: #ccd2da; }

        tfoot td { background: #fafbfc; color: var(--redup); font-size: 12px; font-weight: bold; }

        .catatan { color: var(--redup); font-size: 12px; margin-top: 12px; }
        .peringatan { background: #fdeaec; border: 1px solid #f3c2c7; border-radius: 8px; color: var(--merah); font-size: 14px; line-height: 1.6; padding: 14px 16px; }
        .info { background: #eaf3fd; border: 1px solid #bcd9f5; border-radius: 8px; color: #14538f; font-size: 14px; line-height: 1.6; padding: 14px 16px; }
        .wrn { background: #fff8e6; border: 1px solid #f0dfae; border-radius: 8px; color: var(--kuning); font-size: 14px; line-height: 1.6; padding: 14px 16px; }
        code { background: #f2f5f9; border-radius: 4px; padding: 1px 5px; }
    </style>
</head>
<body>

<div class="bar">
    <h2>Rekap SLA</h2>
    <span>
        <a class="nav" href="index.php">← Generator</a>
        &nbsp;·&nbsp;
        <a class="nav" href="hasil.php">Hasil Laporan</a>
        &nbsp;·&nbsp;
        <a class="nav" href="antrean.php">Antrean</a>
    </span>
</div>

<?php if (!$siap): ?>

    <div class="kartu">
        <div class="peringatan">
            <b>Tabel <code>sla_bulanan</code> belum ada.</b><br>
            Jalankan dulu <code>sql/sla-upgrade.sql</code> di phpMyAdmin pada database
            <code>prtg_generator</code>, lalu buat satu laporan agar datanya mulai terisi.
        </div>
    </div>

<?php elseif ($totalSeluruhnya === 0): ?>

    <div class="kartu">
        <div class="wrn">
            <b>Tabelnya sudah ada, tapi masih kosong.</b><br>
            Rekap SLA hanya terisi untuk laporan yang dibuat <b>setelah</b> upgrade dipasang —
            laporan lama tidak ikut terisi sendiri.<br><br>
            Yang perlu dicek:
            <ol style="margin:6px 0 0;padding-left:22px;">
                <li>Sudah ada laporan yang dibuat setelah <code>generate-report.php</code> dan
                    <code>sla-store.php</code> yang baru dipasang?</li>
                <li>Worker sudah di-restart setelah berkas diganti? Proses lama masih memakai
                    kode versi sebelumnya.</li>
                <li>Cek log error PHP (di XAMPP biasanya <code>C:\xampp\php\logs\php_error_log</code>
                    atau <code>C:\xampp\apache\logs\error.log</code>) — kalau ada baris
                    <code>slaSimpan gagal:</code>, isinya menjelaskan penyebabnya.</li>
            </ol>
        </div>
    </div>

<?php else: ?>

    <form class="kartu" method="get">
        <div class="saring">
            <div>
                <label for="template">Template</label>
                <select id="template" name="template" onchange="this.form.submit()">
                    <option value="">Semua template</option>
                    <?php foreach ($labelTemplate as $k => $label): ?>
                        <option value="<?= htmlspecialchars($k) ?>" <?= $k === $templateF ? 'selected' : '' ?>>
                            <?= htmlspecialchars($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label for="isi">Isi sel</label>
                <select id="isi" name="isi" onchange="this.form.submit()">
                    <option value="uptime" <?= $isiSel === 'uptime' ? 'selected' : '' ?>>Uptime (%)</option>
                    <option value="downtime" <?= $isiSel === 'downtime' ? 'selected' : '' ?>>Downtime (jam:menit:detik)</option>
                </select>
            </div>

            <div style="flex:1;min-width:220px;">
                <label for="q">Cari nama pelanggan</label>
                <input id="q" name="q" type="text" value="<?= htmlspecialchars($cariNama) ?>"
                       placeholder="kosongkan untuk menampilkan semua" style="width:100%;">
            </div>

            <button class="tombol" type="submit">Terapkan</button>

            <?php if ($templateF !== '' || $cariNama !== ''): ?>
                <a class="tombol" href="?isi=<?= htmlspecialchars($isiSel) ?>">Tampilkan semua</a>
            <?php endif; ?>

            <?php if ($tahunan): ?>
                <a class="tombol utama" href="sla-export.php?<?= htmlspecialchars(http_build_query(array_filter([
                    'template' => $templateF,
                    'q'        => $cariNama,
                ]))) ?>">⬇ Ekspor Excel</a>
            <?php endif; ?>
        </div>

        <div class="catatan">
            <?= $totalTampil ?> dari <?= $totalSeluruhnya ?> baris data ditampilkan ·
            Ambang SLA <b><?= number_format($target, 2, ',', '.') ?>%</b>
            (diatur di <code>config.php</code> &rarr; <code>report.sla_target</code>).
            Sel hijau memenuhi ambang, merah di bawahnya, garis putus-putus berarti bulan itu
            belum pernah dibuatkan laporan. Klik sel untuk melihat rincian downtime-nya.
        </div>
    </form>

    <?php if (!$tahunan): ?>
        <div class="kartu">
            <div class="info">
                Tidak ada yang cocok dengan penyaring ini. Hapus isian pencarian atau pilih
                "Semua template" untuk melihat seluruh data.
            </div>
        </div>
    <?php endif; ?>

    <?php foreach ($tahunan as $tahun => $isi): ?>
        <div class="kartu rapat">
            <div class="judul-tahun">
                <h3>Tahun <?= htmlspecialchars((string) $tahun) ?></h3>
                <span><?= count($isi['baris']) ?> pelanggan</span>
            </div>

            <div class="gulir">
                <table>
                    <thead>
                    <tr>
                        <th class="nama">Pelanggan</th>
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <th><?= $namaBulan[$m] ?></th>
                        <?php endfor; ?>
                        <th>Rata-rata</th>
                    </tr>
                    </thead>

                    <tbody>
                    <?php foreach ($isi['baris'] as $b):
                        $nilai = array_map(fn($r) => (float) $r['uptime_persen'], $b['sel']);
                        ?>
                        <tr>
                            <td class="nama" title="<?= htmlspecialchars($b['nama']) ?>">
                                <?= htmlspecialchars($b['nama']) ?>
                                <span class="kunci"><?= htmlspecialchars($b['template']) ?></span>
                            </td>

                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <?php if (!isset($b['sel'][$m])): ?>
                                    <td class="hampa">–</td>
                                <?php else:
                                    $r     = $b['sel'][$m];
                                    $angka = (float) $r['uptime_persen'];

                                    // Toleransi kecil supaya 99.5 tepat tidak terbaca sebagai di bawah ambang.
                                    $kelas = $angka + 0.00005 >= $target ? 'aman' : 'turun';

                                    $teks = $isiSel === 'downtime'
                                        ? slaJamMenitDetik((int) $r['detik_downtime'])
                                        : number_format($angka, 3, ',', '.');
                                    ?>
                                    <td>
                                        <a class="sel <?= $kelas ?>"
                                           href="<?= htmlspecialchars(urlSla(['rinci' => $r['id']])) ?>"
                                           title="Uptime <?= number_format($angka, 4, ',', '.') ?>% · downtime <?= slaJamMenitDetik((int) $r['detik_downtime']) ?> · <?= (int) $r['jumlah_insiden'] ?> insiden">
                                            <?= $teks ?>
                                        </a>
                                    </td>
                                <?php endif; ?>
                            <?php endfor; ?>

                            <td><?= $nilai ? number_format(array_sum($nilai) / count($nilai), 3, ',', '.') : '–' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>

                    <tfoot>
                    <tr>
                        <td class="nama">Rata-rata seluruh pelanggan</td>
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <td>
                                <?= isset($isi['perBulan'][$m])
                                    ? number_format(array_sum($isi['perBulan'][$m]) / count($isi['perBulan'][$m]), 3, ',', '.')
                                    : '–' ?>
                            </td>
                        <?php endfor; ?>
                        <td></td>
                    </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if ($rinci): ?>
        <div class="kartu" id="rincian">
            <h3 style="margin-bottom:12px;">
                Rincian downtime — <?= htmlspecialchars($rinci['nama_pelanggan']) ?>
                · <?= htmlspecialchars($rinci['periode']) ?>
                <span class="kunci"><?= htmlspecialchars($rinci['template']) ?></span>
            </h3>

            <p style="font-size:13px;line-height:1.7;margin:0 0 14px;">
                Uptime <b><?= number_format((float) $rinci['uptime_persen'], 4, ',', '.') ?>%</b>
                · Total downtime <b><?= slaJamMenitDetik((int) $rinci['detik_downtime']) ?></b>
                (<?= formatDurasi((int) $rinci['detik_downtime']) ?>)
                · <b><?= (int) $rinci['jumlah_insiden'] ?></b> insiden
                <?php if ($rinci['trafik_avg_mbps'] !== null): ?>
                    <br>Trafik <?= number_format((float) $rinci['trafik_min_mbps'], 3, ',', '.') ?> /
                    <?= number_format((float) $rinci['trafik_avg_mbps'], 3, ',', '.') ?> /
                    <?= number_format((float) $rinci['trafik_max_mbps'], 3, ',', '.') ?> Mbps
                    (min / rata-rata / maks, kanal <?= htmlspecialchars((string) $rinci['kanal_trafik']) ?>)
                <?php elseif (!empty($rinci['catatan'])): ?>
                    <br><span style="color:var(--kuning);">Trafik tidak tercatat: <?= htmlspecialchars($rinci['catatan']) ?></span>
                <?php endif; ?>
            </p>

            <?php if (empty($rinci['episode'])): ?>
                <div class="info">Tidak ada periode DOWN pada bulan ini.</div>
            <?php else: ?>
                <div class="gulir">
                    <table>
                        <thead>
                        <tr>
                            <th style="width:50px;">No</th>
                            <th>Mulai</th>
                            <th>Selesai</th>
                            <th>Durasi</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rinci['episode'] as $i => $e): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><?= date('d/m/Y H:i:s', strtotime($e['mulai'])) ?></td>
                                <td><?= date('d/m/Y H:i:s', strtotime($e['selesai'])) ?></td>
                                <td><?= slaJamMenitDetik((int) $e['detik']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <p style="margin-top:14px;">
                <?php if (!empty($rinci['file_docx']) && is_file($rinci['file_docx'])): ?>
                    <a class="tombol" href="<?= jobFileUrl($rinci['file_docx']) ?>" download>
                        ⬇ Unduh dokumen Word bulan ini
                    </a>
                <?php endif; ?>
                <a class="tombol" href="<?= htmlspecialchars(urlSla()) ?>">Tutup rincian</a>
            </p>
        </div>
    <?php endif; ?>

<?php endif; ?>

<?php if ($rinci): ?>
    <script>
        'use strict';
        // Rincian ada di bawah tabel; langsung digulir ke sana supaya tidak perlu dicari.
        document.getElementById('rincian').scrollIntoView({ behavior: 'smooth', block: 'start' });
    </script>
<?php endif; ?>

</body>
</html>
