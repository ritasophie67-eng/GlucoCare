<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('patient');

$pdo = get_db_connection();
$stmt = $pdo->prepare('SELECT r.*, c.full_name AS doctor FROM prescriptions r JOIN clinicians c ON c.clinician_id = r.clinician_id
                       WHERE r.patient_id = ? ORDER BY (r.status = \'active\') DESC, r.created_at DESC');
$stmt->execute([(int)$_SESSION['patient_id']]);
$rxs = $stmt->fetchAll();

$page_title = 'My Prescriptions';
require __DIR__ . '/../includes/header.php';
?>
<div class="space-y-6">
    <?= ui_page_header('My prescriptions', 'Medicines prescribed by your doctor. Take them exactly as directed — ask your care team if anything is unclear.') ?>

    <?php if (!$rxs): ?>
        <div class="rounded-lg border border-border bg-card">
            <?= ui_empty('pill', 'No prescriptions yet', 'When your doctor issues a prescription it will appear here with dosage and timing.') ?>
        </div>
    <?php else: ?>
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <?php foreach ($rxs as $r): $active = $r['status'] === 'active'; ?>
                <article class="<?= UI_CARD ?> <?= $active ? '' : 'opacity-70' ?> p-5">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h2 class="truncate text-base font-semibold leading-6"><?= e($r['medication_name']) ?></h2>
                            <p class="mt-0.5 text-sm text-muted-foreground"><?= e($r['dosage']) ?> · <?= e($r['frequency']) ?></p>
                        </div>
                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg <?= $active ? 'bg-success/10 text-success' : 'bg-muted text-muted-foreground' ?>">
                            <?= icon('pill', 'h-4 w-4') ?>
                        </span>
                    </div>
                    <div class="mt-4 flex flex-wrap items-center gap-2">
                        <?= ui_badge(ucfirst($r['status']), $active ? 'success' : 'muted') ?>
                        <?= ui_badge(date('d M Y', strtotime($r['start_date'])) . ($r['end_date'] ? ' → ' . date('d M Y', strtotime($r['end_date'])) : ' · ongoing'), 'outline') ?>
                    </div>
                    <p class="mt-3 flex items-center gap-1.5 border-t border-border pt-3 text-xs text-muted-foreground">
                        <?= icon('stethoscope', 'h-3.5 w-3.5') ?> Prescribed by <?= e($r['doctor']) ?>
                    </p>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
