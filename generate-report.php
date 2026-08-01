<?php

require 'vendor/autoload.php';

require_once 'database.php';
require_once 'helpers.php';

use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\Shared\Converter;
use Spatie\Browsershot\Browsershot;

Settings::setOutputEscapingEnabled(true);

/**
 * Style font yang dipakai berulang di laporan.
 */
function fontStyle(int $size, bool $bold = false, string $name = 'Times New Roman'): array
{
    return ['name' => $name, 'size' => $size, 'bold' => $bold];
}

/**
 * Ubah total detik menjadi teks durasi, mis. "1 jam 16 menit 5 detik".
 */
function formatDurasi(int $detik): string
{
    $bagian = [];

    $hari  = intdiv($detik, 86400);
    $jam   = intdiv($detik % 86400, 3600);
    $menit = intdiv($detik % 3600, 60);
    $sisa  = $detik % 60;

    if ($hari)  { $bagian[] = $hari . ' hari'; }
    if ($jam)   { $bagian[] = $jam . ' jam'; }
    if ($menit) { $bagian[] = $menit . ' menit'; }
    if ($sisa)  { $bagian[] = $sisa . ' detik'; }

    return $bagian ? implode(' ', $bagian) : '0 detik';
}

/**
 * Buat laporan untuk rentang bulan (inklusif) lalu kembalikan daftar file.
 */
function generateReportRange($dari, $sampai, $idPelanggan, $jobId, $includeDowntime)
{
    $files = [];

    $mulai = new DateTime($dari . '-01');
    $akhir = new DateTime($sampai . '-01');

    $akhir->modify('+1 month');

    $interval = new DateInterval('P1M');

    $periode = new DatePeriod(
        $mulai,
        $interval,
        $akhir
    );

    foreach ($periode as $bulan) {
        $files[] = generateReport(
            $bulan->format('Y-m'),
            $idPelanggan,
            $jobId,
            $includeDowntime
        );
    }

    return $files;
}

function generateReport($bulan, $idPelanggan, $jobId, $includeDowntime = true)
{
    $bd = db();

    $perintah = $bd->prepare("
        SELECT nama
        FROM pelanggan
        WHERE id = ?
    ");

    $perintah->execute([$idPelanggan]);

    $namaPelanggan = $perintah->fetchColumn();

    if ($namaPelanggan === false) {
        throw new Exception('Pelanggan tidak valid');
    }

    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $bulan)) {
        throw new Exception('Bulan tidak valid');
    }

    $prtg   = config('prtg');
    $laporan = config('report');

    $baseUrl = rtrim($prtg['base_url'], '/') . '/';

    $tanggalMulai = date('Y-m-01-00-00-00', strtotime($bulan));
    $tanggalAkhir = date('Y-m-t-23-59-00', strtotime($bulan));

    $login = $baseUrl . 'index.htm';
    $dataHistoris = $baseUrl . 'historicdata_html.htm?id=' . $idPelanggan . '&sdate=' . $tanggalMulai . '&edate=' . $tanggalAkhir . '&avg=60&pctavg=300&pctshow=false&pct=95&pctmode=false&hide=NaN';
    $grafik = $baseUrl . 'chart.svg?graphid=-1&id=' . $idPelanggan . '&avg=60&sdate=' . $tanggalMulai . '&edate=' . $tanggalAkhir . '&clgid=&width=850&height=270&graphstylefile=graphstyling.htm&animationandinteraction=1&datastylefile=graphdatastyling.htm&animationstylefile=graphanimationstyling.htm&graphstyling=baseFontSize=%2711%27%20showLegend=%270%27%20tooltexts=%271%27&datastyling=drawAnchors=%271%27%20anchorRadius=%271%27%20lineThickness=%272%27&refreshable=true';

    // File sementara diberi token unik per job+bulan agar aman saat beberapa
    // laporan diproses bersamaan (tidak saling menimpa).
    if (!is_dir('tmp')) {
        mkdir('tmp', 0777, true);
    }

    $token    = $jobId . '-' . $bulan;
    $cookie   = "tmp/cookie-$token.txt";
    $svgName  = "grafik-$token.svg";
    $svgFile  = "tmp/$svgName";
    $htmlFile = "tmp/data-$token.html";
    $pngFile  = "tmp/data-$token.png";

    // login
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $login);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'username' => $prtg['username'],
        'password' => $prtg['password']
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie);

    curl_exec($ch);
    curl_close($ch);

    // data historis
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $dataHistoris);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie);

    $html = curl_exec($ch);

    curl_close($ch);

    $html = str_replace('</head>', '<base href="' . $baseUrl . '"></head>', $html);

    $dom = new DOMDocument();

    @$dom->loadHTML($html);

    $xpath = new DOMXPath($dom);

    $data = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <link href="' . $baseUrl . 'css/prtg.css?version=17.3.33.2753+" media="print,screen,projection" rel="stylesheet" type="text/css">
            <link href="' . $baseUrl . 'css/report.css?version=17.3.33.2753+" media="print,screen,projection" rel="stylesheet" type="text/css">
            <link href="' . $baseUrl . 'css/print.css?version=17.3.33.2753+" media="print" rel="stylesheet" type="text/css">
        </head>
        <body id="reportbody">
        <div class="onereport">
    ';

    $node = $xpath->query("//*[contains(@class, 'overview') and contains(@class, 'table')]");

    foreach ($node as $n) {
        $data .= $dom->saveHTML($n);
    }

    $data .= '
            <div class="reportgraph">
    ';

    $ch = curl_init($grafik);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36');

    $svg = curl_exec($ch);

    curl_close($ch);

    file_put_contents($svgFile, $svg);

    $data .= '<img alt="Grafik" src="' . $svgName . '">';

    $node = $xpath->query("//*[contains(@class, 'reportgraph')]/div[2]");

    foreach ($node as $n) {
        $data .= $dom->saveHTML($n);
    }

    $data .= '
            </div>
            <div>
                <form id="form_histdatatable">
                    <table cellspacing="0" class=" table hoverable histdata" id="table_histdatatable">
    ';

    $node = $xpath->query("//*[contains(@id, 'table_histdatatable')]/colgroup");

    foreach ($node as $n) {
        $data .= $dom->saveHTML($n);
    }

    $node = $xpath->query("//*[contains(@id, 'table_histdatatable')]/thead[contains(@class, 'headersnolink')]");

    foreach ($node as $n) {
        $data .= $dom->saveHTML($n);
    }

    $data .= '
                        <tbody>
    ';

    $data .= $dom->saveHTML($xpath->query("//*[contains(@id, 'table_histdatatable')]//*[contains(@class, 'sums')]")->item(0));
    $data .= $dom->saveHTML($xpath->query("//*[contains(@id, 'table_histdatatable')]//*[contains(@class, 'averages')]")->item(0));

    $data .= '
                        </tbody>
                    </table>
                </form>
            </div>
        </div>
        </body>
        </html>
    ';

    file_put_contents($htmlFile, $data);

    Browsershot::url($laporan['app_base_url'] . '/' . $htmlFile)
        ->windowSize(1920, 1080)
        ->save($pngFile);

    // generate laporan
    $intlDateFormatter = new IntlDateFormatter('id_ID', IntlDateFormatter::FULL, IntlDateFormatter::NONE);

    $intlDateFormatter->setPattern('MMMM yyyy');

    $phpWord = new PhpWord();

    $section = $phpWord->addSection();

    $section->addImage($laporan['watermark'], [
        'width' => Converter::cmToPoint(21),
        'height' => Converter::cmToPoint(29.7),
        'positioning' => 'absolute',
        'posHorizontal' => 'left',
        'posHorizontalRel' => 'page',
        'posVertical' => 'top',
        'posVerticalRel' => 'page',
        'wrappingStyle' => 'behind'
    ]);

    for ($i = 0; $i < 16; $i++) {
        $section->addTextBreak();
    }

    $section->addText(strtoupper($intlDateFormatter->format(strtotime($bulan))), fontStyle(36));

    $section->addTextBreak();
    $section->addTextBreak();

    $section->addText('Dibuat Untuk:', fontStyle(22));

    $section->addTextBreak();

    $section->addText($namaPelanggan, fontStyle(28, true));

    $section = $phpWord->addSection();

    $section->addText('TRAFFIC ' . $namaPelanggan . ' 1-' . date('t', strtotime($bulan)) . ' ' . strtoupper($intlDateFormatter->format(strtotime($bulan))), fontStyle(16, true));

    $section->addImage($pngFile, [
        'width' => Converter::cmToPoint(15.92)
    ]);

    $section->addText('Rekap Log Downtime', fontStyle(11, true));

    $phpWord->addTableStyle('Rekap Log Downtime', [
        'borderSize' => 1,
        'cellMarginBottom' => 108,
        'cellMarginLeft' => 108,
        'cellMarginRight' => 108,
        'cellMarginTop' => 108
    ]);

    $tabel = $section->addTable('Rekap Log Downtime');

    $tambahHeader = function (int $width, string $teks) use ($tabel) {
        $tabel->addCell($width)->addText($teks, fontStyle(11, true), ['align' => 'center', 'spaceAfter' => 0]);
    };

    $tabel->addRow();
    $tambahHeader(805, 'No.');
    $tambahHeader(2546, 'Start Downtime');
    $tambahHeader(2421, 'End Downtime');
    $tambahHeader(1270, 'Durasi');
    $tambahHeader(1979, 'Keterangan');

    $node = $xpath->query("//table[@id='table_statereporttable']/tbody/tr[td[1][contains(normalize-space(.), 'Down')]]");

    // Kelompokkan downtime per jam. Kunci = awal jam, nilai = total detik down.
    $ember = [];

    if ($includeDowntime) {
        foreach ($node as $n) {
            $datetime = trim($xpath->evaluate("string(td[2]/nobr)", $n));

            if (!preg_match('/^(\d{2}\/\d{2}\/\d{4}\s+\d{2}\.\d{2}\.\d{2})\s*[−–—-]\s*(\d{2}\/\d{2}\/\d{4}\s+\d{2}\.\d{2}\.\d{2})/u', $datetime, $cocok)) {
                continue;
            }

            $awalDown  = DateTime::createFromFormat('d/m/Y H.i.s', $cocok[1]);
            $akhirDown = DateTime::createFromFormat('d/m/Y H.i.s', $cocok[2]);

            if (!$awalDown || !$akhirDown || $akhirDown <= $awalDown) {
                continue;
            }

            // Pecah rentang di setiap batas jam agar durasi tiap ember akurat.
            $kursor = clone $awalDown;

            while ($kursor < $akhirDown) {
                $batasJam = (clone $kursor)
                    ->setTime((int) $kursor->format('G'), 0, 0)
                    ->modify('+1 hour');

                $potong = min($batasJam, $akhirDown);
                $kunci  = $kursor->format('Y-m-d H:00:00');

                $ember[$kunci] = ($ember[$kunci] ?? 0)
                    + ($potong->getTimestamp() - $kursor->getTimestamp());

                $kursor = clone $potong;
            }
        }

        ksort($ember);
    }

    if ($ember) {
        $nomor = 1;

        foreach ($ember as $awalJam => $totalDetik) {
            $emberMulai   = new DateTime($awalJam);
            $emberSelesai = (clone $emberMulai)->modify('+1 hour');

            $tabel->addRow();
            $tabel->addCell()->addText($nomor, [], ['align' => 'center']);
            $tabel->addCell()->addText($emberMulai->format('d/m/Y H:i:s'), [], ['align' => 'center']);
            $tabel->addCell()->addText($emberSelesai->format('d/m/Y H:i:s'), [], ['align' => 'center']);
            $tabel->addCell()->addText(formatDurasi($totalDetik), [], ['align' => 'center']);
            $tabel->addCell();

            $nomor++;
        }
    } else {
        $tabel->addRow();
        $tabel->addCell(805);
        $tabel->addCell(2546);
        $tabel->addCell(2421);
        $tabel->addCell(1270);
        $tabel->addCell(1979);
    }

    $section->addTextBreak();
    $section->addTextBreak();

    $phpWord->addTableStyle('Persetujuan', [
        'align'      => 'right',
        'borderSize' => 1,
    ]);

    $ttd  = $laporan['signature'];
    $fCal = fontStyle(11, false, 'Calibri');
    $fPol = ['name' => 'Calibri', 'size' => 11];
    $fGrs = ['name' => 'Calibri', 'size' => 11, 'underline' => 'single'];
    $pTtd = ['align' => 'center', 'spaceAfter' => 0];

    $tabel = $section->addTable('Persetujuan');

    $tabel->addRow();
    $tabel->addCell(2268)->addText($ttd['left']['heading'], $fCal, $pTtd);
    $tabel->addCell(2268)->addText($ttd['right']['heading'], $fCal, $pTtd);

    $tabel->addRow(1134);
    $tabel->addCell(2268);
    $tabel->addCell(2268);

    $sel = $tabel->addRow()->addCell(2268);
    $sel->addText($ttd['left']['name'],  $fGrs, $pTtd);
    $sel->addText($ttd['left']['title'], $fCal, $pTtd);

    $sel = $tabel->addCell(2268);
    $sel->addText($ttd['right']['name'],  $fPol, $pTtd);
    $sel->addText($ttd['right']['title'], $fPol, $pTtd);

    $phpWord->addTableStyle('Approval', [
        'align'           => 'left',
        'cellMarginLeft'  => 54,
        'cellMarginRight' => 54,
    ]);

    $footer = $section->addFooter();

    $footer->addText('Approval :', fontStyle(8, true, 'Calibri'), ['spaceAfter' => 0]);

    $tblApproval = $footer->addTable('Approval');

    $gayaKotak = [
        'valign'      => 'center',
        'borderSize'  => 6,
        'borderColor' => '000000',
    ];

    foreach ($laporan['approval'] as $orang) {
        $baris = $tblApproval->addRow(400);

        $baris->addCell(400, $gayaKotak);   // kotak centang kosong

        $sel = $baris->addCell(8000, ['valign' => 'center']);

        $sel->addText($orang['name'],  fontStyle(8, true, 'Calibri'), ['spaceAfter' => 0]);
        $sel->addText($orang['title'], ['name' => 'Calibri', 'size' => 8], ['spaceAfter' => 0]);
    }

    $intlDateFormatter->setPattern('yyyy-MM');

    $namaFile = strtoupper($intlDateFormatter->format(strtotime($bulan)))
        . ' - ' . sanitizeFolderName($namaPelanggan) . '.docx';

    $folder = 'jobs/' . sanitizeFolderName($namaPelanggan);

    if (!is_dir($folder)) {
        mkdir($folder, 0777, true);
    }

    $penulisObjek = IOFactory::createWriter($phpWord, 'Word2007');

    $penulisObjek->save($folder . '/' . $namaFile);

    // Bersihkan file sementara milik token ini
    foreach ([$cookie, $svgFile, $htmlFile, $pngFile] as $tmp) {
        if (is_file($tmp)) {
            unlink($tmp);
        }
    }

    return $namaFile;
}
