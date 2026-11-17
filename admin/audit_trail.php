<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$pdo = get_db_connection();
$action = trim($_GET['action'] ?? '');
$table  = trim($_GET['table'] ?? '');

$orgId = current_org_id();
$where = ' WHERE (u.org_id = ? OR a.user_id IS NULL)';
$params = [$orgId];
if ($action !== '') { $where .= ' AND a.action = ?';       $params[] = $action; }
if ($table !== '')  { $where .= ' AND a.target_table = ?'; $params[] = $table; }

$countSql = 'SELECT COUNT(*) FROM audit_logs a LEFT JOIN users u ON u.user_id = a.user_id' . $where;
$stmt = $pdo->prepare($countSql);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

const AUDIT_PER_PAGE = 50;
$page = max(1, (int)($_GET['page'] ?? 1));
$pages = max(1, (int)ceil($total / AUDIT_PER_PAGE));
$page = min($page, $pages);

$sql = 'SELECT a.*, u.username, u.role FROM audit_logs a LEFT JOIN users u ON u.user_id = a.user_id' . $where
    . ' ORDER BY a.log_id DESC LIMIT ' . AUDIT_PER_PAGE . ' OFFSET ' . (($page - 1) * AUDIT_PER_PAGE);
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Full filtered trail as CSV — the audit log is the compliance artifact.
if (($_GET['export'] ?? '') === 'csv') {
    $exportParams = [$orgId];
    $exportWhere = ' WHERE (u.org_id = ? OR a.user_id IS NULL)';
    if ($action !== '') { $exportWhere .= ' AND a.action = ?';       $exportParams[] = $action; }
    if ($table !== '')  { $exportWhere .= ' AND a.target_table = ?'; $exportParams[] = $table; }
    $stmt = $pdo->prepare('SELECT a.*, u.username, u.role FROM audit_logs a LEFT JOIN users u ON u.user_id = a.user_id'
        . $exportWhere . ' ORDER BY a.log_id DESC');
    $stmt->execute($exportParams);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="glucocare-audit-' . date('Ymd') . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Entry', 'When', 'User', 'Role', 'Area', 'Action', 'Details']);
    foreach ($stmt as $row) {
        fputcsv($out, [$row['log_id'], $row['created_at'], (string)($row['username'] ?? 'system'),
            (string)($row['role'] ?? ''), $row['target_table'], $row['action'], $row['details']]);
    }
    exit;
}

$actions = $pdo->query('SELECT DISTINCT action FROM audit_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
$tables  = $pdo->query('SELECT DISTINCT target_table FROM audit_logs ORDER BY target_table')->fetchAll(PDO::FETCH_COLUMN);

$page_title = 'Audit Trail';
require __DIR__ . '/../includes/header.php';
?>
<div class="space-y-6">
    <?= ui_page_header('Audit trail', 'A timestamped record of sign-ins, assignments, record views and changes. Entries cannot be edited from the application.') ?>

    <section class="<?= UI_CARD ?>" aria-label="Audit filters">
        <div class="<?= UI_CARD_BODY ?>">
            <form method="get" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                <div class="flex-1 space-y-2">
                    <label for="filter-action" class="<?= UI_LABEL ?>">Action</label>
                    <select id="filter-action" name="action" class="<?= UI_INPUT ?>">
                        <option value="">All actions</option>
                        <?php foreach ($actions as $a): ?>
                            <option <?= $a === $action ? 'selected' : '' ?>><?= e($a) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex-1 space-y-2">
                    <label for="filter-table" class="<?= UI_LABEL ?>">Area</label>
                    <select id="filter-table" name="table" class="<?= UI_INPUT ?>">
                        <option value="">All areas</option>
                        <?php foreach ($tables as $t): ?>
                            <option <?= $t === $table ? 'selected' : '' ?>><?= e($t) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex items-center gap-2">
                    <?= ui_button('Filter', ['type' => 'submit', 'icon' => 'filter']) ?>
                    <?= ui_button('Reset', ['href' => 'audit_trail.php', 'variant' => 'outline']) ?>
                </div>
            </form>
        </div>
    </section>

    <section class="<?= UI_CARD ?>" aria-labelledby="audit-entries">
        <div class="<?= UI_CARD_HEADER ?>">
            <h2 id="audit-entries" class="<?= UI_CARD_TITLE ?>">Entries</h2>
            <div class="flex items-center gap-2">
                <span class="text-xs text-muted-foreground"><?= $total ?> matching entries</span>
                <a href="?<?= e(http_build_query(array_filter(['action' => $action, 'table' => $table, 'export' => 'csv']))) ?>"
                   class="inline-flex h-8 items-center gap-1.5 rounded-md border border-border bg-white px-2.5 text-xs font-medium hover:bg-muted">
                    <?= icon('clipboard-list', 'h-3.5 w-3.5') ?> Export CSV
                </a>
            </div>
        </div>
        <div class="overflow-x-auto scroll-area">
            <table class="w-full min-w-[820px] border-collapse text-sm">
                <caption class="sr-only">Filtered audit log entries.</caption>
                <thead>
                    <tr class="border-b border-border bg-muted/50 text-left text-[0.68rem] uppercase tracking-[0.07em] text-muted-foreground">
                        <th scope="col" class="px-5 py-2.5 font-semibold">#</th>
                        <th scope="col" class="px-3 py-2.5 font-semibold">When</th>
                        <th scope="col" class="px-3 py-2.5 font-semibold">User</th>
                        <th scope="col" class="px-3 py-2.5 font-semibold">Area</th>
                        <th scope="col" class="px-3 py-2.5 font-semibold">Action</th>
                        <th scope="col" class="px-5 py-2.5 font-semibold">Details</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                <?php foreach ($logs as $l): ?>
                    <tr class="hover:bg-muted/40">
                        <td class="px-5 py-2.5 text-xs tabular text-muted-foreground"><?= (int)$l['log_id'] ?></td>
                        <td class="whitespace-nowrap px-3 py-2.5 text-xs text-muted-foreground"><?= e(date('d M Y, H:i:s', strtotime($l['created_at']))) ?></td>
                        <td class="px-3 py-2.5">
                            <?php if ($l['username']): ?>
                                <span class="font-medium"><?= e($l['username']) ?></span>
                                <span class="ml-1"><?= ui_badge($l['role'], 'secondary') ?></span>
                            <?php else: ?>
                                <span class="text-muted-foreground">system</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-3 py-2.5"><code class="rounded bg-muted px-1.5 py-0.5 text-xs text-muted-foreground"><?= e($l['target_table']) ?></code></td>
                        <td class="px-3 py-2.5"><?= ui_badge($l['action'], in_array($l['action'], ['ACCESS_DENIED', 'LOGIN_FAILED'], true) ? 'destructive' : 'secondary') ?></td>
                        <td class="px-5 py-2.5 text-xs text-muted-foreground"><?= e($l['details']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$logs): ?>
                    <tr><td colspan="6"><?= ui_empty('clipboard-list', 'No matching entries', 'Adjust or reset the filters to widen the search.') ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?= ui_pager($page, AUDIT_PER_PAGE, $total, array_filter(['action' => $action, 'table' => $table])) ?>
    </section>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
