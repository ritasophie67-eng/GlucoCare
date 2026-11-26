<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/readings.php';
require_once __DIR__ . '/../includes/receptionist.php';   // unowned_referral_count()
require_once __DIR__ . '/../includes/cds_engine.php';
require_once __DIR__ . '/../includes/digest.php';
require_role('admin');

$pdo = get_db_connection();

// Manual controls run exactly the passes the scheduled jobs run
// (bin/cds_scan.php and bin/cds_digest.php), so results cannot drift from them.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['scan', 'digest_preview', 'digest_send', 'digest_clear'], true)) {
    $action = (string)$_POST['action'];

    if (!csrf_valid()) {
        set_flash('danger', 'Your session expired. Please try again.');
        redirect('admin/dashboard.php');
    }

    if ($action === 'digest_clear') {
        unset($_SESSION['digest_preview']);
    } elseif ($action === 'scan') {
        $ids = $pdo->query("SELECT p.patient_id FROM patients p JOIN users u ON u.user_id = p.user_id
                            WHERE u.is_active = 1 AND u.username NOT LIKE 'sim\\_%'")->fetchAll(PDO::FETCH_COLUMN);
        $totals = ['created' => 0, 'updated' => 0, 'resolved' => 0];
        foreach ($ids as $pid) {
            $r = cds_reassess_patient($pdo, (int)$pid);
            foreach ($totals as $k => $_) {
                $totals[$k] += $r[$k];
            }
        }
        $summary = sprintf('%d patients: %d new, %d refreshed, %d auto-resolved', count($ids), $totals['created'], $totals['updated'], $totals['resolved']);
        log_audit($pdo, (int)$_SESSION['user_id'], 'clinical_alerts', 'ENGINE_SCAN', 'Manual engine scan — ' . $summary . '.');
        meta_set($pdo, 'last_scan_at', date('Y-m-d H:i:s'));
        meta_set($pdo, 'last_scan_patients', (string)count($ids));
        set_flash('success', 'Engine scan complete — ' . $summary . '.');
    } else {
        $hours = digest_after_hours();
        $result = digest_send($pdo, $hours, $action === 'digest_preview');
        $summary = sprintf('%d clinician(s), %d alert(s): %d sent, %d failed, %d skipped',
            $result['clinicians'], $result['alerts'], $result['sent'], $result['failed'], $result['skipped']);

        if ($action === 'digest_preview') {
            $_SESSION['digest_preview'] = ['at' => time(), 'hours' => $hours, 'result' => $result];
            set_flash('info', 'Digest preview ready — ' . $summary . '. Nothing was sent.');
        } else {
            unset($_SESSION['digest_preview']);
            log_audit($pdo, (int)$_SESSION['user_id'], 'clinical_alerts', 'ALERT_DIGEST_MANUAL', 'Manual digest — ' . $summary . '.');
            set_flash($result['failed'] ? 'warning' : 'success', 'Digest complete — ' . $summary . '.');
        }
    }

    redirect('admin/dashboard.php');
}
$count = fn(string $sql) => (int)$pdo->query($sql)->fetchColumn();

$stats = [
    ['Accounts',            $count('SELECT COUNT(*) FROM users'),                                           'users',        'text-primary', 'bg-primary/10', 'All roles'],
    ['Doctors',             $count('SELECT COUNT(*) FROM clinicians'),                                      'stethoscope',  'text-success', 'bg-success/10', 'Credentialed accounts'],
    ['Patients',            $count("SELECT COUNT(*) FROM patients p JOIN users u ON u.user_id = p.user_id WHERE u.username NOT LIKE 'sim\\_%'"), 'heart-pulse', 'text-primary', 'bg-primary/10', 'Excluding simulated data'],
    ['Glucose readings',    $count('SELECT COUNT(*) FROM glucose_readings'),                                'droplet',      'text-primary', 'bg-primary/10', 'Across all patients'],
    ['Pending alerts (unassigned)', $count("SELECT COUNT(*) FROM clinical_alerts a JOIN patients p ON p.patient_id = a.patient_id WHERE a.status = 'pending' AND p.clinician_id IS NULL"), 'bell-off', 'text-destructive', 'bg-destructive/10', 'No doctor to review them'],
    ['Awaiting a doctor',   $count("SELECT COUNT(*) FROM patients p JOIN users u ON u.user_id = p.user_id WHERE p.clinician_id IS NULL AND u.username NOT LIKE 'sim\\_%'"), 'clock', 'text-warning', 'bg-warning/15', 'Unassigned patients'],
];

// A referral to a department for a patient with no doctor has no clinical owner, so it is
// counted here for routing (see admin/referrals.php).
$stats[] = ['Referrals needing a doctor', unowned_referral_count($pdo), 'arrow-right', 'text-destructive', 'bg-destructive/10', 'No clinician can see them'];

$logs = $pdo->query('SELECT a.*, u.username FROM audit_logs a LEFT JOIN users u ON u.user_id = a.user_id
                     ORDER BY a.log_id DESC LIMIT 8')->fetchAll();

// Automation health: the scheduled jobs are only useful if they actually run.
$lastScanAt = meta_get($pdo, 'last_scan_at');
$lastDigestAt = meta_get($pdo, 'last_digest_at');
$digestError = meta_get($pdo, 'last_digest_error');
$digestFailed = (int)meta_get($pdo, 'last_digest_failed', '0');
$staleAfterHours = max(1, (int)app_env('SCAN_STALE_HOURS', '26'));
$ageHours = fn(?string $ts): ?int => $ts ? (int)floor((time() - strtotime($ts)) / 3600) : null;
$scanAge = $ageHours($lastScanAt);
$digestAge = $ageHours($lastDigestAt);
$scanStale = $scanAge === null || $scanAge > $staleAfterHours;
$digestStale = $digestAge === null || $digestAge > $staleAfterHours;
$undelivered = $count('SELECT COUNT(*) FROM clinical_alerts WHERE digest_sent_at IS NULL AND digest_error IS NOT NULL');
$retryExhausted = $count('SELECT COUNT(*) FROM clinical_alerts WHERE digest_sent_at IS NULL AND digest_attempts >= ' . \DIGEST_MAX_ATTEMPTS);

$page_title = 'Administration';
require __DIR__ . '/../includes/header.php';
?>
<div class="space-y-6">
    <?= ui_page_header(
        'Platform administration',
        'Issue clinician accounts, assign patients to a named doctor, and monitor activity across every hospital on the platform.',
        ui_button('Actions', ['attrs' => 'data-dialog-target="admin-actions-dialog"', 'icon' => 'sliders'])
    ) ?>

    <?php if ($digestPreview = $_SESSION['digest_preview'] ?? null): ?>
        <section class="<?= UI_CARD ?>" aria-labelledby="digest-preview">
            <div class="<?= UI_CARD_HEADER ?>">
                <div>
                    <h2 id="digest-preview" class="<?= UI_CARD_TITLE ?>">Digest preview — nothing sent</h2>
                    <p class="mt-1 text-xs text-muted-foreground">
                        Critical alerts pending longer than <?= (int)$digestPreview['hours'] ?> h, checked <?= e(date('d M Y, H:i', (int)$digestPreview['at'])) ?>.
                        Same selection as the scheduled digest.
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="digest_send">
                        <?= ui_button('Send digest now', ['type' => 'submit', 'size' => 'sm', 'icon' => 'mail']) ?></form>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="digest_clear">
                        <?= ui_button('Close preview', ['type' => 'submit', 'size' => 'sm', 'variant' => 'ghost', 'icon' => 'x']) ?></form>
                </div>
            </div>

            <div class="px-5 py-4">
                <?php if (!$digestPreview['result']['messages']): ?>
                    <?= ui_empty('check-circle', 'Nothing to send', 'No critical alert has been waiting longer than the threshold, so no email would be sent.') ?>
                <?php else: ?>
                    <p class="text-[0.7rem] font-semibold uppercase tracking-[0.07em] text-muted-foreground">Would be emailed</p>
                    <ul class="mt-2 space-y-2 text-sm">
                        <?php foreach ($digestPreview['result']['messages'] as $line): ?>
                            <li class="flex items-start gap-2">
                                <?= icon('mail', 'mt-0.5 h-3.5 w-3.5 shrink-0 text-primary') ?>
                                <span><?= e(str_replace('[dry-run] ', '', $line)) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="mt-3 text-xs text-muted-foreground">
                        <?= (int)$digestPreview['result']['clinicians'] ?> clinician(s), <?= (int)$digestPreview['result']['alerts'] ?> alert(s) selected — nothing has been sent yet.
                    </p>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <?php foreach ($stats as [$label, $value, $iconName, $tone, $tile, $foot]): ?>
            <div class="<?= UI_CARD ?> px-5 py-4">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-[0.7rem] font-semibold uppercase tracking-[0.07em] text-muted-foreground"><?= e($label) ?></p>
                        <p class="mt-1 text-3xl font-bold leading-none tabular tracking-tight"><?= (int)$value ?></p>
                    </div>
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg <?= $tile ?> <?= $tone ?>"><?= icon($iconName, 'h-5 w-5') ?></span>
                </div>
                <p class="mt-3 text-xs text-muted-foreground"><?= e($foot) ?></p>
            </div>
        <?php endforeach; ?>
    </div>

    <section class="<?= UI_CARD ?>" aria-labelledby="automation-health">
        <div class="<?= UI_CARD_HEADER ?>">
            <div>
                <h2 id="automation-health" class="<?= UI_CARD_TITLE ?>">Automation health</h2>
                <p class="mt-1 text-xs text-muted-foreground">The alert scan and digest run from Task Scheduler; if they stop, nobody is told unless this panel says so.</p>
            </div>
            <?= ui_badge($scanStale || $digestStale ? 'Needs attention' : 'Running on schedule', $scanStale || $digestStale ? 'warning' : 'success') ?>
        </div>
        <div class="grid gap-px bg-border sm:grid-cols-2">
            <div class="bg-card px-5 py-4">
                <div class="flex items-center justify-between gap-2">
                    <p class="text-[0.7rem] font-semibold uppercase tracking-[0.07em] text-muted-foreground">Alert scan</p>
                    <?= ui_badge($scanStale ? 'Stale' : 'Current', $scanStale ? 'warning' : 'success') ?>
                </div>
                <p class="mt-1.5 text-sm font-medium"><?= $lastScanAt ? e(date('d M Y, H:i', strtotime($lastScanAt))) : 'Never recorded' ?></p>
                <p class="mt-1 text-xs text-muted-foreground"><?= $scanAge === null ? 'Run it now from Actions, or check the scheduled task.' : ($scanAge < 1 ? 'Less than an hour ago' : $scanAge . ' h ago') ?> · expected every <?= (int)$staleAfterHours ?> h</p>
            </div>
            <div class="bg-card px-5 py-4">
                <div class="flex items-center justify-between gap-2">
                    <p class="text-[0.7rem] font-semibold uppercase tracking-[0.07em] text-muted-foreground">Alert digest</p>
                    <?= ui_badge($digestStale ? 'Stale' : 'Current', $digestStale ? 'warning' : 'success') ?>
                </div>
                <p class="mt-1.5 text-sm font-medium"><?= $lastDigestAt ? e(date('d M Y, H:i', strtotime($lastDigestAt))) : 'Never recorded' ?></p>
                <p class="mt-1 text-xs text-muted-foreground"><?= $digestAge === null ? 'No digest run recorded yet.' : ($digestAge < 1 ? 'Less than an hour ago' : $digestAge . ' h ago') ?> · driver <?= e(mail_driver()) ?></p>
            </div>
        </div>
        <?php if ($digestFailed || $undelivered): ?>
            <div class="border-t border-border px-5 py-3">
                <?= ui_alert('warning', 'Some alerts could not be emailed',
                    ($digestError ? e($digestError) . '<br>' : '')
                    . $undelivered . ' alert(s) have a recorded delivery error; ' . $retryExhausted . ' have used all ' . \DIGEST_MAX_ATTEMPTS . ' retries. Use <strong>Send digest now</strong> after fixing the mail settings.',
                    'alert-triangle') ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="<?= UI_CARD ?>" aria-labelledby="recent-activity">
        <div class="<?= UI_CARD_HEADER ?>">
            <div>
                <h2 id="recent-activity" class="<?= UI_CARD_TITLE ?>">Recent activity</h2>
                <p class="mt-1 text-xs text-muted-foreground">Latest entries from the immutable audit trail.</p>
            </div>
            <a href="audit_trail.php" class="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline">
                Full trail <?= icon('chevron-right', 'h-3.5 w-3.5') ?>
            </a>
        </div>
        <div class="overflow-x-auto scroll-area">
            <table class="w-full min-w-[640px] border-collapse text-sm">
                <caption class="sr-only">Eight most recent audit entries.</caption>
                <thead>
                    <tr class="border-b border-border bg-muted/50 text-left text-[0.68rem] uppercase tracking-[0.07em] text-muted-foreground">
                        <th scope="col" class="px-5 py-2.5 font-semibold">When</th>
                        <th scope="col" class="px-3 py-2.5 font-semibold">User</th>
                        <th scope="col" class="px-3 py-2.5 font-semibold">Action</th>
                        <th scope="col" class="px-5 py-2.5 font-semibold">Details</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                <?php foreach ($logs as $l): ?>
                    <tr class="hover:bg-muted/40">
                        <td class="whitespace-nowrap px-5 py-2.5 text-xs text-muted-foreground"><?= e(date('d M, H:i', strtotime($l['created_at']))) ?></td>
                        <td class="px-3 py-2.5"><?= $l['username'] ? e($l['username']) : '<span class="text-muted-foreground">system</span>' ?></td>
                        <td class="px-3 py-2.5"><?= ui_badge($l['action'], in_array($l['action'], ['ACCESS_DENIED', 'LOGIN_FAILED'], true) ? 'destructive' : 'secondary') ?></td>
                        <td class="px-5 py-2.5 text-xs text-muted-foreground"><?= e($l['details']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$logs): ?>
                    <tr><td colspan="4"><?= ui_empty('clipboard-list', 'No activity yet', 'Sign-ins and record changes will appear here.') ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<?php
// One place for every administrative action, so the page header stays calm.
$adminRow = function (string $iconName, string $title, string $desc): string {
    return '<span class="mt-0.5 grid h-9 w-9 shrink-0 place-items-center rounded-full bg-primary/10 text-primary">' . icon($iconName, 'h-4 w-4') . '</span>'
        . '<span class="min-w-0 flex-1"><span class="block text-sm font-semibold text-foreground">' . e($title) . '</span>'
        . '<span class="mt-0.5 block text-xs leading-relaxed text-muted-foreground">' . e($desc) . '</span></span>';
};

$adminPostRow = function (string $action, string $iconName, string $title, string $desc) use ($adminRow): string {
    return '<form method="post"><input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">'
        . '<input type="hidden" name="action" value="' . e($action) . '">'
        . '<button type="submit" class="flex w-full items-start gap-3 px-6 py-4 text-left transition-colors hover:bg-muted/50 focus-visible:bg-muted/50 focus-visible:outline-none">'
        . $adminRow($iconName, $title, $desc)
        . '<span class="ml-auto mt-1">' . icon('chevron-right', 'h-4 w-4 text-muted-foreground') . '</span>'
        . '</button></form>';
};

$adminLinkRow = function (string $href, string $iconName, string $title, string $desc) use ($adminRow): string {
    return '<a href="' . e($href) . '" class="flex items-start gap-3 px-6 py-4 transition-colors hover:bg-muted/50">'
        . $adminRow($iconName, $title, $desc)
        . '<span class="ml-auto mt-1">' . icon('chevron-right', 'h-4 w-4 text-muted-foreground') . '</span></a>';
};

$adminActionsBody = '<div class="divide-y divide-border">'
    . $adminLinkRow('manage_clinicians.php', 'user-plus', 'Add a doctor', 'Issue credentials to a clinician and attach them to a hospital.')
    . $adminLinkRow('manage_patients.php', 'users', 'Assign patients', 'Move a patient under a named clinician.')
    . $adminLinkRow('referrals.php', 'arrow-right', 'Route referrals', 'Assign a doctor to referrals that no clinician can see.')
    . $adminPostRow('scan', 'refresh-cw', 'Run engine scan', 'Re-evaluate every patient and refresh open alerts.')
    . $adminPostRow('digest_preview', 'eye', 'Preview digest', 'See who would be emailed about overdue critical alerts.')
    . $adminPostRow('digest_send', 'mail', 'Send digest now', 'Email every clinician with overdue critical alerts.')
    . '</div>';

echo ui_dialog(
    'admin-actions-dialog',
    'Administration actions',
    'Manage accounts, run the alert engine, or send the digest on demand.',
    $adminActionsBody,
    ui_button('Close', ['variant' => 'outline', 'attrs' => 'data-dialog-close']),
    ['icon' => 'sliders', 'flush' => true]
);
?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
