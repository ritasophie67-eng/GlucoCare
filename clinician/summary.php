<?php
// Printable one-page summary for a consultation or handover.
// Read-only: nothing here changes a record, so it is safe to print and hand over.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/readings.php';
require_once __DIR__ . '/../includes/cds_engine.php';
require_once __DIR__ . '/../includes/clinical_rules.php';
require_role('clinician');

$pdo = get_db_connection();
$patientId = (int)($_GET['patient_id'] ?? 0);
$patient = require_assigned_patient($pdo, $patientId);
$unit = patient_unit($patient);

$stmt = $pdo->prepare('SELECT * FROM patient_thresholds WHERE patient_id = ?');
$stmt->execute([$patientId]);
$t = $stmt->fetch();

$stmt = $pdo->prepare('SELECT created_at FROM users WHERE user_id = ?');
$stmt->execute([$patient['user_id']]);
$registeredAt = (string)$stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT * FROM glucose_readings WHERE patient_id = ? AND logged_at >= (NOW() - make_interval(days => 14)) ORDER BY logged_at DESC');
$stmt->execute([$patientId]);
$readings = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT * FROM clinical_alerts WHERE patient_id = ? ORDER BY (status = 'pending') DESC, created_at DESC LIMIT 5");
$stmt->execute([$patientId]);
$alerts = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT * FROM prescriptions WHERE patient_id = ? AND status = 'active' ORDER BY created_at DESC");
$stmt->execute([$patientId]);
$rx = $stmt->fetchAll();

$vitals = latest_vitals($pdo, $patientId);
$adh14 = adherence_stats($pdo, $patientId, $registeredAt, 14);

$values = array_map(fn($r) => (float)$r['glucose_value'], $readings);
$inRange = 0;
foreach ($readings as $r) {
    if (reading_band((float)$r['glucose_value'], $r['meal_context'], $t) === 'in_range') {
        $inRange++;
    }
}

$page_title = 'Visit summary';
require __DIR__ . '/../includes/header.php';
?>
<div class="space-y-6 print:space-y-3">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Visit summary</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                <?= e(date('l, j F Y')) ?> · prepared by <?= e($_SESSION['full_name']) ?> · decision support only
            </p>
        </div>
        <div class="flex items-center gap-2 print:hidden">
            <?= ui_button('Print', ['variant' => 'outline', 'icon' => 'clipboard-list', 'attrs' => 'data-print']) ?>
            <?= ui_button('Back to record', ['href' => 'patient.php?id=' . $patientId, 'variant' => 'ghost', 'icon' => 'arrow-left']) ?>
        </div>
    </div>

    <section class="<?= UI_CARD ?>">
        <div class="<?= UI_CARD_BODY ?> grid gap-4 sm:grid-cols-3">
            <div>
                <p class="text-[0.68rem] font-semibold uppercase tracking-[0.07em] text-muted-foreground">Patient</p>
                <p class="mt-1 text-base font-semibold"><?= e($patient['full_name']) ?></p>
                <p class="text-xs text-muted-foreground">Patient #<?= (int)$patientId ?> · <?= e(ucfirst($patient['gender'])) ?>, <?= calc_age($patient['date_of_birth']) ?> years · DOB <?= e(date('d M Y', strtotime($patient['date_of_birth']))) ?></p>
                <p class="mt-1 text-xs text-muted-foreground"><?= e(phone_display($patient['phone'])) ?></p>
            </div>
            <div>
                <p class="text-[0.68rem] font-semibold uppercase tracking-[0.07em] text-muted-foreground">Allergies</p>
                <p class="mt-1 text-sm font-medium <?= empty($patient['allergies']) ? 'text-muted-foreground' : 'text-destructive' ?>"><?= e($patient['allergies'] ?: 'None recorded') ?></p>
                <p class="mt-3 text-[0.68rem] font-semibold uppercase tracking-[0.07em] text-muted-foreground">Registered</p>
                <p class="mt-1 text-xs text-muted-foreground"><?= e(date('d M Y', strtotime($registeredAt))) ?></p>
            </div>
            <div>
                <p class="text-[0.68rem] font-semibold uppercase tracking-[0.07em] text-muted-foreground">Data confidence (14 days)</p>
                <p class="mt-1 text-sm font-medium"><?= e(ucfirst(data_confidence($adh14['pct']))) ?> · <?= (int)$adh14['count'] ?> readings (<?= e((string)$adh14['pct']) ?>% of target)</p>
                <p class="text-xs text-muted-foreground"><?= count($readings) ?> readings in the last 14 days</p>
            </div>
        </div>
    </section>

    <section class="<?= UI_CARD ?>">
        <div class="<?= UI_CARD_HEADER ?>"><h2 class="<?= UI_CARD_TITLE ?>">Glucose control — 14 days</h2></div>
        <div class="<?= UI_CARD_BODY ?> grid gap-4 sm:grid-cols-4">
            <?php
            $avg = $values ? array_sum($values) / count($values) : null;
            foreach ([
                ['Average', $avg === null ? '—' : fmt_glucose($avg, $unit)],
                ['Lowest', $values ? fmt_glucose(min($values), $unit) : '—'],
                ['Highest', $values ? fmt_glucose(max($values), $unit) : '—'],
                ['In range', $readings ? round($inRange / count($readings) * 100) . '%' : '—'],
            ] as [$label, $value]): ?>
                <div>
                    <p class="text-[0.68rem] font-semibold uppercase tracking-[0.07em] text-muted-foreground"><?= e($label) ?></p>
                    <p class="mt-1 text-xl font-bold tabular"><?= e($value) ?><?= $value !== '—' && $label !== 'In range' ? ' <span class="text-xs font-medium text-muted-foreground">' . e($unit) . '</span>' : '' ?></p>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="border-t border-border px-5 py-3 text-xs text-muted-foreground">
            Targets: <?= e(fmt_glucose((float)$t['target_fasting_min'], $unit)) ?>–<?= e(fmt_glucose((float)$t['target_fasting_max'], $unit)) ?>
            fasting, post-meal ≤ <?= e(fmt_glucose((float)$t['target_postprandial_max'], $unit)) ?>,
            hypo &lt; <?= e(fmt_glucose((float)$t['hypo_threshold'], $unit)) ?>,
            critical high &gt; <?= e(fmt_glucose((float)$t['critical_high_threshold'], $unit)) ?> <?= e($unit) ?>
        </div>
    </section>

    <div class="grid gap-6 sm:grid-cols-2 print:grid-cols-2">
        <section class="<?= UI_CARD ?>">
            <div class="<?= UI_CARD_HEADER ?>"><h2 class="<?= UI_CARD_TITLE ?>">Latest vitals</h2></div>
            <div class="<?= UI_CARD_BODY ?> text-sm">
                <?php if ($vitals): ?>
                    <dl class="space-y-1.5">
                        <div class="flex justify-between"><dt class="text-muted-foreground">Blood pressure</dt><dd class="font-semibold tabular"><?= (int)$vitals['systolic_bp'] ?>/<?= (int)$vitals['diastolic_bp'] ?> mmHg</dd></div>
                        <div class="flex justify-between"><dt class="text-muted-foreground">Heart rate</dt><dd class="font-semibold tabular"><?= (int)$vitals['heart_rate'] ?> bpm</dd></div>
                        <?php if ($vitals['weight_kg'] !== null): ?><div class="flex justify-between"><dt class="text-muted-foreground">Weight</dt><dd class="font-semibold tabular"><?= e(rtrim(rtrim((string)$vitals['weight_kg'], '0'), '.')) ?> kg</dd></div><?php endif; ?>
                        <div class="flex justify-between"><dt class="text-muted-foreground">Recorded</dt><dd class="tabular"><?= e(date('d M Y, H:i', strtotime($vitals['recorded_at']))) ?></dd></div>
                    </dl>
                <?php else: ?>
                    <p class="text-muted-foreground">No vitals recorded.</p>
                <?php endif; ?>
            </div>
        </section>

        <section class="<?= UI_CARD ?>">
            <div class="<?= UI_CARD_HEADER ?>"><h2 class="<?= UI_CARD_TITLE ?>">Active prescriptions</h2></div>
            <div class="<?= UI_CARD_BODY ?> text-sm">
                <?php if ($rx): ?>
                    <ul class="space-y-2">
                        <?php foreach ($rx as $r): ?>
                            <li>
                                <p class="font-medium"><?= e($r['medication_name']) ?> · <?= e($r['dosage']) ?></p>
                                <p class="text-xs text-muted-foreground"><?= e($r['frequency']) ?> · from <?= e(date('d M Y', strtotime($r['start_date']))) ?></p>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="text-muted-foreground">No active prescriptions.</p>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <section class="<?= UI_CARD ?>">
        <div class="<?= UI_CARD_HEADER ?>">
            <h2 class="<?= UI_CARD_TITLE ?>">Recent alerts</h2>
            <span class="text-xs text-muted-foreground"><?= count($alerts) ?> most recent</span>
        </div>
        <div class="overflow-x-auto scroll-area">
            <table class="w-full min-w-[640px] border-collapse text-sm">
                <thead>
                    <tr class="border-b border-border bg-muted/50 text-left text-[0.68rem] uppercase tracking-[0.07em] text-muted-foreground">
                        <th scope="col" class="px-5 py-2 font-semibold">Raised</th>
                        <th scope="col" class="px-3 py-2 font-semibold">Pattern</th>
                        <th scope="col" class="px-3 py-2 font-semibold">Risk</th>
                        <th scope="col" class="px-5 py-2 font-semibold">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <?php foreach ($alerts as $a): ?>
                        <tr>
                            <td class="whitespace-nowrap px-5 py-2 text-xs text-muted-foreground"><?= e(date('d M Y', strtotime($a['created_at']))) ?></td>
                            <td class="px-3 py-2"><?= e(ALERT_TYPE_LABELS[$a['alert_type']] ?? $a['alert_type']) ?></td>
                            <td class="px-3 py-2 capitalize"><?= e($a['risk_level']) ?></td>
                            <td class="px-5 py-2 capitalize"><?= e($a['status']) ?><?= $a['clinician_note'] ? ' · ' . e(mb_substr($a['clinician_note'], 0, 60)) : '' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$alerts): ?>
                        <tr><td colspan="4" class="px-5 py-4 text-sm text-muted-foreground">No alerts on record.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <p class="text-[0.7rem] leading-relaxed text-muted-foreground">
        Generated <?= e(date('d M Y, H:i')) ?> by <?= e(APP_NAME) ?>. Clinical decision support only — every decision remains with the treating clinician.
        Alerts are rule-based and depend on the quality of logged data.
    </p>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
