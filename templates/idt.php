<?php

/**
 * TEMPLATE UTAMA
 *
 * Dipakai oleh mayoritas pelanggan. Ini adalah tata letak lama yang
 * sebelumnya ditulis langsung di generate-report.php.
 *
 * Isi $konteks yang diterima tiap template:
 *
 *   template    string  kunci template, mis. 'utama'
 *   nama        string  nama pelanggan
 *   bulan       string  'YYYY-MM'
 *   waktu       int     timestamp tanggal 1 bulan tersebut
 *   namaBulan   string  'Juli'
 *   bulanTahun  string  'Juli 2026'
 *   tahun       string  '2026'
 *   jumlahHari  string  jumlah hari dalam bulan, mis. '31'
 *   gambar      string  path PNG hasil render grafik + tabel PRTG
 *   downtime    array   ['Y-m-d H:00:00' => total detik] sudah terurut
 *   report      array   config('report') — pengaturan global
 *   opsi        array   config('templates')[<template>] — pengaturan template ini
 *
 * Template WAJIB mengembalikan objek PhpWord yang siap disimpan.
 */

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Converter;

return function (array $konteks): PhpWord {

    $opsi = $konteks['opsi'];
    $ttd  = $opsi['signature'];

    $phpWord = new PhpWord();

    // ---------------- Halaman 1: sampul ----------------
    $section = $phpWord->addSection();

    $section->addImage($opsi['watermark'], [
        'width'            => Converter::cmToPoint(21),
        'height'           => Converter::cmToPoint(29.7),
        'positioning'      => 'absolute',
        'posHorizontal'    => 'left',
        'posHorizontalRel' => 'page',
        'posVertical'      => 'top',
        'posVerticalRel'   => 'page',
        'wrappingStyle'    => 'behind',
    ]);

    for ($i = 0; $i < 16; $i++) {
        $section->addTextBreak();
    }

    $section->addText(strtoupper($konteks['bulanTahun']), fontStyle(36));

    $section->addTextBreak();
    $section->addTextBreak();

    $section->addText('Dibuat Untuk:', fontStyle(22));

    $section->addTextBreak();

    $section->addText($konteks['nama'], fontStyle(28, true));

    // ---------------- Halaman 2: isi ----------------
    $section = $phpWord->addSection();

    $section->addText(
        'TRAFFIC ' . $konteks['nama']
            . ' 1-' . $konteks['jumlahHari']
            . ' ' . strtoupper($konteks['bulanTahun']),
        fontStyle(16, true)
    );

    $section->addImage($konteks['gambar'], [
        'width' => Converter::cmToPoint(15.92),
    ]);

    $section->addText('Rekap Log Downtime', fontStyle(11, true));

    $phpWord->addTableStyle('Rekap Log Downtime', [
        'borderSize'       => 1,
        'cellMarginBottom' => 108,
        'cellMarginLeft'   => 108,
        'cellMarginRight'  => 108,
        'cellMarginTop'    => 108,
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

    if ($konteks['downtime']) {
        $nomor = 1;

        foreach ($konteks['downtime'] as $awalJam => $totalDetik) {
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

    // ---------------- Blok tanda tangan ----------------
    $phpWord->addTableStyle('Persetujuan', [
        'align'      => 'right',
        'borderSize' => 1,
    ]);

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

    // ---------------- Footer "Approval :" ----------------
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

    foreach ($opsi['approval'] as $orang) {
        $baris = $tblApproval->addRow(400);

        $baris->addCell(400, $gayaKotak);   // kotak centang kosong

        $sel = $baris->addCell(8000, ['valign' => 'center']);

        $sel->addText($orang['name'],  fontStyle(8, true, 'Calibri'), ['spaceAfter' => 0]);
        $sel->addText($orang['title'], ['name' => 'Calibri', 'size' => 8], ['spaceAfter' => 0]);
    }

    return $phpWord;
};
