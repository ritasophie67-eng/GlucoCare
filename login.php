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
        if (!empty($result['two_factor'])) {
            redirect('two_factor.php');
        }
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
<div class="mb-6">
    <span class="inline-flex items-center gap-1.5 rounded-full bg-primary-subtle px-2.5 py-1 text-[0.7rem] font-semibold text-primary">
        <?= icon('shield-check', 'h-3.5 w-3.5') ?> Secure sign-in
    </span>
    <h1 class="mt-3 text-2xl font-semibold tracking-tight">Welcome back</h1>
    <p class="mt-1 text-sm text-muted-foreground">Sign in to your <?= e(APP_NAME) ?> account.</p>
</div>

<?php if ($error): ?>
    <?= ui_alert('destructive', 'Could not sign you in', e($error), 'alert-triangle', 'mb-5') ?>
<?php endif; ?>

<form method="post" autocomplete="on" class="space-y-4">
    <?= csrf_field() ?>
    <?= ui_field([
        'id' => 'username', 'name' => 'username', 'label' => 'Username or email',
        'value' => $identifier, 'required' => true, 'attrs' => 'autofocus autocomplete="username"',
    ]) ?>
    <?= ui_field([
        'id' => 'password', 'name' => 'password', 'type' => 'password', 'label' => 'Password',
        'required' => true, 'attrs' => 'autocomplete="current-password"',
    ]) ?>
    <?= ui_button('Sign in', ['type' => 'submit', 'class' => 'w-full', 'size' => 'lg', 'icon' => 'arrow-right', 'icon_position' => 'end']) ?>
</form>

<div class="mt-6 space-y-2 border-t border-border pt-5 text-xs leading-relaxed text-muted-foreground">
    <p class="flex items-start gap-2">
        <?= icon('stethoscope', 'mt-0.5 h-3.5 w-3.5 shrink-0 text-slate-400') ?>
        <span><strong class="font-semibold text-foreground">Doctors:</strong> accounts are issued by your care-team administrator — contact them if you need access.</span>
    </p>
    <p class="flex items-start gap-2">
        <?= icon('clipboard-list', 'mt-0.5 h-3.5 w-3.5 shrink-0 text-slate-400') ?>
        <span><strong class="font-semibold text-foreground">Reception:</strong> front-desk accounts are issued by your administrator, and sign in here the same way.</span>
    </p>
    <p class="flex items-start gap-2">
        <?= icon('user', 'mt-0.5 h-3.5 w-3.5 shrink-0 text-slate-400') ?>
        <span>New patient? <a href="register.php" class="font-semibold text-primary hover:underline">Create your account</a></span>
    </p>
    <p class="flex items-start gap-2">
        <?= icon('key-round', 'mt-0.5 h-3.5 w-3.5 shrink-0 text-slate-400') ?>
        <span>Forgot your password? <a href="forgot_password.php" class="font-semibold text-primary hover:underline">Reset it by email</a></span>
    </p>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
