<?php

/**
 * Blok dokumen yang dipakai bersama semua template.
 *
 * Tujuan file ini: bagian yang bentuknya sama di banyak template ditulis
 * sekali di sini, dan template hanya mengirim "spesifikasi" (lebar kolom,
 * judul, font, gaya tabel). Menambah template baru berarti menulis
 * spesifikasi, bukan menyalin ulang 40 baris tabel.
 */

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Converter;

/**
 * Ambil path aset milik satu template, sekaligus pastikan berkasnya ada.
 * Lebih baik gagal dengan pesan jelas di sini daripada error PhpWord yang
 * membingungkan, atau aset yang diam-diam tidak muncul di dokumen.
 */
function asetTemplate(array $konteks, string $kunci, bool $wajib = true): ?string
{
    $opsi     = $konteks['opsi'];
    $template = $konteks['template'];

    if (empty($opsi[$kunci])) {
        if (!$wajib) {
            return null;
        }

        throw new RuntimeException(
            "Template '$template' belum punya pengaturan '$kunci' di config('templates')"
        );
    }

    $path = __DIR__ . '/' . ltrim($opsi[$kunci], '/');

    if (!is_file($path)) {
        throw new RuntimeException(
            "Berkas '$kunci' untuk template '$template' tidak ditemukan: " . $opsi[$kunci]
        );
    }

    return $path;
}

/**
 * Pasang gambar satu halaman penuh (A4) di belakang teks.
 */
function blokGambarHalamanPenuh($section, string $path): void
{
    $section->addImage($path, [
        'width'            => Converter::cmToPoint(21),
        'height'           => Converter::cmToPoint(29.7),
        'positioning'      => 'absolute',
        'posHorizontal'    => 'left',
        'posHorizontalRel' => 'page',
        'posVertical'      => 'top',
        'posVerticalRel'   => 'page',
        'wrappingStyle'    => 'behind',
    ]);
}

/**
 * Tabel "Rekap Log Downtime".
 *
 * $spek:
 *   name       string  nama gaya tabel (harus unik dalam satu dokumen)
 *   style      array   gaya tabel untuk addTableStyle()
 *   widths     array   lebar 5 kolom dalam twip
 *   headers    array   5 judul kolom
 *   font       array   gaya font isi tabel; [] berarti ikut default dokumen
 *   headerFont array   gaya font baris judul; default = font + tebal
 */
function blokTabelDowntime(PhpWord $phpWord, $section, array $downtime, array $spek): void
{
    $lebar  = $spek['widths'];
    $font   = $spek['font'] ?? [];
    $judul  = $spek['headerFont'] ?? ($font + ['bold' => true]);
    $tengah = ['align' => 'center', 'spaceAfter' => 0];

    $phpWord->addTableStyle($spek['name'], $spek['style']);

    $tabel = $section->addTable($spek['name']);

    $tabel->addRow();

    foreach ($spek['headers'] as $i => $teks) {
        $tabel->addCell($lebar[$i])->addText($teks, $judul, $tengah);
    }

    // Tidak ada downtime: sisakan satu baris kosong agar tabel tetap terbentuk.
    if (!$downtime) {
        $tabel->addRow();

        foreach ($lebar as $w) {
            $tabel->addCell($w);
        }

        return;
    }

    $nomor = 1;

    foreach ($downtime as $awalJam => $detik) {
        $mulai   = new DateTime($awalJam);
        $selesai = (clone $mulai)->modify('+1 hour');

        $tabel->addRow();
        $tabel->addCell($lebar[0])->addText($nomor, $font, $tengah);
        $tabel->addCell($lebar[1])->addText($mulai->format('d/m/Y H:i:s'), $font, $tengah);
        $tabel->addCell($lebar[2])->addText($selesai->format('d/m/Y H:i:s'), $font, $tengah);
        $tabel->addCell($lebar[3])->addText(formatDurasi($detik), $font, $tengah);
        $tabel->addCell($lebar[4]);   // Keterangan: diisi manual setelah dokumen jadi

        $nomor++;
    }
}

/**
 * Tabel tanda tangan dua kolom.
 *
 * $ttd berasal dari config('templates')[x]['signature'] dan berisi
 * dua sisi ('left' / 'right'), masing-masing: heading, name, title,
 * dan opsional underline (bool).
 *
 * $spek:
 *   name    string  nama gaya tabel
 *   style   array   gaya tabel untuk addTableStyle()
 *   widths  array   lebar 2 kolom dalam twip
 *   heights array   tinggi 3 baris; null berarti otomatis
 *   cell    array   gaya sel, mis. ['valign' => 'center']
 *   font    array   gaya font seluruh isi tabel
 */
function blokTandaTangan(PhpWord $phpWord, $section, array $ttd, array $spek): void
{
    $lebar   = $spek['widths'];
    $tinggi  = $spek['heights'] ?? [null, null, null];
    $gayaSel = $spek['cell'] ?? [];
    $font    = $spek['font'];
    $garis   = $font + ['underline' => 'single'];
    $tengah  = ['align' => 'center', 'spaceAfter' => 0];

    $phpWord->addTableStyle($spek['name'], $spek['style']);

    $tabel = $section->addTable($spek['name']);

    // Baris 1: judul kolom
    $tabel->addRow($tinggi[0]);
    $tabel->addCell($lebar[0], $gayaSel)->addText($ttd['left']['heading'], $font, $tengah);
    $tabel->addCell($lebar[1], $gayaSel)->addText($ttd['right']['heading'], $font, $tengah);

    // Baris 2: ruang kosong untuk tanda tangan basah
    $tabel->addRow($tinggi[1]);
    $tabel->addCell($lebar[0], $gayaSel);
    $tabel->addCell($lebar[1], $gayaSel);

    // Baris 3: nama dan jabatan
    $tabel->addRow($tinggi[2]);

    foreach (['left', 'right'] as $i => $sisi) {
        $sel = $tabel->addCell($lebar[$i], $gayaSel);

        $sel->addText($ttd[$sisi]['name'], empty($ttd[$sisi]['underline']) ? $font : $garis, $tengah);
        $sel->addText($ttd[$sisi]['title'], $font, $tengah);
    }
}

/**
 * Footer "Approval :" berisi daftar kotak centang.
 * Tidak melakukan apa-apa bila template tidak punya daftar approval,
 * sehingga aman dipanggil dari template mana pun.
 */
function blokFooterApproval(PhpWord $phpWord, $section, array $daftar, string $namaGaya = 'Approval'): void
{
    if (!$daftar) {
        return;
    }

    $phpWord->addTableStyle($namaGaya, [
        'align'           => 'left',
        'cellMarginLeft'  => 54,
        'cellMarginRight' => 54,
    ]);

    $footer = $section->addFooter();

    $footer->addText('Approval :', fontStyle(8, true, 'Calibri'), ['spaceAfter' => 0]);

    $tabel = $footer->addTable($namaGaya);

    $gayaKotak = [
        'valign'      => 'center',
        'borderSize'  => 6,
        'borderColor' => '000000',
    ];

    foreach ($daftar as $orang) {
        $baris = $tabel->addRow(400);

        $baris->addCell(400, $gayaKotak);   // kotak centang kosong

        $sel = $baris->addCell(8000, ['valign' => 'center']);

        $sel->addText($orang['name'], fontStyle(8, true, 'Calibri'), ['spaceAfter' => 0]);
        $sel->addText($orang['title'], ['name' => 'Calibri', 'size' => 8], ['spaceAfter' => 0]);
    }
}
