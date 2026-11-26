<?php
// Front-desk accounts. The desk handles identity and contact data only, so these accounts
// are issued the same way doctor accounts are: verified by an administrator, one-time
// password, must be changed at first sign-in.
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$pdo = get_db_connection();
$adminId = (int)$_SESSION['user_id'];
$error = '';
$f = ['full_name' => '', 'desk' => 'Main reception', 'username' => '', 'email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        $error = 'Your session expired. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create') {
            foreach ($f as $k => $_) {
                $f[$k] = trim((string)($_POST[$k] ?? ''));
            }
            if (in_array('', [$f['full_name'], $f['username'], $f['email']], true)) {
                $error = 'Name, username and email are required to issue a front-desk account.';
            } elseif (!preg_match('/^[A-Za-z0-9_.-]{3,30}$/', $f['username'])) {
                $error = 'Username must be 3-30 characters: letters, numbers, dot, dash or underscore.';
            } elseif (!filter_var($f['email'], FILTER_VALIDATE_EMAIL)) {
                $error = 'Enter a valid email address.';
            } else {
                $dupe = $pdo->prepare('SELECT 1 FROM users WHERE username = ? OR email = ?');
                $dupe->execute([$f['username'], $f['email']]);
                if ($dupe->fetch()) {
                    $error = 'That username or email is already in use.';
                } else {
                    $temp = generate_temp_password();
                    try {
                        $pdo->beginTransaction();
                        // The role CHECK constraint allows 'receptionist'; see schema.postgres.sql.
                        $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role, email, must_change_password, org_id)
                                               VALUES (?, ?, 'receptionist', ?, 1, ?) RETURNING user_id");
                        $stmt->execute([$f['username'], password_hash($temp, PASSWORD_DEFAULT), $f['email'], current_org_id()]);
                        $uid = (int)$stmt->fetchColumn();
                        $pdo->prepare('INSERT INTO receptionists (user_id, full_name, desk) VALUES (?, ?, ?)')
                            ->execute([$uid, $f['full_name'], $f['desk'] ?: null]);
                        log_audit($pdo, $adminId, 'receptionists', 'PROVISION',
                            "Admin created front-desk account {$f['username']} ({$f['full_name']}).");
                        $pdo->commit();
                        set_flash('success', "Front-desk account created. Username: {$f['username']} · Temporary password: {$temp} — give it to reception now; it is shown only once and must be changed at first sign-in.");
                        redirect('admin/manage_receptionists.php');
                    } catch (PDOException $ex) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        error_log('Desk account provisioning failed: ' . $ex->getMessage());
                        $error = 'Could not create the account. Please try again.';
                    }
                }
            }
        } elseif (in_array($action, ['toggle', 'reset'], true)) {
            $rid = (int)($_POST['receptionist_id'] ?? 0);
            $stmt = $pdo->prepare('SELECT r.full_name, u.user_id, u.username, u.is_active
                                   FROM receptionists r JOIN users u ON u.user_id = r.user_id
                                   WHERE r.receptionist_id = ?');
            $stmt->execute([$rid]);
            $deskUser = $stmt->fetch();
            if (!$deskUser) {
                $error = 'Front-desk account not found.';
            } elseif ($action === 'toggle') {
                $new = $deskUser['is_active'] ? 0 : 1;
                $pdo->prepare('UPDATE users SET is_active = ? WHERE user_id = ?')->execute([$new, $deskUser['user_id']]);
                log_audit($pdo, $adminId, 'receptionists', $new ? 'ACTIVATE' : 'DEACTIVATE',
                    "Admin " . ($new ? 'reactivated' : 'deactivated') . " front-desk account {$deskUser['username']}.");
                set_flash('success', "{$deskUser['full_name']} " . ($new ? 'can sign in again.' : 'can no longer sign in.'));
                redirect('admin/manage_receptionists.php');
            } else {
                $temp = generate_temp_password();
                $pdo->prepare('UPDATE users SET password_hash = ?, must_change_password = 1 WHERE user_id = ?')
                    ->execute([password_hash($temp, PASSWORD_DEFAULT), $deskUser['user_id']]);
                log_audit($pdo, $adminId, 'receptionists', 'PASSWORD_RESET', "Admin reset the password for {$deskUser['username']}.");
                set_flash('success', "New temporary password for {$deskUser['username']}: {$temp} — shown only once; they must change it at next sign-in.");
                redirect('admin/manage_receptionists.php');
            }
        }
    }
}

$stmt = $pdo->prepare('SELECT r.*, u.username, u.email, u.is_active, u.last_login_at
                       FROM receptionists r JOIN users u ON u.user_id = r.user_id
                       WHERE u.org_id = ? ORDER BY r.full_name');
$stmt->execute([current_org_id()]);
$staff = $stmt->fetchAll();

$page_title = 'Reception';
require __DIR__ . '/../includes/header.php';
?>
<div class="space-y-6">
    <?= ui_page_header(
        'Front desk',
        'Reception staff register patients, record observations and send referrals. They cannot open clinical results: the alert feed they see carries a patient reference, never a name or a reading.',
        ui_button('Add front-desk account', ['attrs' => 'data-dialog-target="add-receptionist-dialog"', 'icon' => 'user-plus'])
        . ui_button('Doctors', ['href' => 'manage_clinicians.php', 'variant' => 'outline', 'icon' => 'stethoscope'])
    ) ?>

    <?php if ($error): ?>
        <?= ui_alert('destructive', 'Action could not be completed', e($error)) ?>
    <?php endif; ?>

    <section class="<?= UI_CARD ?>" aria-labelledby="reception-list">
        <div class="<?= UI_CARD_HEADER ?>">
            <h2 id="reception-list" class="<?= UI_CARD_TITLE ?>">Front-desk accounts</h2>
            <span class="text-xs text-muted-foreground"><?= count($staff) ?> accounts</span>
        </div>
        <div class="overflow-x-auto scroll-area">
            <table class="w-full min-w-[760px] border-collapse text-sm">
                <caption class="sr-only">Front-desk staff with desk, account state, last sign-in and actions.</caption>
                <thead>
                    <tr class="border-b border-border bg-muted/50 text-left text-[0.68rem] uppercase tracking-[0.07em] text-muted-foreground">
                        <th scope="col" class="px-5 py-2.5 font-semibold">Name</th>
                        <th scope="col" class="px-3 py-2.5 font-semibold">Account</th>
                        <th scope="col" class="px-3 py-2.5 font-semibold">Last sign-in</th>
                        <th scope="col" class="px-3 py-2.5 font-semibold">Status</th>
                        <th scope="col" class="px-5 py-2.5 text-right font-semibold"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                <?php foreach ($staff as $s): ?>
                    <tr class="hover:bg-muted/40">
                        <td class="px-5 py-3">
                            <p class="font-medium"><?= e($s['full_name']) ?></p>
                            <p class="text-xs text-muted-foreground"><?= e($s['desk'] ?: 'Front desk') ?></p>
                        </td>
                        <td class="px-3 py-3">
                            <p><?= e($s['username']) ?></p>
                            <p class="text-xs text-muted-foreground"><?= e($s['email']) ?></p>
                        </td>
                        <td class="px-3 py-3 text-xs text-muted-foreground"><?= $s['last_login_at'] ? e(date('d M Y, H:i', strtotime($s['last_login_at']))) : 'Never' ?></td>
                        <td class="px-3 py-3"><?= $s['is_active'] ? ui_badge('Active', 'success') : ui_badge('Deactivated', 'muted') ?></td>
                        <td class="px-5 py-3 text-right">
                            <div class="inline-flex items-center gap-1.5">
                                <form method="post" onsubmit="return confirm('Reset this password?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="reset">
                                    <input type="hidden" name="receptionist_id" value="<?= (int)$s['receptionist_id'] ?>">
                                    <?= ui_button('Reset password', ['type' => 'submit', 'variant' => 'outline', 'size' => 'sm']) ?>
                                </form>
                                <form method="post" onsubmit="return confirm('Change this account\\'s access?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="receptionist_id" value="<?= (int)$s['receptionist_id'] ?>">
                                    <?= ui_button($s['is_active'] ? 'Deactivate' : 'Activate', [
                                        'type' => 'submit', 'size' => 'sm',
                                        'variant' => $s['is_active'] ? 'destructive' : 'success',
                                    ]) ?>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$staff): ?>
                    <tr><td colspan="5"><?= ui_empty('user', 'No front-desk accounts yet', 'Issue the first account so reception can register patients.') ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<?php
$dialogBody = '<p class="mb-4 flex items-start gap-2 rounded-md border border-border bg-muted/50 px-3.5 py-2.5 text-xs leading-relaxed text-muted-foreground">'
    . icon('shield-check', 'mt-0.5 h-3.5 w-3.5 shrink-0 text-success')
    . '<span>Front-desk accounts cannot view glucose results, prescriptions or clinical notes. A temporary password is generated and must be changed at first sign-in.</span></p>'
    . '<div class="grid gap-4 sm:grid-cols-2">'
    . ui_field(['id' => 'rf_full_name', 'name' => 'full_name', 'label' => 'Full name', 'value' => $f['full_name'], 'required' => true])
    . ui_field(['id' => 'rf_desk', 'name' => 'desk', 'label' => 'Desk', 'value' => $f['desk'], 'attrs' => 'maxlength="40"'])
    . ui_field(['id' => 'rf_email', 'name' => 'email', 'type' => 'email', 'label' => 'Work email', 'value' => $f['email'], 'required' => true])
    . ui_field(['id' => 'rf_username', 'name' => 'username', 'label' => 'Username', 'value' => $f['username'], 'required' => true])
    . '</div>';

echo '<dialog id="add-receptionist-dialog" class="app-dialog max-w-2xl" aria-labelledby="add-receptionist-title"'
    . ($error ? ' data-autoshow="1"' : '') . '>'
    . '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="create">'
    . '<div class="flex items-start justify-between gap-4 px-6 pb-4 pt-6">'
    . '<div class="flex items-start gap-3">'
    . '<span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-primary/10 text-primary">' . icon('user-plus', 'h-4 w-4') . '</span>'
    . '<div><h2 id="add-receptionist-title" class="text-base font-semibold leading-6">Issue a front-desk account</h2>'
    . '<p class="mt-1 text-sm text-muted-foreground">Share the one-time password with reception.</p></div></div>'
    . '<button type="button" data-dialog-close aria-label="Close dialog" class="grid h-8 w-8 shrink-0 place-items-center rounded-md text-muted-foreground transition hover:bg-muted hover:text-foreground">' . icon('x', 'h-4 w-4') . '</button>'
    . '</div>'
    . '<div class="app-dialog-body border-t border-border">' . $dialogBody . '</div>'
    . '<div class="flex flex-wrap justify-end gap-2 border-t border-border bg-muted/40 px-6 py-4">'
    . ui_button('Cancel', ['variant' => 'outline', 'attrs' => 'data-dialog-close'])
    . ui_button('Create account', ['type' => 'submit', 'icon' => 'check'])
    . '</div></form></dialog>';
?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
