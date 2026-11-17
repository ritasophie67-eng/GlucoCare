<?php
// Scheduled alert re-evaluation.
//
//   php bin/cds_scan.php            re-evaluate every active patient
//   php bin/cds_scan.php --verbose  print a line per patient with changes
//
// Patterns are created, refreshed, or auto-resolved. Run every 15–60 minutes via Task Scheduler
// (bin/cds_scan.bat) so alerts do not depend on a patient logging a new reading.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/cds_engine.php';

$verbose = in_array('--verbose', array_slice($argv, 1), true);

$pdo = get_db_connection();
$stmt = $pdo->query("SELECT p.patient_id, p.full_name
                     FROM patients p JOIN users u ON u.user_id = p.user_id
                     WHERE u.is_active = 1 AND u.username NOT LIKE 'sim\\_%'
                     ORDER BY p.patient_id");
$patients = $stmt->fetchAll();

$totals = ['created' => 0, 'updated' => 0, 'resolved' => 0];
$scanned = 0;

foreach ($patients as $p) {
    $scanned++;
    $result = cds_reassess_patient($pdo, (int)$p['patient_id']);
    foreach ($totals as $key => $_) {
        $totals[$key] += $result[$key];
    }
    if ($verbose && ($result['created'] || $result['updated'] || $result['resolved'])) {
        printf(
            "  #%d %-24s created=%d refreshed=%d auto-resolved=%d findings=%d\n",
            $p['patient_id'], $p['full_name'],
            $result['created'], $result['updated'], $result['resolved'], $result['findings']
        );
    }
}

printf(
    "[%s] scanned %d patients — created %d, refreshed %d, auto-resolved %d\n",
    date('Y-m-d H:i:s'), $scanned, $totals['created'], $totals['updated'], $totals['resolved']
);

meta_set($pdo, 'last_scan_at', date('Y-m-d H:i:s'));
meta_set($pdo, 'last_scan_patients', (string)$scanned);
meta_set($pdo, 'last_scan_created', (string)$totals['created']);

app_log_line(sprintf(
    'CDS scan: %d patients, %d created, %d refreshed, %d auto-resolved.',
    $scanned, $totals['created'], $totals['updated'], $totals['resolved']
));

exit(0);
