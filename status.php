<?php

require 'database.php';
require 'helpers.php';

$id = $_GET['id'] ?? '';

$bd = db();

$job = null;

if ($id !== '') {
    $perintah = $bd->prepare("
        SELECT j.*, p.nama AS pelanggan_nama
        FROM jobs j
        LEFT JOIN pelanggan p ON p.id = j.pelanggan
        WHERE j.id = ?
    ");
    $perintah->execute([$id]);
    $job = $perintah->fetch(PDO::FETCH_ASSOC);
}

// Backward-compat: masih bisa ambil data mentah sebagai JSON bila diminta.
if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json');
    if (!$job) {
        http_response_code(404);
        echo json_encode(['error' => 'Job tidak ditemukan']);
    } else {
        echo json_encode($job, JSON_PRETTY_PRINT);
    }
    exit;
}

// Kumpulkan file hasil (bila ada).
$files = [];
if ($job) {
    $qf = $bd->prepare('SELECT filename, created_at FROM job_files WHERE job_id = ? ORDER BY filename');
    $qf->execute([$id]);
    $rows = $qf->fetchAll(PDO::FETCH_ASSOC);

    $folder = 'jobs/' . sanitizeFolderName($job['pelanggan_nama'] ?? '');

    foreach ($rows as $r) {
        $path = $folder . '/' . $r['filename'];
        $ada  = is_file($path);
        $files[] = [
            'filename' => $r['filename'],
            'ada'      => $ada,
            'ukuran'   => $ada ? formatBytes(filesize($path)) : null,
            'url'      => jobFileUrl($path),
        ];
    }
}

$statusInfo = [
    'queued'     => ['Antre', 'antre'],
    'processing' => ['Diproses', 'proses'],
    'done'       => ['Selesai', 'selesai'],
    'failed'     => ['Gagal', 'gagal'],
];

$st       = $job['status'] ?? '';
$stLabel  = $statusInfo[$st][0] ?? $st;
$stKelas  = $statusInfo[$st][1] ?? 'antre';
$autoRefresh = in_array($st, ['queued', 'processing'], true);

$diharapkan = $job ? monthCountInclusive($job['bulan_mulai'] ?? null, $job['bulan_akhir'] ?? null) : null;
$didapat    = count($files);
$persen     = $diharapkan ? min(100, round($didapat / $diharapkan * 100)) : ($st === 'done' ? 100 : 0);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta content="initial-scale=1, width=device-width" name="viewport">
    <?php if ($autoRefresh): ?><meta http-equiv="refresh" content="5"><?php endif; ?>
    <title>Status Job — PRTG Generator</title>
    <style>
        :root {
            --biru: #007bff; --biru-tua: #0062cc; --garis: #d9dde3;
            --abu: #f4f4f9; --teks: #2b2f36; --redup: #8a909a;
        }
        * { box-sizing: border-box; }
        body { background: var(--abu); color: var(--teks); font-family: Arial, Helvetica, sans-serif; margin: 0; padding: 24px 16px; }
        .bar { align-items: baseline; display: flex; gap: 14px; justify-content: space-between; margin: 0 auto 18px; max-width: 720px; }
        .bar h2 { margin: 0; }
        a.nav { color: var(--biru); font-size: 14px; text-decoration: none; }
        a.nav:hover { text-decoration: underline; }
        .kartu { background: #fff; border: 1px solid var(--garis); border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,.06); margin: 0 auto 18px; max-width: 720px; padding: 20px 22px; }

        .kepala { align-items: center; display: flex; flex-wrap: wrap; gap: 12px; justify-content: space-between; }
        .kepala h3 { margin: 0; }
        .badge { border-radius: 999px; color: #fff; font-size: 13px; font-weight: bold; padding: 5px 14px; }
        .badge.antre   { background: #6c757d; }
        .badge.proses  { background: var(--biru); }
        .badge.selesai { background: #198754; }
        .badge.gagal   { background: #dc3545; }

        dl { display: grid; grid-template-columns: 150px 1fr; gap: 8px 16px; margin: 18px 0 0; }
        dt { color: var(--redup); font-size: 13px; }
        dd { margin: 0; font-size: 14px; word-break: break-word; }

        .progress-wrap { margin-top: 18px; }
        .progress-label { color: var(--redup); display: flex; font-size: 13px; justify-content: space-between; margin-bottom: 6px; }
        .progress { background: #eef1f5; border-radius: 999px; height: 10px; overflow: hidden; }
        .progress > span { background: var(--biru); border-radius: 999px; display: block; height: 100%; transition: width .3s; }
        .progress.selesai > span { background: #198754; }

        .galat { background: #fdeaec; border: 1px solid #f3c2c7; border-radius: 8px; color: #b52a37; font-size: 13px; margin-top: 16px; padding: 12px 14px; white-space: pre-wrap; }

        .file-head { color: var(--redup); font-size: 13px; margin: 4px 0 10px; text-transform: uppercase; }
        .file { align-items: center; border: 1px solid var(--garis); border-radius: 8px; display: flex; gap: 12px; margin-bottom: 8px; padding: 10px 12px; }
        .file .ikon { flex: none; font-size: 22px; }
        .file .isi { flex: 1; min-width: 0; }
        .file .nama { font-size: 14px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .file .meta { color: var(--redup); font-size: 12px; }
        .file a.unduh { background: var(--biru); border-radius: 6px; color: #fff; flex: none; font-size: 13px; font-weight: bold; padding: 7px 14px; text-decoration: none; }
        .file a.unduh:hover { background: var(--biru-tua); }
        .file .hilang { color: #b52a37; font-size: 12px; }
        .kosong { color: var(--redup); font-size: 14px; padding: 6px 0; }
        .catatan { color: var(--redup); font-size: 12px; margin-top: 12px; }
    </style>
</head>
<body>
<div class="bar">
    <h2>Status Job</h2>
    <span>
        <a class="nav" href="index.php">← Generator</a>
        &nbsp;·&nbsp;
        <a class="nav" href="hasil.php">Semua Hasil →</a>
    </span>
</div>

<?php if (!$job): ?>
    <div class="kartu">
        <div class="kosong">
            <?php if ($id === ''): ?>
                Parameter <b>id</b> belum diisi. Buka halaman ini dari tautan "Cek Status" setelah membuat job.
            <?php else: ?>
                Job dengan ID <b><?= htmlspecialchars($id) ?></b> tidak ditemukan.
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
    <div class="kartu">
        <div class="kepala">
            <h3><?= htmlspecialchars($job['pelanggan_nama'] ?? '(pelanggan tidak dikenal)') ?></h3>
            <span class="badge <?= $stKelas ?>"><?= htmlspecialchars($stLabel) ?></span>
        </div>

        <dl>
            <dt>Job ID</dt><dd><?= htmlspecialchars($job['id']) ?></dd>
            <dt>ID Sensor</dt><dd><?= htmlspecialchars($job['pelanggan']) ?></dd>
            <dt>Periode</dt><dd><?= htmlspecialchars($job['bulan_mulai']) ?> &rarr; <?= htmlspecialchars($job['bulan_akhir']) ?></dd>
            <dt>Rekap Downtime</dt><dd><?= $job['rekap_downtime'] ? 'Ya' : 'Tidak' ?></dd>
            <dt>Dibuat</dt><dd><?= htmlspecialchars($job['created_at']) ?></dd>
            <dt>Selesai</dt><dd><?= htmlspecialchars($job['finished_at'] ?? '—') ?></dd>
        </dl>

        <div class="progress-wrap">
            <div class="progress-label">
                <span>Progres file</span>
                <span><?= $didapat ?><?= $diharapkan ? ' / ' . $diharapkan : '' ?> file</span>
            </div>
            <div class="progress <?= $st === 'done' ? 'selesai' : '' ?>">
                <span style="width: <?= $persen ?>%"></span>
            </div>
        </div>

        <?php if ($st === 'failed' && !empty($job['error'])): ?>
            <div class="galat"><b>Error:</b> <?= htmlspecialchars($job['error']) ?></div>
        <?php endif; ?>

        <?php if ($autoRefresh): ?>
            <div class="catatan">Halaman menyegarkan otomatis tiap 5 detik selama job berjalan.</div>
        <?php endif; ?>
    </div>

    <div class="kartu">
        <div class="file-head">File Hasil (<?= $didapat ?>)</div>
        <?php if ($didapat === 0): ?>
            <div class="kosong">
                <?= $st === 'failed' ? 'Tidak ada file — job gagal.' : 'Belum ada file. File muncul di sini setelah setiap bulan selesai diproses.' ?>
            </div>
        <?php else: ?>
            <?php foreach ($files as $f): ?>
                <div class="file">
                    <div class="ikon">📄</div>
                    <div class="isi">
                        <div class="nama"><?= htmlspecialchars($f['filename']) ?></div>
                        <div class="meta">
                            <?php if ($f['ada']): ?>
                                <?= htmlspecialchars($f['ukuran']) ?> · Word (.docx)
                            <?php else: ?>
                                <span class="hilang">File tidak ditemukan di disk</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($f['ada']): ?>
                        <a class="unduh" href="<?= $f['url'] ?>" download>Unduh</a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
<?php endif; ?>
</body>
</html>
