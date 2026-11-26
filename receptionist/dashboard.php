<?php
// Front-desk dashboard. Deliberately shows no clinical detail: see includes/receptionist.php.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/receptionist.php';
require_once __DIR__ . '/../includes/cds_engine.php';
require_role('receptionist');

$pdo = get_db_connection();
$me = current_user();

$stmt = $pdo->prepare('SELECT full_name, desk FROM receptionists WHERE user_id = ?');
$stmt->execute([$me['user_id']]);
$desk = $stmt->fetch() ?: ['full_name' => $me['full_name'], 'desk' => null];

$stats = receptionist_stats($pdo);
$alerts = deidentified_alerts($pdo, 6);
$counts = open_alert_counts($pdo);

// Today's check-ins: patients who had observations recorded at the desk today.
$checkins = $pdo->prepare('
    SELECT p.patient_id, p.full_name, MAX(v.recorded_at) AS last_at, COUNT(*) AS n
    FROM vitals v
    JOIN patients p ON p.patient_id = v.patient_id
    JOIN users u ON u.user_id = p.user_id
    WHERE u.org_id = ? AND v.recorded_at >= CURRENT_DATE
    GROUP BY p.patient_id, p.full_name
    ORDER BY MAX(v.recorded_at) DESC
    LIMIT 8');
$checkins->execute([current_org_id()]);
$checkinRows = $checkins->fetchAll();

$page_title = 'Front desk';
require __DIR__ . '/../includes/header.php';
?>
<div class="space-y-6">
    <?= ui_page_header(
        $desk['full_name'],
        ($desk['desk'] ? $desk['desk'] . ' · ' : '') . 'Register patients, take observations, send referrals on to a doctor or department, and watch the alert feed.',
        ui_button('Register a patient', ['href' => 'new_patient.php', 'icon' => 'user-plus'])
        . ui_button('Record vitals', ['href' => 'vitals.php', 'variant' => 'outline', 'icon' => 'heart-pulse'])
        . ui_button('Refer a patient', ['href' => 'referrals.php', 'variant' => 'outline', 'icon' => 'arrow-right'])
    ) ?>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <?php foreach ([
            ['Registered today', $stats['registered_today'], 'user-plus', 'text-primary', 'bg-primary/10', 'New patient records'],
            ['Vitals today', $stats['vitals_today'], 'heart-pulse', 'text-success', 'bg-success/10', 'Observations taken at the desk'],
            ['Open alerts', $counts['total'], 'bell', 'text-warning', 'bg-warning/15', $counts['critical'] . ' critical'],
            ['Referrals pending', $stats['pending_referrals'], 'clipboard-list', 'text-slate-600', 'bg-muted', 'Waiting on a doctor or department'],
        ] as [$label, $value, $iconName, $tone, $tile, $foot]): ?>
            <div class="<?= UI_CARD ?> px-5 py-4">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-[0.7rem] font-semibold uppercase tracking-[0.07em] text-muted-foreground"><?= e($label) ?></p>
                        <p class="mt-1 text-3xl font-bold leading-none tabular tracking-tight"><?= (int)$value ?></p>
                    </div>
                    <span class="grid h-10 w-10 place-items-center rounded-lg <?= $tile ?> <?= $tone ?>"><?= icon($iconName, 'h-5 w-5') ?></span>
                </div>
                <p class="mt-3 text-xs text-muted-foreground"><?= e($foot) ?></p>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="grid gap-6 lg:grid-cols-5">
        <section class="lg:col-span-3 space-y-3" aria-labelledby="desk-alerts">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 id="desk-alerts" class="text-base font-semibold tracking-tight">Needs attention</h2>
                    <p class="text-xs text-muted-foreground">Open alerts across your organisation, shown without patient names or clinical values.</p>
                </div>
                <?= ui_badge($counts['total'] . ' open', $counts['critical'] ? 'destructive' : 'secondary') ?>
            </div>

            <?php if ($alerts): ?>
                <ul class="space-y-2.5">
                    <?php foreach ($alerts as $a):
                        $critical = $a['risk_level'] === 'critical';
                        $ageMin = max(0, (int)floor((time() - strtotime($a['created_at'])) / 60));
                        $age = $ageMin < 60 ? $ageMin . ' min ago' : ($ageMin < 2880 ? floor($ageMin / 60) . ' h ago' : floor($ageMin / 1440) . ' d ago');
                        $label = ALERT_TYPE_LABELS[$a['alert_type']] ?? $a['alert_type']; ?>
                        <li class="<?= UI_CARD ?> flex flex-wrap items-start justify-between gap-3 border-l-4 <?= $critical ? 'border-l-destructive' : 'border-l-warning' ?> px-4 py-3">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <?= ui_badge($critical ? 'Emergency' : 'Warning', $critical ? 'destructive' : 'warning') ?>
                                    <span class="font-mono text-xs font-semibold text-foreground"><?= e(patient_code((int)$a['patient_id'])) ?></span>
                                    <?= ui_badge($label, 'secondary') ?>
                                </div>
                                <p class="mt-1.5 text-sm text-foreground"><?= e($a['clinician_name'] ? 'Waiting on ' . $a['clinician_name'] : 'No doctor assigned — ask an administrator to assign one') ?></p>
                                <p class="mt-0.5 text-xs text-muted-foreground">Reference <?= e(patient_code((int)$a['patient_id'])) ?> · raised <?= e($age) ?></p>
                            </div>
                            <a href="patients.php?q=<?= (int)$a['patient_id'] ?>"
                               class="inline-flex h-8 shrink-0 items-center gap-1 rounded-md border border-input bg-white px-2.5 text-xs font-medium transition hover:bg-secondary/60">
                                Find patient <?= icon('arrow-right', 'h-3.5 w-3.5') ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p class="flex items-start gap-2 rounded-lg border border-border bg-muted/40 px-3.5 py-3 text-xs leading-relaxed text-muted-foreground">
                    <?= icon('shield-check', 'mt-0.5 h-3.5 w-3.5 shrink-0 text-success') ?>
                    <span>This feed intentionally hides names, readings and clinical notes. Use the reference to find the patient, then contact the assigned doctor. Only the care team opens the clinical record.</span>
                </p>
            <?php else: ?>
                <div class="rounded-lg border border-border bg-card">
                    <?= ui_empty('check-circle', 'Nothing needs attention', 'No open alerts for your organisation right now. The rule engine re-checks after every reading.') ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="lg:col-span-2 space-y-3" aria-labelledby="checkins">
            <div class="flex items-center justify-between gap-2">
                <h2 id="checkins" class="text-base font-semibold tracking-tight">Checked in today</h2>
                <a href="patients.php" class="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline">
                    Register <?= icon('chevron-right', 'h-3.5 w-3.5') ?>
                </a>
            </div>
            <div class="<?= UI_CARD ?>">
                <ul class="divide-y divide-border">
                    <?php foreach ($checkinRows as $c): ?>
                        <li class="flex items-center justify-between gap-3 px-4 py-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium"><?= e($c['full_name']) ?></p>
                                <p class="text-xs text-muted-foreground">
                                    <span class="font-mono"><?= e(patient_code((int)$c['patient_id'])) ?></span>
                                    · <?= e(date('H:i', strtotime($c['last_at']))) ?>
                                    <?= (int)$c['n'] > 1 ? '· ' . (int)$c['n'] . ' sets' : '' ?>
                                </p>
                            </div>
                            <a href="vitals.php?patient_id=<?= (int)$c['patient_id'] ?>" class="text-xs font-medium text-primary hover:underline">Vitals</a>
                        </li>
                    <?php endforeach; ?>
                    <?php if (!$checkinRows): ?>
                        <li><?= ui_empty('calendar', 'No check-ins yet', 'Patients appear here once observations are recorded today.') ?></li>
                    <?php endif; ?>
                </ul>
            </div>

            <div class="<?= UI_CARD ?> p-4">
                <p class="text-sm font-semibold">Find a patient</p>
                <form method="get" action="patients.php" class="mt-2.5 flex gap-2">
                    <label for="quick-find" class="sr-only">Search by name, phone or reference</label>
                    <input id="quick-find" type="search" name="q" placeholder="Name, phone or P-0000"
                           class="h-10 min-w-0 flex-1 rounded-md border border-input bg-white px-3 text-sm shadow-sm placeholder:text-muted-foreground/70 focus-visible:border-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/30">
                    <?= ui_button('Search', ['type' => 'submit', 'variant' => 'outline', 'icon' => 'search']) ?>
                </form>
            </div>
        </section>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
