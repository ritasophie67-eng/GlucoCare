<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/readings.php';
require_role('clinician');

$pdo = get_db_connection();
$clinicianId = (int)$_SESSION['clinician_id'];

$stmt = $pdo->prepare('SELECT * FROM clinicians WHERE clinician_id = ?');
$stmt->execute([$clinicianId]);
$doc = $stmt->fetch();

// Only patients assigned to THIS clinician.
$stmt = $pdo->prepare('
    SELECT p.patient_id, p.full_name, p.gender, p.date_of_birth, p.phone, u.created_at AS registered_at,
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

$lowConfidence = 0;
foreach ($patients as &$p) {
    $p['adh'] = adherence_stats($pdo, (int)$p['patient_id'], $p['registered_at'], 7);
    if (data_confidence($p['adh']['pct']) === 'low') {
        $lowConfidence++;
    }
}
unset($p);

$page_title = 'Triage Dashboard';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
    <div>
        <h2><?= e($doc['full_name']) ?></h2>
        <p><?= e($doc['specialization']) ?> · License <code><?= e($doc['license_no']) ?></code></p>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-4"><div class="card"><div class="card-body stat">
        <div class="stat-icon"><i class="bi bi-people-fill"></i></div>
        <div><div class="stat-label">My patients</div><div class="stat-value"><?= count($patients) ?></div></div>
    </div></div></div>
    <div class="col-sm-6 col-lg-4"><div class="card"><div class="card-body stat">
        <div class="stat-icon warn"><i class="bi bi-hourglass-split"></i></div>
        <div><div class="stat-label">Low data reliability (7 d)</div><div class="stat-value"><?= $lowConfidence ?></div></div>
    </div></div></div>
</div>

<div class="card">
    <div class="card-header"><i class="bi bi-person-lines-fill text-primary me-2"></i>My assigned patients</div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th class="ps-3">Patient</th><th>Gender / Age</th><th>Latest reading</th><th>Last logged</th><th>7-day adherence</th><th class="text-end pe-3"></th></tr></thead>
            <tbody>
            <?php if (!$patients): ?>
                <tr><td colspan="6"><div class="empty-state"><i class="bi bi-inbox"></i>No patients have been assigned to you yet.</div></td></tr>
            <?php endif; ?>
            <?php foreach ($patients as $p): ?>
                <tr>
                    <td class="ps-3 fw-semibold"><?= e($p['full_name']) ?></td>
                    <td class="text-capitalize"><?= e($p['gender']) ?>, <?= calc_age($p['date_of_birth']) ?> yrs</td>
                    <td>
                        <?php if ($p['latest_value'] !== null):
                            [$bt, $bc] = band_label(reading_band((float)$p['latest_value'], $p['latest_context'], $p)); ?>
                            <strong><?= e($p['latest_value'] + 0) ?></strong> <span class="chip <?= $bc ?>"><?= $bt ?></span>
                        <?php else: ?><span class="text-muted small">No readings</span><?php endif; ?>
                    </td>
                    <td class="small text-muted"><?= $p['last_at'] ? e(date('d M, H:i', strtotime($p['last_at']))) : '—' ?></td>
                    <td style="min-width:150px">
                        <div class="d-flex align-items-center gap-2">
                            <div class="meter flex-grow-1"><span style="width:<?= $p['adh']['pct'] ?>%"></span></div>
                            <span class="small fw-semibold"><?= $p['adh']['pct'] + 0 ?>%</span>
                        </div>
                    </td>
                    <td class="text-end pe-3"><a class="btn btn-sm btn-outline-primary" href="patient.php?id=<?= (int)$p['patient_id'] ?>">Open</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
