<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/mailer.php';

if (is_logged_in()) {
    redirect(home_for_role($_SESSION['role']));
}

$pdo = get_db_connection();
$error = '';
$sent = false;
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));

    if (!csrf_valid()) {
        $error = 'Your session expired. Please try again.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter the email address on your account.';
    } else {
        $stmt = $pdo->prepare('SELECT user_id, username, email, is_active FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // Always report the same outcome, so this page cannot be used to discover accounts.
        if ($user && (int)$user['is_active']) {
            $token = bin2hex(random_bytes(32));
            $pdo->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, NOW() + INTERVAL '60 minutes')")
                ->execute([$user['user_id'], hash('sha256', $token)]);
            $link = app_url('reset_password.php?token=' . $token);
            $subject = APP_NAME . ': reset your password';
            $html = '<p style="font-family:Segoe UI,Arial,sans-serif;font-size:15px;color:#0f172a;">Use the link below to choose a new password. It expires in 60 minutes and works once.</p>'
                . '<p style="margin:18px 0;"><a href="' . e($link) . '" style="background:#0f766e;color:#fff;padding:10px 16px;border-radius:6px;text-decoration:none;font-family:Segoe UI,Arial,sans-serif;">Choose a new password</a></p>'
                . '<p style="font-family:Segoe UI,Arial,sans-serif;font-size:12px;color:#64748b;">If you did not ask for this, ignore the email — your password is unchanged.</p>';
            $text = "Reset your " . APP_NAME . " password (valid for 60 minutes):\n{$link}\n\nIf you did not ask for this, ignore this email.";
            $delivery = send_mail($user['email'], $subject, $html, $text);
            log_audit($pdo, (int)$user['user_id'], 'users', 'PASSWORD_RESET_REQUESTED',
                'Password reset requested by email' . ($delivery['ok'] ? ' (' . $delivery['driver'] . ').' : ' — delivery failed: ' . $delivery['error']));
        }
        $sent = true;
    }
}

$page_title = 'Forgot password';
$layout = 'auth';
require __DIR__ . '/includes/header.php';
?>
<div class="mb-6">
    <h1 class="text-2xl font-semibold tracking-tight">Reset your password</h1>
    <p class="mt-1 text-sm text-muted-foreground">We'll email you a single-use link that expires in 60 minutes.</p>
</div>

<?php if ($error): ?>
    <?= ui_alert('destructive', 'Could not send the link', e($error), 'alert-triangle', 'mb-5') ?>
<?php elseif ($sent): ?>
    <?= ui_alert('success', 'Check your email', 'If an account exists with that address, a reset link is on its way. The link works once and expires in 60 minutes.', 'mail', 'mb-5') ?>
    <p class="text-xs leading-relaxed text-muted-foreground">No email after a few minutes? Ask your administrator to reset the password from the doctors list — they can issue a temporary one immediately.</p>
<?php endif; ?>

<?php if (!$sent): ?>
<form method="post" class="space-y-4">
    <?= csrf_field() ?>
    <?= ui_field(['id' => 'email', 'name' => 'email', 'type' => 'email', 'label' => 'Email address',
        'value' => $email, 'required' => true, 'attrs' => 'autocomplete="email" autofocus']) ?>
    <?= ui_button('Email me a reset link', ['type' => 'submit', 'class' => 'w-full', 'size' => 'lg', 'icon' => 'mail']) ?>
</form>
<?php endif; ?>

<p class="mt-5 border-t border-border pt-4 text-center text-xs text-muted-foreground">
    <a href="login.php" class="font-semibold text-primary hover:underline">Back to sign in</a>
</p>
<?php require __DIR__ . '/includes/footer.php'; ?>
