<?php
// Shared helpers: output escaping, CSRF, flash messages, audit log, navigation.

if (session_status() === PHP_SESSION_NONE) {
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
            ['patient/dashboard.php',     'My Dashboard',     'bi-grid-1x2-fill'],
            ['patient/log_glucose.php',   'Log Reading',      'bi-plus-circle-fill'],
            ['patient/history.php',       'Glucose History',  'bi-graph-up'],
            ['patient/prescriptions.php', 'My Prescriptions', 'bi-capsule'],
        ],
        'clinician' => [
            ['clinician/dashboard.php', 'Triage Dashboard', 'bi-clipboard2-pulse-fill'],
            ['clinician/alerts.php',    'Alerts Queue',     'bi-bell-fill'],
        ],
        'admin' => [
            ['admin/dashboard.php',         'Overview',         'bi-speedometer2'],
            ['admin/manage_clinicians.php', 'Doctors',          'bi-person-badge-fill'],
            ['admin/manage_patients.php',   'Patients',         'bi-people-fill'],
            ['admin/evaluation.php',        'Engine Evaluation', 'bi-bar-chart-line-fill'],
            ['admin/audit_trail.php',       'Audit Trail',      'bi-journal-text'],
        ],
    ];
    $root = dirname(__DIR__);
    return array_values(array_filter($all[$role] ?? [], fn($i) => is_file($root . '/' . $i[0])));
}
