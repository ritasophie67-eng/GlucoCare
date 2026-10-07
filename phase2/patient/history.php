<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/readings.php';
require_role('patient');

$pdo = get_db_connection();
$patientId = (int)$_SESSION['patient_id'];

$days = (int)($_GET['days'] ?? 14);
if (!in_array($days, [7, 14, 30, 90], true)) {
    $days = 14;
}

$stmt = $pdo->prepare('SELECT * FROM patient_thresholds WHERE patient_id = ?');
$stmt->execute([$patientId]);
$t = $stmt->fetch();

$stmt = $pdo->prepare('SELECT * FROM glucose_readings WHERE patient_id = ? AND logged_at >= (NOW() - INTERVAL ? DAY) ORDER BY logged_at DESC');
$stmt->execute([$patientId, $days]);
$readings = $stmt->fetchAll();

$values = array_map(fn($r) => (float)$r['glucose_value'], $readings);

$page_title = 'Glucose History';
$use_charts = true;
$page_scripts = '<script src="' . BASE_URL . 'assets/js/glucose-chart.js"></script><script>'
    . 'glucoseChart("trend", ' . json_encode(chart_rows($readings, $t)) . ', {hypo: ' . (float)$t['hypo_threshold'] . ', post: ' . (float)$t['target_postprandial_max'] . '});</script>';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
    <div><h2>Glucose history</h2><p>Your readings and trend over time.</p></div>
    <div class="d-flex gap-2">
        <div class="btn-group">
            <?php foreach ([7, 14, 30, 90] as $d): ?>
                <a href="?days=<?= $d ?>" class="btn btn-sm <?= $d === $days ? 'btn-primary' : 'btn-outline-primary' ?>"><?= $d ?> days</a>
            <?php endforeach; ?>
        </div>
        <a href="log_glucose.php" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>New reading</a>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><div class="card"><div class="card-body"><div class="stat-label">Readings</div><div class="stat-value"><?= count($readings) ?></div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card"><div class="card-body"><div class="stat-label">Average</div><div class="stat-value"><?= $values ? round(array_sum($values) / count($values)) : '—' ?></div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card"><div class="card-body"><div class="stat-label">Lowest</div><div class="stat-value"><?= $values ? min($values) : '—' ?></div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card"><div class="card-body"><div class="stat-label">Highest</div><div class="stat-value"><?= $values ? max($values) : '—' ?></div></div></div></div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-graph-up text-primary me-2"></i>Trend (last <?= $days ?> days)</div>
    <div class="card-body">
        <?php if ($readings): ?>
            <div style="height:320px"><canvas id="trend"></canvas></div>
        <?php else: ?>
            <div class="empty-state"><i class="bi bi-graph-up"></i>No readings in this period yet.</div>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th class="ps-3">Date &amp; time</th><th>Value</th><th>Context</th><th>Status</th><th class="pe-3">Symptoms</th></tr></thead>
            <tbody>
            <?php foreach ($readings as $r):
                [$bandText, $bandClass] = band_label(reading_band((float)$r['glucose_value'], $r['meal_context'], $t)); ?>
                <tr>
                    <td class="ps-3 text-nowrap"><?= e(date('d M Y, H:i', strtotime($r['logged_at']))) ?></td>
                    <td class="fw-bold"><?= e($r['glucose_value'] + 0) ?> <span class="small text-muted fw-normal">mg/dL</span></td>
                    <td><?= e(MEAL_CONTEXTS[$r['meal_context']]) ?></td>
                    <td><span class="chip <?= $bandClass ?>"><?= $bandText ?></span></td>
                    <td class="pe-3 small text-muted"><?= e($r['symptoms'] ?? '—') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$readings): ?><tr><td colspan="5"><div class="empty-state"><i class="bi bi-inbox"></i>Nothing logged in this period.</div></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
