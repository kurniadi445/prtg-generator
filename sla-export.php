<?php

/**
 * Ekspor rekap SLA (Service Level Agreement) ke berkas Excel (.xlsx).
 *
 * Dipanggil dari tombol "Ekspor Excel" di sla.php, mengikuti penyaring yang
 * sedang aktif di halaman itu (template dan pencarian nama).
 *
 * Isi berkasnya:
 *   - satu lembar per tahun, tata letaknya sama dengan yang tampil di layar:
 *     baris = pelanggan, kolom = Januari sampai Desember
 *   - satu lembar "Data Rinci" berisi data mendatar (satu baris per periode),
 *     lengkap dengan downtime dan trafik — bentuk ini yang enak dipakai kalau
 *     Anda ingin membuat PivotTable atau grafik sendiri di Excel
 *
 * Butuh pustaka PhpSpreadsheet:
 *     composer require phpoffice/phpspreadsheet
 */

require 'vendor/autoload.php';

require_once 'helpers.php';
require_once 'database.php';
require_once 'sla-store.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$gagal = function (int $kode, string $pesan): void {
    http_response_code($kode);
    header('Content-Type: text/html; charset=utf-8');
    echo '<p style="font:14px Arial;padding:20px;line-height:1.6;">' . $pesan
        . '<br><br><a href="sla.php">&larr; Kembali ke Rekap SLA</a></p>';
    exit;
};

// Pesan yang jelas lebih berguna daripada "Class not found" dari autoloader.
if (!class_exists(Spreadsheet::class)) {
    $gagal(500,
        '<b>Pustaka PhpSpreadsheet belum terpasang.</b><br>'
        . 'Buka Command Prompt di folder aplikasi ini, lalu jalankan:<br>'
        . '<code style="background:#f2f5f9;padding:2px 6px;">composer require phpoffice/phpspreadsheet</code>'
    );
}

// ---------------------------------------------------------------------
// Ambil data — fungsi yang sama persis dengan yang dipakai sla.php
// ---------------------------------------------------------------------
$labelTemplate = daftarTemplate();

$templateF = (string) ($_GET['template'] ?? '');

if (!isset($labelTemplate[$templateF])) {
    $templateF = '';
}

$cariNama = trim((string) ($_GET['q'] ?? ''));
$target   = slaTarget();

try {
    $data = slaAmbilData($templateF, $cariNama);
} catch (Throwable $e) {
    $gagal(500, 'Gagal membaca data SLA: ' . htmlspecialchars($e->getMessage())
        . '<br>Pastikan <code>sql/sla-upgrade.sql</code> sudah dijalankan.');
}

if (!$data['tahunan']) {
    $gagal(404, 'Belum ada data SLA yang cocok dengan penyaring ini, jadi tidak ada yang bisa diekspor.');
}

// ---------------------------------------------------------------------
// Gaya yang dipakai berulang
// ---------------------------------------------------------------------
const WARNA_KEPALA = 'FFEFF3F8';
const WARNA_GARIS  = 'FFD9DDE3';
const WARNA_AMAN_L = 'FFE7F6EC';
const WARNA_AMAN_T = 'FF198754';
const WARNA_TURUN_L = 'FFFDEAEC';
const WARNA_TURUN_T = 'FFDC3545';
const WARNA_REDUP  = 'FF8A909A';

/**
 * Isi satu sel dengan warna latar dan warna huruf tertentu.
 */
function warnaiSel(Worksheet $lembar, string $sel, string $latar, string $huruf, bool $tebal = false): void
{
    $gaya = $lembar->getStyle($sel);

    $gaya->getFill()
        ->setFillType(Fill::FILL_SOLID)
        ->getStartColor()->setARGB($latar);

    $gaya->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($huruf))->setBold($tebal);
}

$namaBulan = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
              'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

$keteranganSaring = 'Template: ' . ($templateF === '' ? 'semua' : $labelTemplate[$templateF])
    . ($cariNama === '' ? '' : ' · Pencarian nama: "' . $cariNama . '"')
    . ' · Ambang SLA: ' . number_format($target, 2, ',', '.') . '%'
    . ' · Dibuat: ' . date('d/m/Y H:i');

$spreadsheet = new Spreadsheet();

$spreadsheet->getProperties()
    ->setTitle('Rekap SLA')
    ->setSubject('Rekap SLA bulanan dari PRTG Generator')
    ->setDescription($keteranganSaring);

// ---------------------------------------------------------------------
// Lembar pivot, satu per tahun
// ---------------------------------------------------------------------
$indeks = 0;

foreach ($data['tahunan'] as $tahun => $isi) {
    $lembar = $indeks === 0 ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet();
    $lembar->setTitle('SLA ' . $tahun);

    // Kolom: A nama, B template, C..N bulan 1..12, O rata-rata
    $kolomBulan = [];

    for ($m = 1; $m <= 12; $m++) {
        $kolomBulan[$m] = Coordinate::stringFromColumnIndex(2 + $m);   // C untuk Januari
    }

    $kolomRata = Coordinate::stringFromColumnIndex(15);                // O

    // --- Judul ---
    $lembar->setCellValue('A1', 'Rekap SLA — Tahun ' . $tahun);
    $lembar->getStyle('A1')->getFont()->setBold(true)->setSize(14);

    $lembar->setCellValue('A2', $keteranganSaring);
    $lembar->getStyle('A2')->getFont()->setSize(9)
        ->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(WARNA_REDUP));

    $lembar->setCellValue('A3', 'Angka pada sel = persentase uptime. Hijau memenuhi ambang, merah di bawahnya, tanda "–" berarti bulan itu belum pernah dibuatkan laporan.');
    $lembar->getStyle('A3')->getFont()->setSize(9)
        ->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(WARNA_REDUP));

    // --- Baris kepala ---
    $barisKepala = 5;

    $lembar->setCellValue('A' . $barisKepala, 'Pelanggan');
    $lembar->setCellValue('B' . $barisKepala, 'Template');

    for ($m = 1; $m <= 12; $m++) {
        $lembar->setCellValue($kolomBulan[$m] . $barisKepala, $namaBulan[$m]);
    }

    $lembar->setCellValue($kolomRata . $barisKepala, 'Rata-rata');

    $rentangKepala = 'A' . $barisKepala . ':' . $kolomRata . $barisKepala;

    $lembar->getStyle($rentangKepala)->getFont()->setBold(true);
    $lembar->getStyle($rentangKepala)->getFill()
        ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(WARNA_KEPALA);
    $lembar->getStyle($rentangKepala)->getAlignment()
        ->setHorizontal(Alignment::HORIZONTAL_CENTER)
        ->setVertical(Alignment::VERTICAL_CENTER);
    $lembar->getRowDimension($barisKepala)->setRowHeight(28);

    $lembar->getStyle('A' . $barisKepala . ':B' . $barisKepala)->getAlignment()
        ->setHorizontal(Alignment::HORIZONTAL_LEFT);

    // --- Isi ---
    $baris = $barisKepala + 1;

    foreach ($isi['baris'] as $b) {
        $lembar->setCellValue('A' . $baris, $b['nama']);
        $lembar->setCellValue('B' . $baris, $b['template']);

        $nilai = [];

        for ($m = 1; $m <= 12; $m++) {
            $sel = $kolomBulan[$m] . $baris;

            if (!isset($b['sel'][$m])) {
                $lembar->setCellValue($sel, '–');
                $lembar->getStyle($sel)->getFont()
                    ->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(WARNA_REDUP));

                continue;
            }

            $angka   = (float) $b['sel'][$m]['uptime_persen'];
            $nilai[] = $angka;

            // Disimpan sebagai ANGKA, bukan teks, supaya tetap bisa dihitung
            // dan dibuat grafik langsung di Excel.
            $lembar->setCellValue($sel, $angka);
            $lembar->getStyle($sel)->getNumberFormat()->setFormatCode('0.000');

            // Toleransi kecil supaya 99,5 tepat tidak terbaca sebagai di bawah ambang.
            if ($angka + 0.00005 >= $target) {
                warnaiSel($lembar, $sel, WARNA_AMAN_L, WARNA_AMAN_T);
            } else {
                warnaiSel($lembar, $sel, WARNA_TURUN_L, WARNA_TURUN_T, true);
            }
        }

        $selRata = $kolomRata . $baris;

        if ($nilai) {
            $lembar->setCellValue($selRata, array_sum($nilai) / count($nilai));
            $lembar->getStyle($selRata)->getNumberFormat()->setFormatCode('0.000');
        } else {
            $lembar->setCellValue($selRata, '–');
        }

        $lembar->getStyle($selRata)->getFont()->setBold(true);

        $baris++;
    }

    // --- Baris rata-rata seluruh pelanggan ---
    $barisRata = $baris;

    $lembar->setCellValue('A' . $barisRata, 'Rata-rata seluruh pelanggan');

    for ($m = 1; $m <= 12; $m++) {
        $sel = $kolomBulan[$m] . $barisRata;

        if (empty($isi['perBulan'][$m])) {
            $lembar->setCellValue($sel, '–');

            continue;
        }

        $lembar->setCellValue($sel, array_sum($isi['perBulan'][$m]) / count($isi['perBulan'][$m]));
        $lembar->getStyle($sel)->getNumberFormat()->setFormatCode('0.000');
    }

    $rentangRata = 'A' . $barisRata . ':' . $kolomRata . $barisRata;

    $lembar->getStyle($rentangRata)->getFont()->setBold(true);
    $lembar->getStyle($rentangRata)->getFill()
        ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(WARNA_KEPALA);

    // --- Garis, perataan, lebar kolom ---
    $rentangSemua = 'A' . $barisKepala . ':' . $kolomRata . $barisRata;

    $lembar->getStyle($rentangSemua)->getBorders()->getAllBorders()
        ->setBorderStyle(Border::BORDER_THIN)
        ->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(WARNA_GARIS));

    $lembar->getStyle('C' . ($barisKepala + 1) . ':' . $kolomRata . $barisRata)
        ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    $lembar->getColumnDimension('A')->setWidth(46);
    $lembar->getColumnDimension('B')->setWidth(11);

    for ($m = 1; $m <= 12; $m++) {
        $lembar->getColumnDimension($kolomBulan[$m])->setWidth(11);
    }

    $lembar->getColumnDimension($kolomRata)->setWidth(12);

    // Nama pelanggan tetap terlihat saat digulir ke kanan maupun ke bawah.
    $lembar->freezePane('C' . ($barisKepala + 1));

    $lembar->setSelectedCell('A1');

    $indeks++;
}

// ---------------------------------------------------------------------
// Lembar "Data Rinci" — bentuk mendatar, siap dijadikan PivotTable
// ---------------------------------------------------------------------
$rinci = $spreadsheet->createSheet();
$rinci->setTitle('Data Rinci');

$kepala = [
    'Pelanggan', 'Template', 'Periode', 'Tahun', 'Bulan',
    'Uptime (%)', 'Downtime (jam:menit:detik)', 'Downtime (detik)', 'Jumlah Insiden',
    'Trafik Min (Mbps)', 'Trafik Rata-rata (Mbps)', 'Trafik Maks (Mbps)', 'Kanal Trafik',
];

foreach ($kepala as $i => $teks) {
    $rinci->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . '1', $teks);
}

$kolomAkhir = Coordinate::stringFromColumnIndex(count($kepala));

$rinci->getStyle('A1:' . $kolomAkhir . '1')->getFont()->setBold(true);
$rinci->getStyle('A1:' . $kolomAkhir . '1')->getFill()
    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(WARNA_KEPALA);
$rinci->getStyle('A1:' . $kolomAkhir . '1')->getAlignment()
    ->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
$rinci->getRowDimension(1)->setRowHeight(32);

$baris = 2;

foreach ($data['tahunan'] as $tahun => $isi) {
    foreach ($isi['baris'] as $b) {
        for ($m = 1; $m <= 12; $m++) {
            if (!isset($b['sel'][$m])) {
                continue;
            }

            $r = $b['sel'][$m];

            $rinci->setCellValue('A' . $baris, $r['nama_pelanggan']);
            $rinci->setCellValue('B' . $baris, $r['template']);

            // Periode ditulis sebagai teks agar tidak diubah Excel jadi tanggal.
            $rinci->setCellValueExplicit(
                'C' . $baris,
                $r['periode'],
                \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
            );

            $rinci->setCellValue('D' . $baris, (int) $tahun);
            $rinci->setCellValue('E' . $baris, $namaBulan[$m]);
            $rinci->setCellValue('F' . $baris, (float) $r['uptime_persen']);

            $rinci->setCellValueExplicit(
                'G' . $baris,
                slaJamMenitDetik((int) $r['detik_downtime']),
                \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
            );

            $rinci->setCellValue('H' . $baris, (int) $r['detik_downtime']);
            $rinci->setCellValue('I' . $baris, (int) $r['jumlah_insiden']);

            foreach (['J' => 'trafik_min_mbps', 'K' => 'trafik_avg_mbps', 'L' => 'trafik_max_mbps'] as $kol => $medan) {
                if ($r[$medan] !== null) {
                    $rinci->setCellValue($kol . $baris, (float) $r[$medan]);
                }
            }

            $rinci->setCellValue('M' . $baris, (string) ($r['kanal_trafik'] ?? ''));

            $baris++;
        }
    }
}

$barisAkhir = $baris - 1;

if ($barisAkhir >= 2) {
    $rinci->getStyle('F2:F' . $barisAkhir)->getNumberFormat()->setFormatCode('0.0000');
    $rinci->getStyle('J2:L' . $barisAkhir)->getNumberFormat()->setFormatCode('#,##0.000');

    $rinci->getStyle('A1:' . $kolomAkhir . $barisAkhir)->getBorders()->getAllBorders()
        ->setBorderStyle(Border::BORDER_THIN)
        ->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(WARNA_GARIS));
}

$rinci->setAutoFilter('A1:' . $kolomAkhir . max(1, $barisAkhir));
$rinci->freezePane('A2');

$lebar = ['A' => 46, 'B' => 11, 'C' => 11, 'D' => 8, 'E' => 12, 'F' => 12,
          'G' => 22, 'H' => 16, 'I' => 14, 'J' => 16, 'K' => 18, 'L' => 16, 'M' => 26];

foreach ($lebar as $kol => $w) {
    $rinci->getColumnDimension($kol)->setWidth($w);
}

$rinci->setSelectedCell('A1');

// Lembar tahun terbaru yang terbuka lebih dulu saat berkas dibuka.
$spreadsheet->setActiveSheetIndex(0);

// ---------------------------------------------------------------------
// Kirim ke browser
// ---------------------------------------------------------------------
$namaFile = 'Rekap SLA'
    . ($templateF === '' ? '' : ' - ' . $labelTemplate[$templateF])
    . ' - ' . date('Y-m-d') . '.xlsx';

// Buang sisa buffer apa pun supaya tidak mengotori isi berkas.
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $namaFile) . '"');
header('Cache-Control: max-age=0');

(new Xlsx($spreadsheet))->save('php://output');

$spreadsheet->disconnectWorksheets();

exit;
