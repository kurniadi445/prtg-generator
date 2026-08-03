<?php

/**
 * Penyimpanan rekap SLA (Service Level Agreement) ke database.
 *
 * Dipanggil dari generate-report.php setiap kali satu dokumen bulanan
 * berhasil dibuat. Tujuannya: angka uptime dan daftar downtime tidak lagi
 * hanya "terkubur" di dalam berkas Word, tapi bisa dibaca lewat halaman
 * sla.php kapan saja.
 *
 * Aturan penting: fungsi di sini TIDAK BOLEH menggagalkan pembuatan laporan.
 * Kalau tabel belum dibuat atau database sedang bermasalah, kesalahan ditelan
 * dan dokumen Word tetap dianggap berhasil.
 */

require_once __DIR__ . '/database.php';

/**
 * Simpan (atau perbarui) satu baris rekap SLA beserta rincian downtime-nya.
 *
 * Karena tabel `sla_bulanan` punya UNIQUE (pelanggan_id, periode, template),
 * menjalankan ulang generate untuk bulan yang sama akan MEMPERBARUI baris
 * lama, bukan menambah baris kembar.
 *
 * $data yang diharapkan:
 *   pelanggan_id   string
 *   nama_pelanggan string
 *   template       string
 *   periode        string 'YYYY-MM'
 *   detik_periode  int
 *   episode        array  hasil prtgDowntimeMentah()
 *   trafik         array  hasil prtgRingkasanTrafik()
 *   file_docx      string|null  path relatif dokumen
 *   job_id         string|null
 *
 * @return int|null id baris sla_bulanan, atau null bila gagal disimpan.
 */
function slaSimpan(array $data): ?int
{
    try {
        $bd = db();

        $episode = $data['episode'] ?? [];
        $trafik  = $data['trafik'] ?? [];

        $detikDown = 0;

        foreach ($episode as $e) {
            $detikDown += (int) $e['detik'];
        }

        $detikPeriode = max(1, (int) $data['detik_periode']);

        // Downtime bisa saja melebihi periode bila data PRTG tumpang tindih;
        // dijepit supaya persentase tidak pernah negatif.
        $detikDown = min($detikDown, $detikPeriode);
        $uptime    = (1 - ($detikDown / $detikPeriode)) * 100;

        $bd->beginTransaction();

        $simpan = $bd->prepare('
            INSERT INTO sla_bulanan
                (pelanggan_id, nama_pelanggan, template, periode, detik_periode,
                 detik_downtime, uptime_persen, jumlah_insiden,
                 trafik_min_mbps, trafik_avg_mbps, trafik_max_mbps,
                 kanal_trafik, catatan, file_docx, job_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                id              = LAST_INSERT_ID(id),
                nama_pelanggan  = VALUES(nama_pelanggan),
                detik_periode   = VALUES(detik_periode),
                detik_downtime  = VALUES(detik_downtime),
                uptime_persen   = VALUES(uptime_persen),
                jumlah_insiden  = VALUES(jumlah_insiden),
                trafik_min_mbps = VALUES(trafik_min_mbps),
                trafik_avg_mbps = VALUES(trafik_avg_mbps),
                trafik_max_mbps = VALUES(trafik_max_mbps),
                kanal_trafik    = VALUES(kanal_trafik),
                catatan         = VALUES(catatan),
                file_docx       = VALUES(file_docx),
                job_id          = VALUES(job_id)
        ');

        $simpan->execute([
            (string) $data['pelanggan_id'],
            (string) $data['nama_pelanggan'],
            (string) $data['template'],
            (string) $data['periode'],
            $detikPeriode,
            $detikDown,
            round($uptime, 4),
            count($episode),
            isset($trafik['min']) ? round($trafik['min'], 4) : null,
            isset($trafik['avg']) ? round($trafik['avg'], 4) : null,
            isset($trafik['max']) ? round($trafik['max'], 4) : null,
            $trafik['kanal']   ?? null,
            $trafik['catatan'] ?? null,
            $data['file_docx'] ?? null,
            $data['job_id']    ?? null,
        ]);

        $slaId = (int) $bd->lastInsertId();

        // Rincian selalu ditulis ulang dari nol, supaya tidak ada sisa data
        // dari generate sebelumnya bila periode DOWN-nya berubah.
        $bd->prepare('DELETE FROM sla_downtime WHERE sla_id = ?')->execute([$slaId]);

        if ($episode) {
            $tambah = $bd->prepare(
                'INSERT INTO sla_downtime (sla_id, mulai, selesai, detik) VALUES (?, ?, ?, ?)'
            );

            foreach ($episode as $e) {
                $tambah->execute([
                    $slaId,
                    $e['mulai']->format('Y-m-d H:i:s'),
                    $e['selesai']->format('Y-m-d H:i:s'),
                    (int) $e['detik'],
                ]);
            }
        }

        $bd->commit();

        return $slaId;
    } catch (Throwable $e) {
        try {
            if (isset($bd) && $bd->inTransaction()) {
                $bd->rollBack();
            }
        } catch (Throwable $abaikan) {
            // tidak ada yang bisa dilakukan lagi di sini
        }

        // Dicatat ke log PHP saja; laporan Word tetap dianggap berhasil.
        error_log('slaSimpan gagal: ' . $e->getMessage());

        return null;
    }
}

/**
 * Ambang SLA dari config, dengan nilai bawaan 99.5 persen.
 */
function slaTarget(): float
{
    $laporan = (array) config('report');

    return (float) ($laporan['sla_target'] ?? 99.5);
}

/**
 * Ubah detik menjadi format jam:menit:detik, mis. "27:03:41".
 * Sengaja tidak dibulatkan ke satuan yang lebih besar.
 */
function slaJamMenitDetik(int $detik): string
{
    return sprintf(
        '%02d:%02d:%02d',
        intdiv($detik, 3600),
        intdiv($detik % 3600, 60),
        $detik % 60
    );
}
