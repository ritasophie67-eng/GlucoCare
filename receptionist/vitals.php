<?php
// Recording observations at the desk: blood pressure, pulse, weight, temperature.
// The receptionist never reviews clinical history here — only what they measured today.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/receptionist.php';
require_once __DIR__ . '/../includes/clinical_rules.php';
require_role('receptionist');

$pdo = get_db_connection();
$patientId = (int)($_GET['patient_id'] ?? $_POST['patient_id'] ?? 0);
$q = trim((string)($_GET['q'] ?? ''));

$errors = [];
$form = ['systolic_bp' => '', 'diastolic_bp' => '', 'heart_rate' => '', 'weight_kg' => '', 'temperature_c' => '', 'notes' => ''];
$patient = null;
$history = [];
$picker = [];

if ($patientId > 0) {
    $patient = require_org_patient($pdo, $patientId);

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
                    "Desk recorded vitals for patient #{$patientId}: BP {$d['systolic']}/{$d['diastolic']}, HR {$d['heart_rate']}.");

                // A high reading is routed to the patient's own doctor. When nobody is
                // assigned yet there is no clinical owner, so the alert is left unassigned
                // and shows up for administrators to act on.
                $flag = '';
                if (bp_is_high($d['systolic'], $d['diastolic'])) {
                    $target = $pdo->prepare('SELECT clinician_id FROM patients WHERE patient_id = ?');
                    $target->execute([$patientId]);
                    $clinicianId = $target->fetchColumn() ?: null;

                    $msg = "Flagged as Warning Risk – High Data Confidence: Blood pressure recorded as {$d['systolic']}/{$d['diastolic']} mmHg, above the "
                         . BP_SYSTOLIC_LIMIT . '/' . BP_DIASTOLIC_LIMIT . ' mmHg limit. Staff-measured vitals, so data confidence is High.';
                    $pdo->prepare("INSERT INTO clinical_alerts (patient_id, clinician_id, alert_type, risk_level, data_confidence, adherence_pct, alert_message)
                                   VALUES (?, ?, 'high_bp', 'high', 'high', 100, ?)")
                        ->execute([$patientId, $clinicianId, $msg]);
                    log_audit($pdo, (int)$_SESSION['user_id'], 'clinical_alerts', 'ALERT_CREATED',
                        "High blood pressure alert for patient #{$patientId} raised at the desk.");
                    $flag = $clinicianId
                        ? ' Blood pressure is above the safety limit — the doctor has been alerted.'
                        : ' Blood pressure is above the safety limit and no doctor is assigned yet — an administrator must assign one.';
                }
                set_flash($flag && str_contains($flag, 'no doctor') ? 'warning' : ($flag ? 'warning' : 'success'),
                    'Vitals saved for ' . patient_code($patientId) . '.' . $flag);
                redirect('receptionist/vitals.php?patient_id=' . $patientId);
            }
        }
    }

    $stmt = $pdo->prepare('SELECT v.*, u.username AS recorder FROM vitals v JOIN users u ON u.user_id = v.recorded_by_user_id
                           WHERE v.patient_id = ? ORDER BY v.recorded_at DESC, v.vital_id DESC LIMIT 10');
    $stmt->execute([$patientId]);
    $history = $stmt->fetchAll();
} else {
    $sql = 'SELECT p.patient_id, p.full_name, p.phone, p.date_of_birth, p.gender,
                   (SELECT MAX(v.recorded_at) FROM vitals v WHERE v.patient_id = p.patient_id) AS last_vitals
            FROM patients p JOIN users u ON u.user_id = p.user_id
            WHERE u.org_id = ? AND u.username NOT LIKE \'sim\\_%\'';
    $params = [current_org_id()];
    if ($q !== '') {
        [$term, $byId] = patient_search_input($q);
        if ($byId > 0) {
            $sql .= ' AND p.patient_id = ?';
            $params[] = $byId;
        } else {
            $sql .= ' AND (p.full_name ILIKE ? OR p.phone LIKE ?)';
            $params[] = '%' . $term . '%';
            $params[] = '%' . preg_replace('/\D+/', '', $term) . '%';
        }
    }
    $sql .= ' ORDER BY p.full_name LIMIT 25';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $picker = $stmt->fetchAll();
}

$page_title = 'Record vitals';
require __DIR__ . '/../includes/header.php';
?>
<div class="space-y-6">
    <?= ui_page_header(
        $patient ? 'Observations — ' . $patient['full_name'] : 'Record vitals',
        $patient
            ? patient_code($patientId) . ' · recorded at the desk and shared with the care team.'
            : 'Choose the patient you are about to measure. Measurements are attached to their record immediately.',
        ui_button('Patient register', ['href' => 'patients.php', 'variant' => 'outline', 'icon' => 'arrow-left'])
    ) ?>

    <?php if (!$patient): ?>
        <form method="get" class="flex flex-wrap items-center gap-2">
            <label for="vitals-search" class="sr-only">Search patients</label>
            <div class="relative min-w-[14rem] flex-1">
                <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground"><?= icon('search', 'h-3.5 w-3.5') ?></span>
                <input id="vitals-search" type="search" name="q" value="<?= e($q) ?>" placeholder="Name, phone or P-0000…"
                       class="h-10 w-full rounded-md border border-input bg-white pl-9 pr-3 text-sm shadow-sm placeholder:text-muted-foreground/70 focus-visible:border-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/30">
            </div>
            <?= ui_button('Search', ['type' => 'submit', 'variant' => 'outline', 'icon' => 'search']) ?>
        </form>

        <section class="<?= UI_CARD ?>" aria-labelledby="pick-patient">
            <div class="<?= UI_CARD_HEADER ?>">
                <h2 id="pick-patient" class="<?= UI_CARD_TITLE ?>">Choose a patient</h2>
                <span class="text-xs text-muted-foreground"><?= count($picker) ?> shown</span>
            </div>
            <ul class="divide-y divide-border">
                <?php foreach ($picker as $p): ?>
                    <li>
                        <a href="vitals.php?patient_id=<?= (int)$p['patient_id'] ?>"
                           class="flex items-center justify-between gap-3 px-5 py-3 transition-colors hover:bg-muted/40">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium"><?= e($p['full_name']) ?></p>
                                <p class="text-xs capitalize text-muted-foreground">
                                    <span class="font-mono"><?= e(patient_code((int)$p['patient_id'])) ?></span>
                                    · <?= e($p['gender']) ?> · <?= calc_age($p['date_of_birth']) ?> yrs
                                    · <?= e(phone_display($p['phone'])) ?>
                                </p>
                            </div>
                            <span class="shrink-0 text-xs text-muted-foreground">
                                <?= $p['last_vitals'] ? 'Last ' . e(date('d M', strtotime($p['last_vitals']))) : 'No vitals yet' ?>
                                <?= icon('chevron-right', 'ml-1 inline h-3.5 w-3.5') ?>
                            </span>
                        </a>
                    </li>
                <?php endforeach; ?>
                <?php if (!$picker): ?>
                    <li><?= ui_empty('search', 'No patient matches', 'Search by another spelling or register the patient first.') ?></li>
                <?php endif; ?>
            </ul>
        </section>
    <?php else: ?>
        <?php foreach ($errors as $err): ?>
            <?= ui_alert('destructive', 'Vitals not saved', e($err)) ?>
        <?php endforeach; ?>

        <div class="grid gap-6 lg:grid-cols-5">
            <section class="lg:col-span-2 <?= UI_CARD ?>" aria-labelledby="record-vitals">
                <div class="<?= UI_CARD_HEADER ?>">
                    <h2 id="record-vitals" class="<?= UI_CARD_TITLE ?>">New observations</h2>
                    <span class="text-primary"><?= icon('heart-pulse', 'h-4 w-4') ?></span>
                </div>
                <div class="<?= UI_CARD_BODY ?>">
                    <?php if (!$patient['clinician_id']): ?>
                        <div class="mb-4"><?= ui_alert('warning', 'No doctor assigned', 'An administrator assigns the doctor. High readings are still recorded and flagged.', 'user') ?></div>
                    <?php endif; ?>
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
                        <?= ui_button('Save observations', ['type' => 'submit', 'class' => 'w-full', 'icon' => 'check']) ?>
                    </form>
                </div>
            </section>

            <section class="lg:col-span-3 <?= UI_CARD ?>" aria-labelledby="vitals-history">
                <div class="<?= UI_CARD_HEADER ?>">
                    <h2 id="vitals-history" class="<?= UI_CARD_TITLE ?>">Recent observations</h2>
                    <span class="text-xs text-muted-foreground">Last <?= count($history) ?> records</span>
                </div>
                <div class="max-h-[32rem] overflow-auto scroll-area">
                    <table class="w-full min-w-[520px] border-collapse text-sm">
                        <caption class="sr-only">Recently recorded observations for this patient.</caption>
                        <thead class="sticky top-0 z-10">
                            <tr class="border-b border-border bg-muted/80 text-left text-[0.68rem] uppercase tracking-[0.07em] text-muted-foreground backdrop-blur">
                                <th scope="col" class="px-5 py-2.5 font-semibold">When</th>
                                <th scope="col" class="px-3 py-2.5 font-semibold">BP</th>
                                <th scope="col" class="px-3 py-2.5 font-semibold">HR</th>
                                <th scope="col" class="px-3 py-2.5 font-semibold">Weight</th>
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
                                <td class="px-5 py-2.5 text-xs text-muted-foreground"><?= e($v['recorder']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$history): ?>
                            <tr><td colspan="5"><?= ui_empty('heart-pulse', 'No observations yet', 'Save the first set with the form on the left.') ?></td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
