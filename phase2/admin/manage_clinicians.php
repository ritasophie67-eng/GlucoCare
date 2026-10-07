<?php
// Only hospital administrators can create, deactivate or reset doctor accounts.
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$pdo = get_db_connection();
$adminId = (int)$_SESSION['user_id'];
$error = '';
$f = ['full_name' => '', 'specialization' => '', 'license_no' => '', 'username' => '', 'email' => ''];
$specializations = [
    'Endocrinology & Diabetology', 'Internal Medicine', 'Family Medicine / General Practice',
    'Diabetic Foot & Wound Care', 'Clinical Diabetology Nurse Specialist',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        $error = 'Your session expired. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create') {
            foreach ($f as $k => $_) {
                $f[$k] = trim($_POST[$k] ?? '');
            }
            if (in_array('', $f, true)) {
                $error = 'Every field is required to credential a doctor.';
            } elseif (!in_array($f['specialization'], $specializations, true)) {
                $error = 'Choose a specialization from the list.';
            } elseif (!preg_match('/^[A-Za-z0-9_.-]{3,30}$/', $f['username'])) {
                $error = 'Username must be 3-30 characters: letters, numbers, dot, dash or underscore.';
            } elseif (!filter_var($f['email'], FILTER_VALIDATE_EMAIL)) {
                $error = 'Enter a valid email address.';
            } else {
                $dupe = $pdo->prepare('SELECT 1 FROM users WHERE username = ? OR email = ?');
                $dupe->execute([$f['username'], $f['email']]);
                $lic = $pdo->prepare('SELECT 1 FROM clinicians WHERE license_no = ?');
                $lic->execute([$f['license_no']]);
                if ($dupe->fetch()) {
                    $error = 'That username or email is already in use.';
                } elseif ($lic->fetch()) {
                    $error = 'A doctor with that licence number already exists.';
                } else {
                    $temp = generate_temp_password();
                    try {
                        $pdo->beginTransaction();
                        $pdo->prepare("INSERT INTO users (username, password_hash, role, email, must_change_password) VALUES (?, ?, 'clinician', ?, 1)")
                            ->execute([$f['username'], password_hash($temp, PASSWORD_DEFAULT), $f['email']]);
                        $uid = (int)$pdo->lastInsertId();
                        $pdo->prepare('INSERT INTO clinicians (user_id, full_name, specialization, license_no) VALUES (?, ?, ?, ?)')
                            ->execute([$uid, $f['full_name'], $f['specialization'], $f['license_no']]);
                        log_audit($pdo, $adminId, 'clinicians', 'PROVISION', "Admin created doctor account {$f['username']} ({$f['full_name']}, licence {$f['license_no']}).");
                        $pdo->commit();
                        set_flash('success', "Doctor account created. Username: {$f['username']} · Temporary password: {$temp} — give it to the doctor now; it is shown only once and must be changed at first sign-in.");
                        redirect('admin/manage_clinicians.php');
                    } catch (PDOException $ex) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        error_log('Provision failed: ' . $ex->getMessage());
                        $error = 'Could not create the account. Please try again.';
                    }
                }
            }
        } elseif (in_array($action, ['toggle', 'reset'], true)) {
            $cid = (int)($_POST['clinician_id'] ?? 0);
            $stmt = $pdo->prepare('SELECT c.full_name, u.user_id, u.username, u.is_active FROM clinicians c JOIN users u ON u.user_id = c.user_id WHERE c.clinician_id = ?');
            $stmt->execute([$cid]);
            $doc = $stmt->fetch();
            if (!$doc) {
                $error = 'Doctor not found.';
            } elseif ($action === 'toggle') {
                $new = $doc['is_active'] ? 0 : 1;
                $pdo->prepare('UPDATE users SET is_active = ? WHERE user_id = ?')->execute([$new, $doc['user_id']]);
                log_audit($pdo, $adminId, 'clinicians', $new ? 'ACTIVATE' : 'DEACTIVATE', "Admin " . ($new ? 'reactivated' : 'deactivated') . " {$doc['username']}.");
                set_flash('success', "{$doc['full_name']} " . ($new ? 'can sign in again.' : 'has been deactivated.'));
                redirect('admin/manage_clinicians.php');
            } else {
                $temp = generate_temp_password();
                $pdo->prepare('UPDATE users SET password_hash = ?, must_change_password = 1 WHERE user_id = ?')
                    ->execute([password_hash($temp, PASSWORD_DEFAULT), $doc['user_id']]);
                log_audit($pdo, $adminId, 'clinicians', 'PASSWORD_RESET', "Admin reset the password for {$doc['username']}.");
                set_flash('success', "New temporary password for {$doc['username']}: {$temp} — shown only once; they must change it at next sign-in.");
                redirect('admin/manage_clinicians.php');
            }
        }
    }
}

$doctors = $pdo->query('
    SELECT c.*, u.username, u.email, u.is_active, u.last_login_at,
           (SELECT COUNT(*) FROM patients p WHERE p.clinician_id = c.clinician_id) AS patient_count
    FROM clinicians c JOIN users u ON u.user_id = c.user_id ORDER BY c.full_name')->fetchAll();

$page_title = 'Doctors';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
    <div><h2>Doctors</h2><p>Doctors cannot register themselves — the hospital verifies credentials and issues every account.</p></div>
    <button class="btn btn-primary" data-bs-toggle="collapse" data-bs-target="#addDoctor"><i class="bi bi-person-plus-fill me-1"></i>Add a doctor</button>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<div class="collapse <?= $error ? 'show' : '' ?> mb-4" id="addDoctor">
    <div class="card">
        <div class="card-header"><i class="bi bi-shield-plus text-primary me-2"></i>Credential a new doctor</div>
        <div class="card-body">
            <p class="small text-muted">Verify the doctor's UMDPC licence before issuing access. A temporary password is generated automatically.</p>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label">Full name</label><input class="form-control" name="full_name" value="<?= e($f['full_name']) ?>" required></div>
                    <div class="col-md-6"><label class="form-label">Licence number (UMDPC)</label><input class="form-control" name="license_no" value="<?= e($f['license_no']) ?>" required></div>
                    <div class="col-md-6">
                        <label class="form-label">Specialization</label>
                        <select class="form-select" name="specialization" required>
                            <option value="">Select…</option>
                            <?php foreach ($specializations as $s): ?>
                                <option <?= $f['specialization'] === $s ? 'selected' : '' ?>><?= e($s) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6"><label class="form-label">Hospital email</label><input class="form-control" type="email" name="email" value="<?= e($f['email']) ?>" required></div>
                    <div class="col-md-6"><label class="form-label">Username</label><input class="form-control" name="username" value="<?= e($f['username']) ?>" required></div>
                </div>
                <div class="text-end mt-3"><button class="btn btn-primary" type="submit">Create account</button></div>
            </form>
        </div>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th class="ps-3">Doctor</th><th>Licence</th><th>Account</th><th>Patients</th><th>Last sign-in</th><th>Status</th><th class="text-end pe-3">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($doctors as $d): ?>
                <tr>
                    <td class="ps-3"><div class="fw-semibold"><?= e($d['full_name']) ?></div><div class="small text-muted"><?= e($d['specialization']) ?></div></td>
                    <td><code><?= e($d['license_no']) ?></code></td>
                    <td><div><?= e($d['username']) ?></div><div class="small text-muted"><?= e($d['email']) ?></div></td>
                    <td><span class="chip"><?= (int)$d['patient_count'] ?></span></td>
                    <td class="small text-muted"><?= $d['last_login_at'] ? e(date('d M Y, H:i', strtotime($d['last_login_at']))) : 'Never' ?></td>
                    <td><?= $d['is_active'] ? '<span class="chip chip-ok">Active</span>' : '<span class="chip chip-muted">Deactivated</span>' ?></td>
                    <td class="text-end pe-3 text-nowrap">
                        <form method="post" class="d-inline" onsubmit="return confirm('Reset this doctor\'s password?')">
                            <?= csrf_field() ?><input type="hidden" name="action" value="reset"><input type="hidden" name="clinician_id" value="<?= (int)$d['clinician_id'] ?>">
                            <button class="btn btn-sm btn-outline-primary">Reset password</button>
                        </form>
                        <form method="post" class="d-inline" onsubmit="return confirm('Change this doctor\'s access?')">
                            <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="clinician_id" value="<?= (int)$d['clinician_id'] ?>">
                            <button class="btn btn-sm <?= $d['is_active'] ? 'btn-outline-danger' : 'btn-outline-success' ?>"><?= $d['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
