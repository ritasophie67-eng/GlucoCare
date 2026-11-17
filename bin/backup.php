<?php
// Database backup with retention, for the PostgreSQL (Supabase) database.
//
//   php bin/backup.php                 full dump using pg_dump when available
//   php bin/backup.php --keep=30       override BACKUP_KEEP_DAYS
//   php bin/backup.php --list          show existing backups
//
// pg_dump is preferred because it produces a restorable file. If it is not installed it
// falls back to a data-only SQL export built with PDO: run bin/migrate.php first, then
// load that file to restore the records.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

$args = array_slice($argv, 1);
$keepDays = 14;
foreach ($args as $arg) {
    if (preg_match('/^--keep=(\d+)$/', $arg, $m)) {
        $keepDays = max(1, (int)$m[1]);
    }
}
$keepDays = max(1, (int)app_env('BACKUP_KEEP_DAYS', (string)$keepDays));
$dir = __DIR__ . '/../' . trim((string)app_env('BACKUP_DIR', 'backups'), '/\\');
if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
    fwrite(STDERR, "Cannot create backup directory: {$dir}" . PHP_EOL);
    exit(1);
}
$files = glob($dir . '/glucocare-*.sql*') ?: [];
rsort($files);

if (in_array('--list', $args, true)) {
    echo 'Backups in ' . realpath($dir) . PHP_EOL;
    foreach ($files as $f) {
        printf("  %-44s %8.1f KB  %s\n", basename($f), filesize($f) / 1024, date('Y-m-d H:i', filemtime($f)));
    }
    echo count($files) . ' file(s).' . PHP_EOL;
    exit(0);
}

$pdo = get_db_connection();
$stamp = date('Ymd-His');
$target = $dir . '/glucocare-' . $stamp . '.sql';
$method = '';
$ok = false;

// ---- Preferred: pg_dump --------------------------------------------------
$pgDump = trim((string)app_env('PGDUMP_PATH', ''));
if ($pgDump === '') {
    foreach (['pg_dump', 'C:/Program Files/PostgreSQL/16/bin/pg_dump.exe', 'C:/Program Files/PostgreSQL/15/bin/pg_dump.exe'] as $candidate) {
        // Require a real version line: a missing binary echoes "pg_dump: command not found",
        // which also contains the word pg_dump.
        $probe = @shell_exec(escapeshellarg($candidate) . ' --version 2>&1');
        if (is_string($probe) && preg_match('/pg_dump\s*\(PostgreSQL\)\s*\d+/i', $probe)) {
            $pgDump = $candidate;
            break;
        }
    }
}

if ($pgDump !== '') {
    $connString = sprintf(
        'postgresql://%s:%s@%s:%s/%s?sslmode=%s',
        rawurlencode(DB_USER), rawurlencode(DB_PASS), DB_HOST, DB_PORT, DB_NAME, DB_SSL
    );
    $cmd = escapeshellarg($pgDump) . ' --dbname=' . escapeshellarg($connString)
        . ' --no-owner --no-privileges --schema=public > ' . escapeshellarg($target) . ' 2>&1';
    $output = [];
    $status = 1;
    exec($cmd, $output, $status);
    $ok = $status === 0 && is_file($target) && filesize($target) > 0;
    $method = 'pg_dump';
    if (!$ok) {
        @unlink($target);
        fwrite(STDERR, 'pg_dump failed (exit ' . $status . '): ' . implode(' ', array_slice($output, 0, 3)) . PHP_EOL);
    }
}

// ---- Fallback: data-only dump through PDO --------------------------------
if (!$ok) {
    $method = 'PDO (data only)';
    $fh = fopen($target, 'w');
    if (!$fh) {
        fwrite(STDERR, "Cannot write {$target}" . PHP_EOL);
        exit(1);
    }
    fwrite($fh, "-- GlucoCare data export (PDO) " . date('c') . "\n"
        . "-- Restore with: php bin/migrate.php  then  psql < this-file\n"
        . "SET client_encoding = 'UTF8';\nSET session_replication_role = replica;\n\n");

    $tables = $pdo->query("SELECT table_name FROM information_schema.tables
                           WHERE table_schema = 'public' AND table_type = 'BASE TABLE'
                           ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        // Keep only identifier characters: the name comes from information_schema but is
        // still spliced into SQL, so it is re-validated here.
        $safe = preg_replace('/[^A-Za-z0-9_]/', '', (string)$table);
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string)$safe)) {
            continue;
        }
        $rows = $pdo->query('SELECT * FROM "' . $safe . '"')->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            continue;
        }
        fwrite($fh, "-- {$safe}\n");
        $columns = '"' . implode('","', array_keys($rows[0])) . '"';
        foreach (array_chunk($rows, 100) as $chunk) {
            $values = [];
            foreach ($chunk as $row) {
                $values[] = '(' . implode(',', array_map(
                    fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v),
                    array_values($row)
                )) . ')';
            }
            fwrite($fh, "INSERT INTO \"{$safe}\" ({$columns}) VALUES\n" . implode(",\n", $values)
                . "\nON CONFLICT DO NOTHING;\n");
        }
        fwrite($fh, "\n");
    }
    fwrite($fh, "SET session_replication_role = origin;\n");
    fclose($fh);
    $ok = filesize($target) > 0;
}

if (!$ok) {
    fwrite(STDERR, 'Backup failed.' . PHP_EOL);
    exit(1);
}

$gzip = function_exists('gzencode') ? $target . '.gz' : '';
if ($gzip !== '') {
    $data = file_get_contents($target);
    if ($data !== false && file_put_contents($gzip, gzencode($data, 6)) !== false) {
        @unlink($target);
        $target = $gzip;
    }
}

// Retention: keep the newest BACKUP_KEEP_DAYS days of backups.
$cutoff = time() - $keepDays * 86400;
$removed = 0;
foreach ($files as $f) {
    if (filemtime($f) < $cutoff) {
        @unlink($f);
        $removed++;
    }
}

meta_set($pdo, 'last_backup_at', date('Y-m-d H:i:s'));
meta_set($pdo, 'last_backup_file', basename($target));

printf(
    "[%s] backup written with %s: %s (%.1f KB)%s\n",
    date('Y-m-d H:i:s'), $method, realpath($target), filesize($target) / 1024,
    $removed ? " — removed {$removed} old file(s)" : ''
);
app_log_line('Backup: ' . basename($target) . " ({$method})");
exit(0);
