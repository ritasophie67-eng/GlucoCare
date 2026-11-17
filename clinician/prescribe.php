<?php
// Prescriptions. Locked until vitals have been recorded in the last 24 hours.
// The system records what the clinician decides — it never suggests doses.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/cds_engine.php';
require_once __DIR__ . '/../includes/clinical_rules.php';
require_role('clinician');

$pdo = get_db_connection();
$patientId = (int)($_GET['patient_id'] ?? $_POST['patient_id'] ?? 0);
$patient = require_assigned_patient($pdo, $patientId);
$clinicianId = (int)$_SESSION['clinician_id'];

$errors = [];
$f = ['medication_name' => '', 'dosage' => '', 'frequency' => '', 'start_date' => date('Y-m-d'), 'end_date' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!csrf_valid()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif ($action === 'issue') {
        foreach ($f as $k => $_) {
            $f[$k] = trim((string)($_POST[$k] ?? ''));
        }
        $vitals = valid_vitals_for_prescribing($pdo, $patientId);   // enforced on the server, not just the UI
        $start = DateTime::createFromFormat('Y-m-d', $f['start_date']);
        $end = $f['end_date'] !== '' ? DateTime::createFromFormat('Y-m-d', $f['end_date']) : null;

        if (!$vitals) {
            $errors[] = 'Prescribing is locked: record this patient\'s vitals first (valid for ' . VITALS_VALID_HOURS . ' hours).';
        } elseif (in_array('', [$f['medication_name'], $f['dosage'], $f['frequency']], true)) {
            $errors[] = 'Medication, dosage and frequency are required.';
        } elseif (mb_strlen($f['medication_name']) > 100 || mb_strlen($f['dosage']) > 50 || mb_strlen($f['frequency']) > 50) {
            $errors[] = 'One of the fields is too long.';
        } elseif (!$start) {
            $errors[] = 'Enter a valid start date.';
        } elseif ($f['end_date'] !== '' && (!$end || $end < $start)) {
            $errors[] = 'The end date must be on or after the start date.';
        } else {
            // Allergy cross-check against the medication name. Deliberately simple and
            // transparent: it catches the obvious match ("penicillin") and never guesses.
            $allergyList = array_filter(array_map('trim', explode(',', (string)($patient['allergies'] ?? ''))));
            $conflict = null;
            foreach ($allergyList as $allergy) {
                if ($allergy !== '' && mb_stripos($f['medication_name'], $allergy) !== false) {
                    $conflict = $allergy;
                    break;
                }
            }
            $dupe = $pdo->prepare("SELECT 1 FROM prescriptions WHERE patient_id = ? AND status = 'active' AND LOWER(medication_name) = LOWER(?)");
            $dupe->execute([$patientId, $f['medication_name']]);
            if ($conflict !== null) {
                $errors[] = "Blocked: the patient record lists an allergy to \"{$conflict}\". Remove or correct the allergy record first if this is inaccurate.";
                log_audit($pdo, (int)$_SESSION['user_id'], 'prescriptions', 'ALLERGY_BLOCK',
                    "Prescription for {$f['medication_name']} blocked: recorded allergy \"{$conflict}\" on patient #{$patientId}.");
            } elseif ($dupe->fetch()) {
                $errors[] = "{$f['medication_name']} is already an active prescription for this patient. Discontinue it first if you are changing the regimen.";
            } else {
                $pdo->prepare('INSERT INTO prescriptions (patient_id, clinician_id, vital_id, medication_name, dosage, frequency, start_date, end_date)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$patientId, $clinicianId, $vitals['vital_id'], $f['medication_name'], $f['dosage'], $f['frequency'], $f['start_date'], $f['end_date'] ?: null]);
                log_audit($pdo, (int)$_SESSION['user_id'], 'prescriptions', 'PRESCRIPTION_ISSUED',
                    "Issued {$f['medication_name']} {$f['dosage']} ({$f['frequency']}) to patient #{$patientId}, against vitals #{$vitals['vital_id']}.");
                set_flash('success', 'Prescription issued.');
                redirect('clinician/prescribe.php?patient_id=' . $patientId);
            }
        }
    } elseif (in_array($action, ['completed', 'discontinued'], true)) {
        $rxId = (int)($_POST['prescription_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT medication_name FROM prescriptions WHERE prescription_id = ? AND patient_id = ? AND status = 'active'");
        $stmt->execute([$rxId, $patientId]);                         // patient is already verified as assigned to this clinician
        if ($rx = $stmt->fetch()) {
            $pdo->prepare('UPDATE prescriptions SET status = ? WHERE prescription_id = ?')->execute([$action, $rxId]);
            log_audit($pdo, (int)$_SESSION['user_id'], 'prescriptions', 'PRESCRIPTION_' . strtoupper($action),
                "{$rx['medication_name']} (prescription #{$rxId}) marked {$action} for patient #{$patientId}.");
            set_flash('success', "Prescription marked {$action}.");
        } else {
            set_flash('danger', 'Prescription not found.');
        }
        redirect('clinician/prescribe.php?patient_id=' . $patientId);
    }
}

$vitals = valid_vitals_for_prescribing($pdo, $patientId);
$stmt = $pdo->prepare('SELECT r.*, c.full_name AS doctor FROM prescriptions r JOIN clinicians c ON c.clinician_id = r.clinician_id
                       WHERE r.patient_id = ? ORDER BY (r.status = \'active\') DESC, r.created_at DESC');
$stmt->execute([$patientId]);
$rxs = $stmt->fetchAll();

$page_title = 'Prescriptions';
require __DIR__ . '/../includes/header.php';
?>
<div class="space-y-6">
    <?= ui_page_header(
        'Prescriptions — ' . $patient['full_name'],
        'Issued by you and recorded against the vitals on file. The system never suggests doses.',
        ui_button('Vitals', ['href' => 'vitals.php?patient_id=' . $patientId, 'variant' => 'outline', 'icon' => 'heart-pulse'])
        . ui_button('Patient record', ['href' => 'patient.php?id=' . $patientId, 'variant' => 'outline', 'icon' => 'arrow-left'])
    ) ?>

    <?php foreach ($errors as $err): ?>
        <?= ui_alert('destructive', 'Prescription not issued', e($err)) ?>
    <?php endforeach; ?>

    <?php if (!empty($patient['allergies'])): ?>
        <?= ui_alert('warning', 'Recorded allergies', e($patient['allergies']), 'alert-triangle') ?>
    <?php endif; ?>

    <?php if (!$vitals): ?>
        <div class="<?= UI_CARD ?> flex flex-col gap-3 border-warning/40 bg-warning-subtle px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-start gap-3 text-sm">
                <span class="mt-0.5 text-warning"><?= icon('lock', 'h-4 w-4') ?></span>
                <div>
                    <p class="font-semibold text-warning">Prescribing is locked</p>
                    <p class="mt-0.5 text-foreground/80">No vitals have been recorded for this patient in the last <?= VITALS_VALID_HOURS ?> hours.</p>
                </div>
            </div>
            <?= ui_button('Record vitals', ['href' => 'vitals.php?patient_id=' . $patientId, 'size' => 'sm']) ?>
        </div>
    <?php else:
        $highBp = bp_is_high((int)$vitals['systolic_bp'], (int)$vitals['diastolic_bp']); ?>
        <?= ui_alert($highBp ? 'destructive' : 'success',
            $highBp ? 'Blood pressure above safety limit' : 'Vitals on file — prescribing unlocked',
            'BP <strong>' . (int)$vitals['systolic_bp'] . '/' . (int)$vitals['diastolic_bp'] . '</strong> mmHg, HR ' . (int)$vitals['heart_rate']
            . ' (' . e(date('d M, H:i', strtotime($vitals['recorded_at']))) . '). '
            . ($highBp ? 'Review before prescribing; the limit is ' . BP_SYSTOLIC_LIMIT . '/' . BP_DIASTOLIC_LIMIT . ' mmHg.' : '')) ?>
    <?php endif; ?>

    <div class="grid gap-6 lg:grid-cols-5">
        <section class="lg:col-span-2 <?= UI_CARD ?>" aria-labelledby="new-rx">
            <div class="<?= UI_CARD_HEADER ?>">
                <h2 id="new-rx" class="<?= UI_CARD_TITLE ?>">New prescription</h2>
                <?= $vitals ? ui_badge('Unlocked', 'success') : ui_badge('Locked', 'warning') ?>
            </div>
            <div class="<?= UI_CARD_BODY ?>">
                <form method="post" class="space-y-4">
                    <?= csrf_field() ?>
                    <input type="hidden" name="patient_id" value="<?= $patientId ?>">
                    <input type="hidden" name="action" value="issue">
                    <fieldset <?= $vitals ? '' : 'disabled' ?> class="space-y-4 disabled:opacity-60">
                        <?= ui_field(['id' => 'medication_name', 'name' => 'medication_name', 'label' => 'Medication',
                            'value' => $f['medication_name'], 'required' => true,
                            'attrs' => 'list="meds" maxlength="100" placeholder="e.g. Metformin"']) ?>
                        <datalist id="meds">
                            <?php foreach (COMMON_DIABETES_MEDS as $m): ?><option value="<?= e($m) ?>"></option><?php endforeach; ?>
                        </datalist>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <?= ui_field(['id' => 'dosage', 'name' => 'dosage', 'label' => 'Dosage', 'value' => $f['dosage'],
                                'required' => true, 'attrs' => 'maxlength="50" placeholder="e.g. 500 mg"']) ?>
                            <?= ui_field(['id' => 'frequency', 'name' => 'frequency', 'label' => 'Frequency', 'value' => $f['frequency'],
                                'required' => true, 'attrs' => 'maxlength="50" placeholder="e.g. twice daily"']) ?>
                            <?= ui_field(['id' => 'start_date', 'name' => 'start_date', 'type' => 'date', 'label' => 'Start date',
                                'value' => $f['start_date'], 'required' => true]) ?>
                            <?= ui_field(['id' => 'end_date', 'name' => 'end_date', 'type' => 'date', 'label' => 'End date (optional)',
                                'value' => $f['end_date']]) ?>
                        </div>
                        <?= ui_button('Issue prescription', ['type' => 'submit', 'class' => 'w-full', 'icon' => 'clipboard-plus', 'disabled' => !$vitals]) ?>
                    </fieldset>
                </form>
            </div>
        </section>

        <section class="lg:col-span-3 <?= UI_CARD ?>" aria-labelledby="rx-record">
            <div class="<?= UI_CARD_HEADER ?>">
                <h2 id="rx-record" class="<?= UI_CARD_TITLE ?>">Prescription record</h2>
                <span class="text-xs text-muted-foreground"><?= count($rxs) ?> total</span>
            </div>
            <div class="overflow-x-auto scroll-area">
                <table class="w-full min-w-[620px] border-collapse text-sm">
                    <caption class="sr-only">Prescription history for this patient.</caption>
                    <thead>
                        <tr class="border-b border-border bg-muted/50 text-left text-[0.68rem] uppercase tracking-[0.07em] text-muted-foreground">
                            <th scope="col" class="px-5 py-2.5 font-semibold">Medication</th>
                            <th scope="col" class="px-3 py-2.5 font-semibold">Dose</th>
                            <th scope="col" class="px-3 py-2.5 font-semibold">Period</th>
                            <th scope="col" class="px-3 py-2.5 font-semibold">Status</th>
                            <th scope="col" class="px-5 py-2.5 text-right font-semibold"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                    <?php foreach ($rxs as $r): ?>
                        <tr class="hover:bg-muted/40">
                            <td class="px-5 py-3">
                                <p class="font-medium"><?= e($r['medication_name']) ?></p>
                                <p class="text-xs text-muted-foreground"><?= e($r['doctor']) ?></p>
                            </td>
                            <td class="px-3 py-3">
                                <p class="tabular"><?= e($r['dosage']) ?></p>
                                <p class="text-xs text-muted-foreground"><?= e($r['frequency']) ?></p>
                            </td>
                            <td class="whitespace-nowrap px-3 py-3 text-xs text-muted-foreground">
                                <?= e(date('d M Y', strtotime($r['start_date']))) ?><?= $r['end_date'] ? ' → ' . e(date('d M Y', strtotime($r['end_date']))) : '' ?>
                            </td>
                            <td class="px-3 py-3"><?= ui_badge(ucfirst($r['status']), $r['status'] === 'active' ? 'success' : 'muted') ?></td>
                            <td class="px-5 py-3 text-right">
                                <?php if ($r['status'] === 'active'): ?>
                                    <form method="post" class="inline-flex gap-1.5" onsubmit="return confirm('Update this prescription?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="patient_id" value="<?= $patientId ?>">
                                        <input type="hidden" name="prescription_id" value="<?= (int)$r['prescription_id'] ?>">
                                        <?= ui_button('Complete', ['type' => 'submit', 'name' => 'action', 'value' => 'completed', 'variant' => 'outline', 'size' => 'sm']) ?>
                                        <?= ui_button('Stop', ['type' => 'submit', 'name' => 'action', 'value' => 'discontinued', 'variant' => 'destructive', 'size' => 'sm']) ?>
                                    </form>
                                <?php else: ?>
                                    <span class="text-xs text-muted-foreground">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rxs): ?>
                        <tr><td colspan="5"><?= ui_empty('pill', 'No prescriptions yet', 'Issued prescriptions will be recorded here with their status.') ?></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
