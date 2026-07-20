# Laporan QC — PRTG Generator

Tanggal: 2026-07-20
Penilai: QC Software (Claude)
Lingkungan: PHP 8.2.12, MySQL 8.0.37

---

## 1. Database

- Server MySQL 8.0 aktif di port 3306 (bukan MariaDB bawaan XAMPP; folder `xampp/mysql/bin` kosong).
- Database **`prtg_generator`** berhasil dibuat dari `127_0_0_1(1).sql`.
  Tabel: `jobs`, `job_files`, `pelanggan` — 65 pelanggan, 1 job.
- Koneksi PHP terverifikasi (via `localhost` maupun `127.0.0.1`).

> Catatan: file dump juga membuat database `phpmyadmin` dan `test` (bawaan phpMyAdmin). Keduanya tidak dipakai aplikasi.

---

## 2. Temuan & Perbaikan

### Kritis — Keamanan
| # | Temuan | Status |
|---|--------|--------|
| K1 | Kredensial DB di-hardcode di `database.php`, dan **password salah** (kosong, padahal server butuh password) — aplikasi tidak bisa connect. | ✅ Dipindah ke `config.php`, password benar |
| K2 | Username/password PRTG di-hardcode di dalam kode (`prtgintidata` / `!!Intinet@2015!!`). | ✅ Dipindah ke `config.php` |
| K3 | Kredensial berpotensi ikut ter-commit. | ✅ `config.php` ditambahkan ke `.gitignore` |

### Sedang — Kebenaran & Keandalan
| # | Temuan | Status |
|---|--------|--------|
| S1 | File sementara `tmp/grafik.svg`, `tmp/data.html`, `tmp/data.png`, `cookie.txt` memakai nama tetap → **race condition** bila >1 laporan diproses bersamaan. | ✅ Diberi token unik per job+bulan, dibersihkan setelah selesai |
| S2 | `finished_at` tidak pernah diisi meski kolomnya ada. | ✅ Diisi saat status `done`/`failed` |
| S3 | `status.php` tanpa validasi `id`, tanpa header `Content-Type`, tanpa penanganan job tak ditemukan. | ✅ Ditambah validasi + JSON header + 400/404 |
| S4 | `create-job.php` memakai **GET** untuk operasi tulis, tanpa validasi format bulan. | ✅ Diubah ke **POST** + validasi `YYYY-MM` + cek rentang |
| S5 | Koneksi DB baru dibuat tiap panggilan `generateReport()` (per bulan). | ✅ `db()` kini singleton |

### Rendah — Reusability & Kerapian
| # | Temuan | Status |
|---|--------|--------|
| R1 | `$section->addTextBreak()` ditulis manual 16×. | ✅ Diganti loop |
| R2 | Array style font & sel tabel diulang puluhan kali. | ✅ Helper `fontStyle()` + closure `tambahHeader()` |
| R3 | Nama & jabatan penandatangan, path watermark, base URL PRTG di-hardcode. | ✅ Dipindah ke `config.php` |
| R4 | Form `index.php` action absolut `/prtg-generator/...` (terikat nama folder). | ✅ Diubah relatif |

---

## 3. Masih Perlu Tindakan (di luar perubahan kode)

1. **`composer install`** — folder `vendor/` belum ada, sehingga `generate-report.php`
   (butuh PhpWord & Browsershot) belum bisa jalan. Browsershot juga butuh Node.js + Chromium.
2. **Rotasi password PRTG** — password sempat tersimpan sebagai teks biasa di kode;
   sebaiknya diganti karena sudah ada di riwayat file.
3. **Setup baru** — salin `config.example.php` → `config.php`, lalu sesuaikan.

## 4. Catatan Skema (opsional)

- Kolom `jobs.file` tidak terpakai (nama file disimpan di tabel `job_files`) — kandidat dihapus.
- `job_files.job_id` belum punya FOREIGN KEY ke `jobs.id` — bisa ditambah untuk integritas.

---

## 5. File yang Diubah

`config.php` (baru), `config.example.php` (baru), `database.php`, `generate-report.php`,
`worker.php`, `status.php`, `create-job.php`, `index.php`, `.gitignore`.

Semua file lolos `php -l` (tanpa syntax error). Logika scraping PRTG **tidak diubah**,
hanya struktur, konfigurasi, dan keamanannya yang dirapikan.
