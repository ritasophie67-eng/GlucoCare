<?php
// Administrators assign each patient to exactly one doctor. That assignment is what
// controls which doctor is allowed to see the patient. Phone numbers are edited here
// too, because patients cannot change them after registration.
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$pdo = get_db_connection();
$adminId = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pid = (int)($_POST['patient_id'] ?? 0);
    $action = $_POST['action'] ?? 'assign';

    if (!csrf_valid()) {
        set_flash('danger', 'Your session expired. Please try again.');
        redirect('admin/manage_patients.php');
    }

    if ($action === 'phone') {
        $p = $pdo->prepare('SELECT full_name, phone FROM patients WHERE patient_id = ?');
        $p->execute([$pid]);
        $patient = $p->fetch();
        $newPhone = phone_normalize($_POST['phone'] ?? '');

        if (!$patient) {
            set_flash('danger', 'Patient not found.');
        } elseif ($newPhone === null) {
            set_flash('danger', PHONE_ERROR);
            redirect('admin/manage_patients.php?edit=' . $pid);
        } elseif ($newPhone === $patient['phone']) {
            set_flash('info', 'No change — that phone number is already saved.');
        } else {
            $pdo->prepare('UPDATE patients SET phone = ? WHERE patient_id = ?')->execute([$newPhone, $pid]);
            log_audit($pdo, $adminId, 'patients', 'UPDATE_PHONE',
                "Changed phone for patient #{$pid} ({$patient['full_name']}): {$patient['phone']} → {$newPhone}.");
            set_flash('success', "Phone number for {$patient['full_name']} updated.");
        }
        redirect('admin/manage_patients.php');
    }

    $cid = (int)($_POST['clinician_id'] ?? 0);

    $p = $pdo->prepare('SELECT full_name, clinician_id FROM patients WHERE patient_id = ?');
    $p->execute([$pid]);
    $patient = $p->fetch();

    $doc = null;
    if ($cid > 0) {
        $d = $pdo->prepare('SELECT c.full_name FROM clinicians c JOIN users u ON u.user_id = c.user_id WHERE c.clinician_id = ? AND u.is_active = 1');
        $d->execute([$cid]);
        $doc = $d->fetch();
    }

    if (!$patient) {
        set_flash('danger', 'Patient not found.');
    } elseif ($cid > 0 && !$doc) {
        set_flash('danger', 'Choose an active doctor.');
    } elseif ((int)$patient['clinician_id'] === $cid || ($cid === 0 && $patient['clinician_id'] === null)) {
        set_flash('info', 'No change — that assignment already exists.');
    } else {
        $pdo->prepare('UPDATE patients SET clinician_id = ?, assigned_at = ? WHERE patient_id = ?')
            ->execute([$cid ?: null, $cid ? date('Y-m-d H:i:s') : null, $pid]);
        log_audit($pdo, $adminId, 'patients', $cid ? 'ASSIGN' : 'UNASSIGN',
            $cid ? "Assigned patient #{$pid} ({$patient['full_name']}) to {$doc['full_name']}." : "Removed doctor assignment from patient #{$pid} ({$patient['full_name']}).");
        set_flash('success', $cid ? "{$patient['full_name']} is now assigned to {$doc['full_name']}." : "{$patient['full_name']} is now unassigned.");
    }
    redirect('admin/manage_patients.php');
}

$doctors = $pdo->query('SELECT c.clinician_id, c.full_name FROM clinicians c JOIN users u ON u.user_id = c.user_id WHERE u.is_active = 1 ORDER BY c.full_name')->fetchAll();
$stmt = $pdo->prepare('SELECT p.patient_id, p.full_name, p.gender, p.date_of_birth, p.phone, p.clinician_id, u.is_active,
                         (SELECT COUNT(*) FROM patients p2 JOIN users u2 ON u2.user_id = p2.user_id
                           WHERE p2.phone = p.phone AND u2.username NOT LIKE \'sim\\_%\') AS phone_dupes
                         FROM patients p JOIN users u ON u.user_id = p.user_id
                         WHERE u.username NOT LIKE \'sim\\_%\' AND u.org_id = ?
                         ORDER BY (p.clinician_id IS NULL) DESC, p.full_name');
$stmt->execute([current_org_id()]);
$patients = $stmt->fetchAll();

// Reopen the dialog for a row whose number was rejected, so the admin can correct it.
$editId = (int)($_GET['edit'] ?? 0);
$phoneDialogs = '';

$page_title = 'Patients';
require __DIR__ . '/../includes/header.php';
?>
<div class="space-y-6">
    <?= ui_page_header(
        'Patients & assignments',
        'Each patient is visible only to the one doctor you assign here. Unassigned patients are visible to no doctor.',
        ui_button('Doctors', ['href' => 'manage_clinicians.php', 'variant' => 'outline', 'icon' => 'stethoscope'])
    ) ?>

    <section class="<?= UI_CARD ?>" aria-labelledby="patient-list">
        <div class="<?= UI_CARD_HEADER ?>">
            <h2 id="patient-list" class="<?= UI_CARD_TITLE ?>">Registered patients</h2>
            <span class="text-xs text-muted-foreground"><?= count($patients) ?> records</span>
        </div>
        <div class="overflow-x-auto scroll-area">
            <table class="w-full min-w-[760px] border-collapse text-sm">
                <caption class="sr-only">Patients with their contact number and assigned doctor; unassigned patients are listed first.</caption>
                <thead>
                    <tr class="border-b border-border bg-muted/50 text-left text-[0.68rem] uppercase tracking-[0.07em] text-muted-foreground">
                        <th scope="col" class="px-5 py-2.5 font-semibold">Patient</th>
                        <th scope="col" class="px-3 py-2.5 font-semibold">Gender / Age</th>
                        <th scope="col" class="px-3 py-2.5 font-semibold">Phone</th>
                        <th scope="col" class="px-5 py-2.5 text-right font-semibold">Assigned doctor</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                <?php foreach ($patients as $p): $pid = (int)$p['patient_id']; ?>
                    <tr class="hover:bg-muted/40">
                        <td class="px-5 py-3">
                            <div class="flex items-center gap-2.5">
                                <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-primary/10 text-xs font-semibold text-primary">
                                    <?= e(strtoupper(mb_substr($p['full_name'], 0, 1))) ?>
                                </span>
                                <div class="min-w-0">
                                    <p class="flex items-center gap-2 font-medium"><?= e($p['full_name']) ?>
                                        <?= $p['clinician_id'] ? '' : ui_badge('Unassigned', 'warning') ?>
                                    </p>
                                    <p class="flex items-center gap-1.5 text-xs text-muted-foreground">Patient #<?= $pid ?>
                                        <?php if ((int)$p['phone_dupes'] > 1): ?>
                                            <span title="More than one patient record uses this phone number — check for a duplicate before assigning."><?= ui_badge('Possible duplicate', 'warning') ?></span>
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>
                        </td>
                        <td class="px-3 py-3 capitalize text-muted-foreground"><?= e($p['gender']) ?>, <?= calc_age($p['date_of_birth']) ?></td>
                        <td class="px-3 py-3 text-muted-foreground">
                            <div class="flex items-center gap-1.5">
                                <span class="tabular"><?= e(phone_display($p['phone'])) ?></span>
                                <?php if (phone_normalize($p['phone']) === null): ?>
                                    <span title="This number is not a valid Ugandan phone number — edit it to fix the format."><?= ui_badge('Check', 'warning') ?></span>
                                <?php endif; ?>
                                <button type="button" data-dialog-target="edit-phone-<?= $pid ?>"
                                        aria-label="Edit phone number for <?= e($p['full_name']) ?>" title="Edit phone number"
                                        class="grid h-7 w-7 shrink-0 place-items-center rounded-md text-muted-foreground/60 transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/30">
                                    <?= icon('pencil', 'h-3.5 w-3.5') ?>
                                </button>
                            </div>
                        </td>
                        <td class="px-5 py-3">
                            <form method="post" class="flex items-center justify-end gap-2">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="assign">
                                <input type="hidden" name="patient_id" value="<?= $pid ?>">
                                <label class="sr-only" for="clinician-<?= $pid ?>">Doctor for <?= e($p['full_name']) ?></label>
                                <select id="clinician-<?= $pid ?>" name="clinician_id"
                                        class="h-9 w-[13rem] rounded-md border border-input bg-white px-2.5 text-sm shadow-sm focus-visible:border-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/30">
                                    <option value="0">— Unassigned —</option>
                                    <?php foreach ($doctors as $d): ?>
                                        <option value="<?= (int)$d['clinician_id'] ?>" <?= (int)$p['clinician_id'] === (int)$d['clinician_id'] ? 'selected' : '' ?>><?= e($d['full_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?= ui_button('Save', ['type' => 'submit', 'size' => 'sm']) ?>
                            </form>
                        </td>
                    </tr>
                <?php
                    $phoneDialogs .= '<dialog id="edit-phone-' . $pid . '" class="app-dialog" aria-labelledby="edit-phone-' . $pid . '-title"'
                        . ($editId === $pid ? ' data-autoshow="1"' : '') . '>'
                        . '<form method="post">' . csrf_field()
                        . '<input type="hidden" name="action" value="phone">'
                        . '<input type="hidden" name="patient_id" value="' . $pid . '">'
                        . '<div class="flex items-start justify-between gap-4 px-6 pb-4 pt-6">'
                        . '<div class="flex items-start gap-3">'
                        . '<span class="mt-0.5 grid h-9 w-9 shrink-0 place-items-center rounded-full bg-primary/10 text-primary">' . icon('pencil', 'h-4 w-4') . '</span>'
                        . '<div><h2 id="edit-phone-' . $pid . '-title" class="text-base font-semibold leading-6 text-foreground">Phone number</h2>'
                        . '<p class="mt-1 text-sm leading-relaxed text-muted-foreground">' . e($p['full_name']) . ' · Patient #' . $pid . '</p></div></div>'
                        . '<button type="button" data-dialog-close aria-label="Close dialog" class="grid h-8 w-8 shrink-0 place-items-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground">' . icon('x', 'h-4 w-4') . '</button>'
                        . '</div>'
                        . '<div class="app-dialog-body border-t border-border">'
                        . ui_field(['id' => 'phone-' . $pid, 'name' => 'phone', 'type' => 'tel', 'label' => 'Phone',
                            'value' => phone_display($p['phone']), 'required' => true, 'attrs' => 'autocomplete="tel" inputmode="tel"',
                            'hint' => 'Ugandan number. 0700 000 001 and +256 700 000 001 are stored the same way.'])
                        . '</div>'
                        . '<div class="flex flex-wrap items-center justify-end gap-2 border-t border-border bg-muted/40 px-6 py-4">'
                        . ui_button('Cancel', ['variant' => 'outline', 'attrs' => 'data-dialog-close'])
                        . ui_button('Save phone', ['type' => 'submit', 'icon' => 'check'])
                        . '</div></form></dialog>';
                endforeach; ?>
                <?php if (!$patients): ?>
                    <tr><td colspan="4"><?= ui_empty('users', 'No patients registered yet', 'Patients appear here after they create an account.') ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <?= $phoneDialogs ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
