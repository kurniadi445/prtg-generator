<?php

require_once 'database.php';
require_once 'helpers.php';

$bd = db();

$jobs = $bd->query("
    SELECT j.id, j.pelanggan, j.status, j.bulan_mulai, j.bulan_akhir,
           j.created_at, j.finished_at,
           p.nama AS pelanggan_nama,
           (SELECT COUNT(*) FROM job_files f WHERE f.job_id = j.id) AS jml_file
    FROM jobs j
    LEFT JOIN pelanggan p ON p.id = j.pelanggan
    ORDER BY j.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

$statusInfo = [
    'queued'     => ['Antre', 'antre'],
    'processing' => ['Diproses', 'proses'],
    'done'       => ['Selesai', 'selesai'],
    'failed'     => ['Gagal', 'gagal'],
];

$jumlah = ['queued' => 0, 'processing' => 0, 'done' => 0, 'failed' => 0];
foreach ($jobs as $j) {
    if (isset($jumlah[$j['status']])) {
        $jumlah[$j['status']]++;
    }
}

$adaAktif = ($jumlah['queued'] + $jumlah['processing']) > 0;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta content="initial-scale=1, width=device-width" name="viewport">
    <?php if ($adaAktif): ?><meta http-equiv="refresh" content="5"><?php endif; ?>
    <title>Antrean & Riwayat Job — PRTG Generator</title>
    <style>
        :root {
            --biru: #007bff; --biru-tua: #0062cc; --garis: #d9dde3;
            --abu: #f4f4f9; --teks: #2b2f36; --redup: #8a909a;
        }
        * { box-sizing: border-box; }
        body { background: var(--abu); color: var(--teks); font-family: Arial, Helvetica, sans-serif; margin: 0; padding: 24px 16px; }
        .bar { align-items: baseline; display: flex; gap: 14px; justify-content: space-between; margin: 0 auto 14px; max-width: 900px; }
        .bar h2 { margin: 0; }
        a.nav { color: var(--biru); font-size: 14px; text-decoration: none; }
        a.nav:hover { text-decoration: underline; }

        .worker { align-items: center; background: #fff; border: 1px solid var(--garis); border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,.06); display: flex; flex-wrap: wrap; gap: 12px; margin: 0 auto 14px; max-width: 900px; padding: 12px 16px; }
        .worker .lampu { border-radius: 50%; flex: none; height: 10px; width: 10px; }
        .worker .lampu.hidup { background: #198754; box-shadow: 0 0 0 3px rgba(25,135,84,.18); }
        .worker .lampu.mati  { background: #dc3545; box-shadow: 0 0 0 3px rgba(220,53,69,.18); }
        .worker .lampu.tanya { background: #8a909a; }
        .worker .teks { font-size: 14px; }
        .worker .rinci { color: var(--redup); font-size: 12px; }
        .worker .tombol { display: flex; gap: 8px; margin-left: auto; }
        .worker button { background: #eef1f5; border: 1px solid var(--garis); border-radius: 6px; cursor: pointer; font-size: 13px; padding: 7px 14px; }
        .worker button:hover:not(:disabled) { background: #e2e6ec; }
        .worker button:disabled { cursor: not-allowed; opacity: .5; }
        .worker button.mulai { background: var(--biru); border-color: var(--biru); color: #fff; font-weight: bold; }
        .worker button.mulai:hover:not(:disabled) { background: var(--biru-tua); }

        .chips { display: flex; flex-wrap: wrap; gap: 8px; margin: 0 auto 14px; max-width: 900px; }
        .chip { background: #fff; border: 1px solid var(--garis); border-radius: 999px; cursor: pointer; font-size: 13px; padding: 7px 14px; }
        .chip.aktif { background: var(--biru); border-color: var(--biru); color: #fff; }
        .chip b { font-weight: bold; }

        .kotak { background: #fff; border: 1px solid var(--garis); border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,.06); margin: 0 auto; max-width: 900px; overflow: hidden; }
        #cari { border: 0; border-bottom: 1px solid var(--garis); font-size: 14px; padding: 14px 18px; width: 100%; }
        #cari:focus { outline: none; }

        table { border-collapse: collapse; width: 100%; }
        th, td { padding: 11px 14px; text-align: left; vertical-align: middle; }
        thead th { border-bottom: 1px solid var(--garis); color: var(--redup); font-size: 12px; text-transform: uppercase; }
        tbody tr { border-bottom: 1px solid #eef0f3; }
        tbody tr:last-child { border-bottom: 0; }
        tbody tr:hover { background: #f7f9fc; }

        .nm { font-weight: bold; }
        .sub { color: var(--redup); font-size: 12px; }
        .badge { border-radius: 999px; color: #fff; font-size: 12px; font-weight: bold; padding: 4px 11px; white-space: nowrap; }
        .badge.antre   { background: #6c757d; }
        .badge.proses  { background: var(--biru); }
        .badge.selesai { background: #198754; }
        .badge.gagal   { background: #dc3545; }
        td.aksi { text-align: right; }
        td.aksi a { background: #eef1f5; border: 1px solid var(--garis); border-radius: 6px; color: var(--teks); font-size: 13px; padding: 6px 12px; text-decoration: none; white-space: nowrap; }
        td.aksi a:hover { background: var(--biru); border-color: var(--biru); color: #fff; }

        .kosong { color: var(--redup); font-size: 14px; padding: 30px 18px; text-align: center; }
        #tak-ada { display: none; }
        tr.sembunyi { display: none; }
        .catatan { color: var(--redup); font-size: 12px; margin: 12px auto 0; max-width: 900px; }
    </style>
</head>
<body>
<div class="bar">
    <h2>Antrean &amp; Riwayat Job</h2>
    <span>
        <a class="nav" href="index.php">← Generator</a>
        &nbsp;·&nbsp;
        <a class="nav" href="hasil.php">Hasil Laporan</a>
    </span>
</div>

<div class="worker" id="panel-worker">
    <span class="lampu tanya" id="worker-lampu"></span>
    <span>
        <span class="teks" id="worker-teks">Memeriksa status worker…</span><br>
        <span class="rinci" id="worker-rinci">&nbsp;</span>
    </span>
    <span class="tombol">
        <button type="button" class="mulai" id="worker-mulai" disabled>Nyalakan</button>
        <button type="button" id="worker-henti" disabled>Hentikan</button>
    </span>
</div>

<div class="chips">
    <span class="chip aktif" data-filter="all">Semua <b>(<?= count($jobs) ?>)</b></span>
    <span class="chip" data-filter="aktif">Berlangsung <b>(<?= $jumlah['queued'] + $jumlah['processing'] ?>)</b></span>
    <span class="chip" data-filter="done">Selesai <b>(<?= $jumlah['done'] ?>)</b></span>
    <span class="chip" data-filter="failed">Gagal <b>(<?= $jumlah['failed'] ?>)</b></span>
</div>

<div class="kotak">
    <?php if (!$jobs): ?>
        <div class="kosong">Belum ada job.</div>
    <?php else: ?>
        <input id="cari" type="text" placeholder="🔍 Cari nama pelanggan atau ID sensor..." autocomplete="off">
        <table>
            <thead>
            <tr>
                <th>Pelanggan</th>
                <th style="width:120px">Periode</th>
                <th style="width:110px">Status</th>
                <th style="width:90px">File</th>
                <th style="width:150px">Dibuat</th>
                <th style="width:110px"></th>
            </tr>
            </thead>
            <tbody id="tbody">
            <?php foreach ($jobs as $j):
                $st      = $j['status'];
                $stLabel = $statusInfo[$st][0] ?? $st;
                $stKelas = $statusInfo[$st][1] ?? 'antre';
                $harap   = monthCountInclusive($j['bulan_mulai'], $j['bulan_akhir']);
                $cari    = strtolower(($j['pelanggan_nama'] ?? '') . ' ' . $j['pelanggan'] . ' ' . $j['id']);
                ?>
                <tr data-status="<?= htmlspecialchars($st, ENT_QUOTES) ?>"
                    data-cari="<?= htmlspecialchars($cari, ENT_QUOTES) ?>">
                    <td>
                        <div class="nm"><?= htmlspecialchars($j['pelanggan_nama'] ?? '(tidak dikenal)') ?></div>
                        <div class="sub">sensor <?= htmlspecialchars($j['pelanggan']) ?></div>
                    </td>
                    <td><?= htmlspecialchars($j['bulan_mulai']) ?><br><span class="sub">s/d <?= htmlspecialchars($j['bulan_akhir']) ?></span></td>
                    <td><span class="badge <?= $stKelas ?>"><?= htmlspecialchars($stLabel) ?></span></td>
                    <td><?= (int) $j['jml_file'] ?><?= $harap ? ' / ' . $harap : '' ?></td>
                    <td class="sub"><?= htmlspecialchars($j['created_at']) ?></td>
                    <td class="aksi"><a href="status.php?id=<?= htmlspecialchars($j['id'], ENT_QUOTES) ?>">Detail →</a></td>
                </tr>
            <?php endforeach; ?>
            <tr id="tak-ada"><td colspan="6" class="kosong">Tidak ada job yang cocok.</td></tr>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php if ($adaAktif): ?>
    <div class="catatan">Ada job berjalan — halaman menyegarkan otomatis tiap 5 detik.</div>
<?php endif; ?>

<script>
    'use strict';

    const rows = Array.from(document.querySelectorAll('#tbody tr[data-status]'));
    const chips = Array.from(document.querySelectorAll('.chip'));
    const cari = document.getElementById('cari');
    const takAda = document.getElementById('tak-ada');
    let filter = 'all';

    function cocokFilter(status) {
        if (filter === 'all') return true;
        if (filter === 'aktif') return status === 'queued' || status === 'processing';
        return status === filter;
    }

    function terapkan() {
        const q = (cari ? cari.value.trim().toLowerCase() : '');
        let tampil = 0;

        rows.forEach(tr => {
            const ok = cocokFilter(tr.dataset.status) && tr.dataset.cari.includes(q);
            tr.classList.toggle('sembunyi', !ok);
            if (ok) tampil++;
        });

        if (takAda) takAda.style.display = tampil === 0 ? '' : 'none';
    }

    chips.forEach(ch => ch.addEventListener('click', () => {
        chips.forEach(c => c.classList.remove('aktif'));
        ch.classList.add('aktif');
        filter = ch.dataset.filter;
        terapkan();
    }));

    if (cari) cari.addEventListener('input', terapkan);

    // ---------------- Panel worker ----------------
    const lampu  = document.getElementById('worker-lampu');
    const teks   = document.getElementById('worker-teks');
    const rinci  = document.getElementById('worker-rinci');
    const btnOn  = document.getElementById('worker-mulai');
    const btnOff = document.getElementById('worker-henti');

    let adaJobBerjalan = false;

    function durasi(detik) {
        if (detik === null || detik === undefined) return '';
        const j = Math.floor(detik / 3600);
        const m = Math.floor((detik % 3600) / 60);
        const d = detik % 60;
        if (j) return j + ' jam ' + m + ' menit';
        if (m) return m + ' menit ' + d + ' detik';
        return d + ' detik';
    }

    function gambar(st) {
        const bisa = st.boleh_kendali;

        lampu.className = 'lampu ' + (st.berjalan ? 'hidup' : 'mati');

        if (st.berjalan) {
            teks.textContent = st.kegiatan === 'processing'
                ? 'Worker berjalan — sedang memproses job'
                : 'Worker berjalan — menunggu job';

            const bagian = ['PID ' + st.pid, 'aktif ' + durasi(st.uptime)];
            if (st.job_id) bagian.push('job ' + st.job_id);
            if (st.berhenti_diminta) bagian.push('menunggu berhenti');
            rinci.textContent = bagian.join(' · ');
        } else {
            teks.textContent = 'Worker tidak berjalan';
            rinci.textContent = st.antre > 0
                ? st.antre + ' job menunggu dan tidak akan diproses sampai worker dinyalakan'
                : 'Tidak ada job yang menunggu';
        }

        btnOn.disabled  = st.berjalan || !bisa;
        btnOff.disabled = !st.berjalan || !bisa || st.berhenti_diminta;

        if (!bisa) {
            btnOn.title = btnOff.title = 'Hanya bisa dari komputer server';
        }

        // Muat ulang halaman saat job selesai, agar tabelnya ikut segar.
        const berjalanSekarang = st.diproses > 0;
        if (adaJobBerjalan && !berjalanSekarang) location.reload();
        adaJobBerjalan = berjalanSekarang;
    }

    async function muatStatus() {
        try {
            const r = await fetch('worker-control.php?aksi=status', { cache: 'no-store' });
            gambar(await r.json());
        } catch (e) {
            lampu.className = 'lampu tanya';
            teks.textContent = 'Status worker tidak bisa dibaca';
            rinci.textContent = 'Periksa apakah worker-control.php ada di folder aplikasi';
        }
    }

    async function kirim(aksi, tombol) {
        tombol.disabled = true;
        rinci.textContent = 'Memproses…';

        try {
            const r = await fetch('worker-control.php?aksi=' + aksi, { cache: 'no-store' });
            const j = await r.json();
            if (j.pesan) rinci.textContent = j.pesan;
            if (!j.ok) lampu.className = 'lampu tanya';
        } catch (e) {
            rinci.textContent = 'Permintaan gagal.';
        }

        setTimeout(muatStatus, 800);
    }

    btnOn.addEventListener('click',  () => kirim('start', btnOn));
    btnOff.addEventListener('click', () => kirim('stop',  btnOff));

    muatStatus();
    setInterval(muatStatus, 5000);
</script>
</body>
</html>
