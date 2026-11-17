<?php
// Alerts queue. Only alerts belonging to THIS clinician's assigned patients are ever listed or editable.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/cds_engine.php';
require_once __DIR__ . '/../includes/sms.php';
require_role('clinician');

$pdo = get_db_connection();
$clinicianId = (int)$_SESSION['clinician_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $alertId = (int)($_POST['alert_id'] ?? 0);
    $to = $_POST['status'] ?? '';
    $note = mb_substr(trim($_POST['note'] ?? ''), 0, 255);

    // The JOIN on patients.clinician_id enforces ownership.
    $stmt = $pdo->prepare('SELECT a.alert_id, a.alert_type, a.status, p.patient_id, p.full_name
                           FROM clinical_alerts a JOIN patients p ON p.patient_id = a.patient_id
                           WHERE a.alert_id = ? AND p.clinician_id = ?');
    $stmt->execute([$alertId, $clinicianId]);
    $alert = $stmt->fetch();

    if (!csrf_valid()) {
        set_flash('danger', 'Your session expired. Please try again.');
    } elseif (!$alert || !in_array($to, ['reviewed', 'resolved'], true)) {
        log_audit($pdo, (int)$_SESSION['user_id'], 'clinical_alerts', 'ACCESS_DENIED', "Clinician {$_SESSION['username']} tried to update alert #{$alertId}.");
        set_flash('danger', 'That alert is not on your list.');
    } else {
        $pdo->prepare("UPDATE clinical_alerts SET status = ?, clinician_note = ?, clinician_id = ?,
                       reviewed_at = COALESCE(reviewed_at, NOW()),
                       resolved_at = CASE WHEN ? = 'resolved' THEN NOW() ELSE NULL END WHERE alert_id = ?")
            ->execute([$to, $note ?: null, $clinicianId, $to, $alertId]);
        log_audit($pdo, (int)$_SESSION['user_id'], 'clinical_alerts', 'ALERT_' . strtoupper($to),
            "Alert #{$alertId} ({$alert['alert_type']}) for patient #{$alert['patient_id']} marked {$to}.");

        // Close the loop with the patient: one short, non-clinical SMS when an alert 
        // is resolved for the first time. Delivery is best-effort and never blocks the update.
        $notified = '';
        if ($to === 'resolved') {
            $stmt = $pdo->prepare('SELECT p.full_name, p.phone, a.patient_notified_at
                                   FROM clinical_alerts a JOIN patients p ON p.patient_id = a.patient_id
                                   WHERE a.alert_id = ?');
            $stmt->execute([$alertId]);
            $pp = $stmt->fetch();
            if ($pp && !$pp['patient_notified_at'] && $pp['phone'] !== 'N/A') {
                $sms = sms_send($pp['phone'], 'GlucoCare: your care team has reviewed a recent glucose alert. Keep logging your readings, and contact your clinic if you feel unwell.');
                if ($sms['ok']) {
                    $pdo->prepare('UPDATE clinical_alerts SET patient_notified_at = NOW() WHERE alert_id = ?')->execute([$alertId]);
                    log_audit($pdo, (int)$_SESSION['user_id'], 'clinical_alerts', 'PATIENT_NOTIFIED',
                        "SMS sent to patient #{$alert['patient_id']} about alert #{$alertId} ({$sms['driver']}).");
                    $notified = ' The patient was texted.';
                } else {
                    $notified = ' Patient SMS failed: ' . $sms['error'] . '.';
                }
            }
        }
        set_flash($notified && str_contains($notified, 'failed') ? 'warning' : 'success', "Alert marked {$to}." . $notified);
    }
    redirect('clinician/alerts.php?' . http_build_query(['status' => $_POST['return_status'] ?? 'pending']));
}

$status = $_GET['status'] ?? 'pending';
if (!in_array($status, ['pending', 'reviewed', 'resolved', 'all'], true)) {
    $status = 'pending';
}

$sql = "SELECT a.*, p.full_name FROM clinical_alerts a JOIN patients p ON p.patient_id = a.patient_id WHERE p.clinician_id = ?";
$params = [$clinicianId];
if ($status !== 'all') {
    $sql .= ' AND a.status = ?';
    $params[] = $status;
}
$countSql = 'SELECT COUNT(*) FROM clinical_alerts a JOIN patients p ON p.patient_id = a.patient_id WHERE p.clinician_id = ?';
$countParams = [$clinicianId];
if ($status !== 'all') {
    $countSql .= ' AND a.status = ?';
    $countParams[] = $status;
}
$stmt = $pdo->prepare($countSql);
$stmt->execute($countParams);
$total = (int)$stmt->fetchColumn();

const ALERTS_PER_PAGE = 25;
$page = max(1, (int)($_GET['page'] ?? 1));
$pages = max(1, (int)ceil($total / ALERTS_PER_PAGE));
$page = min($page, $pages);

$sql .= " ORDER BY CASE a.risk_level WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'moderate' THEN 3 ELSE 4 END,
          (a.data_confidence = 'high') DESC, a.created_at DESC
          LIMIT " . ALERTS_PER_PAGE . ' OFFSET ' . (($page - 1) * ALERTS_PER_PAGE);
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$alerts = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT a.status, COUNT(*) FROM clinical_alerts a JOIN patients p ON p.patient_id = a.patient_id WHERE p.clinician_id = ? GROUP BY a.status");
$stmt->execute([$clinicianId]);
$counts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// CSV export of the same filtered list, for case review or reporting.
if (($_GET['export'] ?? '') === 'csv') {
    $exportSql = "SELECT a.*, p.full_name FROM clinical_alerts a JOIN patients p ON p.patient_id = a.patient_id WHERE p.clinician_id = ?";
    $exportParams = [$clinicianId];
    if ($status !== 'all') {
        $exportSql .= ' AND a.status = ?';
        $exportParams[] = $status;
    }
    $exportSql .= " ORDER BY CASE a.risk_level WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'moderate' THEN 3 ELSE 4 END, a.created_at DESC";
    $stmt = $pdo->prepare($exportSql);
    $stmt->execute($exportParams);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="glucocare-alerts-' . date('Ymd') . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Alert', 'Patient', 'Type', 'Risk', 'Data confidence', 'Status', 'Adherence %', 'Created', 'Reviewed', 'Resolved', 'Note']);
    foreach ($stmt as $row) {
        fputcsv($out, [
            $row['alert_id'], $row['full_name'], ALERT_TYPE_LABELS[$row['alert_type']] ?? $row['alert_type'],
            $row['risk_level'], $row['data_confidence'], $row['status'], $row['adherence_pct'],
            $row['created_at'], (string)($row['reviewed_at'] ?? ''), (string)($row['resolved_at'] ?? ''),
            (string)($row['clinician_note'] ?? ''),
        ]);
    }
    exit;
}

$page_title = 'Alerts Queue';
require __DIR__ . '/../includes/header.php';
?>
<div class="space-y-6">
    <?= ui_page_header(
        'Alerts queue',
        'Every automatic flag for your assigned patients, with the exact rule condition that fired and the confidence in the underlying data. Decisions stay with you.',
        ui_button('Back to triage', ['href' => 'dashboard.php', 'variant' => 'outline', 'icon' => 'arrow-left'])
    ) ?>

    <!-- Status tabs -->
    <div class="flex flex-wrap items-center gap-2" role="tablist" aria-label="Alert status">
        <div class="inline-flex items-center gap-1 rounded-lg border border-border bg-muted/60 p-1">
            <?php foreach (['pending' => 'Pending', 'reviewed' => 'Reviewed', 'resolved' => 'Resolved', 'all' => 'All'] as $k => $label): ?>
                <a href="?status=<?= $k ?>" role="tab" aria-selected="<?= $k === $status ? 'true' : 'false' ?>"
                   class="inline-flex h-8 items-center gap-1.5 rounded-md px-3 text-xs font-medium transition-colors <?= $k === $status ? 'bg-white text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground' ?>">
                    <?= e($label) ?>
                    <?php if ($k !== 'all'): ?>
                        <span class="rounded-full bg-slate-900/5 px-1.5 py-0.5 text-[0.65rem] font-semibold tabular"><?= (int)($counts[$k] ?? 0) ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
        <p class="text-xs text-muted-foreground">Sorted by severity, then data confidence.</p>
        <?php if ($alerts): ?>
            <a href="?export=csv&amp;status=<?= e($status) ?>" class="ml-auto inline-flex h-8 items-center gap-1.5 rounded-md border border-border bg-white px-2.5 text-xs font-medium text-foreground hover:bg-muted">
                <?= icon('clipboard-list', 'h-3.5 w-3.5') ?> Export CSV
            </a>
        <?php endif; ?>
    </div>

    <?php if (!$alerts): ?>
        <div class="rounded-lg border border-border bg-card">
            <?= ui_empty('check-circle', 'No ' . ($status === 'all' ? '' : $status . ' ') . 'alerts', 'The rule engine re-evaluates your patients on every new reading and on each scheduled scan; pattern alerts resolve themselves once the pattern stops.') ?>
        </div>
    <?php endif; ?>

    <div class="space-y-3">
        <?php foreach ($alerts as $a):
            $critical = $a['risk_level'] === 'critical';
            $confLabel = $a['data_confidence'] === 'high' ? 'High Data Confidence' : 'Low Data Confidence';
            $rationale = preg_replace('/^Flagged as [^:]+:\s*/', '', $a['alert_message']);
            $adherence = rtrim(rtrim(number_format((float)$a['adherence_pct'], 1), '0'), '.') . '%'; ?>

            <article class="<?= UI_CARD ?> border-l-4 <?= $critical ? 'border-l-destructive' : 'border-l-warning' ?>" aria-labelledby="alert-<?= (int)$a['alert_id'] ?>">
                <div class="px-5 py-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0 space-y-1.5">
                            <div class="flex flex-wrap items-center gap-2">
                                <?= ui_badge($critical ? 'Critical Risk' : 'Warning Risk', $critical ? 'destructive' : 'warning') ?>
                                <?= ui_badge($confLabel, $a['data_confidence'] === 'high' ? 'success' : 'warning') ?>
                                <?= ui_badge(ALERT_TYPE_LABELS[$a['alert_type']] ?? $a['alert_type'], 'secondary') ?>
                                <?php if ($a['status'] !== 'pending'): ?>
                                    <?= ui_badge(ucfirst($a['status']), 'outline') ?>
                                <?php endif; ?>
                                <?php if (str_starts_with((string)$a['clinician_note'], 'Auto-resolved')): ?>
                                    <?= ui_badge('Auto-resolved', 'secondary') ?>
                                <?php endif; ?>
                            </div>
                            <h2 id="alert-<?= (int)$a['alert_id'] ?>" class="text-base font-semibold leading-6">
                                <a href="patient.php?id=<?= (int)$a['patient_id'] ?>" class="inline-flex items-center gap-1 hover:text-primary hover:underline">
                                    <?= e($a['full_name']) ?> <?= icon('chevron-right', 'h-3.5 w-3.5 text-muted-foreground') ?>
                                </a>
                            </h2>
                        </div>
                        <div class="text-right text-[0.7rem] leading-relaxed text-muted-foreground">
                            <p><?= e(date('d M Y, H:i', strtotime($a['created_at']))) ?></p>
                            <p class="font-medium text-foreground">Adherence <?= e($adherence) ?></p>
                            <?php if ($a['digest_sent_at']): ?>
                                <p class="text-[0.65rem]">Digest emailed <?= e(date('d M H:i', strtotime($a['digest_sent_at']))) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="mt-3 rounded-md border border-border bg-muted/40 px-3.5 py-3">
                        <p class="mb-1 flex items-center gap-1.5 text-[0.65rem] font-semibold uppercase tracking-[0.08em] text-muted-foreground">
                            <?= icon('sparkles', 'h-3 w-3') ?> Why this fired
                        </p>
                        <p class="text-[0.83rem] leading-relaxed text-foreground/85"><?= e($rationale) ?></p>
                    </div>

                    <?php if ($a['clinician_note']): ?>
                        <p class="mt-3 flex items-start gap-1.5 text-xs text-muted-foreground">
                            <?= icon('clipboard-list', 'mt-0.5 h-3.5 w-3.5 shrink-0') ?><span><strong class="font-semibold text-foreground">Your note:</strong> <?= e($a['clinician_note']) ?></span>
                        </p>
                    <?php endif; ?>

                    <?php if ($a['status'] !== 'resolved'): ?>
                        <form method="post" class="mt-4 flex flex-col gap-2 border-t border-border pt-3 sm:flex-row sm:items-center">
                            <?= csrf_field() ?>
                            <input type="hidden" name="alert_id" value="<?= (int)$a['alert_id'] ?>">
                            <input type="hidden" name="return_status" value="<?= e($status) ?>">
                            <label for="note-<?= (int)$a['alert_id'] ?>" class="sr-only">Review note for <?= e($a['full_name']) ?></label>
                            <input id="note-<?= (int)$a['alert_id'] ?>" name="note" maxlength="255" placeholder="Optional note for the record"
                                   class="h-9 flex-1 rounded-md border border-input bg-white px-3 text-sm shadow-sm placeholder:text-muted-foreground/70 focus-visible:border-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/30">
                            <div class="flex items-center gap-2">
                                <?php if ($a['status'] === 'pending'): ?>
                                    <?= ui_button('Mark reviewed', ['type' => 'submit', 'name' => 'status', 'value' => 'reviewed', 'variant' => 'outline', 'size' => 'sm']) ?>
                                <?php endif; ?>
                                <?= ui_button('Resolve', ['type' => 'submit', 'name' => 'status', 'value' => 'resolved', 'size' => 'sm', 'icon' => 'check']) ?>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <?php if ($total > ALERTS_PER_PAGE): ?>
        <div class="rounded-lg border border-border bg-card">
            <?= ui_pager($page, ALERTS_PER_PAGE, $total, ['status' => $status]) ?>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
