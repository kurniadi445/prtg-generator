<?php
require_once 'database.php';
require_once 'helpers.php';

$bd = db();

$pelanggan = $bd->query("
    SELECT id, nama, template
    FROM pelanggan
    ORDER BY nama
")->fetchAll(PDO::FETCH_ASSOC);

$daftarTpl    = daftarTemplate();
$templateAwal = config('report')['default_template'] ?? 'idt';

// Hitung jumlah pelanggan per template, untuk ditampilkan di penyaring.
$jumlahTpl = [];

foreach ($pelanggan as $p) {
    $kunci = $p['template'] ?: $templateAwal;
    $jumlahTpl[$kunci] = ($jumlahTpl[$kunci] ?? 0) + 1;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta content="initial-scale=1, width=device-width" name="viewport">
    <title>PRTG Generator</title>
    <style>
        :root {
            --biru: #007bff;
            --biru-tua: #0062cc;
            --garis: #d9dde3;
            --abu: #f4f4f9;
            --teks: #2b2f36;
            --redup: #8a909a;

            /* Lebar isi halaman. Disamakan dengan hasil.php dan sla.php
               supaya berpindah antar-halaman tidak terasa "melompat". */
            --lebar: 1240px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            background-color: var(--abu);
            color: var(--teks);
            font-family: Arial, Helvetica, sans-serif;
            margin: 0;
            padding: 24px 16px;
        }

        .wadah {
            margin: 0 auto;
            max-width: var(--lebar);
        }

        .bar {
            align-items: baseline;
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            justify-content: space-between;
            margin-bottom: 18px;
        }

        .bar h2 {
            margin: 0;
        }

        a.nav {
            color: var(--biru);
            font-size: 14px;
            text-decoration: none;
        }

        a.nav:hover {
            text-decoration: underline;
        }

        .kartu {
            background: #fff;
            border: 1px solid var(--garis);
            border-radius: 10px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, .06);
            margin-bottom: 20px;
            padding: 18px 20px;
        }

        /* --- Baris periode: tanggal dan opsi berjajar dalam satu baris --- */
        .baris {
            align-items: flex-end;
            display: flex;
            flex-wrap: wrap;
            gap: 18px;
        }

        .kolom {
            flex: 0 1 240px;
        }

        label.field {
            display: block;
            font-size: 13px;
            font-weight: bold;
            margin-bottom: 4px;
        }

        input[type=month] {
            border: 1px solid var(--garis);
            border-radius: 6px;
            font-size: 14px;
            padding: 8px;
            width: 100%;
        }

        .cek-opsi {
            align-items: center;
            display: flex;
            gap: 8px;
            padding-bottom: 9px;
        }

        /* --- Panel checklist pelanggan --- */
        .panel-head {
            align-items: center;
            display: flex;
            gap: 12px;
            justify-content: space-between;
            margin-bottom: 14px;
        }

        .panel-head h3 {
            margin: 0;
        }

        .badge {
            background: var(--biru);
            border-radius: 999px;
            color: #fff;
            font-size: 12px;
            font-weight: bold;
            padding: 4px 12px;
            white-space: nowrap;
        }

        /* Cari, penyaring template, dan tombol pilih semua jadi satu baris,
           karena sekarang lebarnya memang cukup. */
        .alat {
            align-items: center;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 12px;
        }

        #cari {
            border: 1px solid var(--garis);
            border-radius: 6px;
            flex: 1 1 280px;
            font-size: 14px;
            padding: 9px 12px;
        }

        .alat label {
            color: var(--redup);
            font-size: 13px;
            white-space: nowrap;
        }

        .alat select {
            background: #fff;
            border: 1px solid var(--garis);
            border-radius: 6px;
            font-size: 14px;
            padding: 8px 10px;
        }

        .tombol-kecil {
            background: #eef1f5;
            border: 1px solid var(--garis);
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            padding: 8px 12px;
            white-space: nowrap;
        }

        .tombol-kecil:hover {
            background: #e2e6ec;
        }

        /* Satu pelanggan satu baris, menurun ke bawah. Lebar penuh dipakai
           untuk memberi ruang nama panjang, bukan untuk memecah jadi kolom. */
        .daftar {
            border: 1px solid var(--garis);
            border-radius: 8px;
            max-height: 60vh;
            min-height: 200px;
            overflow-y: auto;
        }

        .item {
            align-items: center;
            border-bottom: 1px solid #eef0f3;
            cursor: pointer;
            display: flex;
            gap: 12px;
            padding: 10px 14px;
        }

        .item:last-of-type {
            border-bottom: 0;
        }

        .item:hover {
            background: #f7f9fc;
        }

        /* Baris terpilih diberi penanda supaya mudah dilihat di antara 68 baris. */
        .item:has(input:checked) {
            background: #eaf3fd;
        }

        .item input {
            flex: none;
            height: 16px;
            width: 16px;
        }

        .item .id {
            color: var(--redup);
            flex: none;
            font-size: 12px;
            min-width: 48px;
        }

        .item .nama {
            flex: 1;
            font-size: 14px;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* Label template di ujung kanan tiap baris */
        .item .tpl {
            background: #eef1f5;
            border: 1px solid var(--garis);
            border-radius: 999px;
            color: #5b616b;
            flex: none;
            font-size: 11px;
            padding: 2px 9px;
            white-space: nowrap;
        }

        .kosong {
            color: var(--redup);
            display: none;
            padding: 16px;
            text-align: center;
        }

        /* Tombol kirim tidak lagi selebar layar — pada lebar 1240px itu
           terlihat janggal. Diletakkan di kanan bersama ringkasan pilihan. */
        .kaki {
            align-items: center;
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            justify-content: space-between;
            margin-top: 16px;
        }

        .kaki .ringkas {
            color: var(--redup);
            font-size: 13px;
        }

        button[type=submit] {
            background: var(--biru);
            border: 0;
            border-radius: 8px;
            color: #fff;
            cursor: pointer;
            font-size: 15px;
            font-weight: bold;
            padding: 12px 32px;
        }

        button[type=submit]:hover {
            background: var(--biru-tua);
        }

        @media (max-width: 640px) {
            .kolom {
                flex: 1 1 100%;
            }

            button[type=submit] {
                width: 100%;
            }
        }
    </style>
</head>
<body>
<div class="wadah">

    <div class="bar">
        <h2>Data Historis PRTG Generator</h2>
        <span>
            <a class="nav" href="antrean.php">Antrean</a>
            &nbsp;·&nbsp;
            <a class="nav" href="hasil.php">Hasil Laporan</a>
            &nbsp;·&nbsp;
            <a class="nav" href="sla.php">Rekap SLA</a>
            &nbsp;·&nbsp;
            <a class="nav" href="pelanggan.php">Kelola Pelanggan</a>
        </span>
    </div>

    <form action="create-job.php" method="post" id="form-job">
        <div class="kartu">
            <div class="baris">
                <div class="kolom">
                    <label class="field" for="dari">Dari</label>
                    <input id="dari" name="dari" type="month" required>
                </div>
                <div class="kolom">
                    <label class="field" for="sampai">Sampai</label>
                    <input id="sampai" name="sampai" type="month" required>
                </div>
                <label class="cek-opsi">
                    <input type="checkbox" name="rekap_downtime" value="1" checked>
                    Sertakan Rekap Log Downtime
                </label>
            </div>
        </div>

        <div class="kartu">
            <div class="panel-head">
                <h3>Pelanggan</h3>
                <span class="badge"><span id="jml-terpilih">0</span> / <?= count($pelanggan) ?> dipilih</span>
            </div>

            <div class="alat">
                <input id="cari" type="text" placeholder="🔍 Cari nama, ID, atau template..." autocomplete="off">

                <label for="saring-tpl">Template:</label>
                <select id="saring-tpl">
                    <option value="">Semua (<?= count($pelanggan) ?>)</option>
                    <?php foreach ($daftarTpl as $kunci => $label): ?>
                        <option value="<?= htmlspecialchars($kunci, ENT_QUOTES) ?>">
                            <?= htmlspecialchars($label) ?> (<?= $jumlahTpl[$kunci] ?? 0 ?>)
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="button" class="tombol-kecil" id="pilih-semua">Pilih semua</button>
                <button type="button" class="tombol-kecil" id="hapus-semua">Hapus semua</button>
            </div>

            <div class="daftar" id="daftar">
                <?php foreach ($pelanggan as $p):
                    // Template yang tidak lagi terdaftar tetap ditampilkan apa adanya
                    // agar sisa data lama gampang ditemukan.
                    $tplKunci = $p['template'] ?: $templateAwal;
                    $tplLabel = $daftarTpl[$tplKunci] ?? ($tplKunci . ' (tidak terdaftar)');
                    ?>
                    <label class="item"
                           data-tpl="<?= htmlspecialchars($tplKunci, ENT_QUOTES) ?>"
                           data-cari="<?= htmlspecialchars(strtolower($p['id'] . ' ' . $p['nama'] . ' ' . $tplLabel), ENT_QUOTES) ?>">
                        <input type="checkbox" name="pelanggan[]" value="<?= htmlspecialchars($p['id'], ENT_QUOTES) ?>">
                        <span class="id"><?= htmlspecialchars($p['id']) ?></span>
                        <span class="nama" title="<?= htmlspecialchars($p['nama'], ENT_QUOTES) ?>"><?= htmlspecialchars($p['nama']) ?></span>
                        <span class="tpl"><?= htmlspecialchars($tplLabel) ?></span>
                    </label>
                <?php endforeach; ?>
                <div class="kosong" id="kosong">Tidak ada pelanggan yang cocok.</div>
            </div>

            <div class="kaki">
                <span class="ringkas" id="ringkas-saring"></span>
                <button type="submit">Buat Laporan</button>
            </div>
        </div>
    </form>

<script>
    'use strict';

    const daftar = document.getElementById('daftar');
    const items = Array.from(daftar.querySelectorAll('.item'));
    const kotak = items.map(el => el.querySelector('input'));
    const cari = document.getElementById('cari');
    const jml = document.getElementById('jml-terpilih');
    const kosong = document.getElementById('kosong');
    const ringkas = document.getElementById('ringkas-saring');

    function perbaruiJumlah() {
        jml.textContent = kotak.filter(c => c.checked).length;
    }

    function terlihat(el) {
        return el.style.display !== 'none';
    }

    const saringTpl = document.getElementById('saring-tpl');

    // Kotak cari dan penyaring template dievaluasi bersama, supaya
    // keduanya bisa dipakai sekaligus.
    function saring() {
        const q   = cari.value.trim().toLowerCase();
        const tpl = saringTpl.value;
        let ada = 0;

        items.forEach(el => {
            const cocok = el.dataset.cari.includes(q)
                && (tpl === '' || el.dataset.tpl === tpl);

            el.style.display = cocok ? '' : 'none';
            if (cocok) ada++;
        });

        kosong.style.display = ada === 0 ? 'block' : 'none';

        // Beri tahu berapa yang sedang tampil, supaya jelas bahwa "Pilih semua"
        // hanya mengenai baris yang lolos penyaring.
        ringkas.textContent = (q === '' && tpl === '')
            ? items.length + ' pelanggan'
            : ada + ' dari ' + items.length + ' pelanggan tampil';
    }

    cari.addEventListener('input', saring);
    saringTpl.addEventListener('change', saring);

    // "Pilih semua" hanya untuk item yang sedang terlihat (hasil filter)
    document.getElementById('pilih-semua').addEventListener('click', function () {
        items.forEach(el => {
            if (terlihat(el)) el.querySelector('input').checked = true;
        });
        perbaruiJumlah();
    });

    document.getElementById('hapus-semua').addEventListener('click', function () {
        kotak.forEach(c => c.checked = false);
        perbaruiJumlah();
    });

    kotak.forEach(c => c.addEventListener('change', perbaruiJumlah));

    document.getElementById('form-job').addEventListener('submit', function (e) {
        const dari = document.getElementById('dari').value;
        const sampai = document.getElementById('sampai').value;

        if (!dari || !sampai) {
            e.preventDefault();
            alert('Periode "Dari" dan "Sampai" wajib diisi.');
            return;
        }

        if (sampai < dari) {
            e.preventDefault();
            alert('Bulan "Sampai" tidak boleh sebelum "Dari".');
            return;
        }

        if (kotak.filter(c => c.checked).length === 0) {
            e.preventDefault();
            alert('Pilih minimal satu pelanggan.');
        }
    });

    perbaruiJumlah();
    saring();
</script>

</div>
</body>
</html>
