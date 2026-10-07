<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$pdo = get_db_connection();
$action = trim($_GET['action'] ?? '');
$table  = trim($_GET['table'] ?? '');

$sql = 'SELECT a.*, u.username, u.role FROM audit_logs a LEFT JOIN users u ON u.user_id = a.user_id WHERE 1=1';
$params = [];
if ($action !== '') { $sql .= ' AND a.action = ?';       $params[] = $action; }
if ($table !== '')  { $sql .= ' AND a.target_table = ?'; $params[] = $table; }
$sql .= ' ORDER BY a.log_id DESC LIMIT 200';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

$actions = $pdo->query('SELECT DISTINCT action FROM audit_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
$tables  = $pdo->query('SELECT DISTINCT target_table FROM audit_logs ORDER BY target_table')->fetchAll(PDO::FETCH_COLUMN);

$page_title = 'Audit Trail';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
    <div><h2>Audit trail</h2><p>A timestamped record of sign-ins, assignments, record views and changes. Entries cannot be edited from the application.</p></div>
</div>

<div class="card mb-4"><div class="card-body">
    <form class="row g-2" method="get">
        <div class="col-md-4"><select name="action" class="form-select"><option value="">All actions</option>
            <?php foreach ($actions as $a): ?><option <?= $a === $action ? 'selected' : '' ?>><?= e($a) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><select name="table" class="form-select"><option value="">All areas</option>
            <?php foreach ($tables as $t): ?><option <?= $t === $table ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4 d-flex gap-2"><button class="btn btn-primary flex-grow-1">Filter</button><a class="btn btn-outline-primary" href="audit_trail.php">Reset</a></div>
    </form>
</div></div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th class="ps-3">#</th><th>When</th><th>User</th><th>Area</th><th>Action</th><th class="pe-3">Details</th></tr></thead>
            <tbody>
            <?php if (!$logs): ?><tr><td colspan="6"><div class="empty-state"><i class="bi bi-journal"></i>No matching entries.</div></td></tr><?php endif; ?>
            <?php foreach ($logs as $l): ?>
                <tr>
                    <td class="ps-3 text-muted small"><?= (int)$l['log_id'] ?></td>
                    <td class="small text-nowrap"><?= e(date('d M Y, H:i:s', strtotime($l['created_at']))) ?></td>
                    <td><?= $l['username'] ? e($l['username']) . ' <span class="role-pill">' . e($l['role']) . '</span>' : '<span class="text-muted">system</span>' ?></td>
                    <td><code><?= e($l['target_table']) ?></code></td>
                    <td><span class="chip <?= in_array($l['action'], ['ACCESS_DENIED', 'LOGIN_FAILED'], true) ? 'chip-danger' : '' ?>"><?= e($l['action']) ?></span></td>
                    <td class="pe-3 small text-muted"><?= e($l['details']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
