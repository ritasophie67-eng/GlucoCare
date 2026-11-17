<?php
// Shared helpers: output escaping, CSRF, flash messages, audit log, navigation.

require_once __DIR__ . '/errors.php';
app_register_handlers();

// CLI tools (bin/*) do not need a session; starting one there only produces noise.
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . BASE_URL . ltrim($path, '/'));
    exit;
}

/** Absolute URL for links built outside a web request (email digests, CLI jobs). */
function app_url(string $path = ''): string
{
    $base = APP_URL !== '' ? APP_URL : rtrim(BASE_URL, '/');
    return $base . ($path === '' ? '' : '/' . ltrim($path, '/'));
}

// ---- Phone numbers ---------------------------------------------------
// Every number is stored in one canonical form (+256XXXXXXXXX) and shown as
// +256 700 000 001, so the same patient reads the same on every screen.
const PHONE_COUNTRY_CODE = '256';
const PHONE_ERROR = 'Enter a valid Ugandan phone number, for example 0700 000 001 or +256 700 000 001.';

/** Parse a Ugandan number into E.164 (+256XXXXXXXXX); null when it is not one. */
function phone_normalize(?string $raw): ?string
{
    $digits = preg_replace('/\D+/', '', (string)$raw);
    if ($digits === '') {
        return null;
    }
    if (str_starts_with($digits, '00')) {          // international dialling prefix
        $digits = substr($digits, 2);
    }
    if ($digits[0] === '0' && in_array(strlen($digits), [10, 13], true)) {  // trunk 0: 07… or 0256…
        $digits = substr($digits, 1);
    }
    if (str_starts_with($digits, PHONE_COUNTRY_CODE)) {
        $digits = substr($digits, strlen(PHONE_COUNTRY_CODE));
    }
    // Subscriber numbers are 9 digits; mobile starts with 7, fixed lines with 3 or 4.
    if (!preg_match('/^[347]\d{8}$/', $digits)) {
        return null;
    }
    return '+' . PHONE_COUNTRY_CODE . $digits;
}

/** Human-readable form of a stored number; values that are not numbers pass through unchanged. */
function phone_display(?string $stored): string
{
    $e164 = phone_normalize($stored);
    if ($e164 === null) {
        return trim((string)$stored);   // e.g. synthetic profiles store "N/A"
    }
    $nsn = substr($e164, strlen(PHONE_COUNTRY_CODE) + 1);
    return '+' . PHONE_COUNTRY_CODE . ' ' . substr($nsn, 0, 3) . ' ' . substr($nsn, 3, 3) . ' ' . substr($nsn, 6);
}

// ---- Small key/value settings -------------------------------------------
// Job bookkeeping that is not worth its own table: last scan, last digest, last backup.
function meta_get(PDO $pdo, string $key, string $default = ''): string
{
    try {
        $stmt = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value === false || $value === null ? $default : (string)$value;
    } catch (Throwable $e) {
        return $default;
    }
}

function meta_set(PDO $pdo, string $key, string $value): void
{
    try {
        $pdo->prepare('INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)
                       ON CONFLICT (setting_key) DO UPDATE
                          SET setting_value = EXCLUDED.setting_value, updated_at = now()')
            ->execute([$key, $value]);
    } catch (Throwable $e) {
        error_log('Setting write failed: ' . $e->getMessage());
    }
}

// ---- Organisation (tenant) ----------------------------------------------
// Every account belongs to one organisation; listing screens filter on it.
function current_org_id(): int
{
    return (int)($_SESSION['org_id'] ?? 1);
}

function org_name(PDO $pdo, ?int $orgId = null): string
{
    try {
        $stmt = $pdo->prepare('SELECT short_name FROM organisations WHERE org_id = ?');
        $stmt->execute([$orgId ?? current_org_id()]);
        $name = $stmt->fetchColumn();
        return $name === false ? APP_NAME : (string)$name;
    } catch (Throwable $e) {
        return APP_NAME;
    }
}

// ---- CSRF ------------------------------------------------------------
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}

function csrf_valid(): bool
{
    $sent = $_POST['csrf_token'] ?? '';
    return !empty($_SESSION['csrf_token']) && is_string($sent) && hash_equals($_SESSION['csrf_token'], $sent);
}

// ---- Flash messages (plain text, escaped on output) -------------------
function set_flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function take_flash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

// ---- Audit trail -------------------------------------------------------
function log_audit(PDO $pdo, ?int $userId, string $table, string $action, string $details): void
{
    try {
        $stmt = $pdo->prepare('INSERT INTO audit_logs (user_id, target_table, action, details) VALUES (?, ?, ?, ?)');
        $stmt->execute([$userId, $table, strtoupper($action), $details]);
    } catch (Throwable $e) {
        error_log('Audit log failed: ' . $e->getMessage());
    }
}

// ---- Misc ----------------------------------------------------------------
function calc_age(string $dob): int
{
    return (new DateTime($dob))->diff(new DateTime())->y;
}

function generate_temp_password(int $length = 10): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $out . '#' . random_int(10, 99);
}

function password_problem(string $password): ?string
{
    if (strlen($password) < 8) {
        return 'Password must be at least 8 characters long.';
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        return 'Password must contain at least one letter and one number.';
    }
    return null;
}

/**
 * Sidebar navigation. A link only appears if its page exists in this phase folder,
 * so each phase folder automatically shows exactly the features it contains.
 */
function nav_items(string $role): array
{
    $all = [
        'patient' => [
            ['patient/dashboard.php',     'My Dashboard',     'layout-dashboard'],
            ['patient/log_glucose.php',   'Log a Reading',    'plus-circle'],
            ['patient/history.php',       'Glucose History',  'line-chart'],
            ['patient/prescriptions.php', 'My Prescriptions', 'pill'],
            ['security.php',              'Security',         'shield-check'],
        ],
        'clinician' => [
            ['clinician/dashboard.php', 'Triage Dashboard', 'clipboard-list'],
            ['clinician/alerts.php',    'Alerts Queue',     'bell'],
            ['security.php',            'Security',         'shield-check'],
        ],
        'admin' => [
            ['admin/dashboard.php',         'Overview',          'gauge'],
            ['admin/manage_clinicians.php', 'Doctors',           'stethoscope'],
            ['admin/manage_patients.php',   'Patients',          'users'],
            ['admin/evaluation.php',        'Engine Evaluation', 'bar-chart'],
            ['admin/audit_trail.php',       'Audit Trail',       'clipboard-list'],
            ['security.php',                'Security',          'shield-check'],
        ],
    ];
    $root = dirname(__DIR__);
    return array_values(array_filter($all[$role] ?? [], fn($i) => is_file($root . '/' . $i[0])));
}
