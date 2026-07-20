<?php
require 'database.php';

$bd = db();

/**
 * Semua perubahan diproses lewat POST lalu redirect (pola PRG) agar
 * refresh halaman tidak mengirim ulang data.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = $_POST['aksi'] ?? '';
    $id   = trim($_POST['id'] ?? '');
    $nama = trim($_POST['nama'] ?? '');

    $pesan = fn(string $k, string $v) => header('Location: pelanggan.php?' . $k . '=' . urlencode($v));

    try {
        if ($aksi === 'tambah') {
            if ($id === '' || $nama === '') {
                $pesan('err', 'ID dan nama wajib diisi.');
            } elseif (mb_strlen($id) > 20) {
                $pesan('err', 'ID maksimal 20 karakter.');
            } else {
                $cek = $bd->prepare('SELECT 1 FROM pelanggan WHERE id = ?');
                $cek->execute([$id]);

                if ($cek->fetchColumn()) {
                    $pesan('err', 'ID "' . $id . '" sudah terdaftar.');
                } else {
                    $bd->prepare('INSERT INTO pelanggan (id, nama) VALUES (?, ?)')
                        ->execute([$id, mb_substr($nama, 0, 255)]);
                    $pesan('ok', 'Pelanggan "' . $nama . '" ditambahkan.');
                }
            }
        } elseif ($aksi === 'ubah') {
            if ($id === '' || $nama === '') {
                $pesan('err', 'Nama tidak boleh kosong.');
            } else {
                $bd->prepare('UPDATE pelanggan SET nama = ? WHERE id = ?')
                    ->execute([mb_substr($nama, 0, 255), $id]);
                $pesan('ok', 'Nama pelanggan diperbarui.');
            }
        } elseif ($aksi === 'hapus') {
            $bd->prepare('DELETE FROM pelanggan WHERE id = ?')->execute([$id]);
            $pesan('ok', 'Pelanggan dihapus.');
        } else {
            $pesan('err', 'Aksi tidak dikenal.');
        }
    } catch (Throwable $e) {
        $pesan('err', 'Gagal memproses: ' . $e->getMessage());
    }

    exit;
}

$pelanggan = $bd->query('SELECT id, nama FROM pelanggan ORDER BY nama')
    ->fetchAll(PDO::FETCH_ASSOC);

$ok  = $_GET['ok']  ?? '';
$err = $_GET['err'] ?? '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta content="initial-scale=1, width=device-width" name="viewport">
    <title>Kelola Pelanggan — PRTG Generator</title>
    <style>
        :root {
            --biru: #007bff;
            --biru-tua: #0062cc;
            --garis: #d9dde3;
            --abu: #f4f4f9;
            --teks: #2b2f36;
            --merah: #dc3545;
            --hijau: #198754;
        }

        * { box-sizing: border-box; }

        body {
            background: var(--abu);
            color: var(--teks);
            font-family: Arial, Helvetica, sans-serif;
            margin: 0;
            padding: 24px 16px;
        }

        .bar {
            align-items: baseline;
            display: flex;
            justify-content: space-between;
            margin: 0 auto 18px;
            max-width: 760px;
        }

        .bar h2 { margin: 0; }

        a.nav {
            color: var(--biru);
            font-size: 14px;
            text-decoration: none;
        }

        a.nav:hover { text-decoration: underline; }

        .kartu {
            background: #fff;
            border: 1px solid var(--garis);
            border-radius: 10px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, .06);
            margin: 0 auto 18px;
            max-width: 760px;
            padding: 18px 20px;
        }

        .flash {
            border-radius: 8px;
            font-size: 14px;
            margin: 0 auto 16px;
            max-width: 760px;
            padding: 12px 16px;
        }

        .flash.ok  { background: #e7f6ec; border: 1px solid #b6e3c4; color: var(--hijau); }
        .flash.err { background: #fdeaec; border: 1px solid #f3c2c7; color: var(--merah); }

        .tambah {
            align-items: flex-end;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }

        .tambah .grp { flex: 1 1 auto; }

        .tambah .grp.id { flex: 0 0 130px; }

        label.field {
            display: block;
            font-size: 13px;
            font-weight: bold;
            margin-bottom: 4px;
        }

        input[type=text] {
            border: 1px solid var(--garis);
            border-radius: 6px;
            font-size: 14px;
            padding: 9px 10px;
            width: 100%;
        }

        .btn {
            background: var(--biru);
            border: 0;
            border-radius: 6px;
            color: #fff;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
            padding: 10px 18px;
        }

        .btn:hover { background: var(--biru-tua); }

        .btn.abu   { background: #eef1f5; border: 1px solid var(--garis); color: var(--teks); font-weight: normal; }
        .btn.abu:hover { background: #e2e6ec; }
        .btn.hapus { background: var(--merah); }
        .btn.hapus:hover { background: #b52a37; }

        #cari {
            border: 1px solid var(--garis);
            border-radius: 6px;
            font-size: 14px;
            margin-bottom: 12px;
            padding: 9px 12px;
            width: 100%;
        }

        table { border-collapse: collapse; width: 100%; }

        th, td {
            border-bottom: 1px solid #eef0f3;
            padding: 9px 10px;
            text-align: left;
            vertical-align: middle;
        }

        th {
            color: #8a909a;
            font-size: 12px;
            text-transform: uppercase;
        }

        td.id { color: #8a909a; font-size: 13px; white-space: nowrap; }

        td.aksi { text-align: right; white-space: nowrap; }

        .btn-kecil {
            border: 1px solid var(--garis);
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            padding: 5px 12px;
        }

        .btn-kecil.ubah  { background: #fff; }
        .btn-kecil.ubah:hover { background: #f2f4f7; }
        .btn-kecil.hapus { background: #fff; border-color: #f0c2c7; color: var(--merah); }
        .btn-kecil.hapus:hover { background: #fdeaec; }

        .edit-form { display: none; gap: 6px; }
        .edit-form.aktif { display: flex; }
        .edit-form input { min-width: 220px; }

        tr.tersembunyi { display: none; }

        .info { color: #8a909a; font-size: 13px; margin-top: 12px; }
    </style>
</head>
<body>
<div class="bar">
    <h2>Kelola Pelanggan</h2>
    <a class="nav" href="index.php">← Kembali ke Generator</a>
</div>

<?php if ($ok !== ''): ?>
    <div class="flash ok"><?= htmlspecialchars($ok) ?></div>
<?php endif; ?>
<?php if ($err !== ''): ?>
    <div class="flash err"><?= htmlspecialchars($err) ?></div>
<?php endif; ?>

<div class="kartu">
    <form class="tambah" method="post">
        <input type="hidden" name="aksi" value="tambah">
        <div class="grp id">
            <label class="field" for="id-baru">ID Pelanggan</label>
            <input id="id-baru" name="id" type="text" placeholder="mis. 10070" required>
        </div>
        <div class="grp">
            <label class="field" for="nama-baru">Nama Pelanggan</label>
            <input id="nama-baru" name="nama" type="text" placeholder="Nama lengkap pelanggan" required>
        </div>
        <button class="btn" type="submit">+ Tambah</button>
    </form>
</div>

<div class="kartu">
    <input id="cari" type="text" placeholder="🔍 Cari nama atau ID..." autocomplete="off">

    <table>
        <thead>
        <tr>
            <th style="width:120px">ID</th>
            <th>Nama</th>
            <th style="width:170px"></th>
        </tr>
        </thead>
        <tbody id="tbody">
        <?php foreach ($pelanggan as $p):
            $idAman   = htmlspecialchars($p['id'], ENT_QUOTES);
            $namaAman = htmlspecialchars($p['nama'], ENT_QUOTES);
            ?>
            <tr data-cari="<?= htmlspecialchars(strtolower($p['id'] . ' ' . $p['nama']), ENT_QUOTES) ?>">
                <td class="id"><?= $idAman ?></td>
                <td>
                    <span class="nama-text"><?= $namaAman ?></span>
                    <form class="edit-form" method="post">
                        <input type="hidden" name="aksi" value="ubah">
                        <input type="hidden" name="id" value="<?= $idAman ?>">
                        <input type="text" name="nama" value="<?= $namaAman ?>" required>
                        <button class="btn-kecil ubah" type="submit">Simpan</button>
                        <button class="btn-kecil batal" type="button">Batal</button>
                    </form>
                </td>
                <td class="aksi">
                    <button class="btn-kecil ubah tombol-ubah" type="button">Ubah</button>
                    <form method="post" style="display:inline"
                          onsubmit="return confirm('Hapus pelanggan &quot;<?= $namaAman ?>&quot;?');">
                        <input type="hidden" name="aksi" value="hapus">
                        <input type="hidden" name="id" value="<?= $idAman ?>">
                        <button class="btn-kecil hapus" type="submit">Hapus</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="info">Total: <?= count($pelanggan) ?> pelanggan</div>
</div>

<script>
    'use strict';

    const rows = Array.from(document.querySelectorAll('#tbody tr'));

    // Pencarian
    document.getElementById('cari').addEventListener('input', function () {
        const q = this.value.trim().toLowerCase();
        rows.forEach(tr => {
            tr.classList.toggle('tersembunyi', !tr.dataset.cari.includes(q));
        });
    });

    // Ubah / Batal (edit inline)
    rows.forEach(tr => {
        const teks   = tr.querySelector('.nama-text');
        const form   = tr.querySelector('.edit-form');
        const tblUbah = tr.querySelector('.tombol-ubah');

        tblUbah.addEventListener('click', () => {
            teks.style.display = 'none';
            tblUbah.style.display = 'none';
            form.classList.add('aktif');
            form.querySelector('input[name=nama]').focus();
        });

        tr.querySelector('.batal').addEventListener('click', () => {
            form.classList.remove('aktif');
            teks.style.display = '';
            tblUbah.style.display = '';
        });
    });
</script>
</body>
</html>
