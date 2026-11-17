<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/totp.php';

require_login();

$pdo = get_db_connection();
$userId = (int)$_SESSION['user_id'];
$error = '';

$stmt = $pdo->prepare("SELECT u.username, u.email, u.role, u.org_id, u.totp_secret, u.totp_enabled, u.last_login_at
                       FROM users u WHERE u.user_id = ?");
$stmt->execute([$userId]);
$me = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if (!csrf_valid()) {
        $error = 'Your session expired. Please try again.';
    } elseif ($action === 'start') {
        $_SESSION['totp_setup'] = totp_new_secret();
        set_flash('info', 'Scan or type the key below into your authenticator app, then confirm with a code.');
        redirect('security.php');
    } elseif ($action === 'confirm') {
        $secret = (string)($_SESSION['totp_setup'] ?? '');
        if ($secret === '') {
            $error = 'Start the setup again — the key expired.';
        } elseif (!totp_verify($secret, (string)($_POST['code'] ?? ''))) {
            $error = 'That code did not match. Check the app and try the current code.';
        } else {
            $pdo->prepare('UPDATE users SET totp_secret = ?, totp_enabled = 1 WHERE user_id = ?')->execute([$secret, $userId]);
            unset($_SESSION['totp_setup']);
            log_audit($pdo, $userId, 'users', 'TWO_FACTOR_ENABLED', "Two-factor sign-in enabled for {$_SESSION['username']}.");
            set_flash('success', 'Two-factor sign-in is on. You will be asked for a code at next sign-in.');
            redirect('security.php');
        }
    } elseif ($action === 'cancel') {
        unset($_SESSION['totp_setup']);
        redirect('security.php');
    } elseif ($action === 'disable') {
        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE user_id = ?');
        $stmt->execute([$userId]);
        if (!password_verify((string)($_POST['password'] ?? ''), (string)$stmt->fetchColumn())) {
            $error = 'Enter your current password to turn two-factor off.';
        } else {
            $pdo->prepare('UPDATE users SET totp_secret = NULL, totp_enabled = 0 WHERE user_id = ?')->execute([$userId]);
            log_audit($pdo, $userId, 'users', 'TWO_FACTOR_DISABLED', "Two-factor sign-in disabled for {$_SESSION['username']}.");
            set_flash('success', 'Two-factor sign-in is off.');
            redirect('security.php');
        }
    }
}

$stmt = $pdo->prepare('SELECT password_hash, totp_secret, totp_enabled FROM users WHERE user_id = ?');
$stmt->execute([$userId]);
$row = $stmt->fetch();
$enabled = !empty($row['totp_enabled']);
$setupSecret = (string)($_SESSION['totp_setup'] ?? '');

$page_title = 'Security';
require __DIR__ . '/includes/header.php';
?>
<div class="space-y-6">
    <?= ui_page_header('Account security', 'Protect the account that can see patient records: strong password, optional second factor, and idle sign-out.') ?>

    <?php if ($error): ?>
        <?= ui_alert('destructive', 'Not saved', e($error), 'alert-triangle') ?>
    <?php endif; ?>

    <div class="grid gap-6 lg:grid-cols-3">
        <section class="<?= UI_CARD ?> lg:col-span-2" aria-labelledby="twofactor">
            <div class="<?= UI_CARD_HEADER ?>">
                <div>
                    <h2 id="twofactor" class="<?= UI_CARD_TITLE ?>">Two-factor sign-in</h2>
                    <p class="mt-1 text-xs text-muted-foreground">A 6-digit code from an authenticator app, checked after your password.</p>
                </div>
                <?= ui_badge($enabled ? 'On' : 'Off', $enabled ? 'success' : 'secondary') ?>
            </div>

            <div class="<?= UI_CARD_BODY ?> space-y-4">
                <?php if ($setupSecret !== ''): ?>
                    <div class="rounded-md border border-border bg-muted/40 px-4 py-3">
                        <p class="text-xs font-semibold uppercase tracking-[0.07em] text-muted-foreground">Manual setup key</p>
                        <p class="mt-1.5 select-all font-mono text-sm tracking-[0.12em]"><?= e(totp_format_secret($setupSecret)) ?></p>
                        <p class="mt-2 break-all text-[0.7rem] text-muted-foreground">Or open this link on the device with the authenticator app:<br>
                            <a class="text-primary hover:underline" href="<?= e(totp_uri($setupSecret, $me['username'])) ?>"><?= e(totp_uri($setupSecret, $me['username'])) ?></a>
                        </p>
                    </div>
                    <form method="post" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="confirm">
                        <div class="flex-1">
                            <?= ui_field(['id' => 'code', 'name' => 'code', 'label' => 'Enter the 6-digit code to confirm', 'required' => true,
                                'attrs' => 'inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code"']) ?>
                        </div>
                        <div class="flex gap-2">
                            <?= ui_button('Confirm', ['type' => 'submit', 'icon' => 'check']) ?>
                            <?= ui_button('Cancel', ['type' => 'submit', 'name' => 'action', 'value' => 'cancel', 'variant' => 'outline']) ?>
                        </div>
                    </form>
                <?php elseif ($enabled): ?>
                    <p class="text-sm text-muted-foreground">This account asks for a code at every sign-in. If you change phones, turn it off here first and set it up again.</p>
                    <form method="post" class="flex flex-col gap-3 border-t border-border pt-4 sm:flex-row sm:items-end">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="disable">
                        <div class="flex-1">
                            <?= ui_field(['id' => 'password', 'name' => 'password', 'type' => 'password', 'label' => 'Current password',
                                'required' => true, 'attrs' => 'autocomplete="current-password"']) ?>
                        </div>
                        <?= ui_button('Turn off', ['type' => 'submit', 'variant' => 'destructive', 'icon' => 'unlock']) ?>
                    </form>
                <?php else: ?>
                    <p class="text-sm text-muted-foreground">Recommended for administrators and anyone who signs in from a shared clinic computer.</p>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="start">
                        <?= ui_button('Set up two-factor', ['type' => 'submit', 'icon' => 'shield-check']) ?>
                    </form>
                <?php endif; ?>
            </div>
        </section>

        <section class="<?= UI_CARD ?>" aria-labelledby="session-info">
            <div class="<?= UI_CARD_HEADER ?>"><h2 id="session-info" class="<?= UI_CARD_TITLE ?>">Session</h2></div>
            <div class="<?= UI_CARD_BODY ?> space-y-2 text-sm">
                <div class="flex items-center justify-between gap-2"><span class="text-muted-foreground">Account</span><span class="font-medium"><?= e($me['username']) ?></span></div>
                <div class="flex items-center justify-between gap-2"><span class="text-muted-foreground">Role</span><span class="font-medium capitalize"><?= e($me['role']) ?></span></div>
                <div class="flex items-center justify-between gap-2"><span class="text-muted-foreground">Organisation</span><span class="font-medium"><?= e(org_name($pdo)) ?></span></div>
                <div class="flex items-center justify-between gap-2"><span class="text-muted-foreground">Last sign-in</span><span class="font-medium"><?= $me['last_login_at'] ? e(date('d M Y, H:i', strtotime($me['last_login_at']))) : '—' ?></span></div>
                <div class="flex items-center justify-between gap-2"><span class="text-muted-foreground">Idle sign-out</span><span class="font-medium"><?= (int)app_env('SESSION_IDLE_MINUTES', (string)SESSION_IDLE_MINUTES) ?> min</span></div>
                <a href="change_password.php" class="mt-2 inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline">Change password <?= icon('chevron-right', 'h-3 w-3') ?></a>
            </div>
        </section>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
