<?php
// Referrals nobody currently owns: sent to a department for a patient with no assigned
// doctor. Doctors cannot see them (their inbox is limited to their own patients), so the
// administrator routes them by assigning a doctor — after which the referral appears in
// that doctor's inbox automatically.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/receptionist.php';
require_role('admin');

$pdo = get_db_connection();
$adminId = (int)$_SESSION['user_id'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $referralId = (int)($_POST['referral_id'] ?? 0);
    $clinicianId = (int)($_POST['clinician_id'] ?? 0);

    if (!csrf_valid()) {
        $error = 'Your session expired. Please try again.';
    } else {
        // Only a still-unowned referral in this organisation can be routed here.
        $stmt = $pdo->prepare("SELECT r.referral_id, r.patient_id, r.urgency, p.full_name, p.clinician_id
                               FROM referrals r
                               JOIN patients p ON p.patient_id = r.patient_id
                               JOIN users pu ON pu.user_id = p.user_id
                               WHERE r.referral_id = ? AND pu.org_id = ? AND r.status = 'pending'
                                 AND r.clinician_id IS NULL");
        $stmt->execute([$referralId, current_org_id()]);
        $ref = $stmt->fetch();

        $doc = null;
        if ($clinicianId > 0) {
            $d = $pdo->prepare('SELECT c.clinician_id, c.full_name FROM clinicians c
                                JOIN users u ON u.user_id = c.user_id
                                WHERE c.clinician_id = ? AND u.is_active = 1');
            $d->execute([$clinicianId]);
            $doc = $d->fetch();
        }

        if (!$ref) {
            $error = 'That referral is no longer waiting for a doctor.';
        } elseif (!$doc) {
            $error = 'Choose an active doctor.';
        } elseif ($ref['clinician_id'] !== null) {
            $error = $ref['full_name'] . ' already has a doctor, so this referral has an owner.';
        } else {
            $pdo->prepare('UPDATE patients SET clinician_id = ?, assigned_at = ? WHERE patient_id = ?')
                ->execute([$clinicianId, date('Y-m-d H:i:s'), (int)$ref['patient_id']]);
            log_audit($pdo, $adminId, 'patients', 'ASSIGN',
                "Assigned patient #{$ref['patient_id']} ({$ref['full_name']}) to {$doc['full_name']} to route referral #{$referralId}.");
            log_audit($pdo, $adminId, 'referrals', 'REFERRAL_ROUTED',
                "Referral #{$referralId} now visible to {$doc['full_name']} after assigning patient #{$ref['patient_id']}.");
            set_flash('success', $ref['full_name'] . ' is now assigned to ' . $doc['full_name'] . ' — the referral is in their inbox.');
            redirect('admin/referrals.php');
        }
    }
}

$queue = unowned_referrals($pdo);

$doctors = $pdo->prepare('SELECT c.clinician_id, c.full_name, c.specialization
                          FROM clinicians c JOIN users u ON u.user_id = c.user_id
                          WHERE u.is_active = 1 AND u.org_id = ? ORDER BY c.full_name');
$doctors->execute([current_org_id()]);
$doctors = $doctors->fetchAll();

// Everything still open, so an ownerless referral is visibly the exception rather than the rule.
$stmt = $pdo->prepare("SELECT r.*, p.full_name, p.clinician_id AS patient_clinician_id,
                              d.name AS department_name, c.full_name AS clinician_name, pc.full_name AS owner_name
                       FROM referrals r
                       JOIN patients p ON p.patient_id = r.patient_id
                       JOIN users pu ON pu.user_id = p.user_id
                       LEFT JOIN departments d ON d.department_id = r.department_id
                       LEFT JOIN clinicians c ON c.clinician_id = r.clinician_id
                       LEFT JOIN clinicians pc ON pc.clinician_id = p.clinician_id
                       WHERE pu.org_id = ? AND r.status IN ('pending','accepted')
                       ORDER BY CASE r.urgency WHEN 'emergency' THEN 1 WHEN 'urgent' THEN 2 ELSE 3 END, r.created_at DESC
                       LIMIT 100");
$stmt->execute([current_org_id()]);
$open = $stmt->fetchAll();

$page_title = 'Referrals';
require __DIR__ . '/../includes/header.php';
?>
<div class="space-y-6">
    <?= ui_page_header(
        'Referrals waiting for an owner',
        'A referral to a department for a patient with no doctor is visible to no clinician, so it is routed here. Assign a doctor and it appears in their inbox immediately.',
        ui_button('Doctors', ['href' => 'manage_clinicians.php', 'variant' => 'outline', 'icon' => 'stethoscope'])
        . ui_button('Assign patients', ['href' => 'manage_patients.php', 'variant' => 'outline', 'icon' => 'users'])
    ) ?>

    <?php if ($error): ?>
        <?= ui_alert('destructive', 'Referral not routed', e($error)) ?>
    <?php endif; ?>

    <?php if ($queue): ?>
        <?php if (array_filter($queue, fn($r) => $r['urgency'] === 'emergency')): ?>
            <?= ui_alert('destructive', 'Emergency referral without a doctor', 'Assign a doctor now, then tell the department directly — the alert feed is not a substitute for a phone call.', 'alert-octagon') ?>
        <?php endif; ?>

        <section class="<?= UI_CARD ?>" aria-labelledby="unowned-queue">
            <div class="<?= UI_CARD_HEADER ?>">
                <div>
                    <h2 id="unowned-queue" class="<?= UI_CARD_TITLE ?>">No doctor owns these</h2>
                    <p class="mt-1 text-xs text-muted-foreground">Oldest urgent first. Assignment is permanent until you change it in Patients.</p>
                </div>
                <span class="text-xs font-semibold text-destructive"><?= count($queue) ?> waiting</span>
            </div>
            <ul class="divide-y divide-border">
                <?php foreach ($queue as $r): ?>
                    <li class="px-5 py-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <?= referral_urgency_badge($r['urgency']) ?>
                                    <?= ui_badge($r['department_name'] ? 'Department: ' . $r['department_name'] : 'No target', 'secondary') ?>
                                    <?= ui_badge('No doctor assigned', 'warning') ?>
                                </div>
                                <p class="mt-1.5 text-sm font-semibold"><?= e($r['full_name']) ?>
                                    <span class="ml-1 font-mono text-xs font-normal text-muted-foreground"><?= e(patient_code((int)$r['patient_id'])) ?></span>
                                </p>
                                <p class="text-xs text-muted-foreground"><?= e(phone_display($r['phone'])) ?> · sent <?= e(date('d M Y, H:i', strtotime($r['created_at']))) ?> by <?= e($r['referred_by']) ?></p>
                                <p class="mt-1.5 max-w-2xl text-[0.83rem] leading-relaxed text-foreground/80"><?= e($r['reason']) ?></p>
                            </div>
                            <form method="post" class="flex w-full items-end gap-2 sm:w-auto">
                                <?= csrf_field() ?>
                                <input type="hidden" name="referral_id" value="<?= (int)$r['referral_id'] ?>">
                                <div class="min-w-0 flex-1 sm:w-64">
                                    <label for="assign-<?= (int)$r['referral_id'] ?>" class="mb-1 block text-xs font-medium text-muted-foreground">Assign a doctor</label>
                                    <select id="assign-<?= (int)$r['referral_id'] ?>" name="clinician_id" required
                                            class="h-9 w-full rounded-md border border-input bg-white px-2.5 text-sm shadow-sm focus-visible:border-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/30">
                                        <option value="">Select…</option>
                                        <?php foreach ($doctors as $d): ?>
                                            <option value="<?= (int)$d['clinician_id'] ?>"><?= e($d['full_name']) ?> — <?= e($d['specialization']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?= ui_button('Route', ['type' => 'submit', 'size' => 'sm', 'icon' => 'check']) ?>
                            </form>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php else: ?>
        <div class="<?= UI_CARD ?> border-l-4 border-l-success">
            <?= ui_empty('check-circle', 'Every referral has an owner', 'Nothing is waiting for a doctor assignment right now.') ?>
        </div>
    <?php endif; ?>

    <section class="<?= UI_CARD ?>" aria-labelledby="open-referrals">
        <div class="<?= UI_CARD_HEADER ?>">
            <h2 id="open-referrals" class="<?= UI_CARD_TITLE ?>">All open referrals</h2>
            <span class="text-xs text-muted-foreground"><?= count($open) ?> records</span>
        </div>
        <div class="overflow-x-auto scroll-area">
            <table class="w-full min-w-[820px] border-collapse text-sm">
                <caption class="sr-only">Pending and accepted referrals with target, urgency, owner and age.</caption>
                <thead>
                    <tr class="border-b border-border bg-muted/50 text-left text-[0.68rem] uppercase tracking-[0.07em] text-muted-foreground">
                        <th scope="col" class="px-5 py-2.5 font-semibold">Patient</th>
                        <th scope="col" class="px-3 py-2.5 font-semibold">Refer to</th>
                        <th scope="col" class="px-3 py-2.5 font-semibold">Urgency</th>
                        <th scope="col" class="px-3 py-2.5 font-semibold">Owner</th>
                        <th scope="col" class="px-5 py-2.5 font-semibold">Sent</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                <?php foreach ($open as $r):
                    $owner = $r['clinician_name'] ?: ($r['owner_name'] ?? null); ?>
                    <tr class="hover:bg-muted/40">
                        <td class="px-5 py-3">
                            <p class="font-medium"><?= e($r['full_name']) ?></p>
                            <p class="font-mono text-xs text-muted-foreground"><?= e(patient_code((int)$r['patient_id'])) ?></p>
                        </td>
                        <td class="px-3 py-3">
                            <p><?= e(referral_target($r)) ?></p>
                            <p class="max-w-[18rem] truncate text-xs text-muted-foreground" title="<?= e($r['reason']) ?>"><?= e($r['reason']) ?></p>
                        </td>
                        <td class="px-3 py-3"><?= referral_urgency_badge($r['urgency']) ?></td>
                        <td class="px-3 py-3">
                            <?= $owner ? ui_badge($owner, 'default') : ui_badge('No doctor', 'destructive') ?>
                            <?= referral_status_badge($r['status']) ?>
                        </td>
                        <td class="px-5 py-3 whitespace-nowrap text-xs text-muted-foreground"><?= e(date('d M Y, H:i', strtotime($r['created_at']))) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$open): ?>
                    <tr><td colspan="5"><?= ui_empty('arrow-right', 'No open referrals', 'Referrals sent by the front desk appear here until they are completed.') ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
