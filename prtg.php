<?php

/**
 * Klien PRTG (Paessler Router Traffic Grapher).
 *
 * Semua urusan "ambil data dari PRTG dan ubah jadi bahan mentah laporan"
 * ada di sini. File ini tidak menyentuh PhpWord sama sekali, sehingga
 * bisa dites atau dipakai ulang tanpa membuat dokumen Word.
 *
 * PERUBAHAN dari versi sebelumnya:
 *   + prtgDowntimeMentah()    - daftar kejadian DOWN apa adanya (untuk database)
 *   + prtgDowntimePerJam()    - kini dibangun di atas fungsi di atas, tidak lagi
 *                               mengulang regex sendiri
 *   + prtgRingkasanTrafik()   - min/rata-rata/maks trafik dalam Mbps, untuk rekap SLA
 */

require_once __DIR__ . '/database.php';

// Batas waktu permintaan ke PRTG.
//
// Bawaan cURL adalah TANPA batas. Bila PRTG menggantung — bukan menolak —
// worker ikut menggantung selamanya di satu job: heartbeat berhenti, job tetap
// 'processing', dan dari luar tidak terbedakan dari worker yang mati.
// Lebih baik job itu gagal dengan pesan jelas daripada antrean berhenti total.
const PRTG_TIMEOUT_SAMBUNG = 15;    // detik untuk membangun koneksi
const PRTG_TIMEOUT_TOTAL   = 180;   // detik untuk seluruh permintaan

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
        CURLOPT_CONNECTTIMEOUT => PRTG_TIMEOUT_SAMBUNG,
        CURLOPT_TIMEOUT        => PRTG_TIMEOUT_TOTAL,
    ]);

    curl_exec($ch);

    $galat    = curl_error($ch);
    $kode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $tujuan   = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);

    curl_close($ch);

    if ($galat !== '') {
        throw new RuntimeException('Login PRTG gagal: ' . $galat);
    }

    if ($kode >= 400) {
        throw new RuntimeException('Login PRTG ditolak server (HTTP ' . $kode . ')');
    }

    // PRTG membalas kredensial yang salah dengan 302 ke index.htm?errormsg=...,
    // bukan dengan kode error. Tanpa pemeriksaan ini laporan tetap dibuat —
    // dari halaman login, bukan dari data sensor — dan job dinyatakan berhasil
    // padahal isinya kosong.
    //
    // Hanya bukti positif kegagalan yang dijadikan alasan melempar, supaya
    // login yang sebenarnya berhasil tidak ikut ditolak.
    if (stripos($tujuan, 'errormsg=') !== false) {
        parse_str((string) parse_url($tujuan, PHP_URL_QUERY), $kueri);

        throw new RuntimeException(
            'Login PRTG ditolak: ' . trim(strip_tags((string) ($kueri['errormsg'] ?? 'kredensial salah')))
        );
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
        CURLOPT_CONNECTTIMEOUT => PRTG_TIMEOUT_SAMBUNG,
        CURLOPT_TIMEOUT        => PRTG_TIMEOUT_TOTAL,
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
 * Daftar kejadian DOWN apa adanya, tanpa dibulatkan dan tanpa dikelompokkan.
 *
 * Kembalian: array of ['mulai' => DateTime, 'selesai' => DateTime, 'detik' => int],
 * terurut menaik menurut waktu mulai.
 *
 * Inilah yang disimpan ke tabel `sla_downtime`. Pengelompokan per jam
 * (prtgDowntimePerJam) hanya dipakai untuk tabel di dokumen Word.
 */
function prtgDowntimeMentah(DOMXPath $xpath): array
{
    $hasil = [];

    $baris = $xpath->query(
        "//table[@id='table_statereporttable']/tbody/tr[td[1][contains(normalize-space(.), 'Down')]]"
    );

    // PRTG memakai pemisah yang berbeda-beda antar versi (minus, en-dash,
    // em-dash, atau hyphen biasa), jadi semuanya diterima.
    $pola = '/^(\d{2}\/\d{2}\/\d{4}\s+\d{2}\.\d{2}\.\d{2})\s*[−–—-]\s*(\d{2}\/\d{2}\/\d{4}\s+\d{2}\.\d{2}\.\d{2})/u';

    foreach ($baris as $n) {
        $teks = trim($xpath->evaluate('string(td[2]/nobr)', $n));

        if (!preg_match($pola, $teks, $cocok)) {
            continue;
        }

        $awal    = DateTime::createFromFormat('d/m/Y H.i.s', $cocok[1]);
        $selesai = DateTime::createFromFormat('d/m/Y H.i.s', $cocok[2]);

        if (!$awal || !$selesai || $selesai <= $awal) {
            continue;
        }

        $hasil[] = [
            'mulai'   => $awal,
            'selesai' => $selesai,
            'detik'   => $selesai->getTimestamp() - $awal->getTimestamp(),
        ];
    }

    usort($hasil, fn(array $a, array $b): int => $a['mulai'] <=> $b['mulai']);

    return $hasil;
}

/**
 * Kelompokkan kejadian DOWN ke dalam ember per jam.
 *
 * Rentang yang melewati pergantian jam dipecah lebih dulu, sehingga durasi
 * tiap jam tetap akurat. Kembalian: ['Y-m-d H:00:00' => total detik],
 * sudah terurut menaik.
 */
function prtgDowntimeKeEmberJam(array $episode): array
{
    $ember = [];

    foreach ($episode as $e) {
        $kursor  = clone $e['mulai'];
        $selesai = $e['selesai'];

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

/**
 * Baca tabel state report lalu kelompokkan seluruh downtime per jam.
 * Tanda tangan fungsi ini sengaja dipertahankan agar pemanggil lama tetap jalan.
 */
function prtgDowntimePerJam(DOMXPath $xpath): array
{
    return prtgDowntimeKeEmberJam(prtgDowntimeMentah($xpath));
}

/**
 * Ringkasan trafik satu sensor: minimum, rata-rata, dan maksimum dalam Mbps.
 *
 * Diambil lewat API (Application Programming Interface) resmi PRTG
 * `/api/historicdata.json`, bukan dari HTML, karena nilainya sudah berupa
 * angka sehingga tidak perlu ditebak dari teks berformat.
 *
 * Server PRTG 17.x tidak mengirim field "_raw", jadi nilai dibaca langsung
 * dari key kanal. Satuan kanal (speed) adalah byte/detik, maka pembagi ke
 * Mbps adalah 125000 (bukan 1.000.000).
 *
 * SENGAJA TIDAK PERNAH MELEMPAR EXCEPTION. Kalau gagal, kembaliannya berisi
 * nilai null plus 'catatan' berisi alasannya — pembuatan dokumen Word tidak
 * boleh ikut gagal hanya karena angka pelengkap ini tidak terbaca.
 */
function prtgRingkasanTrafik(array $prtg, string $idSensor, string $mulai, string $akhir, string $cookie): array
{
    $kosong = [
        'min' => null, 'avg' => null, 'max' => null,
        'kanal' => null, 'titik' => 0, 'catatan' => null,
    ];

    try {
        $url = prtgBaseUrl($prtg) . 'api/historicdata.json?' . http_build_query([
            'id'         => $idSensor,
            'avg'        => 3600,      // rata-rata per jam: ~744 baris per bulan
            'sdate'      => $mulai,
            'edate'      => $akhir,
            'usecaption' => 1,
        ]);

        $data = json_decode(prtgAmbil($url, $cookie), true);

        if (!is_array($data) || empty($data['histdata']) || !is_array($data['histdata'])) {
            return array_merge($kosong, ['catatan' => 'historicdata.json kosong atau bukan JSON']);
        }

        // Cari nama kanal. Prioritas: "Traffic Total (Speed)"; bila tidak ada,
        // kanal apa pun yang mengandung "speed". Kanal (volume) diabaikan
        // karena nilainya kumulatif, bukan kecepatan.
        $kanal = null;

        foreach ([['total', 'speed'], ['speed']] as $syarat) {
            foreach ($data['histdata'] as $baris) {
                if (!is_array($baris)) {
                    continue;
                }

                foreach (array_keys($baris) as $k) {
                    $l = strtolower((string) $k);

                    if (strpos($l, 'volume') !== false) {
                        continue;
                    }

                    $cocok = true;

                    foreach ($syarat as $kata) {
                        if (strpos($l, $kata) === false) {
                            $cocok = false;
                            break;
                        }
                    }

                    if ($cocok) {
                        $kanal = (string) $k;
                        break 3;
                    }
                }
            }
        }

        if ($kanal === null) {
            return array_merge($kosong, ['catatan' => 'kanal trafik (speed) tidak ditemukan']);
        }

        $nilai = [];

        foreach ($data['histdata'] as $baris) {
            if (!is_array($baris) || !isset($baris[$kanal]) || !is_numeric($baris[$kanal])) {
                continue;
            }

            $nilai[] = (float) $baris[$kanal];
        }

        if (!$nilai) {
            return array_merge($kosong, [
                'kanal'   => $kanal,
                'catatan' => 'nilai kanal bukan angka (kemungkinan PRTG mengirim teks berformat)',
            ]);
        }

        $pembagi = 125000;   // byte/detik -> Mbps

        return [
            'min'     => min($nilai) / $pembagi,
            'avg'     => array_sum($nilai) / count($nilai) / $pembagi,
            'max'     => max($nilai) / $pembagi,
            'kanal'   => $kanal,
            'titik'   => count($nilai),
            'catatan' => null,
        ];
    } catch (Throwable $e) {
        return array_merge($kosong, ['catatan' => 'gagal: ' . $e->getMessage()]);
    }
}
