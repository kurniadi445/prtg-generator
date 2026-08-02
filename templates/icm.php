<?php

/**
 * TEMPLATE ICM — PT Intergate Cahaya Media
 *
 * Strukturnya sengaja dibuat sama persis dengan templates/idt.php:
 * section 1 untuk sampul, section 2 untuk isi, sampul berupa paragraf
 * biasa yang didorong ke bawah dengan baris kosong.
 *
 * Beda isinya dengan idt:
 *   - ada logo kecil di header lembar isi
 *   - judul "Traffic Bulan ..." dan baris "Report for T – Lokal – ..."
 *   - kolom tabel downtime bernama "Start Down" / "Last Up"
 *   - tanpa footer "Approval :"
 *
 * Daftar isi $konteks ada di templates/idt.php.
 */

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\Style\Image;

return function (array $konteks): PhpWord {

    $opsi = $konteks['opsi'];
    $nama = $konteks['nama'];

    $phpWord = new PhpWord();

    // ================= Halaman 1: sampul =================
    $sampul = $phpWord->addSection();

    blokGambarHalamanPenuh($sampul, asetTemplate($konteks, 'cover'));

    // Untuk menggeser blok teks sampul naik atau turun, ubah angka 11.
    for ($i = 0; $i < 11; $i++) {
        $sampul->addTextBreak();
    }

    $sampul->addText($konteks['bulanTahun'], fontStyle(36));

    $sampul->addTextBreak();
    $sampul->addTextBreak();

    $sampul->addText('Dibuat Untuk.', fontStyle(22));

    $sampul->addTextBreak();

    $sampul->addText($nama, fontStyle(28, true));

    // ================= Halaman 2: isi =================
    $isi = $phpWord->addSection();

    $logo = asetTemplate($konteks, 'logo', false);

    if ($logo !== null) {
        $isi->addHeader()->addImage($logo, [
            'width'  => Converter::cmToPoint(4.1),
            'height' => Converter::cmToPoint(2.33),

            // Objek mengambang, bukan sisipan sebaris.
            'positioning' => Image::POSITION_ABSOLUTE,

            // Kunci di balik posisi yang benar: POSITION_ABSOLUTE pada
            // posHorizontal/posVertical menyuruh Word memakai OFFSET
            // (marginLeft/marginTop). Bila diisi perataan seperti 'left'
            // atau 'top', offset-nya diabaikan.
            //
            // Horizontal: -1,5 cm terhadap margin.
            'posHorizontal'    => Image::POSITION_ABSOLUTE,
            'posHorizontalRel' => Image::POSITION_RELATIVE_TO_MARGIN,
            'marginLeft'       => Converter::cmToPoint(-1.5),

            // Vertikal: 0 cm terhadap tepi atas halaman.
            // Perbesar angkanya untuk menurunkan logo.
            'posVertical'    => Image::POSITION_ABSOLUTE,
            'posVerticalRel' => Image::POSITION_RELATIVE_TO_PAGE,
            'marginTop'      => Converter::cmToPoint(0),

            // Di depan teks, supaya tidak tertimpa isi halaman.
            'wrappingStyle' => Image::WRAPPING_STYLE_INFRONT,
        ]);
    }

    $isi->addText(
        'Traffic Bulan ' . $konteks['namaBulan'] . ' ' . $nama
            . ' (1-' . $konteks['jumlahHari']
            . ' ' . $konteks['namaBulan'] . ' ' . $konteks['tahun'] . ')',
        fontStyle(13, true)
    );

    $isi->addText(
        'Report for T – Lokal – ' . $nama . ' Bulan ' . $konteks['namaBulan'],
        fontStyle(12)
    );

    $isi->addImage($konteks['gambar'], ['width' => Converter::cmToPoint(15.92)]);

    $isi->addText('Rekap Log Downtime.', fontStyle(11, true));

    blokTabelDowntime($phpWord, $isi, $konteks['downtime'], [
        'name'       => 'RekapDowntime',
        'style'      => ['borderSize' => 4, 'borderColor' => '000000'],
        'widths'     => [562, 1843, 1984, 1843, 3686],
        'headers'    => ['No', 'Start Down', 'Last Up', 'Durasi', 'Keterangan'],
        'font'       => fontStyle(11),
        'headerFont' => fontStyle(11, true),
    ]);

    $isi->addTextBreak();
    $isi->addTextBreak();

    blokTandaTangan($phpWord, $isi, $opsi['signature'], [
        'name'    => 'Persetujuan',
        'style'   => ['align' => 'right', 'borderSize' => 4, 'borderColor' => '000000'],
        'widths'  => [2269, 2270],
        'heights' => [240, 777, 480],
        'cell'    => ['valign' => 'center'],
        'font'    => ['name' => 'Calibri', 'size' => 11],
    ]);

    // Template ini tidak memakai footer approval; baris di bawah sengaja
    // tetap ada agar cukup menambahkan 'approval' di config bila nanti perlu.
    blokFooterApproval($phpWord, $isi, $opsi['approval'] ?? []);

    return $phpWord;
};
