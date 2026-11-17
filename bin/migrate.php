<?php
// Schema sync / health check for the PostgreSQL (Supabase) database.
//
//   php bin/migrate.php           create missing tables, indexes and seed rows
//   php bin/migrate.php --check   report only; exit code 1 when something is missing
//
// database/schema.postgres.sql is written to be re-runnable (CREATE ... IF NOT EXISTS,
// ON CONFLICT DO NOTHING), so the whole file is applied each time. Existing rows are
// never deleted or modified.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/errors.php';

$checkOnly = in_array('--check', array_slice($argv, 1), true);

// Columns added after the first release. Kept as a health check; the schema file creates them.
const SCHEMA_COLUMNS = [
    ['patients', 'preferred_unit'],
    ['glucose_readings', 'entered_unit'],
    ['clinical_alerts', 'digest_sent_at'],
    ['users', 'org_id'],
    ['users', 'totp_secret'],
    ['users', 'totp_enabled'],
    ['patients', 'allergies'],
    ['glucose_readings', 'was_edited'],
    ['glucose_readings', 'edited_at'],
    ['clinical_alerts', 'digest_attempts'],
    ['clinical_alerts', 'digest_error'],
    ['clinical_alerts', 'patient_notified_at'],
];

function schema_has_column(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns
                           WHERE table_schema = 'public' AND table_name = ? AND column_name = ?");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

/** Split a .sql file into statements, ignoring -- comments. */
function pg_split_statements(string $sql): array
{
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    $parts = array_map('trim', explode(';', (string)$sql));
    return array_values(array_filter($parts, fn($s) => $s !== ''));
}

echo 'GlucoCare schema sync (PostgreSQL)' . PHP_EOL . str_repeat('-', 34) . PHP_EOL;
echo 'Target: ' . DB_NAME . ' on ' . DB_HOST . ':' . DB_PORT . PHP_EOL;

try {
    $pdo = get_db_connection();
} catch (Throwable $e) {
    fwrite(STDERR, 'Cannot connect: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$expected = app_schema_tables();
if (!$expected) {
    fwrite(STDERR, 'Could not read database/schema.postgres.sql' . PHP_EOL);
    exit(1);
}

$missingTables = app_missing_tables($pdo);
$missingColumns = array_values(array_filter(
    SCHEMA_COLUMNS,
    fn($c) => !schema_has_column($pdo, $c[0], $c[1])
));

echo 'Expected tables: ' . count($expected) . PHP_EOL;

if ($missingTables) {
    echo 'Missing tables (' . count($missingTables) . '): ' . implode(', ', $missingTables) . PHP_EOL;
}
foreach ($missingColumns as [$table, $column]) {
    echo "Missing column: {$table}.{$column}" . PHP_EOL;
}

if (!$missingTables && !$missingColumns) {
    echo 'Schema up to date — all tables and columns present.' . PHP_EOL;
    exit(0);
}

if ($checkOnly) {
    echo 'Run "php bin/migrate.php" to create them.' . PHP_EOL;
    exit(1);
}

$schema = (string)@file_get_contents(__DIR__ . '/../database/schema.postgres.sql');
if ($schema === '') {
    fwrite(STDERR, 'Could not read database/schema.postgres.sql' . PHP_EOL);
    exit(1);
}

$statements = pg_split_statements($schema);
$done = 0;
foreach ($statements as $statement) {
    try {
        $pdo->exec($statement);
        $done++;
    } catch (Throwable $e) {
        fwrite(STDERR, 'Failed statement ' . ($done + 1) . ': ' . $e->getMessage() . PHP_EOL);
        fwrite(STDERR, '--- SQL ---' . PHP_EOL . mb_substr($statement, 0, 500) . PHP_EOL);
        exit(1);
    }
}
echo 'Applied ' . $done . ' statement(s) from schema.postgres.sql.' . PHP_EOL;

$stillMissingTables = app_missing_tables($pdo);
$stillMissingColumns = array_values(array_filter(SCHEMA_COLUMNS, fn($c) => !schema_has_column($pdo, $c[0], $c[1])));

if ($stillMissingTables || $stillMissingColumns) {
    fwrite(STDERR, 'Schema still incomplete.' . PHP_EOL);
    foreach ($stillMissingTables as $t) {
        fwrite(STDERR, "  missing table: {$t}" . PHP_EOL);
    }
    foreach ($stillMissingColumns as [$t, $c]) {
        fwrite(STDERR, "  missing column: {$t}.{$c}" . PHP_EOL);
    }
    exit(1);
}

echo 'Schema is now up to date. Existing rows were not modified.' . PHP_EOL;
exit(0);
