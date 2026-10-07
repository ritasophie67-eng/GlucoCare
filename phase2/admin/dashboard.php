<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$pdo = get_db_connection();
$count = fn(string $sql) => (int)$pdo->query($sql)->fetchColumn();

$stats = [
    ['Accounts',            $count('SELECT COUNT(*) FROM users'),                                           'bi-people',         ''],
    ['Doctors',             $count('SELECT COUNT(*) FROM clinicians'),                                      'bi-person-badge',   ''],
    ['Patients',            $count('SELECT COUNT(*) FROM patients'),                                        'bi-heart-pulse',    'ok'],
    ['Glucose readings',    $count('SELECT COUNT(*) FROM glucose_readings'),                                'bi-droplet-half',   ''],
    ['Awaiting a doctor',   $count('SELECT COUNT(*) FROM patients WHERE clinician_id IS NULL'),             'bi-hourglass-split', 'warn'],
];

$logs = $pdo->query('SELECT a.*, u.username FROM audit_logs a LEFT JOIN users u ON u.user_id = a.user_id
                     ORDER BY a.log_id DESC LIMIT 8')->fetchAll();

$page_title = 'Hospital Administration';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
    <div><h2>Hospital administration</h2><p>Issue doctor accounts, assign patients, and monitor activity.</p></div>
    <div class="d-flex gap-2">
        <a class="btn btn-primary" href="manage_clinicians.php"><i class="bi bi-person-plus-fill me-1"></i>Add a doctor</a>
        <a class="btn btn-outline-primary" href="manage_patients.php"><i class="bi bi-diagram-3 me-1"></i>Assign patients</a>
    </div>
</div>

<div class="row g-3 mb-4">
    <?php foreach ($stats as [$label, $value, $icon, $tone]): ?>
        <div class="col-sm-6 col-xl">
            <div class="card"><div class="card-body stat">
                <div class="stat-icon <?= $tone ?>"><i class="bi <?= $icon ?>"></i></div>
                <div><div class="stat-label"><?= e($label) ?></div><div class="stat-value"><?= $value ?></div></div>
            </div></div>
        </div>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between"><span><i class="bi bi-journal-text text-primary me-2"></i>Recent activity</span><a href="audit_trail.php" class="small">View full trail</a></div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th class="ps-3">When</th><th>User</th><th>Action</th><th class="pe-3">Details</th></tr></thead>
            <tbody>
            <?php foreach ($logs as $l): ?>
                <tr>
                    <td class="ps-3 small text-nowrap"><?= e(date('d M, H:i', strtotime($l['created_at']))) ?></td>
                    <td><?= $l['username'] ? e($l['username']) : '<span class="text-muted">system</span>' ?></td>
                    <td><span class="chip"><?= e($l['action']) ?></span></td>
                    <td class="pe-3 small text-muted"><?= e($l['details']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
