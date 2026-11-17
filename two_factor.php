<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/totp.php';

if (is_logged_in()) {
    redirect(home_for_role($_SESSION['role']));
}

$pending = $_SESSION['pending_2fa'] ?? null;
if (!$pending) {
    redirect('login.php');
}
// A half-finished sign-in is abandoned after 5 minutes.
if (time() - (int)($pending['at'] ?? 0) > 300) {
    unset($_SESSION['pending_2fa']);
    set_flash('warning', 'That sign-in took too long. Please enter your password again.');
    redirect('login.php');
}

$pdo = get_db_connection();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        $error = 'Your session expired. Please try again.';
    } else {
        $stmt = $pdo->prepare("SELECT u.user_id, u.username, u.role, u.email, u.is_active, u.must_change_password,
                                      u.org_id, u.totp_secret, u.totp_enabled,
                                      COALESCE(c.full_name, p.full_name, u.username) AS full_name,
                                      c.clinician_id, p.patient_id
                               FROM users u
                               LEFT JOIN clinicians c ON c.user_id = u.user_id
                               LEFT JOIN patients p ON p.user_id = u.user_id
                               WHERE u.user_id = ? LIMIT 1");
        $stmt->execute([(int)$pending['user_id']]);
        $user = $stmt->fetch();

        $_SESSION['pending_2fa']['attempts'] = (int)($_SESSION['pending_2fa']['attempts'] ?? 0) + 1;

        if (!$user || !(int)$user['is_active'] || empty($user['totp_enabled'])) {
            unset($_SESSION['pending_2fa']);
            redirect('login.php');
        } elseif ($_SESSION['pending_2fa']['attempts'] > 5) {
            unset($_SESSION['pending_2fa']);
            log_audit($pdo, (int)$user['user_id'], 'users', 'LOGIN_FAILED', "Two-factor code failed 5 times for '{$user['username']}'.");
            set_flash('danger', 'Too many incorrect codes. Please sign in again.');
            redirect('login.php');
        } elseif (!totp_verify((string)$user['totp_secret'], (string)($_POST['code'] ?? ''))) {
            $error = 'That code is not valid. Check the code in your authenticator app and try again.';
        } else {
            establish_session($pdo, $user, 'two-factor');
            redirect(!empty($_SESSION['must_change']) ? 'change_password.php' : home_for_role($user['role']));
        }
    }
}

$page_title = 'Verify sign-in';
$layout = 'auth';
require __DIR__ . '/includes/header.php';
?>
<div class="mb-6">
    <span class="inline-flex items-center gap-1.5 rounded-full bg-primary-subtle px-2.5 py-1 text-[0.7rem] font-semibold text-primary">
        <?= icon('shield-check', 'h-3.5 w-3.5') ?> Second factor
    </span>
    <h1 class="mt-3 text-2xl font-semibold tracking-tight">Enter your 6-digit code</h1>
    <p class="mt-1 text-sm text-muted-foreground">Open your authenticator app and type the current code for <?= e(APP_NAME) ?>.</p>
</div>

<?php if ($error): ?>
    <?= ui_alert('destructive', 'Code not accepted', e($error), 'alert-triangle', 'mb-5') ?>
<?php endif; ?>

<form method="post" class="space-y-4">
    <?= csrf_field() ?>
    <?= ui_field(['id' => 'code', 'name' => 'code', 'label' => 'Authenticator code', 'required' => true,
        'attrs' => 'inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]*" maxlength="6" autofocus',
        'hint' => 'Six digits, no spaces.']) ?>
    <?= ui_button('Verify and continue', ['type' => 'submit', 'class' => 'w-full', 'size' => 'lg', 'icon' => 'shield-check']) ?>
</form>

<p class="mt-5 border-t border-border pt-4 text-center text-xs text-muted-foreground">
    Lost your device? Ask your administrator to disable two-factor sign-in for your account.
    <a href="logout.php" class="ml-1 font-semibold text-primary hover:underline">Cancel</a>
</p>
<?php require __DIR__ . '/includes/footer.php'; ?>
