-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Waktu pembuatan: 03 Agu 2026 pada 16.12
-- Versi server: 10.4.32-MariaDB
-- Versi PHP: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `prtg_generator`
--

-- --------------------------------------------------------

--
-- Struktur dari tabel `jobs`
--

CREATE TABLE `jobs` (
  `id` varchar(50) NOT NULL,
  `bulan_mulai` varchar(7) DEFAULT NULL,
  `pelanggan` varchar(20) NOT NULL,
  `status` varchar(20) DEFAULT 'queued',
  `file` varchar(255) DEFAULT NULL,
  `error` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `finished_at` timestamp NULL DEFAULT NULL,
  `bulan_akhir` varchar(7) DEFAULT NULL,
  `rekap_downtime` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `job_files`
--

CREATE TABLE `job_files` (
  `id` int(11) NOT NULL,
  `job_id` varchar(50) DEFAULT NULL,
  `filename` varchar(255) DEFAULT NULL,
  `path` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `pelanggan`
--

CREATE TABLE `pelanggan` (
  `id` varchar(20) NOT NULL,
  `nama` varchar(255) NOT NULL,
  `template` varchar(50) NOT NULL DEFAULT 'idt'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data untuk tabel `pelanggan`
--

INSERT INTO `pelanggan` (`id`, `nama`, `template`) VALUES
('10070', 'GRAND SWISS BELHOTEL', 'idt'),
('10244', 'GRAND DARMO SUITE HOTEL', 'idt'),
('10312', 'POLITEKNIK PELAYARAN PERAK SURABAYA', 'idt'),
('10375', 'PENGADILAN AGAMA PROBOLINGGO', 'idt'),
('10521', 'KANWIL KEMENTERIAN AGAMA PROVINSI JAWA TIMUR', 'idt'),
('11025', 'NARITA HOTEL SURABAYA', 'idt'),
('11164', 'BADAN PERTANAHAN NASIONAL KOTA MOJOKERTO', 'idt'),
('11900', 'SPIL DOK PANTAI LAMONGAN (METRO)', 'idt'),
('11902', 'SPIL DOK PANTAI LAMONGAN', 'idt'),
('11990', 'DINAS KOMUNIKASI DAN INFORMASI KABUPATEN BEKASI', 'idt'),
('12029', 'SPIL KALIANAK', 'idt'),
('12074', 'LAYANAN PENGADAAN SECARA ELEKTRONIK (LPSE) KOTA PEKALONGAN', 'idt'),
('12163', 'TOP WALLET BANGKALAN', 'idt'),
('12168', 'DREAM TOURS & TRAVEL, PT', 'idt'),
('12491', 'DINAS KOMUNIKASI DAN INFORMASI KABUPATEN LAMONGAN', 'idt'),
('12504', 'PENGADILAN MILITER TINGGI III SURABAYA', 'idt'),
('12515', 'BALAI PELAKSANA PEMILIHAN JASA KONSTRUKSI', 'idt'),
('12554', 'AKADEMI PENERBANG INDONESIA BANYUWANGI', 'idt'),
('12562', 'BP3 BALAI PENDIDIKAN DAN PELATIHAN PENERBANGAN CURUG', 'idt'),
('12613', 'DINAS LINGKUNGAN HIDUP OSOWILANGUN', 'idt'),
('12615', 'DINAS LINGKUNGAN HIDUP TANJUNGSARI', 'idt'),
('12650', 'POLITEKNIK KELAUTAN DAN PERIKANAN SIDOARJO', 'idt'),
('12651', 'SEKOLAH MENENGAH PERTAMA NEGERI 2 PORONG', 'idt'),
('12654', 'DINAS LINGKUNGAN HIDUP MANYAR', 'idt'),
('12698', 'RUMAH SAKIT BHAYANGKARA KEDIRI', 'idt'),
('12720', 'TAMAN DAYU GOLF & RESORT', 'idt'),
('12752', 'SPBU DUPAK', 'idt'),
('12790', 'SURABAYA INTERCULTURAL SCHOOL', 'idt'),
('12899', 'DINAS KOMUNIKASI DAN INFORMASI TANJUNG SELOR', 'idt'),
('13249', 'RSUD MOHAMMAD NOER PAMEKASAN', 'idt'),
('13391', 'NARITA HOTEL TULUNGAGUNG', 'idt'),
('13423', 'SEKOLAH MENENGAH KEJURUAN NEGERI 8 SURABAYA', 'idt'),
('13440', 'PENGADILAN TINDAK PIDANA KORUPSI SIDOARJO', 'idt'),
('13500', 'RUMAH SAKIT UNIT DARURAT HUSADA PRIMA', 'idt'),
('13515', 'RESERVOAR SPAM GRESIK', 'idt'),
('13525', 'SEKOLAH TINGGI ILMU PELAYARAN JAKARTA UTARA', 'idt'),
('13549', 'INSTITUSI ASIA MALANG', 'idt'),
('13564', 'BADAN PERTANAHAN NASIONAL SIDOARJO', 'idt'),
('14072', 'EUROFINS MODERN TESTING SERVICES CPT INDONESIA PT', 'idt'),
('14104', 'PENGADILAN AGAMA BANGKALAN', 'idt'),
('14133', 'DINAS KOMUNIKASI DAN INFORMATIKA KABUPATEN KUDUS', 'idt'),
('14149', 'DINAS KOMUNIKASI DAN INFORMASI KENDAL', 'idt'),
('14151', 'PENGADILAN AGAMA LUMAJANG', 'idt'),
('14168', 'BALAI BESAR PELAKSANAAN JALAN NASIONAL JAWA TIMUR BALI', 'idt'),
('14203', 'POLITEKNIK PELAYARAN BANTEN', 'idt'),
('14231', 'UNIVERSITAS ISLAM MADURA', 'idt'),
('14383', 'TELKOM POLDA JATIM', 'idt'),
('2099', 'PDAM PUSAT GRESIK', 'idt'),
('2107', 'INSTITUSI AGAMA ISLAM NEGERI PAMEKASAN', 'icm'),
('2126', 'DINAS KOMUNIKASI DAN INFORMASI BANGKALAN', 'icm'),
('2132', 'DINAS KOMUNIKASI DAN INFORMATIKA KABUPATEN PEKALONGAN', 'icm'),
('2720', 'YUSEN LOGISTIC, PT', 'idt'),
('4463', 'DEWAN PERWAKILAN RAKYAT DAERAH SURABAYA', 'idt'),
('4719', 'BADAN KEPEGAWAIAN NEGARA II SIDOARJO KANREG', 'idt'),
('5436', 'DINAS KOMUNIKASI DAN INFORMASI BANGKALAN', 'idt'),
('5487', 'BANDUNG PANCING KEMBANG JEPUN', 'idt'),
('6216', 'PENGADILAN AGAMA PASURUAN', 'idt'),
('6569', 'GREAT DIPONEGORO HOTEL SURABAYA', 'idt'),
('6572', 'POLITEKNIK PELAYARAN SURABAYA', 'idt'),
('6637', 'PENGADILAN MILITER III-12 SURABAYA', 'idt'),
('6681', 'PENGADILAN NEGERI GRESIK', 'idt'),
('6699', 'BADAN PERTANAHAN NASIONAL KOTA MALANG', 'idt'),
('6817', 'PENGADILAN NEGERI MALANG', 'idt'),
('9087', 'PENGADILAN NEGERI SURABAYA', 'idt'),
('9140', 'RUMAH SAKIT HUSADA UTAMA SURABAYA', 'idt'),
('9341', 'TRIPILLAR JUANDA', 'idt'),
('9670', 'PENGADILAN AGAMA BANGIL', 'idt'),
('9789', 'BALAI PENDIDIKAN DAN PELATIHAN TRANSPORTASI LAUT JAKARTA SELATAN', 'idt');

-- --------------------------------------------------------

--
-- Struktur dari tabel `sla_bulanan`
--

CREATE TABLE `sla_bulanan` (
  `id` int(11) NOT NULL,
  `pelanggan_id` varchar(20) NOT NULL,
  `nama_pelanggan` varchar(255) NOT NULL,
  `template` varchar(50) NOT NULL,
  `periode` char(7) NOT NULL,
  `detik_periode` int(11) NOT NULL,
  `detik_downtime` int(11) NOT NULL DEFAULT 0,
  `uptime_persen` decimal(7,4) NOT NULL,
  `jumlah_insiden` int(11) NOT NULL DEFAULT 0,
  `trafik_min_mbps` decimal(12,4) DEFAULT NULL,
  `trafik_avg_mbps` decimal(12,4) DEFAULT NULL,
  `trafik_max_mbps` decimal(12,4) DEFAULT NULL,
  `kanal_trafik` varchar(120) DEFAULT NULL,
  `catatan` varchar(255) DEFAULT NULL,
  `file_docx` varchar(500) DEFAULT NULL,
  `job_id` varchar(50) DEFAULT NULL,
  `dibuat_pada` timestamp NOT NULL DEFAULT current_timestamp(),
  `diperbarui_pada` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `sla_downtime`
--

CREATE TABLE `sla_downtime` (
  `id` int(11) NOT NULL,
  `sla_id` int(11) NOT NULL,
  `mulai` datetime NOT NULL,
  `selesai` datetime NOT NULL,
  `detik` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indeks untuk tabel `jobs`
--
ALTER TABLE `jobs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_status_created` (`status`,`created_at`);

--
-- Indeks untuk tabel `job_files`
--
ALTER TABLE `job_files`
  ADD PRIMARY KEY (`id`);

--
-- Indeks untuk tabel `pelanggan`
--
ALTER TABLE `pelanggan`
  ADD PRIMARY KEY (`id`);

--
-- Indeks untuk tabel `sla_bulanan`
--
ALTER TABLE `sla_bulanan`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_sla` (`pelanggan_id`,`periode`,`template`),
  ADD KEY `idx_periode` (`periode`),
  ADD KEY `idx_pelanggan` (`pelanggan_id`);

--
-- Indeks untuk tabel `sla_downtime`
--
ALTER TABLE `sla_downtime`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sla` (`sla_id`);

--
-- AUTO_INCREMENT untuk tabel yang dibuang
--

--
-- AUTO_INCREMENT untuk tabel `job_files`
--
ALTER TABLE `job_files`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT untuk tabel `sla_bulanan`
--
ALTER TABLE `sla_bulanan`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `sla_downtime`
--
ALTER TABLE `sla_downtime`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Ketidakleluasaan untuk tabel pelimpahan (Dumped Tables)
--

--
-- Ketidakleluasaan untuk tabel `sla_downtime`
--
ALTER TABLE `sla_downtime`
  ADD CONSTRAINT `fk_sla_downtime` FOREIGN KEY (`sla_id`) REFERENCES `sla_bulanan` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
