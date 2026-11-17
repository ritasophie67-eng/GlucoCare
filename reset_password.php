<?php
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    redirect(home_for_role($_SESSION['role']));
}

$pdo = get_db_connection();
$token = (string)($_POST['token'] ?? $_GET['token'] ?? '');
$error = '';
$done = false;

$lookup = function (string $token) use ($pdo): ?array {
    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/i', $token)) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT r.reset_id, r.user_id, r.expires_at, r.used_at, u.username, u.email
                           FROM password_resets r JOIN users u ON u.user_id = r.user_id
                           WHERE r.token_hash = ? LIMIT 1');
    $stmt->execute([hash('sha256', $token)]);
    return $stmt->fetch() ?: null;
};

$reset = $lookup($token);
$invalid = !$reset || $reset['used_at'] !== null || strtotime($reset['expires_at']) < time();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$invalid) {
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    if (!csrf_valid()) {
        $error = 'Your session expired. Please try again.';
    } elseif ($password !== $confirm) {
        $error = 'The two passwords do not match.';
    } elseif ($problem = password_problem($password)) {
        $error = $problem;
    } else {
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE user_id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $reset['user_id']]);
        $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE reset_id = ?')->execute([$reset['reset_id']]);
        // Any other outstanding links for this account stop working.
        $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')->execute([$reset['user_id']]);
        log_audit($pdo, (int)$reset['user_id'], 'users', 'PASSWORD_RESET', "Password reset completed for {$reset['username']} via emailed link.");
        $pdo->commit();
        $done = true;
    }
}

$page_title = 'Choose a new password';
$layout = 'auth';
require __DIR__ . '/includes/header.php';
?>
<div class="mb-6">
    <h1 class="text-2xl font-semibold tracking-tight">Choose a new password</h1>
    <p class="mt-1 text-sm text-muted-foreground">You will be signed out everywhere else after this change.</p>
</div>

<?php if ($done): ?>
    <?= ui_alert('success', 'Password updated', 'You can sign in with your new password now.', 'check-circle', 'mb-5') ?>
    <?= ui_button('Sign in', ['href' => 'login.php', 'class' => 'w-full', 'size' => 'lg', 'icon' => 'arrow-right', 'icon_position' => 'end']) ?>
<?php elseif ($invalid): ?>
    <?= ui_alert('destructive', 'This link is no longer valid', 'Reset links work once and expire after 60 minutes. Request a new one below.', 'alert-octagon', 'mb-5') ?>
    <?= ui_button('Request a new link', ['href' => 'forgot_password.php', 'class' => 'w-full', 'size' => 'lg', 'icon' => 'mail']) ?>
<?php else: ?>
    <?php if ($error): ?>
        <?= ui_alert('destructive', 'Password not changed', e($error), 'alert-triangle', 'mb-5') ?>
    <?php endif; ?>
    <form method="post" class="space-y-4">
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <?= ui_field(['id' => 'password', 'name' => 'password', 'type' => 'password', 'label' => 'New password',
            'required' => true, 'attrs' => 'minlength="8" autocomplete="new-password" autofocus',
            'hint' => 'At least 8 characters, with a letter and a number.']) ?>
        <?= ui_field(['id' => 'confirm_password', 'name' => 'confirm_password', 'type' => 'password',
            'label' => 'Confirm new password', 'required' => true, 'attrs' => 'minlength="8" autocomplete="new-password"']) ?>
        <?= ui_button('Save new password', ['type' => 'submit', 'class' => 'w-full', 'size' => 'lg', 'icon' => 'check']) ?>
    </form>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
