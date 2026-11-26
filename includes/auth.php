<?php
// Authentication, role-based access control and the "assigned patients only" rule.

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/functions.php';

const LOGIN_MAX_FAILURES = 5;      // failed attempts allowed...
const LOGIN_LOCK_MINUTES = 15;     // ...within this many minutes
const SESSION_IDLE_MINUTES = 45;   // signed-out automatically after this long without a request

function is_logged_in(): bool
{
    return !empty($_SESSION['user_id']);
}

function current_user(): ?array
{
    if (!is_logged_in()) {
        return null;
    }
    return [
        'user_id'      => (int)$_SESSION['user_id'],
        'username'     => $_SESSION['username'],
        'role'         => $_SESSION['role'],
        'email'        => $_SESSION['email'] ?? '',
        'full_name'    => $_SESSION['full_name'] ?? $_SESSION['username'],
        'patient_id'   => $_SESSION['patient_id'] ?? null,
        'clinician_id' => $_SESSION['clinician_id'] ?? null,
        'org_id'       => (int)($_SESSION['org_id'] ?? 1),
    ];
}

function home_for_role(string $role): string
{
    return match ($role) {
        'patient'      => 'patient/dashboard.php',
        'clinician'    => 'clinician/dashboard.php',
        'receptionist' => 'receptionist/dashboard.php',
        'admin'        => 'admin/dashboard.php',
        default     => 'login.php',
    };
}

function login_failure_key(string $identifier): string
{
    return "Failed login for '" . mb_strtolower($identifier) . "'";
}

function attempt_login(PDO $pdo, string $identifier, string $password): array
{
    $key = login_failure_key($identifier);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs
        WHERE action = 'LOGIN_FAILED' AND details = ? AND created_at > (NOW() - make_interval(mins => " . (int)LOGIN_LOCK_MINUTES . "))");
    $stmt->execute([$key]);
    if ((int)$stmt->fetchColumn() >= LOGIN_MAX_FAILURES) {
        return ['success' => false, 'message' => 'Too many failed attempts. Please wait ' . LOGIN_LOCK_MINUTES . ' minutes and try again.'];
    }

    $stmt = $pdo->prepare("
        SELECT u.user_id, u.username, u.password_hash, u.role, u.email, u.is_active, u.must_change_password,
               u.org_id, u.totp_secret, u.totp_enabled,
               COALESCE(c.full_name, r.full_name, p.full_name, u.username) AS full_name,
               c.clinician_id, p.patient_id
        FROM users u
        LEFT JOIN clinicians c ON c.user_id = u.user_id
        LEFT JOIN receptionists r ON r.user_id = u.user_id
        LEFT JOIN patients p ON p.user_id = u.user_id
        WHERE u.username = ? OR u.email = ?
        LIMIT 1");
    $stmt->execute([$identifier, $identifier]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        log_audit($pdo, $user ? (int)$user['user_id'] : null, 'users', 'LOGIN_FAILED', $key);
        return ['success' => false, 'message' => 'Invalid username or password.'];
    }
    if (!(int)$user['is_active']) {
        return ['success' => false, 'message' => 'This account has been deactivated. Please contact your care-team administrator.'];
    }

    // Second factor: do not open a session yet, only remember who is halfway in.
    if (!empty($user['totp_enabled'])) {
        session_regenerate_id(true);
        $_SESSION = ['pending_2fa' => ['user_id' => (int)$user['user_id'], 'at' => time()]];
        return ['success' => false, 'two_factor' => true, 'message' => '']; 
    }

    establish_session($pdo, $user);
    return ['success' => true, 'role' => $user['role'], 'message' => ''];
}

/** Everything that must happen once identity is fully proven (password, plus 2FA when enabled). */
function establish_session(PDO $pdo, array $user, string $note = ''): void
{
    session_regenerate_id(true);
    unset($_SESSION['pending_2fa']);
    $_SESSION['user_id']      = (int)$user['user_id'];
    $_SESSION['username']     = $user['username'];
    $_SESSION['role']         = $user['role'];
    $_SESSION['email']        = $user['email'];
    $_SESSION['full_name']    = $user['full_name'];
    $_SESSION['clinician_id'] = $user['clinician_id'] ? (int)$user['clinician_id'] : null;
    $_SESSION['patient_id']   = $user['patient_id'] ? (int)$user['patient_id'] : null;
    $_SESSION['org_id']       = (int)($user['org_id'] ?? 1);
    $_SESSION['must_change']  = (int)$user['must_change_password'] === 1;
    $_SESSION['last_seen']    = time();

    $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE user_id = ?')->execute([$user['user_id']]);
    log_audit($pdo, (int)$user['user_id'], 'users', 'LOGIN',
        "User {$user['username']} signed in as {$user['role']}" . ($note !== '' ? " ({$note})" : '') . '.');
}

function logout_user(PDO $pdo): void
{
    if (is_logged_in()) {
        log_audit($pdo, (int)$_SESSION['user_id'], 'users', 'LOGOUT', "User {$_SESSION['username']} signed out.");
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function require_login(): void
{
    if (!is_logged_in()) {
        set_flash('warning', 'Please sign in to continue.');
        redirect('login.php');
    }

    // End the session at once if an administrator deactivated the account.
    $stmt = get_db_connection()->prepare('SELECT is_active FROM users WHERE user_id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $active = $stmt->fetchColumn();
    if ($active === false || !(int)$active) {
        logout_user(get_db_connection());
        session_start();
        set_flash('danger', 'Your account is no longer active.');
        redirect('login.php');
    }

    // Idle timeout: a clinic terminal left signed in must not stay usable all day.
    $idleMinutes = max(5, (int)app_env('SESSION_IDLE_MINUTES', (string)SESSION_IDLE_MINUTES));
    if (!empty($_SESSION['last_seen']) && (time() - (int)$_SESSION['last_seen']) > $idleMinutes * 60) {
        logout_user(get_db_connection());
        session_start();
        set_flash('warning', 'You were signed out after ' . $idleMinutes . ' minutes of inactivity.');
        redirect('login.php');
    }
    $_SESSION['last_seen'] = time();

    // Accounts created by the hospital must set their own password first.
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if (!empty($_SESSION['must_change']) && !in_array($script, ['change_password.php', 'logout.php'], true)) {
        redirect('change_password.php');
    }
}

function require_role(string ...$roles): void
{
    require_login();
    if (!in_array($_SESSION['role'], $roles, true)) {
        log_audit(get_db_connection(), (int)$_SESSION['user_id'], 'access', 'ACCESS_DENIED',
            "User {$_SESSION['username']} ({$_SESSION['role']}) tried to open " . ($_SERVER['SCRIPT_NAME'] ?? ''));
        set_flash('danger', 'You do not have permission to open that page.');
        redirect(home_for_role($_SESSION['role']));
    }
}

/**
 * THE RULE: a clinician may only ever see patients assigned to them.
 * Returns the patient row, or null when the patient does not exist OR belongs to another doctor
 * (both cases look identical so a doctor cannot probe for other patients' IDs).
 */
function get_assigned_patient(PDO $pdo, int $patientId): ?array
{
    $clinicianId = $_SESSION['clinician_id'] ?? null;
    if (($_SESSION['role'] ?? '') !== 'clinician' || !$clinicianId) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM patients WHERE patient_id = ? AND clinician_id = ?');
    $stmt->execute([$patientId, $clinicianId]);
    return $stmt->fetch() ?: null;
}

/**
 * Front desk may open ANY patient in its own organisation (it books and refers them), but
 * never a patient from another organisation. Returns the patient row or redirects.
 */
function require_org_patient(PDO $pdo, int $patientId): array
{
    $stmt = $pdo->prepare('SELECT p.*, u.org_id FROM patients p JOIN users u ON u.user_id = p.user_id
                           WHERE p.patient_id = ? AND u.org_id = ?');
    $stmt->execute([$patientId, current_org_id()]);
    $patient = $stmt->fetch();
    if (!$patient) {
        log_audit($pdo, (int)$_SESSION['user_id'], 'patients', 'ACCESS_DENIED',
            "{$_SESSION['role']} {$_SESSION['username']} requested patient #{$patientId} outside their organisation.");
        set_flash('danger', 'That patient is not in your register.');
        redirect('receptionist/patients.php');
    }
    return $patient;
}

function require_assigned_patient(PDO $pdo, int $patientId): array
{
    $patient = get_assigned_patient($pdo, $patientId);
    if (!$patient) {
        log_audit($pdo, (int)$_SESSION['user_id'], 'patients', 'ACCESS_DENIED',
            "Clinician {$_SESSION['username']} requested patient #{$patientId}, who is not assigned to them.");
        set_flash('danger', 'That patient is not on your assigned list.');
        redirect('clinician/dashboard.php');
    }
    return $patient;
}
