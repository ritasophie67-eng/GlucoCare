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
                        $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role, email, must_change_password, org_id)
                                               VALUES (?, ?, 'clinician', ?, 1, ?) RETURNING user_id");
                        $stmt->execute([$f['username'], password_hash($temp, PASSWORD_DEFAULT), $f['email'], current_org_id()]);
                        $uid = (int)$stmt->fetchColumn();
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

$stmt = $pdo->prepare('
    SELECT c.*, u.username, u.email, u.is_active, u.last_login_at,
           (SELECT COUNT(*) FROM patients p WHERE p.clinician_id = c.clinician_id) AS patient_count
    FROM clinicians c JOIN users u ON u.user_id = c.user_id WHERE u.org_id = ? ORDER BY c.full_name');
$stmt->execute([current_org_id()]);
$doctors = $stmt->fetchAll();

$page_title = 'Doctors';
require __DIR__ . '/../includes/header.php';
?>
<div class="space-y-6">
    <?= ui_page_header(
        'Doctors',
        'Doctors cannot register themselves — your administrator verifies credentials and issues every account.',
        ui_button('Add a doctor', ['attrs' => 'data-dialog-target="add-doctor-dialog"', 'icon' => 'user-plus'])
    ) ?>

    <?php if ($error): ?>
        <?= ui_alert('destructive', 'Action could not be completed', e($error)) ?>
    <?php endif; ?>

    <section class="<?= UI_CARD ?>" aria-labelledby="doctor-list">
        <div class="<?= UI_CARD_HEADER ?>">
            <h2 id="doctor-list" class="<?= UI_CARD_TITLE ?>">Credentialed clinicians</h2>
            <span class="text-xs text-muted-foreground"><?= count($doctors) ?> accounts</span>
        </div>
        <div class="overflow-x-auto scroll-area">
            <table class="w-full min-w-[880px] border-collapse text-sm">
                <caption class="sr-only">Doctor accounts with licence, patient count, sign-in activity and status.</caption>
                <thead>
                    <tr class="border-b border-border bg-muted/50 text-left text-[0.68rem] uppercase tracking-[0.07em] text-muted-foreground">
                        <th scope="col" class="px-5 py-2.5 font-semibold">Doctor</th>
                        <th scope="col" class="px-3 py-2.5 font-semibold">Licence</th>
                        <th scope="col" class="px-3 py-2.5 font-semibold">Account</th>
                        <th scope="col" class="px-3 py-2.5 font-semibold">Patients</th>
                        <th scope="col" class="px-3 py-2.5 font-semibold">Last sign-in</th>
                        <th scope="col" class="px-3 py-2.5 font-semibold">Status</th>
                        <th scope="col" class="px-5 py-2.5 text-right font-semibold"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                <?php foreach ($doctors as $d): ?>
                    <tr class="hover:bg-muted/40">
                        <td class="px-5 py-3">
                            <p class="font-medium"><?= e($d['full_name']) ?></p>
                            <p class="text-xs text-muted-foreground"><?= e($d['specialization']) ?></p>
                        </td>
                        <td class="px-3 py-3"><code class="rounded bg-muted px-1.5 py-0.5 text-xs"><?= e($d['license_no']) ?></code></td>
                        <td class="px-3 py-3">
                            <p><?= e($d['username']) ?></p>
                            <p class="text-xs text-muted-foreground"><?= e($d['email']) ?></p>
                        </td>
                        <td class="px-3 py-3"><?= ui_badge((string)(int)$d['patient_count'], 'secondary') ?></td>
                        <td class="px-3 py-3 text-xs text-muted-foreground"><?= $d['last_login_at'] ? e(date('d M Y, H:i', strtotime($d['last_login_at']))) : 'Never' ?></td>
                        <td class="px-3 py-3"><?= $d['is_active'] ? ui_badge('Active', 'success') : ui_badge('Deactivated', 'muted') ?></td>
                        <td class="px-5 py-3 text-right">
                            <div class="inline-flex items-center gap-1.5">
                                <form method="post" onsubmit="return confirm('Reset this doctor\'s password?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="reset">
                                    <input type="hidden" name="clinician_id" value="<?= (int)$d['clinician_id'] ?>">
                                    <?= ui_button('Reset password', ['type' => 'submit', 'variant' => 'outline', 'size' => 'sm']) ?>
                                </form>
                                <form method="post" onsubmit="return confirm('Change this doctor\'s access?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="clinician_id" value="<?= (int)$d['clinician_id'] ?>">
                                    <?= ui_button($d['is_active'] ? 'Deactivate' : 'Activate', [
                                        'type' => 'submit', 'size' => 'sm',
                                        'variant' => $d['is_active'] ? 'destructive' : 'success',
                                    ]) ?>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$doctors): ?>
                    <tr><td colspan="7"><?= ui_empty('stethoscope', 'No doctors yet', 'Credential the first clinician to start assigning patients.') ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<?php
$specializationOptions = '<option value="">Select…</option>';
foreach ($specializations as $s) {
    $specializationOptions .= '<option' . ($f['specialization'] === $s ? ' selected' : '') . '>' . e($s) . '</option>';
}
$dialogBody = '<p class="mb-4 flex items-start gap-2 rounded-md border border-border bg-muted/50 px-3.5 py-2.5 text-xs leading-relaxed text-muted-foreground">'
    . icon('shield-check', 'mt-0.5 h-3.5 w-3.5 shrink-0 text-success')
    . '<span>Verify the doctor\'s UMDPC licence before issuing access. A temporary password is generated automatically and must be changed at first sign-in.</span></p>'
    . '<div class="grid gap-4 sm:grid-cols-2">'
    . ui_field(['id' => 'cf_full_name', 'name' => 'full_name', 'label' => 'Full name', 'value' => $f['full_name'], 'required' => true])
    . ui_field(['id' => 'cf_license_no', 'name' => 'license_no', 'label' => 'Licence number (UMDPC)', 'value' => $f['license_no'], 'required' => true])
    . '<div class="sm:col-span-2">' . ui_field(['id' => 'cf_specialization', 'name' => 'specialization', 'tag' => 'select',
        'label' => 'Specialization', 'required' => true, 'children' => $specializationOptions]) . '</div>'
    . ui_field(['id' => 'cf_email', 'name' => 'email', 'type' => 'email', 'label' => 'Work email', 'value' => $f['email'], 'required' => true])
    . ui_field(['id' => 'cf_username', 'name' => 'username', 'label' => 'Username', 'value' => $f['username'], 'required' => true])
    . '</div>'
    . '<p class="mt-4 text-xs text-muted-foreground">The temporary password is shown once, in the confirmation message after this form is submitted.</p>';

echo '<dialog id="add-doctor-dialog" class="app-dialog max-w-2xl" aria-labelledby="add-doctor-title"'
    . ($error ? ' data-autoshow="1"' : '') . '>'
    . '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="create">'
    . '<div class="flex items-start justify-between gap-4 px-6 pb-4 pt-6">'
    . '<div class="flex items-start gap-3">'
    . '<span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-primary/10 text-primary">' . icon('user-plus', 'h-4 w-4') . '</span>'
    . '<div><h2 id="add-doctor-title" class="text-base font-semibold leading-6">Credential a new doctor</h2>'
    . '<p class="mt-1 text-sm text-muted-foreground">Create the clinician account and share the one-time password.</p></div></div>'
    . '<button type="button" data-dialog-close aria-label="Close dialog" class="grid h-8 w-8 shrink-0 place-items-center rounded-md text-muted-foreground transition hover:bg-muted hover:text-foreground">' . icon('x', 'h-4 w-4') . '</button>'
    . '</div>'
    . '<div class="app-dialog-body border-t border-border">' . $dialogBody . '</div>'
    . '<div class="flex flex-wrap justify-end gap-2 border-t border-border bg-muted/40 px-6 py-4">'
    . ui_button('Cancel', ['variant' => 'outline', 'attrs' => 'data-dialog-close'])
    . ui_button('Create account', ['type' => 'submit', 'icon' => 'check'])
    . '</div></form></dialog>';
?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
