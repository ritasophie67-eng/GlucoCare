<?php
// Referrals from the desk: send a patient on to a named doctor or a department, then
// track whether it has been picked up. The referral carries a reason from the desk, not
// a diagnosis.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/receptionist.php';
require_role('receptionist');

$pdo = get_db_connection();
$errors = [];
$patientId = (int)($_GET['patient_id'] ?? $_POST['patient_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create';

    if (!csrf_valid()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif ($action === 'cancel') {
        $referralId = (int)($_POST['referral_id'] ?? 0);
        // Only a still-pending referral for this organisation can be withdrawn.
        $stmt = $pdo->prepare('SELECT r.referral_id, r.patient_id, r.status
                               FROM referrals r
                               JOIN patients p ON p.patient_id = r.patient_id
                               JOIN users u ON u.user_id = p.user_id
                               WHERE r.referral_id = ? AND u.org_id = ?');
        $stmt->execute([$referralId, current_org_id()]);
        if (!($ref = $stmt->fetch())) {
            set_flash('danger', 'That referral is not in your register.');
        } elseif ($ref['status'] !== 'pending') {
            set_flash('warning', 'That referral has already been picked up, so it cannot be withdrawn.');
        } else {
            $pdo->prepare("UPDATE referrals SET status = 'cancelled', updated_at = NOW() WHERE referral_id = ?")->execute([$referralId]);
            log_audit($pdo, (int)$_SESSION['user_id'], 'referrals', 'REFERRAL_CANCELLED',
                "Desk cancelled referral #{$referralId} for patient #{$ref['patient_id']}.");
            set_flash('success', 'Referral withdrawn.');
        }
        redirect('receptionist/referrals.php');
    } else {
        $patientId = (int)($_POST['patient_id'] ?? 0);
        $patient = $patientId > 0 ? require_org_patient($pdo, $patientId) : null;

        if (!$patient) {
            $errors[] = 'Choose the patient being referred.';
        } else {
            $check = referral_validate($pdo, $_POST);
            $errors = $check['errors'];
            if (!$errors) {
                $d = $check['data'];
                $pdo->prepare('INSERT INTO referrals (patient_id, referred_by_user_id, clinician_id, department_id, urgency, reason)
                               VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$patientId, $_SESSION['user_id'], $d['clinician_id'] ?: null, $d['department_id'] ?: null, $d['urgency'], $d['reason']]);
                log_audit($pdo, (int)$_SESSION['user_id'], 'referrals', 'REFERRAL_CREATED',
                    "Desk referred patient #{$patientId} to " . ($d['clinician_id'] ? "clinician #{$d['clinician_id']}" : "department #{$d['department_id']}")
                    . " ({$d['urgency']}).");
                // A department referral for a patient with no doctor has no clinical owner:
                // it is routed to the administrator's queue, and the desk is told so.
                $unowned = !$d['clinician_id'] && !$patient['clinician_id'];
                $message = 'Referral sent for ' . patient_code($patientId) . '.';
                if ($unowned) {
                    $message .= ' No doctor is assigned to this patient yet, so it is waiting in the administrator\'s queue to be routed.';
                }
                if ($d['urgency'] === 'emergency') {
                    $message .= ' Emergency — tell the department now.';
                }
                set_flash($unowned || $d['urgency'] === 'emergency' ? 'warning' : 'success', $message);
                redirect('receptionist/referrals.php?patient_id=' . $patientId);
            }
        }
    }
}

$doctors = $pdo->query('SELECT c.clinician_id, c.full_name, c.specialization
                        FROM clinicians c JOIN users u ON u.user_id = c.user_id
                        WHERE u.is_active = 1 ORDER BY c.full_name')->fetchAll();
$departments = $pdo->prepare('SELECT department_id, name, code FROM departments WHERE is_active = 1 AND org_id = ? ORDER BY name');
$departments->execute([current_org_id()]);
$departments = $departments->fetchAll();

$status = $_GET['status'] ?? 'all';
if (!isset(REFERRAL_STATUSES[$status])) {
    $status = 'all';
}

$sql = 'SELECT r.*, p.patient_id, p.full_name AS patient_name, p.phone, p.clinician_id AS patient_clinician_id,
               c.full_name AS clinician_name, d.name AS department_name, u.username AS referred_by
        FROM referrals r
        JOIN patients p ON p.patient_id = r.patient_id
        JOIN users pu ON pu.user_id = p.user_id
        LEFT JOIN clinicians c ON c.clinician_id = r.clinician_id
        LEFT JOIN departments d ON d.department_id = r.department_id
        LEFT JOIN users u ON u.user_id = r.referred_by_user_id
        WHERE pu.org_id = ?';
$params = [current_org_id()];
if ($patientId > 0) {
    $sql .= ' AND r.patient_id = ?';
    $params[] = $patientId;
}
if ($status !== 'all') {
    $sql .= ' AND r.status = ?';
    $params[] = $status;
}
$sql .= " ORDER BY CASE r.urgency WHEN 'emergency' THEN 1 WHEN 'urgent' THEN 2 ELSE 3 END,
                    CASE r.status WHEN 'pending' THEN 1 ELSE 2 END, r.created_at DESC LIMIT 100";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$referrals = $stmt->fetchAll();

// Patient picker for the form; the linked patient is preselected.
$picker = $pdo->prepare("SELECT p.patient_id, p.full_name FROM patients p JOIN users u ON u.user_id = p.user_id
                         WHERE u.org_id = ? AND u.username NOT LIKE 'sim\\_%' ORDER BY p.full_name LIMIT 300");
$picker->execute([current_org_id()]);
$picker = $picker->fetchAll();

$selected = $patientId > 0
    ? $pdo->prepare('SELECT p.patient_id, p.full_name, p.clinician_id, c.full_name AS doctor
                     FROM patients p LEFT JOIN clinicians c ON c.clinician_id = p.clinician_id WHERE p.patient_id = ?')
    : null;
$current = null;
if ($selected) {
    $selected->execute([$patientId]);
    $current = $selected->fetch() ?: null;
}

$options = '<option value="">Select a patient…</option>';
foreach ($picker as $p) {
    $options .= '<option value="' . (int)$p['patient_id'] . '"' . ($patientId === (int)$p['patient_id'] ? ' selected' : '') . '>'
        . e($p['full_name'] . ' · ' . patient_code((int)$p['patient_id'])) . '</option>';
}
$doctorOptions = '<option value="">Select a doctor…</option>';
foreach ($doctors as $d) {
    $doctorOptions .= '<option value="' . (int)$d['clinician_id'] . '">' . e($d['full_name'] . ' — ' . $d['specialization']) . '</option>';
}
$deptOptions = '<option value="">Select a department…</option>';
foreach ($departments as $d) {
    $deptOptions .= '<option value="' . (int)$d['department_id'] . '">' . e($d['name']) . '</option>';
}
$urgencyOptions = '';
foreach (REFERRAL_URGENCIES as $key => $label) {
    $urgencyOptions .= '<option value="' . e($key) . '">' . e($label) . '</option>';
}

$page_title = 'Referrals';
require __DIR__ . '/../includes/header.php';
?>
<div class="space-y-6">
    <?= ui_page_header(
        'Referrals',
        'Send a patient to a named doctor or to a department. The doctor sees the reason you write here; nothing else about the visit is added by the desk.',
        ui_button('Patient register', ['href' => 'patients.php', 'variant' => 'outline', 'icon' => 'arrow-left'])
    ) ?>

    <?php foreach ($errors as $err): ?>
        <?= ui_alert('destructive', 'Referral not sent', e($err)) ?>
    <?php endforeach; ?>

    <div class="grid gap-6 lg:grid-cols-5">
        <section class="lg:col-span-2 <?= UI_CARD ?>" aria-labelledby="new-referral">
            <div class="<?= UI_CARD_HEADER ?>">
                <h2 id="new-referral" class="<?= UI_CARD_TITLE ?>">New referral</h2>
                <span class="text-primary"><?= icon('arrow-right', 'h-4 w-4') ?></span>
            </div>
            <div class="<?= UI_CARD_BODY ?>">
                <?php if ($current): ?>
                    <div class="mb-4 rounded-md border border-border bg-muted/40 px-3.5 py-2.5 text-xs leading-relaxed">
                        <p class="font-semibold text-foreground"><?= e($current['full_name']) ?></p>
                        <p class="text-muted-foreground">
                            <span class="font-mono"><?= e(patient_code((int)$current['patient_id'])) ?></span>
                            · <?= $current['doctor'] ? 'Doctor: ' . e($current['doctor']) : 'No doctor assigned' ?>
                        </p>
                    </div>
                <?php endif; ?>

                <form method="post" class="space-y-4">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create">
                    <?php if (!$current): ?>
                        <?= ui_field(['id' => 'ref_patient', 'name' => 'patient_id', 'tag' => 'select', 'label' => 'Patient',
                            'required' => true, 'children' => $options]) ?>
                    <?php else: ?>
                        <input type="hidden" name="patient_id" value="<?= (int)$current['patient_id'] ?>">
                    <?php endif; ?>

                    <fieldset class="space-y-2">
                        <legend class="<?= UI_LABEL ?>">Refer to</legend>
                        <div class="flex flex-wrap gap-3">
                            <label class="inline-flex items-center gap-2 text-sm">
                                <input type="radio" name="target" value="clinician" class="h-4 w-4" data-referral-target="clinician">
                                A doctor
                            </label>
                            <label class="inline-flex items-center gap-2 text-sm">
                                <input type="radio" name="target" value="department" class="h-4 w-4" checked data-referral-target="department">
                                A department
                            </label>
                        </div>
                    </fieldset>

                    <div data-referral-field="clinician" hidden>
                        <?= ui_field(['id' => 'ref_doctor', 'name' => 'clinician_id', 'tag' => 'select', 'label' => 'Doctor', 'children' => $doctorOptions]) ?>
                    </div>
                    <div data-referral-field="department">
                        <?= ui_field(['id' => 'ref_department', 'name' => 'department_id', 'tag' => 'select', 'label' => 'Department', 'children' => $deptOptions]) ?>
                    </div>

                    <?= ui_field(['id' => 'ref_urgency', 'name' => 'urgency', 'tag' => 'select', 'label' => 'Urgency',
                        'children' => $urgencyOptions,
                        'hint' => 'Emergency means the patient must be seen immediately — tell the department as well.']) ?>
                    <?= ui_field(['id' => 'ref_reason', 'name' => 'reason', 'tag' => 'textarea', 'rows' => 3,
                        'label' => 'Reason for referral', 'required' => true,
                        'attrs' => 'maxlength="255" placeholder="e.g. Walk-in with a foot wound, asked to see the foot clinic"']) ?>
                    <?= ui_button('Send referral', ['type' => 'submit', 'class' => 'w-full', 'icon' => 'arrow-right']) ?>
                </form>
            </div>
        </section>

        <section class="lg:col-span-3 <?= UI_CARD ?>" aria-labelledby="referral-list">
            <div class="<?= UI_CARD_HEADER ?>">
                <div>
                    <h2 id="referral-list" class="<?= UI_CARD_TITLE ?>">Referral log</h2>
                    <p class="mt-1 text-xs text-muted-foreground"><?= count($referrals) ?> shown<?= $current ? ' for ' . e($current['full_name']) : '' ?></p>
                </div>
                <div class="flex flex-wrap items-center gap-1.5">
                    <?php foreach (array_merge(['all' => 'All'], REFERRAL_STATUSES) as $key => $label): ?>
                        <a href="?<?= e(http_build_query(array_filter(['status' => $key, 'patient_id' => $patientId ?: null]))) ?>"
                           class="inline-flex h-8 items-center rounded-md border border-border px-2.5 text-xs font-medium transition-colors <?= $key === $status ? 'bg-primary text-primary-foreground' : 'bg-white text-muted-foreground hover:bg-muted' ?>">
                            <?= e($label) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="overflow-x-auto scroll-area">
                <table class="w-full min-w-[680px] border-collapse text-sm">
                    <caption class="sr-only">Referrals with patient, target, urgency and status.</caption>
                    <thead>
                        <tr class="border-b border-border bg-muted/50 text-left text-[0.68rem] uppercase tracking-[0.07em] text-muted-foreground">
                            <th scope="col" class="px-5 py-2.5 font-semibold">Patient</th>
                            <th scope="col" class="px-3 py-2.5 font-semibold">Refer to</th>
                            <th scope="col" class="px-3 py-2.5 font-semibold">Urgency</th>
                            <th scope="col" class="px-3 py-2.5 font-semibold">Status</th>
                            <th scope="col" class="px-5 py-2.5 text-right font-semibold"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                    <?php foreach ($referrals as $r): ?>
                        <tr class="hover:bg-muted/40">
                            <td class="px-5 py-3">
                                <p class="font-medium"><?= e($r['patient_name']) ?></p>
                                <p class="text-xs text-muted-foreground">
                                    <span class="font-mono"><?= e(patient_code((int)$r['patient_id'])) ?></span>
                                    · <?= e(date('d M, H:i', strtotime($r['created_at']))) ?>
                                    · by <?= e($r['referred_by']) ?>
                                </p>
                            </td>
                            <td class="px-3 py-3">
                                <p><?= e(referral_target($r)) ?></p>
                                <p class="max-w-[16rem] truncate text-xs text-muted-foreground" title="<?= e($r['reason']) ?>"><?= e($r['reason']) ?></p>
                            </td>
                            <td class="px-3 py-3"><?= referral_urgency_badge($r['urgency']) ?></td>
                            <td class="px-3 py-3">
                                <?= referral_status_badge($r['status']) ?>
                                <?php if ($r['status'] === 'pending' && !$r['clinician_id'] && !$r['patient_clinician_id']): ?>
                                    <span class="mt-1 block" title="No doctor is assigned to this patient, so the administrator must route it."><?= ui_badge('Needs a doctor', 'warning') ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="px-5 py-3 text-right">
                                <?php if ($r['status'] === 'pending'): ?>
                                    <form method="post" onsubmit="return confirm('Withdraw this referral?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="cancel">
                                        <input type="hidden" name="referral_id" value="<?= (int)$r['referral_id'] ?>">
                                        <?= ui_button('Withdraw', ['type' => 'submit', 'variant' => 'outline', 'size' => 'sm']) ?>
                                    </form>
                                <?php else: ?>
                                    <span class="text-xs text-muted-foreground"><?= $r['updated_at'] ? e(date('d M, H:i', strtotime($r['updated_at']))) : '—' ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$referrals): ?>
                        <tr><td colspan="5"><?= ui_empty('arrow-right', 'No referrals yet', 'Send the first patient on to a doctor or department with the form on the left.') ?></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
