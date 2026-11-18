<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/readings.php';
require_once __DIR__ . '/../includes/cds_engine.php';
require_role('clinician');

$pdo = get_db_connection();
$clinicianId = (int)$_SESSION['clinician_id'];

// One-click acknowledgement from the triage view. The JOIN enforces ownership.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ack') {
    if (!csrf_valid()) {
        set_flash('danger', 'Your session expired. Please try again.');
    } else {
        $stmt = $pdo->prepare("SELECT a.alert_id, a.alert_type, p.patient_id, p.full_name
                               FROM clinical_alerts a JOIN patients p ON p.patient_id = a.patient_id
                               WHERE a.alert_id = ? AND p.clinician_id = ? AND a.status = 'pending'");
        $stmt->execute([(int)($_POST['alert_id'] ?? 0), $clinicianId]);
        if ($a = $stmt->fetch()) {
            $pdo->prepare("UPDATE clinical_alerts SET status = 'reviewed', reviewed_at = NOW(), clinician_id = ? WHERE alert_id = ?")
                ->execute([$clinicianId, (int)$a['alert_id']]);
            log_audit($pdo, (int)$_SESSION['user_id'], 'clinical_alerts', 'ALERT_REVIEWED',
                "Alert #{$a['alert_id']} ({$a['alert_type']}) for patient #{$a['patient_id']} acknowledged from triage.");
            set_flash('success', 'Alert acknowledged for ' . $a['full_name'] . '.');
        } else {
            set_flash('danger', 'That alert is no longer pending on your list.');
        }
    }
    redirect('clinician/dashboard.php');
}

$stmt = $pdo->prepare('SELECT * FROM clinicians WHERE clinician_id = ?');
$stmt->execute([$clinicianId]);
$doc = $stmt->fetch();

// Only patients assigned to THIS clinician.
$stmt = $pdo->prepare('
    SELECT p.patient_id, p.full_name, p.gender, p.date_of_birth, p.phone, p.preferred_unit, u.created_at AS registered_at,
           (SELECT glucose_value FROM glucose_readings g WHERE g.patient_id = p.patient_id ORDER BY logged_at DESC LIMIT 1) AS latest_value,
           (SELECT meal_context  FROM glucose_readings g WHERE g.patient_id = p.patient_id ORDER BY logged_at DESC LIMIT 1) AS latest_context,
           (SELECT logged_at     FROM glucose_readings g WHERE g.patient_id = p.patient_id ORDER BY logged_at DESC LIMIT 1) AS last_at,
           t.hypo_threshold, t.severe_hypo_threshold, t.critical_high_threshold, t.target_fasting_max, t.target_postprandial_max
    FROM patients p
    JOIN users u ON u.user_id = p.user_id
    JOIN patient_thresholds t ON t.patient_id = p.patient_id
    WHERE p.clinician_id = ?
    ORDER BY p.full_name');
$stmt->execute([$clinicianId]);
$patients = $stmt->fetchAll();

$rank = ['critical' => 4, 'high' => 3, 'moderate' => 2, 'low' => 1];
$stmt = $pdo->prepare("SELECT a.patient_id, a.risk_level, a.data_confidence FROM clinical_alerts a
                       JOIN patients p ON p.patient_id = a.patient_id
                       WHERE p.clinician_id = ? AND a.status = 'pending'");
$stmt->execute([$clinicianId]);
$worst = [];
$pendingTotal = $criticalTotal = 0;
foreach ($stmt->fetchAll() as $a) {
    $pid = (int)$a['patient_id'];
    $pendingTotal++;
    $criticalTotal += $a['risk_level'] === 'critical' ? 1 : 0;
    $key = $rank[$a['risk_level']] * 2 + ($a['data_confidence'] === 'high' ? 1 : 0);
    if (!isset($worst[$pid]) || $key > $worst[$pid]['key']) {
        $worst[$pid] = ['key' => $key, 'level' => $a['risk_level'], 'conf' => $a['data_confidence']];
    }
    $worst[$pid]['n'] = ($worst[$pid]['n'] ?? 0) + 1;
}

$lowConfidence = 0;
$criticalPatients = 0;
foreach ($patients as &$p) {
    $p['adh'] = adherence_stats($pdo, (int)$p['patient_id'], $p['registered_at'], CDS_ADHERENCE_DAYS);
    $p['conf'] = data_confidence($p['adh']['pct']);
    $p['risk'] = $worst[(int)$p['patient_id']] ?? null;
    $p['sort'] = $p['risk']['key'] ?? 0;
    // Dual-factor severity for the triage filters.
    $p['severity'] = match (true) {
        ($p['risk']['level'] ?? '') === 'critical' => 'critical',
        in_array($p['risk']['level'] ?? '', ['high', 'moderate'], true) => 'warning',
        default => 'stable',
    };
    if ($p['conf'] === 'low') {
        $lowConfidence++;
    }
    if ($p['severity'] === 'critical') {
        $criticalPatients++;
    }
}
unset($p);
// Triage order: highest risk first, then higher data confidence, then name.
usort($patients, fn($a, $b) => [$b['sort'], $a['full_name']] <=> [$b['sort'], $a['full_name']]);

// Explainable rationale for the most urgent open alerts.
$stmt = $pdo->prepare("SELECT a.*, p.full_name FROM clinical_alerts a
                       JOIN patients p ON p.patient_id = a.patient_id
                       WHERE p.clinician_id = ? AND a.status = 'pending'
                       ORDER BY CASE a.risk_level WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'moderate' THEN 3 ELSE 4 END,
                                (a.data_confidence = 'high') DESC, a.created_at DESC
                       LIMIT 3");
$stmt->execute([$clinicianId]);
$priorityAlerts = $stmt->fetchAll();

$page_title = 'Triage Dashboard';
require __DIR__ . '/../includes/header.php';
?>
<div class="space-y-6">
    <?= ui_page_header(
        $doc['full_name'],
        $doc['specialization'] . ' · Licence ' . $doc['license_no'] . '. Patients are ordered by clinical severity, then by how much the recorded data can be trusted.',
        ui_button('Open alerts queue', ['href' => 'alerts.php', 'icon' => 'bell'])
        . ui_button('Patient list', ['href' => '#triage-table', 'variant' => 'outline', 'icon' => 'users'])
    ) ?>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <?php foreach ([
            ['My patients', count($patients), 'users', 'text-primary', 'bg-primary/10', 'Assigned to you only'],
            ['Critical patients', $criticalPatients, 'alert-octagon', 'text-destructive', 'bg-destructive/10', 'Needs review today'],
            ['Pending alerts', $pendingTotal, 'bell', 'text-warning', 'bg-warning/15', $criticalTotal . ' critical severity'],
            ['Low-confidence data', $lowConfidence, 'gauge', 'text-slate-600', 'bg-muted', '< ' . HIGH_CONFIDENCE_PCT . '% adherence'],
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

    <!-- Explainable alert cards -->
    <section class="space-y-3" aria-labelledby="priority-alerts">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h2 id="priority-alerts" class="text-base font-semibold tracking-tight">Priority alerts — why they fired</h2>
                <p class="text-xs text-muted-foreground">Each card states the exact rule condition and the data confidence behind it.</p>
            </div>
            <a href="alerts.php" class="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline">
                All alerts <?= icon('chevron-right', 'h-3.5 w-3.5') ?>
            </a>
        </div>

        <?php if (!$priorityAlerts): ?>
            <div class="rounded-lg border border-border bg-card">
                <?= ui_empty('check-circle', 'No pending alerts', 'The rule engine has not flagged any of your patients. It re-evaluates on every new reading and on each scheduled scan.') ?>
            </div>
        <?php endif; ?>

        <div class="grid gap-3 <?= count($priorityAlerts) > 1 ? 'lg:grid-cols-2 2xl:grid-cols-3' : '' ?>">
            <?php foreach ($priorityAlerts as $a):
                $critical = $a['risk_level'] === 'critical';
                $sevLabel = $critical ? 'Critical' : 'Warning';
                $confLabel = $a['data_confidence'] === 'high' ? 'High Data Confidence' : 'Low Data Confidence';
                $rationale = preg_replace('/^Flagged as [^:]+:\s*/', '', $a['alert_message']);
                $accent = $critical ? 'border-l-4 border-l-destructive' : 'border-l-4 border-l-warning'; ?>
                <article class="<?= UI_CARD ?> <?= $accent ?> p-5">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold leading-5"><?= e($sevLabel . ' Risk – ' . $confLabel) ?></p>
                            <a href="patient.php?id=<?= (int)$a['patient_id'] ?>" class="mt-0.5 inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline">
                                <?= e($a['full_name']) ?> <?= icon('chevron-right', 'h-3.5 w-3.5') ?>
                            </a>
                        </div>
                        <span class="mt-0.5 shrink-0"><?= icon($critical ? 'alert-octagon' : 'alert-triangle', 'h-4 w-4 ' . ($critical ? 'text-destructive' : 'text-warning')) ?></span>
                    </div>
                    <p class="mt-3 text-[0.83rem] leading-relaxed text-foreground/80"><?= e($rationale) ?></p>
                    <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-border pt-3">
                        <?= ui_badge(ALERT_TYPE_LABELS[$a['alert_type']] ?? $a['alert_type'], 'secondary') ?>
                        <?= ui_badge('Adherence ' . rtrim(rtrim(number_format((float)$a['adherence_pct'], 1), '0'), '.') . '%', $a['data_confidence'] === 'high' ? 'success' : 'warning') ?>
                        <?php
                        $ageH = max(0, (int)floor((time() - strtotime($a['created_at'])) / 3600));
                        $slaText = $ageH < 1 ? 'New' : ($ageH < 48 ? "Unacknowledged {$ageH} h" : 'Unacknowledged ' . round($ageH / 24) . ' d');
                        $slaVariant = $ageH >= 24 ? 'destructive' : ($ageH >= 4 ? 'warning' : 'secondary');
                        ?>
                        <?= ui_badge($slaText, $slaVariant) ?>
                        <span class="text-[0.7rem] text-muted-foreground"><?= e(date('d M, H:i', strtotime($a['created_at']))) ?></span>
                        <form method="post" class="ml-auto">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="ack">
                            <input type="hidden" name="alert_id" value="<?= (int)$a['alert_id'] ?>">
                            <?= ui_button('Acknowledge', ['type' => 'submit', 'size' => 'sm', 'variant' => 'outline', 'icon' => 'check']) ?>
                        </form>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- Triage table -->
    <section id="triage-table" class="scroll-mt-20 <?= UI_CARD ?>" aria-labelledby="triage-title">
        <div class="<?= UI_CARD_HEADER ?>">
            <div>
                <h2 id="triage-title" class="<?= UI_CARD_TITLE ?>">Assigned patients</h2>
                <p class="mt-1 text-xs text-muted-foreground"><span id="triage-count"><?= count($patients) ?></span> shown · severity × data confidence</p>
            </div>
            <div class="flex flex-wrap items-center gap-1.5">
                <?php foreach (['all' => 'All', 'critical' => 'Critical', 'warning' => 'Warning', 'stable' => 'Stable'] as $key => $label): ?>
                    <button type="button" data-triage-severity="<?= $key ?>" aria-pressed="<?= $key === 'all' ? 'true' : 'false' ?>"
                            class="inline-flex h-8 items-center rounded-md border border-border px-2.5 text-xs font-medium transition-colors <?= $key === 'all' ? 'bg-primary text-primary-foreground hover:bg-primary/90' : 'bg-white text-muted-foreground hover:bg-muted' ?>">
                        <?= e($label) ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2 border-b border-border px-5 py-3">
            <label for="triage-search" class="sr-only">Search patients by name</label>
            <div class="relative min-w-[12rem] flex-1">
                <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground"><?= icon('search', 'h-3.5 w-3.5') ?></span>
                <input id="triage-search" type="search" placeholder="Search patient name…"
                       class="h-9 w-full rounded-md border border-input bg-white pl-9 pr-3 text-sm shadow-sm placeholder:text-muted-foreground/70 focus-visible:border-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/30">
            </div>
            <label for="triage-confidence" class="sr-only">Filter by data confidence</label>
            <select id="triage-confidence" class="h-9 rounded-md border border-input bg-white px-3 text-sm shadow-sm focus-visible:border-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/30">
                <option value="all">Any confidence</option>
                <option value="high">High confidence (≥ <?= HIGH_CONFIDENCE_PCT ?>%)</option>
                <option value="low">Low confidence (&lt; <?= HIGH_CONFIDENCE_PCT ?>%)</option>
            </select>
        </div>

        <div data-triage class="overflow-x-auto scroll-area">
            <table class="w-full min-w-[880px] border-collapse text-sm">
                <caption class="sr-only">Assigned patients sorted by risk severity and data confidence, with latest reading and adherence.</caption>
                <thead>
                    <tr class="border-b border-border bg-muted/50 text-left text-[0.68rem] uppercase tracking-[0.07em] text-muted-foreground">
                        <th scope="col" class="px-5 py-2.5 font-semibold">Patient</th>
                        <th scope="col" class="px-3 py-2.5 font-semibold">Latest reading</th>
                        <th scope="col" class="px-3 py-2.5 font-semibold">Last logged</th>
                        <th scope="col" class="px-3 py-2.5 font-semibold">Risk status</th>
                        <th scope="col" class="px-3 py-2.5 font-semibold">Data confidence (<?= CDS_ADHERENCE_DAYS ?> d)</th>
                        <th scope="col" class="px-5 py-2.5 text-right font-semibold"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                <?php foreach ($patients as $p):
                    $sevVariant = ['critical' => 'destructive', 'warning' => 'warning', 'stable' => 'success'][$p['severity']];
                    $sevText = ['critical' => 'Critical', 'warning' => 'Warning', 'stable' => 'Stable'][$p['severity']]; ?>
                    <tr class="transition-colors hover:bg-muted/40"
                        data-severity="<?= e($p['severity']) ?>" data-confidence="<?= e($p['conf']) ?>" data-name="<?= e($p['full_name']) ?>">
                        <td class="px-5 py-3">
                            <div class="flex items-center gap-2.5">
                                <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-primary/10 text-xs font-semibold text-primary">
                                    <?= e(strtoupper(mb_substr($p['full_name'], 0, 1))) ?>
                                </span>
                                <div class="min-w-0">
                                    <p class="truncate font-medium text-foreground"><?= e($p['full_name']) ?></p>
                                    <p class="text-xs capitalize text-muted-foreground"><?= e($p['gender']) ?> · <?= calc_age($p['date_of_birth']) ?> yrs</p>
                                </div>
                            </div>
                        </td>
                        <td class="px-3 py-3">
                            <?php if ($p['latest_value'] !== null):
                                $band = reading_band((float)$p['latest_value'], $p['latest_context'], $p);
                                [$bandText] = band_label($band);
                                $pUnit = patient_unit($p); ?>
                                <div class="flex items-center gap-2">
                                    <span class="font-semibold tabular"><?= e(fmt_glucose((float)$p['latest_value'], $pUnit)) ?></span>
                                    <span class="text-[0.7rem] font-medium text-muted-foreground"><?= e($pUnit) ?></span>
                                    <?= band_badge($band, $bandText) ?>
                                </div>
                                <p class="mt-0.5 text-[0.7rem] text-muted-foreground"><?= e(MEAL_CONTEXTS[$p['latest_context']]) ?></p>
                            <?php else: ?>
                                <span class="text-xs text-muted-foreground">No readings</span>
                            <?php endif; ?>
                        </td>
                        <td class="whitespace-nowrap px-3 py-3 text-xs text-muted-foreground">
                            <?= $p['last_at'] ? e(date('d M, H:i', strtotime($p['last_at']))) : '—' ?>
                        </td>
                        <td class="px-3 py-3">
                            <div class="flex flex-col items-start gap-1">
                                <?= ui_badge($sevText, $sevVariant) ?>
                                <span class="text-[0.7rem] text-muted-foreground">
                                    <?= $p['risk'] ? (int)$p['risk']['n'] . ' pending alert' . ($p['risk']['n'] > 1 ? 's' : '') : 'No open alerts' ?>
                                </span>
                            </div>
                        </td>
                        <td class="px-3 py-3">
                            <div class="flex items-center gap-2">
                                <div class="w-24"><?= ui_progress((float)$p['adh']['pct'], $p['conf'] === 'high') ?></div>
                                <span class="text-xs font-semibold tabular"><?= $p['adh']['pct'] + 0 ?>%</span>
                            </div>
                            <p class="mt-1 text-[0.7rem] <?= $p['conf'] === 'high' ? 'text-success' : 'text-warning' ?>">
                                <?= $p['conf'] === 'high' ? 'High confidence' : 'Low confidence' ?>
                            </p>
                        </td>
                        <td class="px-5 py-3 text-right">
                            <a href="patient.php?id=<?= (int)$p['patient_id'] ?>"
                               class="inline-flex h-8 items-center gap-1 rounded-md border border-input bg-white px-2.5 text-xs font-medium transition hover:bg-secondary/60">
                                Open <?= icon('arrow-right', 'h-3.5 w-3.5') ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div id="triage-empty" hidden class="border-t border-border"><?= ui_empty('search', 'No patients match these filters', 'Clear the search or choose another severity.') ?></div>
            <?php if (!$patients): ?>
                <div class="border-t border-border"><?= ui_empty('users', 'No patients assigned', 'Your administrator assigns patients to you.') ?></div>
            <?php endif; ?>
        </div>
    </section>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
