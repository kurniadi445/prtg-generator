<?php

/**
 * Alur pembuatan laporan.
 *
 * File ini hanya mengatur urutan kerja. Rinciannya ada di:
 *   prtg.php          - login, ambil data, parse downtime
 *   report-blocks.php - blok dokumen yang dipakai bersama template
 *   templates/*.php   - tata letak per template
 */

require 'vendor/autoload.php';

require_once 'database.php';
require_once 'helpers.php';
require_once 'prtg.php';
require_once 'report-blocks.php';

use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use Spatie\Browsershot\Browsershot;

// PhpWord tidak meng-escape XML secara default, sehingga nama pelanggan
// yang mengandung & < > akan merusak dokumen. Wajib diaktifkan.
Settings::setOutputEscapingEnabled(true);

/**
 * Ambil pengaturan satu template dan jalankan berkas tata letaknya.
 *
 * Nama template selalu divalidasi terhadap daftar di config('templates'),
 * jadi nilai dari database tidak pernah dipakai langsung sebagai path.
 */
function renderTemplate(array $konteks): PhpWord
{
    $nama   = $konteks['template'];
    $daftar = (array) config('templates');

    if (!isset($daftar[$nama])) {
        throw new RuntimeException("Template '$nama' tidak terdaftar di config('templates')");
    }

    $berkas = __DIR__ . '/templates/' . $nama . '.php';

    if (!is_file($berkas)) {
        throw new RuntimeException("Berkas template tidak ditemukan: templates/$nama.php");
    }

    $render = require $berkas;

    if (!($render instanceof Closure)) {
        throw new RuntimeException("templates/$nama.php harus mengembalikan sebuah closure");
    }

    $dokumen = $render($konteks);

    if (!($dokumen instanceof PhpWord)) {
        throw new RuntimeException("templates/$nama.php harus mengembalikan objek PhpWord");
    }

    return $dokumen;
}

/**
 * Ambil data pelanggan sekaligus tentukan template dan pengaturan PRTG-nya.
 */
function konfigurasiPelanggan(string $idPelanggan): array
{
    $perintah = db()->prepare('SELECT nama, template FROM pelanggan WHERE id = ?');
    $perintah->execute([$idPelanggan]);

    $pelanggan = $perintah->fetch(PDO::FETCH_ASSOC);

    if ($pelanggan === false) {
        throw new RuntimeException('Pelanggan tidak valid');
    }

    $laporan  = config('report');
    $template = $pelanggan['template'] ?: ($laporan['default_template'] ?? 'idt');
    $daftar   = (array) config('templates');

    if (!isset($daftar[$template])) {
        throw new RuntimeException("Template '$template' tidak terdaftar di config('templates')");
    }

    $opsi = $daftar[$template];

    return [
        'nama'     => $pelanggan['nama'],
        'template' => $template,
        'opsi'     => $opsi,
        // Template boleh menimpa sebagian atau seluruh pengaturan PRTG.
        'prtg'     => array_merge((array) config('prtg'), $opsi['prtg'] ?? []),
        'report'   => $laporan,
    ];
}

/**
 * Ambil data satu bulan dari PRTG lalu kembalikan bahan mentah laporan:
 * path PNG hasil potret dan rekap downtime per jam.
 *
 * $sementara diisi dengan daftar berkas sementara yang dibuat, agar
 * pemanggil bisa membersihkannya walau terjadi error.
 */
function ambilDataPrtg(array $prtg, array $laporan, string $idSensor, string $bulan, string $token, bool $includeDowntime, array &$sementara): array
{
    if (!is_dir('tmp')) {
        mkdir('tmp', 0777, true);
    }

    $cookie   = "tmp/cookie-$token.txt";
    $namaSvg  = "grafik-$token.svg";
    $svgFile  = "tmp/$namaSvg";
    $htmlFile = "tmp/data-$token.html";
    $pngFile  = "tmp/data-$token.png";

    $sementara = [$cookie, $svgFile, $htmlFile];

    $baseUrl = prtgBaseUrl($prtg);
    $mulai   = date('Y-m-01-00-00-00', strtotime($bulan));
    $akhir   = date('Y-m-t-23-59-00', strtotime($bulan));

    prtgLogin($prtg, $cookie);

    $html = prtgAmbil(prtgUrlHistoris($prtg, $idSensor, $mulai, $akhir), $cookie);
    $html = str_replace('</head>', '<base href="' . $baseUrl . '"></head>', $html);

    $dom = new DOMDocument();
    @$dom->loadHTML($html);

    $xpath = new DOMXPath($dom);

    file_put_contents($svgFile, prtgAmbil(prtgUrlGrafik($prtg, $idSensor, $mulai, $akhir), $cookie, true));
    file_put_contents($htmlFile, prtgSusunHalaman($dom, $xpath, $baseUrl, $namaSvg));

    Browsershot::url($laporan['app_base_url'] . '/' . $htmlFile)
        ->windowSize(1920, 1080)
        ->save($pngFile);

    $sementara[] = $pngFile;

    return [
        'gambar'   => $pngFile,
        'downtime' => $includeDowntime ? prtgDowntimePerJam($xpath) : [],
    ];
}

/**
 * Buat laporan untuk rentang bulan (inklusif) lalu kembalikan daftar berkas.
 */
function generateReportRange($dari, $sampai, $idPelanggan, $jobId, $includeDowntime)
{
    $files = [];

    $mulai = new DateTime($dari . '-01');
    $akhir = (new DateTime($sampai . '-01'))->modify('+1 month');

    $periode = new DatePeriod($mulai, new DateInterval('P1M'), $akhir);

    foreach ($periode as $bulan) {
        $files[] = generateReport($bulan->format('Y-m'), $idPelanggan, $jobId, $includeDowntime);
    }

    return $files;
}

/**
 * Buat laporan satu bulan. Mengembalikan nama berkas .docx yang dihasilkan.
 */
function generateReport($bulan, $idPelanggan, $jobId, $includeDowntime = true)
{
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $bulan)) {
        throw new RuntimeException('Bulan tidak valid');
    }

    $pelanggan = konfigurasiPelanggan((string) $idPelanggan);
    $sementara = [];

    try {
        $data = ambilDataPrtg(
            $pelanggan['prtg'],
            $pelanggan['report'],
            (string) $idPelanggan,
            $bulan,
            $jobId . '-' . $bulan,
            $includeDowntime,
            $sementara
        );

        $waktu     = strtotime($bulan);
        $formatter = new IntlDateFormatter('id_ID', IntlDateFormatter::FULL, IntlDateFormatter::NONE);

        $formatter->setPattern('MMMM');
        $namaBulan = $formatter->format($waktu);

        $formatter->setPattern('MMMM yyyy');
        $bulanTahun = $formatter->format($waktu);

        $formatter->setPattern('yyyy-MM');
        $kodeBulan = $formatter->format($waktu);

        $dokumen = renderTemplate([
            'template'   => $pelanggan['template'],
            'opsi'       => $pelanggan['opsi'],
            'report'     => $pelanggan['report'],
            'nama'       => $pelanggan['nama'],
            'bulan'      => $bulan,              // 'YYYY-MM'
            'waktu'      => $waktu,              // timestamp tanggal 1
            'namaBulan'  => $namaBulan,          // 'Juli'
            'bulanTahun' => $bulanTahun,         // 'Juli 2026'
            'tahun'      => date('Y', $waktu),
            'jumlahHari' => date('t', $waktu),
            'gambar'     => $data['gambar'],
            'downtime'   => $data['downtime'],
        ]);

        $namaAman = sanitizeFolderName($pelanggan['nama']);
        $namaFile = strtoupper($kodeBulan) . ' - ' . $namaAman . '.docx';
        $folder   = 'jobs/' . $namaAman;

        if (!is_dir($folder)) {
            mkdir($folder, 0777, true);
        }

        IOFactory::createWriter($dokumen, 'Word2007')->save($folder . '/' . $namaFile);

        return $namaFile;
    } finally {
        // Dibersihkan juga saat terjadi error, supaya tmp/ tidak menumpuk.
        foreach ($sementara as $berkas) {
            if (is_file($berkas)) {
                unlink($berkas);
            }
        }
    }
}
