<?php
// Patient self-registration. Doctors can NOT register here: the hospital creates their accounts.
// New patients start unassigned; an administrator assigns the doctor.
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    redirect(home_for_role($_SESSION['role']));
}

$pdo = get_db_connection();
$error = '';
$f = ['full_name' => '', 'username' => '', 'email' => '', 'phone' => '', 'date_of_birth' => '', 'gender' => 'female'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($f as $k => $_) {
        $f[$k] = trim($_POST[$k] ?? '');
    }
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (!csrf_valid()) {
        $error = 'Your session expired. Please try again.';
    } elseif (in_array('', [$f['full_name'], $f['username'], $f['email'], $f['phone'], $f['date_of_birth']], true)) {
        $error = 'Please fill in every field.';
    } elseif (!preg_match('/^[A-Za-z0-9_.-]{3,30}$/', $f['username'])) {
        $error = 'Username must be 3-30 characters: letters, numbers, dot, dash or underscore.';
    } elseif (!filter_var($f['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } elseif (($phone = phone_normalize($f['phone'])) === null) {
        $error = PHONE_ERROR;
    } elseif (!in_array($f['gender'], ['male', 'female', 'other'], true)) {
        $error = 'Choose a valid gender option.';
    } elseif (!($dob = DateTime::createFromFormat('Y-m-d', $f['date_of_birth'])) || $dob > new DateTime() || $dob < new DateTime('-120 years')) {
        $error = 'Enter a valid date of birth.';
    } elseif ($password !== $confirm) {
        $error = 'The two passwords do not match.';
    } elseif ($problem = password_problem($password)) {
        $error = $problem;
    } else {
        $chk = $pdo->prepare('SELECT 1 FROM users WHERE username = ? OR email = ?');
        $chk->execute([$f['username'], $f['email']]);
        // The phone number is the identity a clinic actually recognises, so a second
        // account on the same number is almost always a duplicate record.
        $dupePhone = $pdo->prepare('SELECT 1 FROM patients WHERE phone = ?');
        $dupePhone->execute([$phone]);
        if ($chk->fetch()) {
            $error = 'That username or email is already registered.';
        } elseif ($dupePhone->fetch()) {
            $error = 'A patient account already exists with that phone number. If that is you, sign in instead or contact your clinic.';
        } else {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role, email) VALUES (?, ?, 'patient', ?) RETURNING user_id");
                $stmt->execute([$f['username'], password_hash($password, PASSWORD_DEFAULT), $f['email']]);
                $userId = (int)$stmt->fetchColumn();

                $stmt = $pdo->prepare('INSERT INTO patients (user_id, full_name, date_of_birth, gender, phone) VALUES (?, ?, ?, ?, ?) RETURNING patient_id');
                $stmt->execute([$userId, $f['full_name'], $f['date_of_birth'], $f['gender'], $phone]);
                $patientId = (int)$stmt->fetchColumn();

                $pdo->prepare('INSERT INTO patient_thresholds (patient_id) VALUES (?)')->execute([$patientId]);
                log_audit($pdo, $userId, 'patients', 'REGISTER', "New patient registered: {$f['full_name']} (patient #{$patientId}).");
                $pdo->commit();

                set_flash('success', 'Account created. You can sign in now — your care team will assign your doctor.');
                redirect('login.php');
            } catch (PDOException $ex) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Registration failed: ' . $ex->getMessage());
                $error = 'Registration failed. Please try again.';
            }
        }
    }
}

$page_title = 'Create account';
$layout = 'auth';
require __DIR__ . '/includes/header.php';
?>
<div class="mb-6">
    <h1 class="text-2xl font-semibold tracking-tight">Patient registration</h1>
    <p class="mt-1 text-sm text-muted-foreground">Create your account. Your doctor is assigned by your care team.</p>
</div>

<?php if ($error): ?>
    <?= ui_alert('destructive', 'Account not created', e($error), 'alert-triangle', 'mb-5') ?>
<?php endif; ?>

<form method="post" class="space-y-4">
    <?= csrf_field() ?>
    <?= ui_field(['id' => 'full_name', 'name' => 'full_name', 'label' => 'Full name', 'value' => $f['full_name'],
        'required' => true, 'attrs' => 'autocomplete="name" autofocus']) ?>
    <div class="grid gap-4 sm:grid-cols-2">
        <?= ui_field(['id' => 'username', 'name' => 'username', 'label' => 'Username', 'value' => $f['username'],
            'required' => true, 'attrs' => 'autocomplete="username"']) ?>
        <?= ui_field(['id' => 'email', 'name' => 'email', 'type' => 'email', 'label' => 'Email', 'value' => $f['email'],
            'required' => true, 'attrs' => 'autocomplete="email"']) ?>
        <?= ui_field(['id' => 'phone', 'name' => 'phone', 'type' => 'tel', 'label' => 'Phone', 'value' => $f['phone'],
            'required' => true, 'attrs' => 'autocomplete="tel" inputmode="tel"',
            'hint' => 'Ugandan number, e.g. 0700 000 001. Saved once, shown the same everywhere.']) ?>
        <?= ui_field(['id' => 'date_of_birth', 'name' => 'date_of_birth', 'type' => 'date', 'label' => 'Date of birth',
            'value' => $f['date_of_birth'], 'required' => true, 'attrs' => 'max="' . date('Y-m-d') . '"']) ?>
    </div>
    <?= ui_field(['id' => 'gender', 'name' => 'gender', 'tag' => 'select', 'label' => 'Gender',
        'children' => implode('', array_map(
            fn($v, $l) => '<option value="' . e($v) . '"' . ($f['gender'] === $v ? ' selected' : '') . '>' . e($l) . '</option>',
            array_keys(['female' => 'Female', 'male' => 'Male', 'other' => 'Other']),
            ['Female', 'Male', 'Other']
        ))]) ?>
    <div class="grid gap-4 sm:grid-cols-2">
        <?= ui_field(['id' => 'password', 'name' => 'password', 'type' => 'password', 'label' => 'Password',
            'required' => true, 'attrs' => 'minlength="8" autocomplete="new-password"',
            'hint' => 'At least 8 characters, with a letter and a number.']) ?>
        <?= ui_field(['id' => 'confirm_password', 'name' => 'confirm_password', 'type' => 'password',
            'label' => 'Confirm password', 'required' => true, 'attrs' => 'minlength="8" autocomplete="new-password"']) ?>
    </div>
    <?= ui_button('Create account', ['type' => 'submit', 'class' => 'w-full', 'size' => 'lg', 'icon' => 'user-plus']) ?>
</form>

<p class="mt-5 border-t border-border pt-4 text-center text-xs text-muted-foreground">
    Already registered? <a href="login.php" class="font-semibold text-primary hover:underline">Sign in</a>
</p>
<?php require __DIR__ . '/includes/footer.php'; ?>
