<?php
// Dependency-free test suite:  php tests/run.php
//
// Covers the pure logic the clinical advice depends on — phone/unit formatting, reading
// banding, validation rules, TOTP, pagination. It deliberately needs no database, so it
// runs on any machine with PHP and can gate a change to the rule engine.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/readings.php';
require_once __DIR__ . '/../includes/cds_engine.php';
require_once __DIR__ . '/../includes/totp.php';
require_once __DIR__ . '/../includes/ui.php';

$passed = 0;
$failed = 0;
$group = '';

function group(string $name): void
{
    global $group;
    $group = $name;
    echo PHP_EOL . $name . PHP_EOL . str_repeat('-', strlen($name)) . PHP_EOL;
}

function check(string $label, $actual, $expected): void
{
    global $passed, $failed;
    if ($actual === $expected) {
        $passed++;
        echo "  ok   {$label}\n";
        return;
    }
    $failed++;
    echo "  FAIL {$label}\n       expected: " . var_export($expected, true) . "\n       actual:   " . var_export($actual, true) . "\n";
}

function truthy(string $label, bool $value): void
{
    check($label, $value, true);
}

// ---- Phone numbers -------------------------------------------------------
group('Phone numbers');
check('+256 stored form is idempotent', phone_normalize('+256700000001'), '+256700000001');
check('local trunk zero', phone_normalize('0700 000 001'), '+256700000001');
check('bare subscriber number', phone_normalize('781954142'), '+256781954142');
check('international prefix 00', phone_normalize('00256781954142'), '+256781954142');
check('trunk zero before country code', phone_normalize('0256781954142'), '+256781954142');
check('too many digits rejected', phone_normalize('07008942739'), null);
check('letters rejected', phone_normalize('not a number'), null);
check('empty rejected', phone_normalize(''), null);
check('display grouping', phone_display('+256781954142'), '+256 781 954 142');
check('non-numbers pass through', phone_display('N/A'), 'N/A');

// ---- Units and formatting ----------------------------------------------
group('Glucose units');
check('mmol to mg/dL', glucose_to_mgdl(5.5, UNIT_MMOL), 99.088);
check('mg/dL unchanged', glucose_to_mgdl(99, UNIT_MGDL), 99.0);
check('mg/dL to mmol', round(glucose_from_mgdl(180, UNIT_MMOL), 1), 10.0);
check('fmt_number trims zeros', fmt_number(45.0), '45');
check('fmt_number keeps one decimal', fmt_number(5.6), '5.6');
check('fmt_glucose in mmol', fmt_glucose(180, UNIT_MMOL), '10');
check('unknown unit falls back', normalize_unit('mmol/l '), UNIT_MGDL);
check('patient_unit default', patient_unit([]), UNIT_MGDL);
check('chart limits converted', chart_limits(['target_fasting_min' => 80, 'target_fasting_max' => 130,
    'target_postprandial_max' => 180, 'hypo_threshold' => 70, 'severe_hypo_threshold' => 54], UNIT_MMOL)['hypo'], 3.89);

// ---- Reading banding -----------------------------------------------------
group('Reading banding');
$targets = ['severe_hypo_threshold' => 54, 'hypo_threshold' => 70, 'critical_high_threshold' => 300,
    'target_fasting_max' => 130, 'target_postprandial_max' => 180];
check('below severe', reading_band(50, 'fasting', $targets), 'severe_low');
check('hypo band', reading_band(65, 'fasting', $targets), 'low');
check('in range', reading_band(100, 'fasting', $targets), 'in_range');
check('above fasting target', reading_band(140, 'fasting', $targets), 'high');
check('post-meal uses its own target', reading_band(160, 'post_meal', $targets), 'in_range');
check('critical high', reading_band(320, 'fasting', $targets), 'very_high');
check('band label', band_label('severe_low')[0], 'Severe low');

// ---- Validation rules ----------------------------------------------------
group('Validation rules');
check('short password rejected', password_problem('abc'), 'Password must be at least 8 characters long.');
check('letters only rejected', password_problem('abcdefghi'), 'Password must contain at least one letter and one number.');
check('good password accepted', password_problem('Password123'), null);
check('temp password meets rules', password_problem(generate_temp_password()), null);

// ---- Two-factor ----------------------------------------------------------
group('Two-factor');
$secret = totp_new_secret();
truthy('secret is base32', (bool)preg_match('/^[A-Z2-7]{16,}$/', $secret));
check('current code verifies', totp_verify($secret, totp_code($secret)), true);
check('wrong code rejected', totp_verify($secret, '000000'), false);
$slice = (int)floor(time() / 30);
check('previous step allowed', totp_verify($secret, totp_code($secret, $slice - 1)), true);
check('two steps back rejected', totp_verify($secret, totp_code($secret, $slice - 2)), false);
truthy('otpauth URI', str_starts_with(totp_uri($secret, 'admin'), 'otpauth://totp/'));

// ---- UI helpers ----------------------------------------------------------
group('UI helpers');
check('escaping', e('<b>&"'), '&lt;b&gt;&amp;&quot;');
truthy('pager shows totals', str_contains(ui_pager(2, 20, 45, ['days' => 7]), 'Showing <span class="font-semibold text-foreground">21–40</span> of 45'));
truthy('pager links keep filters', str_contains(ui_pager(1, 20, 45, ['days' => 7]), 'days=7'));
truthy('pager has no links on a single page', !str_contains(ui_pager(1, 20, 5), 'Previous'));

// ---- Dialect guard -------------------------------------------------------
// The application runs on PostgreSQL only. MySQL-only constructs fail at runtime, and
// they are exactly the kind of thing a copy-pasted query reintroduces, so guard them here.
group('PostgreSQL dialect guard');
$root = dirname(__DIR__);
$skip = ['/node_modules/', '/tests/', '/database/schema.sql', '/assets/js/vendor/'];
$forbidden = [
    'backtick identifier'        => '/`[A-Za-z_][A-Za-z0-9_]*`/',
    'ON DUPLICATE KEY'           => '/ON\s+DUPLICATE\s+KEY/i',
    'TIMESTAMPDIFF'              => '/TIMESTAMPDIFF\s*\(/i',
    'DATE_ADD / DATE_SUB'        => '/DATE_(ADD|SUB)\s*\(/i',
    'MySQL INTERVAL n UNIT'      => '/INTERVAL\s+(?:[0-9?]|\.)/',
    'FIELD() ordering'           => '/ORDER\s+BY\s+FIELD\s*\(/i',
    'IF() function'              => '/\bIF\s*\(/',            // uppercase only: PHP if() is lowercase
    'SHOW TABLES / SHOW CREATE'  => '/SHOW\s+(TABLES|CREATE)/i',
    'lastInsertId()'             => '/->lastInsertId\s*\(/i',
    'LIMIT ? placeholder'        => '/LIMIT\s+\?/i',
    'NULL-safe <=> (SQL)'        => '/<=>\s*\?/',   // PHP spaceship is <=> $a, never <=> ?
];
$offenders = [];
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $path = str_replace('\\', '/', $file->getPathname());
    foreach ($skip as $fragment) {
        if (str_contains($path, $fragment)) {
            continue 2;
        }
    }
    $code = (string)file_get_contents($path);
    foreach ($forbidden as $label => $pattern) {
        if (preg_match($pattern, $code)) {
            $offenders[] = basename($path) . ': ' . $label;
        }
    }
}
check('no MySQL-only SQL in application code', $offenders === [] ? 'clean' : implode(' | ', $offenders), 'clean');
truthy('postgres schema exists', is_file($root . '/database/schema.postgres.sql'));
truthy('schema declares identity columns', str_contains((string)@file_get_contents($root . '/database/schema.postgres.sql'), 'GENERATED BY DEFAULT AS IDENTITY'));

// ---- Schema file ---------------------------------------------------------
group('PostgreSQL schema file');
$schemaText = (string)file_get_contents($root . '/database/schema.postgres.sql');
$stripped = preg_replace('/^\s*--.*$/m', '', $schemaText);
$statements = array_values(array_filter(array_map('trim', explode(';', (string)$stripped))));
truthy('splits into statements', count($statements) > 20);
$badStatements = array_values(array_filter($statements, fn($s) => !preg_match('/^(CREATE|INSERT|SELECT)\s/i', $s)));
check('every statement starts with CREATE/INSERT/SELECT', $badStatements === [] ? 'clean' : mb_substr($badStatements[0], 0, 80), 'clean');
check('no MySQL backticks in the schema', preg_match('/`/', $schemaText), 0);
$expectedTables = app_schema_tables();
check('13 expected tables are declared', count($expectedTables), 13);
truthy('tables include app_settings and password_resets', in_array('app_settings', $expectedTables, true) && in_array('password_resets', $expectedTables, true));
truthy('seed data advances identity sequences', str_contains($schemaText, "setval(pg_get_serial_sequence('users','user_id')"));

// ---- Result --------------------------------------------------------------
echo PHP_EOL . str_repeat('=', 46) . PHP_EOL;
printf("%d passed, %d failed%s", $passed, $failed, PHP_EOL);
exit($failed === 0 ? 0 : 1);
