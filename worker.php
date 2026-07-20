<?php
require 'database.php';
require 'generate-report.php';

$bd = db();

echo "Worker aktif...\n";

while (true)
{

    $job = $bd->query('SELECT * FROM jobs WHERE status = \'queued\' ORDER BY created_at LIMIT 1')
        ->fetch(PDO::FETCH_ASSOC);

    if (!$job) {
        sleep(2);

        continue;
    }

    $bd->prepare('UPDATE jobs SET status = \'processing\' WHERE id = ?')->execute([
        $job['id']
    ]);

    try {
        $files = generateReportRange(
            $job['bulan_mulai'],
            $job['bulan_akhir'],
            $job['pelanggan'],
            $job['id'],
            $job['rekap_downtime']
        );

        foreach ($files as $file) {
            $bd->prepare('INSERT INTO job_files (job_id, filename) VALUES (?, ?)')
                ->execute([
                    $job['id'],
                    $file
                ]);
        }

        $bd->prepare('UPDATE jobs SET status = \'done\', finished_at = NOW() WHERE id = ?')
            ->execute([
                $job['id']
            ]);
    } catch(Exception $e){
        $bd->prepare('UPDATE jobs SET status = \'failed\', error = ?, finished_at = NOW() WHERE id = ?')->execute([
            $e->getMessage(),
            $job['id']
        ]);
    }
}
