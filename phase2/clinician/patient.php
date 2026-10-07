<?php
// Patient record. A clinician can open it ONLY if the patient is assigned to them.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/readings.php';
require_role('clinician');

$pdo = get_db_connection();
$patientId = (int)($_GET['id'] ?? 0);
$patient = require_assigned_patient($pdo, $patientId);

$stmt = $pdo->prepare('SELECT * FROM patient_thresholds WHERE patient_id = ?');
$stmt->execute([$patientId]);
$t = $stmt->fetch();

$stmt = $pdo->prepare('SELECT created_at FROM users WHERE user_id = ?');
$stmt->execute([$patient['user_id']]);
$registeredAt = $stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT * FROM glucose_readings WHERE patient_id = ? AND logged_at >= (NOW() - INTERVAL 30 DAY) ORDER BY logged_at DESC');
$stmt->execute([$patientId]);
$readings = $stmt->fetchAll();

$adh7  = adherence_stats($pdo, $patientId, $registeredAt, 7);
$adh30 = adherence_stats($pdo, $patientId, $registeredAt, 30);
$conf  = data_confidence($adh7['pct']);
$gap   = hours_since_last_reading($pdo, $patientId);

log_audit($pdo, (int)$_SESSION['user_id'], 'patients', 'PATIENT_VIEW', "Viewed record of patient #{$patientId} ({$patient['full_name']}).");

$page_title = $patient['full_name'];
$use_charts = true;
$page_scripts = $readings ? '<script src="' . BASE_URL . 'assets/js/glucose-chart.js"></script><script>'
    . 'glucoseChart("trend", ' . json_encode(chart_rows($readings, $t)) . ', {hypo: ' . (float)$t['hypo_threshold'] . ', post: ' . (float)$t['target_postprandial_max'] . '});</script>' : '';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
    <div>
        <h2><?= e($patient['full_name']) ?></h2>
        <p class="text-capitalize"><?= e($patient['gender']) ?>, <?= calc_age($patient['date_of_birth']) ?> years · <?= e($patient['phone']) ?> · Patient #<?= $patientId ?></p>
    </div>
    <a href="dashboard.php" class="btn btn-outline-primary"><i class="bi bi-arrow-left me-1"></i>Back to my patients</a>
</div>

<?php if ($gap === null || $gap >= MISSING_ALERT_HOURS): ?>
    <div class="alert alert-warning"><i class="bi bi-alarm-fill me-1"></i>
        <?= $gap === null ? 'This patient has not logged any readings.' : "No reading for {$gap} hours." ?>
    </div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-4"><div class="card h-100"><div class="card-body">
        <div class="stat-label">Data reliability</div>
        <div class="stat-value"><?= $adh7['pct'] + 0 ?>%</div>
        <span class="chip <?= $conf === 'high' ? 'chip-ok' : 'chip-warn' ?>"><?= $conf === 'high' ? 'High' : 'Low' ?> data confidence</span>
        <div class="small text-muted mt-2">7-day: <?= $adh7['count'] ?> of <?= $adh7['expected'] ?> expected readings</div>
    </div></div></div>
    <div class="col-md-4"><div class="card h-100"><div class="card-body">
        <div class="stat-label">30-day adherence</div>
        <div class="stat-value"><?= $adh30['pct'] + 0 ?>%</div>
        <div class="meter my-2"><span style="width:<?= $adh30['pct'] ?>%"></span></div>
        <div class="small text-muted"><?= $adh30['days_logged'] ?> days with readings out of <?= $adh30['days'] ?></div>
    </div></div></div>
    <div class="col-md-4"><div class="card h-100"><div class="card-body">
        <div class="stat-label">Target ranges (mg/dL)</div>
        <div class="small mt-2">
            <div class="d-flex justify-content-between"><span class="text-muted">Fasting</span><strong><?= (float)$t['target_fasting_min'] ?>–<?= (float)$t['target_fasting_max'] ?></strong></div>
            <div class="d-flex justify-content-between"><span class="text-muted">Post-meal</span><strong>&lt; <?= (float)$t['target_postprandial_max'] ?></strong></div>
            <div class="d-flex justify-content-between"><span class="text-muted">Hypo / severe</span><strong>&lt; <?= (float)$t['hypo_threshold'] ?> / &lt; <?= (float)$t['severe_hypo_threshold'] ?></strong></div>
        </div>
    </div></div></div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-graph-up text-primary me-2"></i>30-day glucose trend</div>
    <div class="card-body">
        <?php if ($readings): ?><div style="height:320px"><canvas id="trend"></canvas></div>
        <?php else: ?><div class="empty-state"><i class="bi bi-graph-up"></i>No readings in the last 30 days.</div><?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header"><i class="bi bi-table text-primary me-2"></i>Readings (last 30 days)</div>
    <div class="table-responsive" style="max-height:420px">
        <table class="table table-hover mb-0">
            <thead><tr><th class="ps-3">Date &amp; time</th><th>Value</th><th>Context</th><th>Status</th><th class="pe-3">Symptoms</th></tr></thead>
            <tbody>
            <?php foreach ($readings as $r):
                [$bt, $bc] = band_label(reading_band((float)$r['glucose_value'], $r['meal_context'], $t)); ?>
                <tr>
                    <td class="ps-3 text-nowrap"><?= e(date('d M Y, H:i', strtotime($r['logged_at']))) ?></td>
                    <td class="fw-bold"><?= e($r['glucose_value'] + 0) ?></td>
                    <td><?= e(MEAL_CONTEXTS[$r['meal_context']]) ?></td>
                    <td><span class="chip <?= $bc ?>"><?= $bt ?></span><?= $r['was_confirmed'] ? ' <span class="chip chip-muted" title="Patient confirmed an extreme value">confirmed</span>' : '' ?></td>
                    <td class="pe-3 small text-muted"><?= e($r['symptoms'] ?? '—') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$readings): ?><tr><td colspan="5"><div class="empty-state"><i class="bi bi-inbox"></i>No readings yet.</div></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
