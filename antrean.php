<?php

require_once 'database.php';
require_once 'helpers.php';
require_once __DIR__ . '/worker-heartbeat.php';

$bd = db();

/**
 * Apakah worker sedang hidup? Dipakai untuk melindungi job yang sedang
 * diproses agar tidak dihapus di tengah jalan.
 *
 * Aturannya sengaja tidak ditulis ulang di sini: worker yang sedang memproses
 * job meminta masa berlaku heartbeat yang jauh lebih panjang, dan salinan
 * aturan yang tertinggal akan mengira worker sibuk itu sudah mati — lalu
 * mengizinkan job yang sedang dikerjakan ikut terhapus.
 */
function workerSedangHidup(): bool
{
    return detakHidup(detakBaca());
}

/**
 * Penghapusan job diproses lewat POST lalu redirect (pola PRG), agar
 * refresh halaman tidak mengirim ulang perintah hapus.
 *
 * Catatan: yang dihapus hanya baris di database. Berkas .docx yang sudah
 * jadi tetap ada dan dikelola di halaman Hasil Laporan.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = $_POST['aksi'] ?? '';
    $id   = trim($_POST['id'] ?? '');

    $pesan = fn(string $k, string $v) => header('Location: antrean.php?' . $k . '=' . urlencode($v));

    // Job yang sedang diproses hanya boleh dihapus bila worker sudah mati;
    // kalau tidak, worker akan tetap menulis hasil untuk job yang sudah hilang.
    $lindungiProses = workerSedangHidup();

    try {
        if ($aksi === 'hapus') {
            if ($id === '') {
                $pesan('err', 'ID job tidak valid.');
                exit;
            }

            $cek = $bd->prepare('SELECT status FROM jobs WHERE id = ?');
            $cek->execute([$id]);
            $status = $cek->fetchColumn();

            if ($status === false) {
                $pesan('err', 'Job tidak ditemukan.');
            } elseif ($status === 'processing' && $lindungiProses) {
                $pesan('err', 'Job ini sedang diproses worker. Hentikan worker lebih dulu.');
            } else {
                $bd->prepare('DELETE FROM job_files WHERE job_id = ?')->execute([$id]);
                $bd->prepare('DELETE FROM jobs WHERE id = ?')->execute([$id]);
                $pesan('ok', 'Job dihapus.');
            }
        } elseif ($aksi === 'bersihkan') {
            // Kelompok status yang boleh dibersihkan sekaligus.
            $kelompok = [
                'done'   => ['status' => ['done'],            'label' => 'selesai'],
                'failed' => ['status' => ['failed'],          'label' => 'gagal'],
                'queued' => ['status' => ['queued'],          'label' => 'antre'],
                'semua'  => ['status' => ['done', 'failed', 'queued', 'processing'], 'label' => ''],
            ];

            $pilih = $_POST['kelompok'] ?? '';

            if (!isset($kelompok[$pilih])) {
                $pesan('err', 'Kelompok tidak dikenal.');
                exit;
            }

            $status = $kelompok[$pilih]['status'];

            if ($lindungiProses) {
                $status = array_values(array_diff($status, ['processing']));
            }

            if (!$status) {
                $pesan('err', 'Tidak ada job yang boleh dihapus saat worker berjalan.');
                exit;
            }

            $tanda = implode(',', array_fill(0, count($status), '?'));

            $bd->prepare("DELETE FROM job_files WHERE job_id IN (SELECT id FROM jobs WHERE status IN ($tanda))")
                ->execute($status);

            $hapus = $bd->prepare("DELETE FROM jobs WHERE status IN ($tanda)");
            $hapus->execute($status);

            $n     = $hapus->rowCount();
            $label = $kelompok[$pilih]['label'];

            $pesan('ok', $n . ' job' . ($label ? ' ' . $label : '') . ' dihapus.');
        } else {
            $pesan('err', 'Aksi tidak dikenal.');
        }
    } catch (Throwable $e) {
        $pesan('err', 'Gagal memproses: ' . $e->getMessage());
    }

    exit;
}

$jobs = $bd->query("
    SELECT j.id, j.pelanggan, j.status, j.bulan_mulai, j.bulan_akhir,
           j.created_at, j.finished_at,
           p.nama AS pelanggan_nama,
           (SELECT COUNT(*) FROM job_files f WHERE f.job_id = j.id) AS jml_file
    FROM jobs j
    LEFT JOIN pelanggan p ON p.id = j.pelanggan
    ORDER BY j.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Ikon tong sampah sebagai SVG sebaris, sama seperti di hasil.php.
$ikonHapus = '<svg class="ikon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>';

$ok  = $_GET['ok']  ?? '';
$err = $_GET['err'] ?? '';

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

        .flash { border-radius: 8px; font-size: 14px; margin: 0 auto 14px; max-width: 900px; padding: 12px 16px; }
        .flash.ok  { background: #e7f6ec; border: 1px solid #b6e3c4; color: #198754; }
        .flash.err { background: #fdeaec; border: 1px solid #f3c2c7; color: #dc3545; }

        .bersih { align-items: center; display: flex; flex-wrap: wrap; gap: 8px; margin: 0 auto 14px; max-width: 900px; }
        .bersih span.ket { color: var(--redup); font-size: 12px; margin-left: auto; }
        .bersih button { background: #fff; border: 1px solid var(--garis); border-radius: 6px; cursor: pointer; font-size: 13px; padding: 6px 12px; }
        .bersih button:hover:not(:disabled) { background: #f2f4f7; }
        .bersih button:disabled { cursor: not-allowed; opacity: .5; }
        .bersih button.bahaya { border-color: #f0c2c7; color: #dc3545; }
        .bersih button.bahaya:hover:not(:disabled) { background: #fdeaec; }

        td.aksi form { display: inline; }
        .ikon { flex: none; height: 14px; vertical-align: -2px; width: 14px; }
        td.aksi button.hapus { align-items: center; background: #fff; border: 1px solid #f0c2c7; border-radius: 6px; color: #dc3545; cursor: pointer; display: inline-flex; margin-left: 6px; padding: 5px 9px; vertical-align: middle; }
        td.aksi button.hapus:hover { background: #fdeaec; }

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

<?php if ($ok !== ''): ?>
    <div class="flash ok"><?= htmlspecialchars($ok) ?></div>
<?php endif; ?>
<?php if ($err !== ''): ?>
    <div class="flash err"><?= htmlspecialchars($err) ?></div>
<?php endif; ?>

<?php if ($jobs): ?>
    <form class="bersih" method="post" id="form-bersih">
        <input type="hidden" name="aksi" value="bersihkan">
        <input type="hidden" name="kelompok" id="kelompok">
        <button type="button" data-kelompok="done"   <?= $jumlah['done']   ? '' : 'disabled' ?>>Bersihkan selesai (<?= $jumlah['done'] ?>)</button>
        <button type="button" data-kelompok="failed" <?= $jumlah['failed'] ? '' : 'disabled' ?>>Bersihkan gagal (<?= $jumlah['failed'] ?>)</button>
        <button type="button" data-kelompok="queued" <?= $jumlah['queued'] ? '' : 'disabled' ?>>Batalkan antrean (<?= $jumlah['queued'] ?>)</button>
        <button type="button" class="bahaya" data-kelompok="semua">Hapus semua (<?= count($jobs) ?>)</button>
        <span class="ket">Hanya menghapus riwayat; berkas .docx tetap ada di Hasil Laporan</span>
    </form>
<?php endif; ?>

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
                <th style="width:150px"></th>
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
                    <td class="aksi">
                        <a href="status.php?id=<?= htmlspecialchars($j['id'], ENT_QUOTES) ?>">Detail →</a>
                        <form method="post"
                              onsubmit="return confirm('Hapus job ini dari riwayat?');">
                            <input type="hidden" name="aksi" value="hapus">
                            <input type="hidden" name="id" value="<?= htmlspecialchars($j['id'], ENT_QUOTES) ?>">
                            <button type="submit" class="hapus" title="Hapus job"><?= $ikonHapus ?></button>
                        </form>
                    </td>
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

    // ---------------- Tombol bersihkan ----------------
    const formBersih = document.getElementById('form-bersih');

    if (formBersih) {
        const teksKonfirmasi = {
            done:   'Hapus semua job berstatus selesai dari riwayat?',
            failed: 'Hapus semua job berstatus gagal dari riwayat?',
            queued: 'Batalkan semua job yang masih antre?',
            semua:  'Hapus SELURUH riwayat job? Tindakan ini tidak bisa dibatalkan.'
        };

        formBersih.querySelectorAll('button[data-kelompok]').forEach(b => {
            b.addEventListener('click', () => {
                const k = b.dataset.kelompok;

                if (!confirm(teksKonfirmasi[k])) return;

                document.getElementById('kelompok').value = k;
                formBersih.submit();
            });
        });
    }

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

        // Kalau kendali terkunci, sebutkan alasannya lengkap dengan IP yang
        // terdeteksi — supaya jelas apa yang perlu didaftarkan di config.
        if (!bisa) {
            const alasan = 'Alamat ' + (st.ip_anda || '?') + ' belum diizinkan. '
                + "Tambahkan ke config('worker')['kendali_ip'].";

            btnOn.title = btnOff.title = alasan;
            rinci.textContent = alasan;
        } else {
            btnOn.title = btnOff.title = '';
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
