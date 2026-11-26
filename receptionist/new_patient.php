<?php
// Register a patient at the desk. The account is created with a one-time password and no
// doctor: an administrator assigns the doctor, exactly as for self-registration.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/receptionist.php';
require_role('receptionist');

$pdo = get_db_connection();

$errors = [];
$f = ['full_name' => '', 'username' => '', 'email' => '', 'phone' => '', 'date_of_birth' => '', 'gender' => 'female'];
$created = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($f as $k => $_) {
        $f[$k] = trim((string)($_POST[$k] ?? ''));
    }

    if (!csrf_valid()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (in_array('', [$f['full_name'], $f['phone'], $f['date_of_birth']], true)) {
        $errors[] = 'Full name, phone number and date of birth are required.';
    } elseif (($phone = phone_normalize($f['phone'])) === null) {
        $errors[] = PHONE_ERROR;
    } elseif (!in_array($f['gender'], ['male', 'female', 'other'], true)) {
        $errors[] = 'Choose a valid gender option.';
    } elseif (!($dob = DateTime::createFromFormat('Y-m-d', $f['date_of_birth'])) || $dob > new DateTime() || $dob < new DateTime('-120 years')) {
        $errors[] = 'Enter a valid date of birth.';
    } elseif ($f['email'] !== '' && !filter_var($f['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address, or leave it empty.';
    } else {
        // The phone number is the identity the clinic recognises; a second record on the
        // same number is almost always a duplicate, so refuse it and point at the record.
        $dupe = $pdo->prepare('SELECT patient_id, full_name FROM patients WHERE phone = ? LIMIT 1');
        $dupe->execute([$phone]);
        $existing = $dupe->fetch();

        if ($existing) {
            $errors[] = 'A patient record already uses that phone number: '
                . patient_code((int)$existing['patient_id']) . '. Open it from the register instead of creating a duplicate.';
        } else {
            // Username and email: generated if the desk left them blank, so a patient who
            // has no email can still be registered and later sign in.
            $username = $f['username'] !== '' ? $f['username'] : '';
            if ($username === '') {
                $base = strtolower(preg_replace('/[^a-z0-9]+/i', '.', $f['full_name']));
                $base = trim($base, '.') ?: 'patient';
                $username = mb_substr($base, 0, 20) . random_int(100, 999);
            }
            $email = $f['email'] !== '' ? $f['email'] : null;
            $synthetic = $email === null;

            if (!preg_match('/^[A-Za-z0-9_.-]{3,30}$/', $username)) {
                $errors[] = 'Username must be 3-30 characters: letters, numbers, dot, dash or underscore.';
            } else {
                $chk = $pdo->prepare('SELECT 1 FROM users WHERE username = ?');
                $chk->execute([$username]);
                if ($chk->fetch()) {
                    $errors[] = 'That username is already taken.';
                } elseif ($email !== null) {
                    $dupeEmail = $pdo->prepare('SELECT 1 FROM users WHERE email = ?');
                    $dupeEmail->execute([$email]);
                    if ($dupeEmail->fetch()) {
                        $errors[] = 'That email address is already in use by another account.';
                    }
                }
            }

            if (!$errors) {
                $temp = generate_temp_password();
                try {
                    $pdo->beginTransaction();
                    // Synthetic addresses keep the NOT NULL UNIQUE column usable without
                    // inventing an inbox the patient cannot read.
                    $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role, email, must_change_password, org_id)
                                           VALUES (?, ?, 'patient', ?, 1, ?) RETURNING user_id");
                    $stmt->execute([$username, password_hash($temp, PASSWORD_DEFAULT),
                        $email ?? $username . '@no-email.invalid', current_org_id()]);
                    $userId = (int)$stmt->fetchColumn();

                    $stmt = $pdo->prepare('INSERT INTO patients (user_id, full_name, date_of_birth, gender, phone)
                                           VALUES (?, ?, ?, ?, ?) RETURNING patient_id');
                    $stmt->execute([$userId, $f['full_name'], $dob->format('Y-m-d'), $f['gender'], $phone]);
                    $patientId = (int)$stmt->fetchColumn();
                    $pdo->prepare('INSERT INTO patient_thresholds (patient_id) VALUES (?)')->execute([$patientId]);

                    log_audit($pdo, (int)$_SESSION['user_id'], 'patients', 'REGISTER',
                        "Reception registered patient #{$patientId} ({$f['full_name']}) at the desk.");
                    $pdo->commit();

                    $created = [
                        'name' => $f['full_name'],
                        'patient_id' => $patientId,
                        'code' => patient_code($patientId),
                        'username' => $username,
                        'temp' => $temp,
                        'no_email' => $synthetic,
                    ];
                    $f = ['full_name' => '', 'username' => '', 'email' => '', 'phone' => '', 'date_of_birth' => '', 'gender' => 'female'];
                } catch (PDOException $ex) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('Desk registration failed: ' . $ex->getMessage());
                    $errors[] = 'Could not save the patient. Please try again.';
                }
            }
        }
    }
}

$page_title = 'Register patient';
require __DIR__ . '/../includes/header.php';
?>
<div class="space-y-6">
    <?= ui_page_header(
        'Register a patient',
        'Create the record, then hand the patient their one-time password. A doctor is assigned by an administrator, not at the desk.',
        ui_button('Patient register', ['href' => 'patients.php', 'variant' => 'outline', 'icon' => 'arrow-left'])
    ) ?>

    <?php if ($created): ?>
        <div class="<?= UI_CARD ?> border-l-4 border-l-success p-5" role="status">
            <div class="flex items-start gap-3">
                <span class="mt-0.5 grid h-9 w-9 shrink-0 place-items-center rounded-full bg-success/10 text-success"><?= icon('check-circle', 'h-4 w-4') ?></span>
                <div class="min-w-0 space-y-1">
                    <p class="text-sm font-semibold"><?= e($created['name']) ?> registered as <?= e($created['code']) ?></p>
                    <p class="text-xs leading-relaxed text-muted-foreground">Write these down now — the password is shown only once.</p>
                    <dl class="mt-2 grid gap-1 text-sm sm:grid-cols-2">
                        <div><dt class="inline text-muted-foreground">Username: </dt><dd class="inline font-mono font-semibold"><?= e($created['username']) ?></dd></div>
                        <div><dt class="inline text-muted-foreground">One-time password: </dt><dd class="inline font-mono font-semibold"><?= e($created['temp']) ?></dd></div>
                    </dl>
                    <?php if ($created['no_email']): ?>
                        <p class="text-xs text-muted-foreground">No email was given, so password resets must be done by an administrator.</p>
                    <?php endif; ?>
                    <div class="flex flex-wrap gap-2 pt-2">
                        <?= ui_button('Record vitals', ['href' => 'vitals.php?patient_id=' . (int)$created['patient_id'], 'size' => 'sm', 'icon' => 'heart-pulse']) ?>
                        <?= ui_button('Send a referral', ['href' => 'referrals.php?patient_id=' . (int)$created['patient_id'], 'size' => 'sm', 'variant' => 'outline', 'icon' => 'arrow-right']) ?>
                        <?= ui_button('Back to register', ['href' => 'patients.php', 'size' => 'sm', 'variant' => 'ghost']) ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php foreach ($errors as $err): ?>
        <?= ui_alert('destructive', 'Patient not registered', e($err)) ?>
    <?php endforeach; ?>

    <div class="grid gap-6 lg:grid-cols-5">
        <section class="lg:col-span-3 <?= UI_CARD ?>" aria-labelledby="new-patient">
            <div class="<?= UI_CARD_HEADER ?>">
                <h2 id="new-patient" class="<?= UI_CARD_TITLE ?>">Patient details</h2>
                <span class="text-xs text-muted-foreground">Reference is assigned automatically</span>
            </div>
            <div class="<?= UI_CARD_BODY ?>">
                <form method="post" class="space-y-4">
                    <?= csrf_field() ?>
                    <?= ui_field(['id' => 'full_name', 'name' => 'full_name', 'label' => 'Full name', 'value' => $f['full_name'],
                        'required' => true, 'attrs' => 'autocomplete="off" autofocus']) ?>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <?= ui_field(['id' => 'phone', 'name' => 'phone', 'type' => 'tel', 'label' => 'Phone', 'value' => $f['phone'],
                            'required' => true, 'attrs' => 'autocomplete="tel" inputmode="tel"',
                            'hint' => 'Ugandan number. Duplicate numbers are refused to stop double records.']) ?>
                        <?= ui_field(['id' => 'date_of_birth', 'name' => 'date_of_birth', 'type' => 'date', 'label' => 'Date of birth',
                            'value' => $f['date_of_birth'], 'required' => true, 'attrs' => 'max="' . date('Y-m-d') . '"']) ?>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <?= ui_field(['id' => 'gender', 'name' => 'gender', 'tag' => 'select', 'label' => 'Gender',
                            'children' => implode('', array_map(
                                fn($v, $l) => '<option value="' . e($v) . '"' . ($f['gender'] === $v ? ' selected' : '') . '>' . e($l) . '</option>',
                                ['female', 'male', 'other'], ['Female', 'Male', 'Other']
                            ))]) ?>
                        <?= ui_field(['id' => 'email', 'name' => 'email', 'type' => 'email', 'label' => 'Email (optional)',
                            'value' => $f['email'], 'attrs' => 'autocomplete="off"',
                            'hint' => 'Needed only for password resets by email.']) ?>
                    </div>
                    <?= ui_field(['id' => 'username', 'name' => 'username', 'label' => 'Username (optional)',
                        'value' => $f['username'], 'attrs' => 'autocomplete="off"',
                        'hint' => 'Leave blank to generate one from the name.']) ?>
                    <?= ui_button('Create patient record', ['type' => 'submit', 'icon' => 'user-plus', 'size' => 'lg', 'class' => 'w-full']) ?>
                </form>
            </div>
        </section>

        <aside class="lg:col-span-2 space-y-4">
            <?= ui_alert('info', 'What the desk records', 'Identity and contact details only. Allergies, diagnoses and glucose readings are captured by the care team, not here.') ?>
            <?= ui_alert('warning', 'The one-time password', 'The patient signs in with it once and is forced to choose their own password. If they cannot sign in, an administrator resets it.', 'key-round') ?>
        </aside>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
