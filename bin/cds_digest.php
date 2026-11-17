<?php
// Clinician alert digest job.
//
//   php bin/cds_digest.php                 send for alerts older than DIGEST_AFTER_HOURS
//   php bin/cds_digest.php --hours=2       override the waiting threshold
//   php bin/cds_digest.php --dry-run       show who would be emailed, send nothing
//   php bin/cds_digest.php --verbose       print each delivery result
//
// Run it after bin/cds_scan.php (bin/cds_scan.bat runs both), so newly raised critical
// alerts are considered in the same scheduled pass.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/digest.php';

$args = array_slice($argv, 1);
$dryRun = in_array('--dry-run', $args, true);
$verbose = in_array('--verbose', $args, true);
$hoursOverride = 0;
foreach ($args as $arg) {
    if (preg_match('/^--hours=(\d+)$/', $arg, $m)) {
        $hoursOverride = (int)$m[1];
    }
}

$hours = digest_after_hours($hoursOverride);
$pdo = get_db_connection();

printf("[%s] digest check — critical alerts pending for more than %d h (driver: %s)%s\n",
    date('Y-m-d H:i:s'), $hours, mail_driver(), $dryRun ? ' [dry run]' : '');

$result = digest_send($pdo, $hours, $dryRun);

foreach ($result['messages'] as $line) {
    echo '  ' . $line . PHP_EOL;
}

printf(
    "  %d clinician(s), %d alert(s): %d sent, %d failed, %d skipped (no email)\n",
    $result['clinicians'], $result['alerts'], $result['sent'], $result['failed'], $result['skipped']
);

app_log_line(sprintf(
    'Alert digest (%s): %d clinician(s), %d alert(s), %d sent, %d failed, %d skipped.',
    $dryRun ? 'dry run' : 'live',
    $result['clinicians'], $result['alerts'], $result['sent'], $result['failed'], $result['skipped']
));

exit($result['failed'] > 0 ? 1 : 0);
