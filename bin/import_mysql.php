<?php
// One-off import of the legacy MySQL database into the PostgreSQL (Supabase) database.
//
//   php bin/import_mysql.php --dry-run     report what would be copied, write nothing
//   php bin/import_mysql.php               copy the records
//
// The PostgreSQL schema must already exist (run bin/migrate.php first). Rows are matched
// on their primary key and skipped when they already exist, so re-running the import is
// safe and never duplicates readings. Identity sequences are advanced afterwards so new
// records do not collide with the imported ids.
//
// Connection details come from the environment, with XAMPP defaults:
//   MYSQL_HOST (127.0.0.1)  MYSQL_PORT (3306)  MYSQL_DB (glucocare)
//   MYSQL_USER (root)       MYSQL_PASS ()

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/db.php';

$dryRun = in_array('--dry-run', array_slice($argv, 1), true);

// Parent tables first so foreign keys always resolve.
$tables = [
    'organisations'      => 'org_id',
    'users'              => 'user_id',
    'clinicians'         => 'clinician_id',
    'patients'           => 'patient_id',
    'patient_thresholds' => 'threshold_id',
    'vitals'             => 'vital_id',
    'prescriptions'      => 'prescription_id',
    'glucose_readings'   => 'reading_id',
    'clinical_alerts'    => 'alert_id',
    'sim_ground_truth'   => 'truth_id',
    'app_settings'       => 'setting_key',
    'password_resets'    => 'reset_id',
    'audit_logs'         => 'log_id',
];

$host = (string)app_env('MYSQL_HOST', '127.0.0.1');
$port = (string)app_env('MYSQL_PORT', '3306');
$name = (string)app_env('MYSQL_DB', 'glucocare');
$user = (string)app_env('MYSQL_USER', 'root');
$pass = (string)app_env('MYSQL_PASS', '');

echo "Legacy MySQL -> PostgreSQL import\n";
echo "---------------------------------\n";
echo 'Source: ' . $name . ' on ' . $host . ':' . $port . ' as ' . $user . "\n";
echo 'Target: ' . DB_NAME . ' on ' . DB_HOST . ':' . DB_PORT . "\n";
echo $dryRun ? "Mode:   dry run (nothing is written)\n\n" : "Mode:   copy\n\n";

try {
    $my = new PDO(
        'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';charset=utf8mb4',
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 8]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "Cannot connect to MySQL: " . $e->getMessage() . PHP_EOL);
    fwrite(STDERR, "Set MYSQL_USER / MYSQL_PASS (and MYSQL_HOST/MYSQL_PORT/MYSQL_DB if different)." . PHP_EOL);
    exit(1);
}

$pg = get_db_connection();

// ---- Plan: which columns exist on both sides -------------------------------
$plan = [];
foreach ($tables as $table => $pk) {
    $myCols = [];
    try {
        foreach ($my->query('SHOW COLUMNS FROM `' . $table . '`') as $row) {
            $myCols[$row['Field']] = true;
        }
    } catch (PDOException $e) {
        fwrite(STDERR, "Skipping {$table}: not present in MySQL." . PHP_EOL);
        continue;
    }

    $pgCols = [];
    $stmt = $pg->prepare('SELECT column_name FROM information_schema.columns
                          WHERE table_schema = \'public\' AND table_name = ? ORDER BY ordinal_position');
    $stmt->execute([$table]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $c) {
        $pgCols[] = $c;
    }
    if ($pgCols === []) {
        fwrite(STDERR, "Skipping {$table}: not present in PostgreSQL. Run bin/migrate.php first." . PHP_EOL);
        continue;
    }

    // PostgreSQL column order is kept so INSERT ... VALUES lines read predictably.
    $shared = array_values(array_filter($pgCols, fn($c) => isset($myCols[$c])));
    if (!in_array($pk, $shared, true)) {
        fwrite(STDERR, "Skipping {$table}: primary key {$pk} not shared by both schemas." . PHP_EOL);
        continue;
    }
    $ignored = array_values(array_diff($pgCols, $shared));
    $plan[$table] = ['pk' => $pk, 'cols' => $shared, 'ignored' => $ignored];
}

// ---- Copy -----------------------------------------------------------------
$pg->beginTransaction();
$inserted = [];
$skipped = [];
try {
    foreach ($plan as $table => $info) {
        $cols = $info['cols'];
        $pk = $info['pk'];
        $rows = $my->query('SELECT `' . implode('`,`', $cols) . '` FROM `' . $table . '`')->fetchAll();
        $inserted[$table] = 0;
        $skipped[$table] = 0;

        $colList = '"' . implode('","', $cols) . '"';
        $updates = array_values(array_filter($cols, fn($c) => $c !== $pk));
        $setList = implode(', ', array_map(fn($c) => '"' . $c . '" = EXCLUDED."' . $c . '"', $updates));
        $sql = 'INSERT INTO "' . $table . '" (' . $colList . ') VALUES ';
        $sql .= $dryRun ? '' : 'ON CONFLICT ("' . $pk . '") DO ' . ($updates ? 'UPDATE SET ' . $setList : 'NOTHING');

        foreach (array_chunk($rows, 100) as $chunk) {
            $tuple = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
            if ($dryRun) {
                $inserted[$table] += count($chunk);
                continue;
            }
            $params = [];
            foreach ($chunk as $row) {
                foreach ($cols as $c) {
                    $params[] = $row[$c] === '' && $c !== $pk ? null : $row[$c];
                }
            }
            $stmt = $pg->prepare($sql . implode(',', array_fill(0, count($chunk), $tuple)));
            $stmt->execute($params);
            $inserted[$table] += $stmt->rowCount();
            $skipped[$table] += count($chunk) - $stmt->rowCount();
        }
    }
    if (!$dryRun) {
        // Advance every identity sequence past the imported ids.
        foreach ($plan as $table => $info) {
            $pk = $info['pk'];
            $seq = $pg->query("SELECT pg_get_serial_sequence('" . $table . "', '" . $pk . "')")->fetchColumn();
            if (!$seq) {
                continue;
            }
            $pg->exec("SELECT setval('" . $seq . "', GREATEST(COALESCE((SELECT MAX(\"" . $pk . "\") FROM \"" . $table . "\"), 0), 1), COALESCE((SELECT MAX(\"" . $pk . "\") FROM \"" . $table . "\"), 0) > 0)");
        }
        $pg->commit();
    } else {
        $pg->rollBack();
    }
} catch (PDOException $e) {
    if ($pg->inTransaction()) {
        $pg->rollBack();
    }
    fwrite(STDERR, 'Import failed, nothing was committed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

// ---- Report ---------------------------------------------------------------
echo str_pad('table', 22) . str_pad('source', 9) . str_pad('written', 9) . "skipped\n";
$total = 0;
foreach ($plan as $table => $info) {
    $sourceCount = (int)$my->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
    $total += $inserted[$table];
    printf("%-22s%-9d%-9d%d\n", $table, $sourceCount, $inserted[$table], $skipped[$table]);
    if ($info['ignored']) {
        echo '  note: PostgreSQL-only column(s) left at their defaults: ' . implode(', ', $info['ignored']) . "\n";
    }
}
echo "\n" . ($dryRun ? 'Would write ' : 'Wrote ') . $total . " row(s).\n";

if ($dryRun) {
    exit(0);
}

// ---- Integrity checks -----------------------------------------------------
$checks = [
    'readings without a patient'        => 'SELECT COUNT(*) FROM glucose_readings r LEFT JOIN patients p ON p.patient_id = r.patient_id WHERE p.patient_id IS NULL',
    'alerts without a patient'          => 'SELECT COUNT(*) FROM clinical_alerts a LEFT JOIN patients p ON p.patient_id = a.patient_id WHERE p.patient_id IS NULL',
    'patients without a user'           => 'SELECT COUNT(*) FROM patients p LEFT JOIN users u ON u.user_id = p.user_id WHERE u.user_id IS NULL',
    'prescriptions without a patient'   => 'SELECT COUNT(*) FROM prescriptions x LEFT JOIN patients p ON p.patient_id = x.patient_id WHERE p.patient_id IS NULL',
    'thresholds without a patient'      => 'SELECT COUNT(*) FROM patient_thresholds t LEFT JOIN patients p ON p.patient_id = t.patient_id WHERE p.patient_id IS NULL',
];
echo "\nIntegrity:\n";
$clean = true;
foreach ($checks as $label => $sql) {
    $n = (int)$pg->query($sql)->fetchColumn();
    printf("  %-34s %s\n", $label, $n === 0 ? 'ok' : $n . ' BROKEN');
    $clean = $clean && $n === 0;
}
$counts = $pg->query('SELECT (SELECT COUNT(*) FROM users) u, (SELECT COUNT(*) FROM patients) p, (SELECT COUNT(*) FROM glucose_readings) r')->fetch();
printf("\nNow in PostgreSQL: %d user(s), %d patient(s), %d reading(s).\n", $counts['u'], $counts['p'], $counts['r']);
app_log_line('MySQL import: ' . $total . ' row(s), integrity ' . ($clean ? 'ok' : 'PROBLEMS'));
exit($clean ? 0 : 1);
