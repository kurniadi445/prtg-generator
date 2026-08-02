<?php

/**
 * Klien PRTG.
 *
 * Semua urusan "ambil data dari PRTG dan ubah jadi bahan mentah laporan"
 * ada di sini. File ini tidak menyentuh PhpWord sama sekali, sehingga
 * bisa dites atau dipakai ulang tanpa membuat dokumen Word.
 */

require_once __DIR__ . '/database.php';

/**
 * Login ke PRTG. Cookie sesi disimpan di $cookie dan dipakai ulang oleh
 * prtgAmbil() untuk permintaan berikutnya.
 */
function prtgLogin(array $prtg, string $cookie): void
{
    $ch = curl_init(prtgBaseUrl($prtg) . 'index.htm');

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'username' => $prtg['username'],
            'password' => $prtg['password'],
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $cookie,
        CURLOPT_COOKIEFILE     => $cookie,
    ]);

    curl_exec($ch);

    $galat = curl_error($ch);
    $kode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($galat !== '') {
        throw new RuntimeException('Login PRTG gagal: ' . $galat);
    }

    if ($kode >= 400) {
        throw new RuntimeException('Login PRTG ditolak server (HTTP ' . $kode . ')');
    }
}

/**
 * Ambil satu URL PRTG memakai cookie sesi yang sudah ada.
 */
function prtgAmbil(string $url, string $cookie, bool $ikutiRedirect = false): string
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEFILE     => $cookie,
        CURLOPT_FOLLOWLOCATION => $ikutiRedirect,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36',
    ]);

    $isi   = curl_exec($ch);
    $galat = curl_error($ch);

    curl_close($ch);

    if ($isi === false) {
        throw new RuntimeException('Gagal mengambil data PRTG: ' . $galat);
    }

    return $isi;
}

/**
 * Base URL PRTG yang selalu berakhir dengan garis miring.
 */
function prtgBaseUrl(array $prtg): string
{
    if (empty($prtg['base_url'])) {
        throw new RuntimeException("Pengaturan PRTG 'base_url' kosong");
    }

    return rtrim($prtg['base_url'], '/') . '/';
}

/**
 * URL tabel data historis (HTML) untuk satu sensor dan satu bulan.
 */
function prtgUrlHistoris(array $prtg, string $idSensor, string $mulai, string $akhir): string
{
    return prtgBaseUrl($prtg) . 'historicdata_html.htm?' . http_build_query([
        'id'      => $idSensor,
        'sdate'   => $mulai,
        'edate'   => $akhir,
        'avg'     => 60,
        'pctavg'  => 300,
        'pctshow' => 'false',
        'pct'     => 95,
        'pctmode' => 'false',
        'hide'    => 'NaN',
    ]);
}

/**
 * URL grafik SVG. Parameter styling PRTG mengandung tanda kutip yang harus
 * tetap ter-encode apa adanya, jadi bagian itu ditulis manual.
 */
function prtgUrlGrafik(array $prtg, string $idSensor, string $mulai, string $akhir): string
{
    $dasar = prtgBaseUrl($prtg) . 'chart.svg?' . http_build_query([
        'graphid' => -1,
        'id'      => $idSensor,
        'avg'     => 60,
        'sdate'   => $mulai,
        'edate'   => $akhir,
        'clgid'   => '',
        'width'   => 850,
        'height'  => 270,
        'graphstylefile'          => 'graphstyling.htm',
        'animationandinteraction' => 1,
        'datastylefile'           => 'graphdatastyling.htm',
        'animationstylefile'      => 'graphanimationstyling.htm',
    ]);

    return $dasar
        . '&graphstyling=baseFontSize=%2711%27%20showLegend=%270%27%20tooltexts=%271%27'
        . '&datastyling=drawAnchors=%271%27%20anchorRadius=%271%27%20lineThickness=%272%27'
        . '&refreshable=true';
}

/**
 * Susun halaman HTML ringkas berisi tabel ringkasan, grafik, dan baris
 * sum/average. Halaman ini yang nanti dipotret Browsershot jadi PNG.
 */
function prtgSusunHalaman(DOMDocument $dom, DOMXPath $xpath, string $baseUrl, string $namaSvg): string
{
    $ambil = function (string $ekspresi) use ($dom, $xpath): string {
        $keluaran = '';

        foreach ($xpath->query($ekspresi) as $simpul) {
            $keluaran .= $dom->saveHTML($simpul);
        }

        return $keluaran;
    };

    $ambilSatu = function (string $ekspresi) use ($dom, $xpath): string {
        $simpul = $xpath->query($ekspresi)->item(0);

        return $simpul ? $dom->saveHTML($simpul) : '';
    };

    $gaya = '';

    foreach (['prtg.css' => 'print,screen,projection', 'report.css' => 'print,screen,projection', 'print.css' => 'print'] as $berkas => $media) {
        $gaya .= '<link href="' . $baseUrl . 'css/' . $berkas . '?version=17.3.33.2753+" media="' . $media . '" rel="stylesheet" type="text/css">';
    }

    return '<!DOCTYPE html><html><head><meta charset="utf-8">' . $gaya . '</head>'
        . '<body id="reportbody"><div class="onereport">'
        . $ambil("//*[contains(@class, 'overview') and contains(@class, 'table')]")
        . '<div class="reportgraph">'
        . '<img alt="Grafik" src="' . $namaSvg . '">'
        . $ambil("//*[contains(@class, 'reportgraph')]/div[2]")
        . '</div>'
        . '<div><form id="form_histdatatable">'
        . '<table cellspacing="0" class=" table hoverable histdata" id="table_histdatatable">'
        . $ambil("//*[contains(@id, 'table_histdatatable')]/colgroup")
        . $ambil("//*[contains(@id, 'table_histdatatable')]/thead[contains(@class, 'headersnolink')]")
        . '<tbody>'
        . $ambilSatu("//*[contains(@id, 'table_histdatatable')]//*[contains(@class, 'sums')]")
        . $ambilSatu("//*[contains(@id, 'table_histdatatable')]//*[contains(@class, 'averages')]")
        . '</tbody></table></form></div>'
        . '</div></body></html>';
}

/**
 * Baca tabel state report lalu kelompokkan seluruh downtime per jam.
 *
 * Rentang yang melewati pergantian jam dipecah lebih dulu, sehingga durasi
 * tiap jam tetap akurat. Kembalian: ['Y-m-d H:00:00' => total detik],
 * sudah terurut menaik.
 */
function prtgDowntimePerJam(DOMXPath $xpath): array
{
    $ember = [];

    $baris = $xpath->query(
        "//table[@id='table_statereporttable']/tbody/tr[td[1][contains(normalize-space(.), 'Down')]]"
    );

    foreach ($baris as $n) {
        $teks = trim($xpath->evaluate('string(td[2]/nobr)', $n));

        // PRTG memakai pemisah yang berbeda-beda antar versi (minus, en-dash,
        // em-dash, atau hyphen biasa), jadi semuanya diterima.
        $pola = '/^(\d{2}\/\d{2}\/\d{4}\s+\d{2}\.\d{2}\.\d{2})\s*[−–—-]\s*(\d{2}\/\d{2}\/\d{4}\s+\d{2}\.\d{2}\.\d{2})/u';

        if (!preg_match($pola, $teks, $cocok)) {
            continue;
        }

        $awal   = DateTime::createFromFormat('d/m/Y H.i.s', $cocok[1]);
        $selesai = DateTime::createFromFormat('d/m/Y H.i.s', $cocok[2]);

        if (!$awal || !$selesai || $selesai <= $awal) {
            continue;
        }

        $kursor = clone $awal;

        while ($kursor < $selesai) {
            $batasJam = (clone $kursor)
                ->setTime((int) $kursor->format('G'), 0, 0)
                ->modify('+1 hour');

            $potong = min($batasJam, $selesai);
            $kunci  = $kursor->format('Y-m-d H:00:00');

            $ember[$kunci] = ($ember[$kunci] ?? 0)
                + ($potong->getTimestamp() - $kursor->getTimestamp());

            $kursor = clone $potong;
        }
    }

    ksort($ember);

    return $ember;
}
