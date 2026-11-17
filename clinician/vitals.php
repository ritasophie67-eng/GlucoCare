<?php
// Record and review a patient's vitals. Only for patients assigned to the signed-in clinician.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/cds_engine.php';
require_once __DIR__ . '/../includes/clinical_rules.php';
require_role('clinician');

$pdo = get_db_connection();
$patientId = (int)($_GET['patient_id'] ?? $_POST['patient_id'] ?? 0);
$patient = require_assigned_patient($pdo, $patientId);
$clinicianId = (int)$_SESSION['clinician_id'];

$errors = [];
$form = ['systolic_bp' => '', 'diastolic_bp' => '', 'heart_rate' => '', 'weight_kg' => '', 'temperature_c' => '', 'notes' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($form as $k => $_) {
        $form[$k] = trim((string)($_POST[$k] ?? ''));
    }
    if (!csrf_valid()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $res = validate_vitals($_POST);
        $errors = $res['errors'];
        if (!$errors) {
            $d = $res['data'];
            $pdo->prepare('INSERT INTO vitals (patient_id, recorded_by_user_id, systolic_bp, diastolic_bp, heart_rate, weight_kg, temperature_c, notes)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$patientId, $_SESSION['user_id'], $d['systolic'], $d['diastolic'], $d['heart_rate'], $d['weight'], $d['temperature'], $d['notes'] ?: null]);
            log_audit($pdo, (int)$_SESSION['user_id'], 'vitals', 'VITALS_RECORDED',
                "Vitals for patient #{$patientId}: BP {$d['systolic']}/{$d['diastolic']}, HR {$d['heart_rate']}.");

            $flag = '';
            if (bp_is_high($d['systolic'], $d['diastolic'])) {
                $msg = "Flagged as Warning Risk – High Data Confidence: Blood pressure recorded as {$d['systolic']}/{$d['diastolic']} mmHg, above the "
                     . BP_SYSTOLIC_LIMIT . '/' . BP_DIASTOLIC_LIMIT . ' mmHg limit. Staff-measured vitals, so data confidence is High.';
                $pdo->prepare("INSERT INTO clinical_alerts (patient_id, clinician_id, alert_type, risk_level, data_confidence, adherence_pct, alert_message)
                               VALUES (?, ?, 'high_bp', 'high', 'high', 100, ?)")->execute([$patientId, $clinicianId, $msg]);
                log_audit($pdo, (int)$_SESSION['user_id'], 'clinical_alerts', 'ALERT_CREATED', "High blood pressure alert for patient #{$patientId}.");
                $flag = ' Blood pressure is above the safety limit — an alert was added to your queue.';
            }
            set_flash($flag ? 'warning' : 'success', 'Vitals saved.' . $flag);
            redirect('clinician/vitals.php?patient_id=' . $patientId);
        }
    }
}

$stmt = $pdo->prepare('SELECT v.*, u.username AS recorder FROM vitals v JOIN users u ON u.user_id = v.recorded_by_user_id
                       WHERE v.patient_id = ? ORDER BY v.recorded_at DESC, v.vital_id DESC LIMIT 20');
$stmt->execute([$patientId]);
$history = $stmt->fetchAll();
$valid = valid_vitals_for_prescribing($pdo, $patientId);

$page_title = 'Vitals';
require __DIR__ . '/../includes/header.php';
?>
<div class="space-y-6">
    <?= ui_page_header(
        'Vitals — ' . $patient['full_name'],
        'Record blood pressure and other observations before prescribing. Vitals stay valid for ' . VITALS_VALID_HOURS . ' hours.',
        ui_button('Prescriptions', ['href' => 'prescribe.php?patient_id=' . $patientId, 'icon' => 'pill'])
        . ui_button('Patient record', ['href' => 'patient.php?id=' . $patientId, 'variant' => 'outline', 'icon' => 'arrow-left'])
    ) ?>

    <?php if ($valid): ?>
        <?= ui_alert('success', 'Prescribing is unlocked', 'Vitals on file from ' . e(date('d M, H:i', strtotime($valid['recorded_at']))) . '.') ?>
    <?php else: ?>
        <?= ui_alert('warning', 'Prescribing is locked', 'No vitals in the last ' . VITALS_VALID_HOURS . ' hours. Record them below to unlock prescriptions.', 'lock') ?>
    <?php endif; ?>

    <?php foreach ($errors as $err): ?>
        <?= ui_alert('destructive', 'Vitals not saved', e($err)) ?>
    <?php endforeach; ?>

    <div class="grid gap-6 lg:grid-cols-5">
        <section class="lg:col-span-2 <?= UI_CARD ?>" aria-labelledby="record-vitals">
            <div class="<?= UI_CARD_HEADER ?>">
                <h2 id="record-vitals" class="<?= UI_CARD_TITLE ?>">Record vitals</h2>
                <span class="text-primary"><?= icon('heart-pulse', 'h-4 w-4') ?></span>
            </div>
            <div class="<?= UI_CARD_BODY ?>">
                <form method="post" class="space-y-4">
                    <?= csrf_field() ?>
                    <input type="hidden" name="patient_id" value="<?= $patientId ?>">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <?= ui_field(['id' => 'systolic_bp', 'name' => 'systolic_bp', 'type' => 'number', 'label' => 'Systolic (mmHg)',
                            'value' => $form['systolic_bp'], 'required' => true, 'attrs' => 'min="60" max="260" inputmode="numeric"']) ?>
                        <?= ui_field(['id' => 'diastolic_bp', 'name' => 'diastolic_bp', 'type' => 'number', 'label' => 'Diastolic (mmHg)',
                            'value' => $form['diastolic_bp'], 'required' => true, 'attrs' => 'min="30" max="160" inputmode="numeric"']) ?>
                        <?= ui_field(['id' => 'heart_rate', 'name' => 'heart_rate', 'type' => 'number', 'label' => 'Heart rate (bpm)',
                            'value' => $form['heart_rate'], 'required' => true, 'attrs' => 'min="30" max="220" inputmode="numeric"']) ?>
                        <?= ui_field(['id' => 'weight_kg', 'name' => 'weight_kg', 'type' => 'number', 'label' => 'Weight (kg)',
                            'value' => $form['weight_kg'], 'attrs' => 'step="0.1" min="2" max="400" inputmode="decimal"']) ?>
                        <?= ui_field(['id' => 'temperature_c', 'name' => 'temperature_c', 'type' => 'number', 'label' => 'Temperature (°C)',
                            'value' => $form['temperature_c'], 'attrs' => 'step="0.1" min="30" max="43" inputmode="decimal"']) ?>
                        <?= ui_field(['id' => 'notes', 'name' => 'notes', 'label' => 'Notes', 'value' => $form['notes'],
                            'attrs' => 'maxlength="255" placeholder="Optional"']) ?>
                    </div>
                    <?= ui_button('Save vitals', ['type' => 'submit', 'class' => 'w-full', 'icon' => 'check']) ?>
                </form>
            </div>
        </section>

        <section class="lg:col-span-3 <?= UI_CARD ?>" aria-labelledby="vitals-history">
            <div class="<?= UI_CARD_HEADER ?>">
                <h2 id="vitals-history" class="<?= UI_CARD_TITLE ?>">Recent vitals</h2>
                <span class="text-xs text-muted-foreground">Last <?= count($history) ?> records</span>
            </div>
            <div class="max-h-[32rem] overflow-auto scroll-area">
                <table class="w-full min-w-[560px] border-collapse text-sm">
                    <caption class="sr-only">Previously recorded vitals.</caption>
                    <thead class="sticky top-0 z-10">
                        <tr class="border-b border-border bg-muted/80 text-left text-[0.68rem] uppercase tracking-[0.07em] text-muted-foreground backdrop-blur">
                            <th scope="col" class="px-5 py-2.5 font-semibold">When</th>
                            <th scope="col" class="px-3 py-2.5 font-semibold">BP</th>
                            <th scope="col" class="px-3 py-2.5 font-semibold">HR</th>
                            <th scope="col" class="px-3 py-2.5 font-semibold">Weight</th>
                            <th scope="col" class="px-3 py-2.5 font-semibold">Temp</th>
                            <th scope="col" class="px-5 py-2.5 font-semibold">By</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                    <?php foreach ($history as $v): $high = bp_is_high((int)$v['systolic_bp'], (int)$v['diastolic_bp']); ?>
                        <tr class="hover:bg-muted/40">
                            <td class="whitespace-nowrap px-5 py-2.5 text-muted-foreground"><?= e(date('d M Y, H:i', strtotime($v['recorded_at']))) ?></td>
                            <td class="px-3 py-2.5">
                                <span class="font-semibold tabular"><?= (int)$v['systolic_bp'] ?>/<?= (int)$v['diastolic_bp'] ?></span>
                                <?= $high ? ' ' . ui_badge('High', 'destructive') : '' ?>
                            </td>
                            <td class="px-3 py-2.5 tabular"><?= (int)$v['heart_rate'] ?></td>
                            <td class="px-3 py-2.5 tabular"><?= $v['weight_kg'] !== null ? e($v['weight_kg'] + 0) . ' kg' : '—' ?></td>
                            <td class="px-3 py-2.5 tabular"><?= $v['temperature_c'] !== null ? e($v['temperature_c'] + 0) . ' °C' : '—' ?></td>
                            <td class="px-5 py-2.5 text-xs text-muted-foreground"><?= e($v['recorder']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$history): ?>
                        <tr><td colspan="6"><?= ui_empty('heart-pulse', 'No vitals recorded yet', 'Record the first set of observations to unlock prescribing.') ?></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
