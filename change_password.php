<?php
// Every role can change their password. Accounts issued by the hospital are forced here on first sign-in.
require_once __DIR__ . '/includes/auth.php';
require_login();

$pdo = get_db_connection();
$forced = !empty($_SESSION['must_change']);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE user_id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $hash = (string)$stmt->fetchColumn();

    if (!csrf_valid()) {
        $error = 'Your session expired. Please try again.';
    } elseif (!password_verify($current, $hash)) {
        $error = 'Your current password is incorrect.';
    } elseif ($new !== $confirm) {
        $error = 'The new passwords do not match.';
    } elseif ($new === $current) {
        $error = 'Choose a password different from the current one.';
    } elseif ($problem = password_problem($new)) {
        $error = $problem;
    } else {
        $pdo->prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE user_id = ?')
            ->execute([password_hash($new, PASSWORD_DEFAULT), $_SESSION['user_id']]);
        log_audit($pdo, (int)$_SESSION['user_id'], 'users', 'PASSWORD_CHANGE', "User {$_SESSION['username']} changed their password.");
        $_SESSION['must_change'] = false;
        session_regenerate_id(true);
        set_flash('success', 'Password updated.');
        redirect(home_for_role($_SESSION['role']));
    }
}

$page_title = 'Change password';
require __DIR__ . '/includes/header.php';
?>
<div class="mx-auto max-w-xl space-y-5">
    <?= ui_page_header('Change password', 'Choose a password you do not use anywhere else. You will stay signed in on this device.') ?>

    <?php if ($forced): ?>
        <?= ui_alert('warning', 'Set your own password first',
            'Your account was created by your administrator. Choose a personal password before continuing.', 'shield-lock') ?>
    <?php endif; ?>
    <?php if ($error): ?>
        <?= ui_alert('destructive', 'Password not updated', e($error)) ?>
    <?php endif; ?>

    <section class="<?= UI_CARD ?>">
        <div class="<?= UI_CARD_HEADER ?>">
            <h2 class="<?= UI_CARD_TITLE ?>">Password details</h2>
            <?= icon('key-round', 'h-4 w-4 text-primary') ?>
        </div>
        <div class="<?= UI_CARD_BODY ?>">
            <form method="post" class="space-y-4">
                <?= csrf_field() ?>
                <?= ui_field(['id' => 'current_password', 'name' => 'current_password', 'type' => 'password',
                    'label' => 'Current password', 'required' => true, 'attrs' => 'autocomplete="current-password" autofocus']) ?>
                <?= ui_field(['id' => 'new_password', 'name' => 'new_password', 'type' => 'password',
                    'label' => 'New password', 'required' => true, 'attrs' => 'minlength="8" autocomplete="new-password"',
                    'hint' => 'At least 8 characters, with a letter and a number.']) ?>
                <?= ui_field(['id' => 'confirm_password', 'name' => 'confirm_password', 'type' => 'password',
                    'label' => 'Confirm new password', 'required' => true, 'attrs' => 'minlength="8" autocomplete="new-password"']) ?>
                <div class="flex items-center justify-end border-t border-border pt-4">
                    <?= ui_button('Update password', ['type' => 'submit', 'icon' => 'check']) ?>
                </div>
            </form>
        </div>
    </section>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
