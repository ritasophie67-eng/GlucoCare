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
<div class="row justify-content-center">
    <div class="col-lg-6 col-xl-5">
        <?php if ($forced): ?>
            <div class="alert alert-warning"><i class="bi bi-shield-lock-fill me-1"></i> Your account was set up by the hospital. Please choose your own password before continuing.</div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= e($error) ?></div>
        <?php endif; ?>
        <div class="card">
            <div class="card-body">
                <form method="post">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label">Current password</label>
                        <input class="form-control" type="password" name="current_password" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">New password</label>
                        <input class="form-control" type="password" name="new_password" required minlength="8">
                        <div class="form-text">At least 8 characters, with a letter and a number.</div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label">Confirm new password</label>
                        <input class="form-control" type="password" name="confirm_password" required minlength="8">
                    </div>
                    <button class="btn btn-primary" type="submit">Update password</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
