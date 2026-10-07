<?php
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    redirect(home_for_role($_SESSION['role']));
}

$error = '';
$identifier = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!csrf_valid()) {
        $error = 'Your session expired. Please try again.';
    } elseif ($identifier === '' || $password === '') {
        $error = 'Enter your username (or email) and password.';
    } else {
        $result = attempt_login(get_db_connection(), $identifier, $password);
        if ($result['success']) {
            redirect(!empty($_SESSION['must_change']) ? 'change_password.php' : home_for_role($result['role']));
        }
        $error = $result['message'];
    }
}

$page_title = 'Sign in';
$layout = 'auth';
require __DIR__ . '/includes/header.php';
?>
<h2 class="mb-1">Welcome back</h2>
<p class="text-muted mb-4">Sign in to your <?= e(APP_NAME) ?> account.</p>

<?php if ($error): ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i> <?= e($error) ?></div>
<?php endif; ?>

<form method="post" autocomplete="on">
    <?= csrf_field() ?>
    <div class="mb-3">
        <label class="form-label" for="username">Username or email</label>
        <input class="form-control" id="username" name="username" value="<?= e($identifier) ?>" required autofocus>
    </div>
    <div class="mb-4">
        <label class="form-label" for="password">Password</label>
        <input class="form-control" id="password" name="password" type="password" required>
    </div>
    <button class="btn btn-primary w-100 py-2" type="submit">Sign in</button>
</form>

<hr class="my-4">
<p class="small text-muted mb-1"><i class="bi bi-person-badge me-1"></i> <strong>Doctors:</strong> accounts are issued by the hospital administrator — contact the hospital if you need access.</p>
<p class="small mb-0">New patient? <a href="register.php" class="fw-semibold">Create your account</a></p>
<?php require __DIR__ . '/includes/footer.php'; ?>
