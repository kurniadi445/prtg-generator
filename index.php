<?php
require 'database.php';

$bd = db();

$pelanggan = $bd->query("
    SELECT id, nama
    FROM pelanggan
    ORDER BY nama
")->fetchAll(PDO::FETCH_ASSOC);
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

        .kartu {
            background: #fff;
            border: 1px solid var(--garis);
            border-radius: 10px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, .06);
            margin: 0 auto 20px;
            max-width: 640px;
            padding: 20px 22px;
        }

        h2 {
            margin: 0 auto 18px;
            max-width: 640px;
        }

        .baris {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
        }

        .kolom {
            flex: 1 1 180px;
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
            margin-top: 16px;
        }

        /* --- Panel checklist pelanggan --- */
        .panel-head {
            align-items: center;
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
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

        #cari {
            border: 1px solid var(--garis);
            border-radius: 6px;
            font-size: 14px;
            margin-bottom: 10px;
            padding: 9px 12px;
            width: 100%;
        }

        .aksi {
            display: flex;
            gap: 8px;
            margin-bottom: 10px;
        }

        .tombol-kecil {
            background: #eef1f5;
            border: 1px solid var(--garis);
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            padding: 6px 12px;
        }

        .tombol-kecil:hover {
            background: #e2e6ec;
        }

        .daftar {
            border: 1px solid var(--garis);
            border-radius: 8px;
            max-height: 340px;
            overflow-y: auto;
        }

        .item {
            align-items: center;
            border-bottom: 1px solid #eef0f3;
            cursor: pointer;
            display: flex;
            gap: 10px;
            padding: 9px 12px;
        }

        .item:last-child {
            border-bottom: 0;
        }

        .item:hover {
            background: #f7f9fc;
        }

        .item input {
            height: 16px;
            width: 16px;
        }

        .item .id {
            color: #8a909a;
            font-size: 12px;
            min-width: 48px;
        }

        .item .nama {
            font-size: 14px;
        }

        .kosong {
            color: #8a909a;
            display: none;
            padding: 16px;
            text-align: center;
        }

        button[type=submit] {
            background: var(--biru);
            border: 0;
            border-radius: 8px;
            color: #fff;
            cursor: pointer;
            font-size: 15px;
            font-weight: bold;
            margin-top: 18px;
            padding: 12px 20px;
            width: 100%;
        }

        button[type=submit]:hover {
            background: var(--biru-tua);
        }
    </style>
</head>
<body>
<div style="align-items:baseline;display:flex;justify-content:space-between;margin:0 auto 18px;max-width:640px;">
    <h2 style="margin:0;">Data Historis PRTG Generator</h2>
    <span>
        <a href="antrean.php" style="color:#007bff;font-size:14px;text-decoration:none;">Antrean</a>
        &nbsp;·&nbsp;
        <a href="hasil.php" style="color:#007bff;font-size:14px;text-decoration:none;">Hasil Laporan</a>
        &nbsp;·&nbsp;
        <a href="pelanggan.php" style="color:#007bff;font-size:14px;text-decoration:none;">Kelola Pelanggan</a>
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
        </div>
        <label class="cek-opsi">
            <input type="checkbox" name="rekap_downtime" value="1" checked>
            Sertakan Rekap Log Downtime
        </label>
    </div>

    <div class="kartu">
        <div class="panel-head">
            <h3>Pelanggan</h3>
            <span class="badge"><span id="jml-terpilih">0</span> / <?= count($pelanggan) ?> dipilih</span>
        </div>

        <input id="cari" type="text" placeholder="🔍 Cari nama atau ID pelanggan..." autocomplete="off">

        <div class="aksi">
            <button type="button" class="tombol-kecil" id="pilih-semua">Pilih semua</button>
            <button type="button" class="tombol-kecil" id="hapus-semua">Hapus semua</button>
        </div>

        <div class="daftar" id="daftar">
            <?php foreach ($pelanggan as $p): ?>
                <label class="item"
                       data-cari="<?= htmlspecialchars(strtolower($p['id'] . ' ' . $p['nama']), ENT_QUOTES) ?>">
                    <input type="checkbox" name="pelanggan[]" value="<?= htmlspecialchars($p['id'], ENT_QUOTES) ?>">
                    <span class="id"><?= htmlspecialchars($p['id']) ?></span>
                    <span class="nama"><?= htmlspecialchars($p['nama']) ?></span>
                </label>
            <?php endforeach; ?>
            <div class="kosong" id="kosong">Tidak ada pelanggan yang cocok.</div>
        </div>

        <button type="submit">Buat Laporan</button>
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

    function perbaruiJumlah() {
        jml.textContent = kotak.filter(c => c.checked).length;
    }

    function terlihat(el) {
        return el.style.display !== 'none';
    }

    cari.addEventListener('input', function () {
        const q = this.value.trim().toLowerCase();
        let ada = 0;

        items.forEach(el => {
            const cocok = el.dataset.cari.includes(q);
            el.style.display = cocok ? '' : 'none';
            if (cocok) ada++;
        });

        kosong.style.display = ada === 0 ? 'block' : 'none';
    });

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
</script>
</body>
</html>
