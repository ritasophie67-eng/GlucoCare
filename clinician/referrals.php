<?php
// Referrals the front desk sent on: to THIS doctor directly, or to a department for one of
// THIS doctor's assigned patients. Nothing else is visible, which keeps the "assigned
// patients only" rule intact.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/receptionist.php';
require_role('clinician');

$pdo = get_db_connection();
$me = (int)$_SESSION['user_id'];
$clinicianId = (int)$_SESSION['clinician_id'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $referralId = (int)($_POST['referral_id'] ?? 0);
    $to = $_POST['status'] ?? '';
    $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 255);

    // Same visibility rule as the list below.
    $stmt = $pdo->prepare('SELECT r.referral_id, r.patient_id, r.status, p.full_name
                           FROM referrals r JOIN patients p ON p.patient_id = r.patient_id
                           WHERE r.referral_id = ? AND (r.clinician_id = ? OR p.clinician_id = ?)');
    $stmt->execute([$referralId, $clinicianId, $clinicianId]);
    $ref = $stmt->fetch();

    if (!csrf_valid()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!$ref) {
        log_audit($pdo, $me, 'referrals', 'ACCESS_DENIED', "Clinician {$_SESSION['username']} tried to update referral #{$referralId}.");
        $errors[] = 'That referral is not on your list.';
    } elseif (!in_array($to, ['accepted', 'completed'], true)) {
        $errors[] = 'Choose a valid action.';
    } elseif ($ref['status'] === 'cancelled') {
        $errors[] = 'That referral was withdrawn by the front desk.';
    } else {
        $pdo->prepare('UPDATE referrals SET status = ?, outcome_note = ?, updated_at = NOW(),
                       clinician_id = COALESCE(clinician_id, ?) WHERE referral_id = ?')
            ->execute([$to, $note ?: null, $clinicianId, $referralId]);
        log_audit($pdo, $me, 'referrals', 'REFERRAL_' . strtoupper($to),
            "Referral #{$referralId} for patient #{$ref['patient_id']} marked {$to}.");
        set_flash('success', 'Referral marked ' . $to . '.');
        redirect('clinician/referrals.php');
    }
}

$status = $_GET['status'] ?? 'pending';
if (!isset(REFERRAL_STATUSES[$status])) {
    $status = 'pending';
}

$base = 'FROM referrals r
         JOIN patients p ON p.patient_id = r.patient_id
         LEFT JOIN clinicians c ON c.clinician_id = r.clinician_id
         LEFT JOIN departments d ON d.department_id = r.department_id
         WHERE (r.clinician_id = ? OR p.clinician_id = ?)';
$params = [$clinicianId, $clinicianId];
if ($status !== 'all') {
    $base .= ' AND r.status = ?';
    $params[] = $status;
}

$stmt = $pdo->prepare('SELECT r.*, p.full_name, p.phone, c.full_name AS clinician_name, d.name AS department_name ' . $base
    . " ORDER BY CASE r.urgency WHEN 'emergency' THEN 1 WHEN 'urgent' THEN 2 ELSE 3 END, r.created_at DESC LIMIT 100");
$stmt->execute($params);
$referrals = $stmt->fetchAll();

// Counts ignore the status filter, so they use their own unfiltered query.
$counts = [];
$stmt = $pdo->prepare('SELECT r.status, COUNT(*) FROM referrals r JOIN patients p ON p.patient_id = r.patient_id
                       WHERE (r.clinician_id = ? OR p.clinician_id = ?) GROUP BY r.status');
$stmt->execute([$clinicianId, $clinicianId]);
foreach ($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
    $counts[$k] = (int)$v;
}

$page_title = 'Referrals';
require __DIR__ . '/../includes/header.php';
?>
<div class="space-y-6">
    <?= ui_page_header(
        'Referrals to you',
        'Patients sent on by the front desk — to you by name, or to a department for one of your assigned patients. Accept one to take it on; complete it when the visit is done.',
        ui_button('Back to triage', ['href' => 'dashboard.php', 'variant' => 'outline', 'icon' => 'arrow-left'])
    ) ?>

    <?php foreach ($errors as $err): ?>
        <?= ui_alert('destructive', 'Referral not updated', e($err)) ?>
    <?php endforeach; ?>

    <div class="flex flex-wrap items-center gap-2">
        <div class="inline-flex items-center gap-1 rounded-lg border border-border bg-muted/60 p-1">
            <?php foreach (array_merge(['all' => 'All'], REFERRAL_STATUSES) as $k => $label): ?>
                <a href="?status=<?= e($k) ?>"
                   class="inline-flex h-8 items-center gap-1.5 rounded-md px-3 text-xs font-medium transition-colors <?= $k === $status ? 'bg-white text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground' ?>">
                    <?= e($label) ?>
                    <?php if (isset($counts[$k])): ?><span class="rounded-full bg-slate-900/5 px-1.5 py-0.5 text-[0.65rem] font-semibold tabular"><?= (int)$counts[$k] ?></span><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
        <p class="text-xs text-muted-foreground">Emergency first, then urgent, then newest.</p>
    </div>

    <?php if (!$referrals): ?>
        <div class="rounded-lg border border-border bg-card">
            <?= ui_empty('arrow-right', 'No referrals here', 'When the front desk sends a patient to you, it appears in this list.') ?>
        </div>
    <?php endif; ?>

    <div class="space-y-3">
        <?php foreach ($referrals as $r): ?>
            <article class="<?= UI_CARD ?> border-l-4 <?= $r['urgency'] === 'emergency' ? 'border-l-destructive' : ($r['urgency'] === 'urgent' ? 'border-l-warning' : 'border-l-border') ?>">
                <div class="px-5 py-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0 space-y-1.5">
                            <div class="flex flex-wrap items-center gap-2">
                                <?= referral_urgency_badge($r['urgency']) ?>
                                <?= referral_status_badge($r['status']) ?>
                                <?= ui_badge($r['department_name'] ? 'Department: ' . $r['department_name'] : 'Named doctor', 'secondary') ?>
                                <?php if ((int)$r['clinician_id'] === $clinicianId): ?>
                                    <?= ui_badge('Sent to you', 'outline') ?>
                                <?php endif; ?>
                            </div>
                            <h2 class="text-base font-semibold leading-6">
                                <a href="patient.php?id=<?= (int)$r['patient_id'] ?>" class="inline-flex items-center gap-1 hover:text-primary hover:underline">
                                    <?= e($r['full_name']) ?> <?= icon('chevron-right', 'h-3.5 w-3.5 text-muted-foreground') ?>
                                </a>
                            </h2>
                            <p class="text-xs text-muted-foreground"><span class="font-mono"><?= e(patient_code((int)$r['patient_id'])) ?></span> · <?= e(phone_display($r['phone'])) ?></p>
                        </div>
                        <p class="text-right text-[0.7rem] leading-relaxed text-muted-foreground"><?= e(date('d M Y, H:i', strtotime($r['created_at']))) ?></p>
                    </div>

                    <div class="mt-3 rounded-md border border-border bg-muted/40 px-3.5 py-3">
                        <p class="mb-1 text-[0.65rem] font-semibold uppercase tracking-[0.08em] text-muted-foreground">Reason given at the desk</p>
                        <p class="text-[0.83rem] leading-relaxed text-foreground/85"><?= e($r['reason']) ?></p>
                    </div>

                    <?php if ($r['outcome_note']): ?>
                        <p class="mt-3 flex items-start gap-1.5 text-xs text-muted-foreground">
                            <?= icon('clipboard-list', 'mt-0.5 h-3.5 w-3.5 shrink-0') ?><span><strong class="font-semibold text-foreground">Outcome:</strong> <?= e($r['outcome_note']) ?></span>
                        </p>
                    <?php endif; ?>

                    <?php if (in_array($r['status'], ['pending', 'accepted'], true)): ?>
                        <form method="post" class="mt-4 flex flex-col gap-2 border-t border-border pt-3 sm:flex-row sm:items-center">
                            <?= csrf_field() ?>
                            <input type="hidden" name="referral_id" value="<?= (int)$r['referral_id'] ?>">
                            <label for="ref-note-<?= (int)$r['referral_id'] ?>" class="sr-only">Outcome note for <?= e($r['full_name']) ?></label>
                            <input id="ref-note-<?= (int)$r['referral_id'] ?>" name="note" maxlength="255" placeholder="Optional note for the record"
                                   class="h-9 flex-1 rounded-md border border-input bg-white px-3 text-sm shadow-sm placeholder:text-muted-foreground/70 focus-visible:border-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/30">
                            <div class="flex items-center gap-2">
                                <?php if ($r['status'] === 'pending'): ?>
                                    <?= ui_button('Accept', ['type' => 'submit', 'name' => 'status', 'value' => 'accepted', 'variant' => 'outline', 'size' => 'sm']) ?>
                                <?php endif; ?>
                                <?= ui_button('Complete', ['type' => 'submit', 'name' => 'status', 'value' => 'completed', 'size' => 'sm', 'icon' => 'check']) ?>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
