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
        if ($chk->fetch()) {
            $error = 'That username or email is already registered.';
        } else {
            try {
                $pdo->beginTransaction();
                $pdo->prepare("INSERT INTO users (username, password_hash, role, email) VALUES (?, ?, 'patient', ?)")
                    ->execute([$f['username'], password_hash($password, PASSWORD_DEFAULT), $f['email']]);
                $userId = (int)$pdo->lastInsertId();

                $pdo->prepare('INSERT INTO patients (user_id, full_name, date_of_birth, gender, phone) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$userId, $f['full_name'], $f['date_of_birth'], $f['gender'], $f['phone']]);
                $patientId = (int)$pdo->lastInsertId();

                $pdo->prepare('INSERT INTO patient_thresholds (patient_id) VALUES (?)')->execute([$patientId]);
                log_audit($pdo, $userId, 'patients', 'REGISTER', "New patient registered: {$f['full_name']} (patient #{$patientId}).");
                $pdo->commit();

                set_flash('success', 'Account created. You can sign in now — the hospital will assign your doctor.');
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
<h2 class="mb-1">Patient registration</h2>
<p class="text-muted mb-4">Create your account. Your doctor will be assigned by the hospital.</p>

<?php if ($error): ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i> <?= e($error) ?></div>
<?php endif; ?>

<form method="post">
    <?= csrf_field() ?>
    <div class="row g-3">
        <div class="col-12">
            <label class="form-label">Full name</label>
            <input class="form-control" name="full_name" value="<?= e($f['full_name']) ?>" required>
        </div>
        <div class="col-sm-6">
            <label class="form-label">Username</label>
            <input class="form-control" name="username" value="<?= e($f['username']) ?>" required>
        </div>
        <div class="col-sm-6">
            <label class="form-label">Email</label>
            <input class="form-control" type="email" name="email" value="<?= e($f['email']) ?>" required>
        </div>
        <div class="col-sm-6">
            <label class="form-label">Phone</label>
            <input class="form-control" type="tel" name="phone" value="<?= e($f['phone']) ?>" required>
        </div>
        <div class="col-sm-6">
            <label class="form-label">Date of birth</label>
            <input class="form-control" type="date" name="date_of_birth" max="<?= date('Y-m-d') ?>" value="<?= e($f['date_of_birth']) ?>" required>
        </div>
        <div class="col-12">
            <label class="form-label">Gender</label>
            <select class="form-select" name="gender">
                <?php foreach (['female' => 'Female', 'male' => 'Male', 'other' => 'Other'] as $v => $l): ?>
                    <option value="<?= $v ?>" <?= $f['gender'] === $v ? 'selected' : '' ?>><?= $l ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-sm-6">
            <label class="form-label">Password</label>
            <input class="form-control" type="password" name="password" required minlength="8">
        </div>
        <div class="col-sm-6">
            <label class="form-label">Confirm password</label>
            <input class="form-control" type="password" name="confirm_password" required minlength="8">
        </div>
        <div class="col-12 form-text mt-0">At least 8 characters, with a letter and a number.</div>
    </div>
    <button class="btn btn-primary w-100 py-2 mt-4" type="submit">Create account</button>
</form>
<p class="small text-center mt-3 mb-0">Already registered? <a href="login.php" class="fw-semibold">Sign in</a></p>
<?php require __DIR__ . '/includes/footer.php'; ?>
