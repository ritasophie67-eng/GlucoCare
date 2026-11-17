<?php
// Patient record. A clinician can open it ONLY if the patient is assigned to them.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/readings.php';
require_once __DIR__ . '/../includes/cds_engine.php';
require_once __DIR__ . '/../includes/clinical_rules.php';
require_role('clinician');

$pdo = get_db_connection();
$patientId = (int)($_GET['id'] ?? 0);
$patient = require_assigned_patient($pdo, $patientId);

$stmt = $pdo->prepare('SELECT * FROM patient_thresholds WHERE patient_id = ?');
$stmt->execute([$patientId]);
$t = $stmt->fetch();

$unit = patient_unit($patient);   // this patient's display preference; storage stays mg/dL

// ---- Recorded allergies (checked when prescribing) -----------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'allergies') {
    $allergies = trim((string)($_POST['allergies'] ?? ''));
    if (!csrf_valid()) {
        set_flash('danger', 'Your session expired. Please try again.');
    } elseif (mb_strlen($allergies) > 255) {
        set_flash('danger', 'Keep the allergy list under 255 characters.');
    } else {
        $pdo->prepare('UPDATE patients SET allergies = ? WHERE patient_id = ?')->execute([$allergies !== '' ? $allergies : null, $patientId]);
        log_audit($pdo, (int)$_SESSION['user_id'], 'patients', 'ALLERGIES_UPDATED',
            "Patient #{$patientId} allergies set to: " . ($allergies !== '' ? $allergies : 'none recorded') . '.');
        set_flash('success', 'Allergy list updated.');
    }
    redirect('clinician/patient.php?id=' . $patientId);
}

// ---- Clinician threshold override (ownership already enforced above) ----
$thresholdError = '';
const THRESHOLD_FIELDS = [
    'severe_hypo_threshold'   => 'Severe hypo',
    'hypo_threshold'          => 'Hypo',
    'target_fasting_min'      => 'Fasting min',
    'target_fasting_max'      => 'Fasting max',
    'target_postprandial_max' => 'Post-meal max',
    'critical_high_threshold' => 'Critical high',
];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'thresholds') {
    $new = [];
    foreach (THRESHOLD_FIELDS as $k => $_) {
        $raw = trim((string)($_POST[$k] ?? ''));
        $new[$k] = is_numeric($raw) ? round((float)$raw, 1) : null;
    }
    if (!csrf_valid()) {
        $thresholdError = 'Your session expired. Please try again.';
    } elseif (in_array(null, $new, true)) {
        $thresholdError = 'Every threshold must be a number.';
    } elseif (!($new['severe_hypo_threshold'] >= 20
        && $new['severe_hypo_threshold'] < $new['hypo_threshold']
        && $new['hypo_threshold'] <= $new['target_fasting_min']
        && $new['target_fasting_min'] < $new['target_fasting_max']
        && $new['target_fasting_max'] <= $new['target_postprandial_max']
        && $new['target_postprandial_max'] < $new['critical_high_threshold']
        && $new['critical_high_threshold'] <= 600)) {
        $thresholdError = 'Thresholds must be in order: severe hypo < hypo ≤ fasting min < fasting max ≤ post-meal max < critical high (max 600).';
    } else {
        $pdo->prepare('UPDATE patient_thresholds SET severe_hypo_threshold = ?, hypo_threshold = ?, target_fasting_min = ?,
                       target_fasting_max = ?, target_postprandial_max = ?, critical_high_threshold = ?,
                       updated_at = now() WHERE patient_id = ?')
            ->execute([$new['severe_hypo_threshold'], $new['hypo_threshold'], $new['target_fasting_min'],
                       $new['target_fasting_max'], $new['target_postprandial_max'], $new['critical_high_threshold'], $patientId]);
        $changes = [];
        foreach (THRESHOLD_FIELDS as $k => $label) {
            if ((float)$t[$k] !== $new[$k]) {
                $changes[] = "{$label} " . cds_fmt((float)$t[$k]) . ' → ' . cds_fmt($new[$k]);
            }
        }
        log_audit($pdo, (int)$_SESSION['user_id'], 'patient_thresholds', 'THRESHOLDS_UPDATED',
            "Patient #{$patientId} targets changed: " . ($changes ? implode('; ', $changes) : 'no change') . '.');
        set_flash('success', 'Target ranges updated. The rule engine now uses them for new readings.');
        redirect('clinician/patient.php?id=' . $patientId);
    }
}

$stmt = $pdo->prepare('SELECT created_at FROM users WHERE user_id = ?');
$stmt->execute([$patient['user_id']]);
$registeredAt = $stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT * FROM glucose_readings WHERE patient_id = ? AND logged_at >= (NOW() - make_interval(days => 30)) ORDER BY logged_at DESC');
$stmt->execute([$patientId]);
$readings = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT * FROM clinical_alerts WHERE patient_id = ? ORDER BY (status = 'pending') DESC, created_at DESC LIMIT 8");
$stmt->execute([$patientId]);
$alerts = $stmt->fetchAll();

$vitals = latest_vitals($pdo, $patientId);
$stmt = $pdo->prepare("SELECT COUNT(*) FROM prescriptions WHERE patient_id = ? AND status = 'active'");
$stmt->execute([$patientId]);
$activeRx = (int)$stmt->fetchColumn();

$adh7  = adherence_stats($pdo, $patientId, $registeredAt, 7);
$adh30 = adherence_stats($pdo, $patientId, $registeredAt, 30);
$adh14 = adherence_stats($pdo, $patientId, $registeredAt, CDS_ADHERENCE_DAYS);
$conf  = data_confidence($adh14['pct']);
$gap   = hours_since_last_reading($pdo, $patientId);

log_audit($pdo, (int)$_SESSION['user_id'], 'patients', 'PATIENT_VIEW', "Viewed record of patient #{$patientId} ({$patient['full_name']}).");

$page_title = $patient['full_name'];
$use_charts = true;
$page_scripts = $readings ? '<script src="' . BASE_URL . 'assets/js/glucose-chart.js"></script><script nonce="' . (defined('APP_CSP_NONCE') ? APP_CSP_NONCE : '') . '">'
    . 'glucoseChart("trend", ' . json_encode(chart_rows($readings, $t, $unit)) . ', ' . json_encode(chart_limits($t, $unit)) . ');</script>' : '';
require __DIR__ . '/../includes/header.php';
?>
<div class="space-y-6">
    <?= ui_page_header(
        $patient['full_name'],
        ucfirst($patient['gender']) . ', ' . calc_age($patient['date_of_birth']) . ' years · ' . phone_display($patient['phone']) . ' · Patient #' . $patientId
        . (!empty($patient['allergies']) ? ' · Allergies: ' . $patient['allergies'] : ''),
        ui_button('Visit summary', ['href' => 'summary.php?patient_id=' . $patientId, 'variant' => 'outline', 'icon' => 'clipboard-list'])
        . ui_button('Allergies', ['attrs' => 'data-dialog-target="allergies-dialog"', 'variant' => 'outline', 'icon' => 'alert-triangle'])
        . ui_button('Vitals', ['href' => 'vitals.php?patient_id=' . $patientId, 'variant' => 'outline', 'icon' => 'heart-pulse'])
        . ui_button('Prescriptions' . ($activeRx ? " ({$activeRx})" : ''), ['href' => 'prescribe.php?patient_id=' . $patientId, 'icon' => 'pill'])
        . ui_button('Back', ['href' => 'dashboard.php', 'variant' => 'ghost', 'icon' => 'arrow-left'])
    ) ?>

    <?php if ($gap === null || $gap >= MISSING_ALERT_HOURS): ?>
        <?= ui_alert('warning', $gap === null ? 'This patient has not logged any readings.' : "No reading for {$gap} hours.",
            'Rule findings below are based on older data and may no longer reflect the current state.') ?>
    <?php endif; ?>

    <!-- Clinical summary -->
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <div class="<?= UI_CARD ?> px-5 py-4">
            <p class="text-[0.7rem] font-semibold uppercase tracking-[0.07em] text-muted-foreground">Latest vitals</p>
            <?php if ($vitals): $hi = bp_is_high((int)$vitals['systolic_bp'], (int)$vitals['diastolic_bp']); ?>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <span class="text-xl font-bold tabular"><?= (int)$vitals['systolic_bp'] ?>/<?= (int)$vitals['diastolic_bp'] ?></span>
                    <span class="text-xs text-muted-foreground">mmHg · HR <?= (int)$vitals['heart_rate'] ?></span>
                    <?= $hi ? ui_badge('High', 'destructive') : ui_badge('Normal', 'success') ?>
                </div>
                <p class="mt-1.5 text-[0.7rem] text-muted-foreground"><?= e(date('d M, H:i', strtotime($vitals['recorded_at']))) ?></p>
            <?php else: ?>
                <p class="mt-2 text-sm text-muted-foreground">None recorded</p>
            <?php endif; ?>
            <div class="mt-3 border-t border-border pt-2.5">
                <?php if (valid_vitals_for_prescribing($pdo, $patientId)): ?>
                    <span class="inline-flex items-center gap-1.5 text-xs font-medium text-success"><?= icon('unlock', 'h-3.5 w-3.5') ?> Prescribing unlocked</span>
                <?php else: ?>
                    <span class="inline-flex items-center gap-1.5 text-xs font-medium text-warning"><?= icon('lock', 'h-3.5 w-3.5') ?> Prescribing locked — vitals needed</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="<?= UI_CARD ?> px-5 py-4">
            <p class="text-[0.7rem] font-semibold uppercase tracking-[0.07em] text-muted-foreground">Data reliability (<?= CDS_ADHERENCE_DAYS ?> d)</p>
            <?= ui_gauge((float)$adh14['pct'], '', 'sm') ?>
            <p class="mt-2 text-[0.7rem] text-muted-foreground"><?= (int)$adh14['count'] ?> of <?= (int)$adh14['expected'] ?> expected · 7-day <?= $adh7['pct'] + 0 ?>%</p>
        </div>

        <div class="<?= UI_CARD ?> px-5 py-4">
            <p class="text-[0.7rem] font-semibold uppercase tracking-[0.07em] text-muted-foreground">30-day adherence</p>
            <p class="mt-1 text-3xl font-bold leading-none tabular"><?= $adh30['pct'] + 0 ?>%</p>
            <div class="mt-3"><?= ui_progress((float)$adh30['pct'], $adh30['pct'] >= HIGH_CONFIDENCE_PCT) ?></div>
            <p class="mt-2 text-[0.7rem] text-muted-foreground"><?= (int)$adh30['days_logged'] ?> of <?= (int)$adh30['days'] ?> days with readings</p>
        </div>

        <div class="<?= UI_CARD ?> px-5 py-4">
            <div class="flex items-start justify-between gap-2">
                <p class="text-[0.7rem] font-semibold uppercase tracking-[0.07em] text-muted-foreground">Targets (<?= e($unit) ?>)</p>
                <button type="button" data-dialog-target="threshold-dialog"
                        class="inline-flex h-7 items-center gap-1 rounded-md border border-input bg-white px-2 text-[0.7rem] font-medium transition hover:bg-secondary/60">
                    <?= icon('sliders', 'h-3.5 w-3.5') ?> Adjust
                </button>
            </div>
            <dl class="mt-2 space-y-1 text-xs">
                <div class="flex justify-between gap-2"><dt class="text-muted-foreground">Fasting</dt><dd class="font-semibold tabular"><?= e(fmt_glucose((float)$t['target_fasting_min'], $unit)) ?>–<?= e(fmt_glucose((float)$t['target_fasting_max'], $unit)) ?></dd></div>
                <div class="flex justify-between gap-2"><dt class="text-muted-foreground">Post-meal</dt><dd class="font-semibold tabular">&lt; <?= e(fmt_glucose((float)$t['target_postprandial_max'], $unit)) ?></dd></div>
                <div class="flex justify-between gap-2"><dt class="text-muted-foreground">Hypo / severe</dt><dd class="font-semibold tabular">&lt; <?= e(fmt_glucose((float)$t['hypo_threshold'], $unit)) ?> / <?= e(fmt_glucose((float)$t['severe_hypo_threshold'], $unit)) ?></dd></div>
                <div class="flex justify-between gap-2"><dt class="text-muted-foreground">Critical high</dt><dd class="font-semibold tabular">&gt; <?= e(fmt_glucose((float)$t['critical_high_threshold'], $unit)) ?></dd></div>
            </dl>
        </div>
    </div>

    <!-- Alerts with rationale -->
    <?php if ($alerts): ?>
        <section class="space-y-3" aria-labelledby="patient-alerts">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 id="patient-alerts" class="text-base font-semibold tracking-tight">Alerts for this patient</h2>
                <a href="alerts.php" class="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline">
                    Open queue <?= icon('chevron-right', 'h-3.5 w-3.5') ?>
                </a>
            </div>
            <div class="grid gap-3 lg:grid-cols-2">
                <?php foreach ($alerts as $a):
                    $critical = $a['risk_level'] === 'critical';
                    $rationale = preg_replace('/^Flagged as [^:]+:\s*/', '', $a['alert_message']); ?>
                    <article class="<?= UI_CARD ?> border-l-4 <?= $critical ? 'border-l-destructive' : 'border-l-warning' ?> p-5">
                        <div class="flex flex-wrap items-center gap-2">
                            <?= ui_badge(ALERT_TYPE_LABELS[$a['alert_type']] ?? $a['alert_type'], 'secondary') ?>
                            <?= ui_badge(($critical ? 'Critical' : 'Warning') . ' risk', $critical ? 'destructive' : 'warning') ?>
                            <?= ui_badge($a['data_confidence'] === 'high' ? 'High confidence' : 'Low confidence', $a['data_confidence'] === 'high' ? 'success' : 'warning') ?>
                            <span class="ml-auto text-[0.7rem] text-muted-foreground"><?= e(date('d M, H:i', strtotime($a['created_at']))) ?> · <?= e(ucfirst($a['status'])) ?></span>
                        </div>
                        <p class="mt-3 text-[0.83rem] leading-relaxed text-foreground/85"><?= e($rationale) ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <!-- Trend -->
    <section class="<?= UI_CARD ?>" aria-labelledby="trend-title">
        <div class="<?= UI_CARD_HEADER ?>">
            <div>
                <h2 id="trend-title" class="<?= UI_CARD_TITLE ?>">30-day glucose trend</h2>
                <p class="mt-1 text-xs text-muted-foreground">Shaded band = ADA pre-meal target (<?= e(fmt_glucose((float)$t['target_fasting_min'], $unit)) ?>–<?= e(fmt_glucose((float)$t['target_fasting_max'], $unit)) ?> <?= e($unit) ?>) · values shown in the patient's preferred unit</p>
            </div>
            <?= ui_badge($conf === 'high' ? 'High data confidence' : 'Low data confidence', $conf === 'high' ? 'success' : 'warning') ?>
        </div>
        <div class="<?= UI_CARD_BODY ?>">
            <?php if ($readings): ?>
                <div class="h-[340px] w-full"><canvas id="trend" role="img" aria-label="Blood glucose trend for the last 30 days against target ranges"></canvas></div>
            <?php else: ?>
                <?= ui_empty('line-chart', 'No readings in the last 30 days', 'Nothing to trend yet for this patient.') ?>
            <?php endif; ?>
        </div>
    </section>

    <!-- Readings table -->
    <section class="<?= UI_CARD ?>" aria-labelledby="readings-title">
        <div class="<?= UI_CARD_HEADER ?>">
            <div>
                <h2 id="readings-title" class="<?= UI_CARD_TITLE ?>">Readings — last 30 days</h2>
                <p class="mt-1 text-xs text-muted-foreground"><?= count($readings) ?> recorded</p>
            </div>
        </div>
        <div class="max-h-[26rem] overflow-auto scroll-area">
            <table class="w-full min-w-[640px] border-collapse text-sm">
                <caption class="sr-only">Glucose readings with context, status and reported symptoms.</caption>
                <thead class="sticky top-0 z-10">
                    <tr class="border-b border-border bg-muted/80 text-left text-[0.68rem] uppercase tracking-[0.07em] text-muted-foreground backdrop-blur">
                        <th scope="col" class="px-5 py-2.5 font-semibold">Date &amp; time</th>
                        <th scope="col" class="px-3 py-2.5 font-semibold">Value</th>
                        <th scope="col" class="px-3 py-2.5 font-semibold">Context</th>
                        <th scope="col" class="px-3 py-2.5 font-semibold">Status</th>
                        <th scope="col" class="px-5 py-2.5 font-semibold">Symptoms</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                <?php foreach ($readings as $r):
                    $band = reading_band((float)$r['glucose_value'], $r['meal_context'], $t);
                    [$bandText] = band_label($band); ?>
                    <tr class="hover:bg-muted/40">
                        <td class="whitespace-nowrap px-5 py-2.5 text-muted-foreground"><?= e(date('d M Y, H:i', strtotime($r['logged_at']))) ?></td>
                        <td class="px-3 py-2.5 font-semibold tabular">
                            <?= e(fmt_glucose((float)$r['glucose_value'], $unit)) ?> <span class="text-[0.65rem] font-normal text-muted-foreground"><?= e($unit) ?></span>
                            <?php if (normalize_unit($r['entered_unit'] ?? null) !== $unit): ?>
                                <span class="block text-[0.65rem] font-normal text-muted-foreground">entered <?= e(fmt_glucose((float)$r['glucose_value'], normalize_unit($r['entered_unit'] ?? null), true)) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="px-3 py-2.5 text-muted-foreground"><?= e(MEAL_CONTEXTS[$r['meal_context']]) ?></td>
                        <td class="px-3 py-2.5">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <?= band_badge($band, $bandText) ?>
                                <?php if ($r['was_confirmed']): ?><?= ui_badge('Patient confirmed', 'outline') ?><?php endif; ?>
                            </div>
                        </td>
                        <td class="px-5 py-2.5 text-xs text-muted-foreground"><?= e($r['symptoms'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$readings): ?>
                    <tr><td colspan="5"><?= ui_empty('droplet', 'No readings yet', 'This patient has not logged any glucose readings in the last 30 days.') ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<?php
$thresholdInputs = '';
foreach (THRESHOLD_FIELDS as $k => $label) {
    $thresholdInputs .= '<div class="space-y-2">'
        . '<label for="' . $k . '" class="' . UI_LABEL . '">' . e($label) . '</label>'
        . '<input id="' . $k . '" name="' . $k . '" type="number" step="0.1" min="20" max="600" required'
        . ' value="' . e(cds_fmt((float)($_POST[$k] ?? $t[$k]))) . '"'
        . ' class="' . UI_INPUT . ' tabular" inputmode="decimal">'
        . '</div>';
}

$dialogBody = ($thresholdError ? ui_alert('destructive', 'Targets were not saved', e($thresholdError)) : '')
    . '<p class="mb-4 text-xs leading-relaxed text-muted-foreground">'
    . 'Values are in <strong>mg/dL</strong> (the canonical storage unit); the patient\'s views convert them to ' . e($unit) . '. '
    . 'Defaults follow ADA guidance. Ranges must stay ordered: severe hypo &lt; hypo ≤ fasting min &lt; fasting max ≤ post-meal max &lt; critical high. Every change is audited.'
    . '</p>'
    . '<div class="grid grid-cols-2 gap-4">' . $thresholdInputs . '</div>'
    . '<p id="threshold-order-error" role="alert" hidden class="mt-3 flex items-start gap-1.5 text-xs font-medium text-destructive">'
    . icon('alert-triangle', 'mt-px h-3.5 w-3.5 shrink-0')
    . '<span>Ranges must be ordered: severe hypo &lt; hypo ≤ fasting min &lt; fasting max ≤ post-meal max &lt; critical high (max 600 mg/dL).</span></p>';

$dialogFooter = ui_button('Cancel', ['variant' => 'outline', 'attrs' => 'data-dialog-close'])
    . ui_button('Save targets', ['type' => 'submit', 'icon' => 'check', 'attrs' => 'form="thresholdForm"']);

echo '<dialog id="threshold-dialog" class="app-dialog' . ($thresholdError ? ' max-w-2xl' : '') . '" aria-labelledby="threshold-dialog-title"'
    . ($thresholdError ? ' data-autoshow="1"' : '') . '>'
    . '<form method="post" id="thresholdForm">'
    . csrf_field()
    . '<input type="hidden" name="action" value="thresholds">'
    . '<div class="flex items-start justify-between gap-4 px-6 pb-4 pt-6">'
    . '<div class="flex items-start gap-3">'
    . '<span class="mt-0.5 grid h-9 w-9 shrink-0 place-items-center rounded-full bg-primary/10 text-primary">' . icon('sliders', 'h-4 w-4') . '</span>'
    . '<div><h2 id="threshold-dialog-title" class="text-base font-semibold leading-6">Adjust target ranges</h2>'
    . '<p class="mt-1 text-sm text-muted-foreground">' . e($patient['full_name']) . ' · individualised thresholds override the ADA defaults.</p></div>'
    . '</div>'
    . '<button type="button" data-dialog-close aria-label="Close dialog" class="grid h-8 w-8 shrink-0 place-items-center rounded-md text-muted-foreground transition hover:bg-muted hover:text-foreground">' . icon('x', 'h-4 w-4') . '</button>'
    . '</div>'
    . '<div class="app-dialog-body border-t border-border">' . $dialogBody . '</div>'
    . '<div class="flex flex-wrap justify-end gap-2 border-t border-border bg-muted/40 px-6 py-4">' . $dialogFooter . '</div>'
    . '</form></dialog>';

echo '<dialog id="allergies-dialog" class="app-dialog" aria-labelledby="allergies-dialog-title">'
    . '<form method="post" id="allergies-form">' . csrf_field()
    . '<input type="hidden" name="action" value="allergies">'
    . '<div class="flex items-start justify-between gap-4 px-6 pb-4 pt-6">'
    . '<div class="flex items-start gap-3">'
    . '<span class="mt-0.5 grid h-9 w-9 shrink-0 place-items-center rounded-full bg-warning/15 text-warning">' . icon('alert-triangle', 'h-4 w-4') . '</span>'
    . '<div><h2 id="allergies-dialog-title" class="text-base font-semibold leading-6">Recorded allergies</h2>'
    . '<p class="mt-1 text-sm text-muted-foreground">' . e($patient['full_name']) . ' · checked before a prescription is issued.</p></div></div>'
    . '<button type="button" data-dialog-close aria-label="Close dialog" class="grid h-8 w-8 shrink-0 place-items-center rounded-md text-muted-foreground transition hover:bg-muted hover:text-foreground">' . icon('x', 'h-4 w-4') . '</button>'
    . '</div>'
    . '<div class="app-dialog-body border-t border-border">'
    . ui_field(['id' => 'allergies', 'name' => 'allergies', 'label' => 'Allergies',
        'value' => (string)($patient['allergies'] ?? ''), 'attrs' => 'maxlength="255" placeholder="e.g. penicillin, sulfa drugs"',
        'hint' => 'Separate with commas. A prescription for a medication that matches one of these is blocked.'])
    . '<p class="mt-4 flex items-start gap-2 text-xs leading-relaxed text-muted-foreground">'
    . icon('shield-check', 'mt-0.5 h-3.5 w-3.5 shrink-0 text-success')
    . '<span>Keep this factual and short ("penicillin", not "penicillin rash"). Changes are written to the audit trail.</span></p>'
    . '</div>'
    . '<div class="flex flex-wrap justify-end gap-2 border-t border-border bg-muted/40 px-6 py-4">'
    . ui_button('Cancel', ['variant' => 'outline', 'attrs' => 'data-dialog-close'])
    . ui_button('Save allergies', ['type' => 'submit', 'icon' => 'check'])
    . '</div></form></dialog>';
?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
