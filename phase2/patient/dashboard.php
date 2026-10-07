<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/readings.php';
require_role('patient');

$pdo = get_db_connection();
$patientId = (int)$_SESSION['patient_id'];

$stmt = $pdo->prepare('
    SELECT p.*, u.created_at AS registered_at, c.full_name AS doctor_name, c.specialization,
           t.target_fasting_min, t.target_fasting_max, t.target_postprandial_max, t.hypo_threshold,
           t.severe_hypo_threshold, t.critical_high_threshold
    FROM patients p
    JOIN users u ON u.user_id = p.user_id
    LEFT JOIN clinicians c ON c.clinician_id = p.clinician_id
    LEFT JOIN patient_thresholds t ON t.patient_id = p.patient_id
    WHERE p.patient_id = ?');
$stmt->execute([$patientId]);
$me = $stmt->fetch();

$stmt = $pdo->prepare('SELECT * FROM glucose_readings WHERE patient_id = ? ORDER BY logged_at DESC LIMIT 14');
$stmt->execute([$patientId]);
$recent = $stmt->fetchAll();
$latest = $recent[0] ?? null;

$adh7  = adherence_stats($pdo, $patientId, $me['registered_at'], 7);
$adh30 = adherence_stats($pdo, $patientId, $me['registered_at'], 30);
$gapHours = hours_since_last_reading($pdo, $patientId);

$page_title = 'My Dashboard';
$use_charts = true;
$page_scripts = $recent ? '<script src="' . BASE_URL . 'assets/js/glucose-chart.js"></script><script>'
    . 'glucoseChart("mini", ' . json_encode(chart_rows($recent, $me)) . ', {hypo: ' . (float)$me['hypo_threshold'] . ', post: ' . (float)$me['target_postprandial_max'] . '});</script>' : '';
require __DIR__ . '/../includes/header.php';
?>
<div class="card hero-card mb-4">
    <div class="card-body p-4 d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <h3 class="fw-bold mb-1">Hello, <?= e(explode(' ', $me['full_name'])[0]) ?> 👋</h3>
            <p class="mb-0 text-muted">
                <?php if ($me['doctor_name']): ?>
                    Your care doctor is <strong class="text-white"><?= e($me['doctor_name']) ?></strong> · <?= e($me['specialization']) ?>
                <?php else: ?>
                    The hospital has not assigned a doctor to you yet.
                <?php endif; ?>
            </p>
        </div>
        <a href="log_glucose.php" class="btn btn-light fw-semibold text-primary"><i class="bi bi-plus-circle-fill me-1"></i>Log a reading</a>
    </div>
</div>

<?php if ($gapHours === null || $gapHours >= MISSING_ALERT_HOURS): ?>
    <div class="alert alert-warning"><i class="bi bi-alarm-fill me-1"></i>
        <?= $gapHours === null ? 'You have not logged any readings yet.' : "It has been {$gapHours} hours since your last reading." ?>
        Regular readings help your doctor keep you safe.
    </div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card h-100"><div class="card-body">
            <div class="stat-label">Latest reading</div>
            <?php if ($latest):
                [$bt, $bc] = band_label(reading_band((float)$latest['glucose_value'], $latest['meal_context'], $me)); ?>
                <div class="stat-value"><?= e($latest['glucose_value'] + 0) ?> <span class="fs-6 text-muted fw-normal">mg/dL</span></div>
                <span class="chip <?= $bc ?>"><?= $bt ?></span>
                <span class="small text-muted ms-1"><?= e(MEAL_CONTEXTS[$latest['meal_context']]) ?> · <?= e(date('d M, H:i', strtotime($latest['logged_at']))) ?></span>
            <?php else: ?>
                <div class="text-muted py-2">Nothing logged yet.</div>
            <?php endif; ?>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="card h-100"><div class="card-body">
            <div class="stat-label">Logging consistency · 7 days</div>
            <div class="stat-value"><?= $adh7['pct'] + 0 ?>%</div>
            <div class="meter my-2"><span style="width:<?= $adh7['pct'] ?>%"></span></div>
            <div class="small text-muted"><?= $adh7['count'] ?> of <?= $adh7['expected'] ?> expected readings</div>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="card h-100"><div class="card-body">
            <div class="stat-label">Logging consistency · 30 days</div>
            <div class="stat-value"><?= $adh30['pct'] + 0 ?>%</div>
            <div class="meter my-2"><span style="width:<?= $adh30['pct'] ?>%"></span></div>
            <div class="small text-muted"><?= $adh30['count'] ?> of <?= $adh30['expected'] ?> expected · aim for <?= TARGET_READINGS_PER_DAY ?> a day</div>
        </div></div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between"><span><i class="bi bi-graph-up text-primary me-2"></i>Recent trend</span><a href="history.php" class="small">Full history</a></div>
            <div class="card-body">
                <?php if ($recent): ?><div style="height:280px"><canvas id="mini"></canvas></div>
                <?php else: ?><div class="empty-state"><i class="bi bi-graph-up"></i>Log your first reading to see your trend.</div><?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-bullseye text-primary me-2"></i>Your targets (mg/dL)</div>
            <div class="card-body">
                <div class="d-flex justify-content-between py-2 border-bottom"><span class="text-muted">Fasting</span><strong><?= (float)$me['target_fasting_min'] ?>–<?= (float)$me['target_fasting_max'] ?></strong></div>
                <div class="d-flex justify-content-between py-2 border-bottom"><span class="text-muted">After meals</span><strong>&lt; <?= (float)$me['target_postprandial_max'] ?></strong></div>
                <div class="d-flex justify-content-between py-2 border-bottom"><span class="text-muted">Low (hypo)</span><strong class="text-warning">&lt; <?= (float)$me['hypo_threshold'] ?></strong></div>
                <div class="d-flex justify-content-between py-2"><span class="text-muted">Severe low</span><strong class="text-danger">&lt; <?= (float)$me['severe_hypo_threshold'] ?></strong></div>
                <p class="small text-muted mt-3 mb-0">Set from American Diabetes Association guidance; your doctor can adjust them.</p>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
