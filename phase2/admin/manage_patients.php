<?php
// Administrators assign each patient to exactly one doctor. That assignment is what
// controls which doctor is allowed to see the patient.
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$pdo = get_db_connection();
$adminId = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pid = (int)($_POST['patient_id'] ?? 0);
    $cid = (int)($_POST['clinician_id'] ?? 0);

    if (!csrf_valid()) {
        set_flash('danger', 'Your session expired. Please try again.');
    } else {
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
    }
    redirect('admin/manage_patients.php');
}

$doctors = $pdo->query('SELECT c.clinician_id, c.full_name FROM clinicians c JOIN users u ON u.user_id = c.user_id WHERE u.is_active = 1 ORDER BY c.full_name')->fetchAll();
$patients = $pdo->query('SELECT p.patient_id, p.full_name, p.gender, p.date_of_birth, p.phone, p.clinician_id, u.is_active
                         FROM patients p JOIN users u ON u.user_id = p.user_id
                         ORDER BY (p.clinician_id IS NULL) DESC, p.full_name')->fetchAll();

$page_title = 'Patients';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
    <div><h2>Patients &amp; assignments</h2><p>Each patient is visible only to the one doctor you assign here. Unassigned patients are visible to no doctor.</p></div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th class="ps-3">Patient</th><th>Gender / Age</th><th>Phone</th><th>Assigned doctor</th><th class="pe-3"></th></tr></thead>
            <tbody>
            <?php if (!$patients): ?><tr><td colspan="5"><div class="empty-state"><i class="bi bi-people"></i>No patients registered yet.</div></td></tr><?php endif; ?>
            <?php foreach ($patients as $p): ?>
                <tr>
                    <td class="ps-3 fw-semibold"><?= e($p['full_name']) ?> <?= $p['clinician_id'] ? '' : '<span class="chip chip-warn ms-1">Unassigned</span>' ?></td>
                    <td class="text-capitalize"><?= e($p['gender']) ?>, <?= calc_age($p['date_of_birth']) ?></td>
                    <td><?= e($p['phone']) ?></td>
                    <td colspan="2" class="pe-3">
                        <form method="post" class="d-flex gap-2 justify-content-end">
                            <?= csrf_field() ?>
                            <input type="hidden" name="patient_id" value="<?= (int)$p['patient_id'] ?>">
                            <select name="clinician_id" class="form-select form-select-sm" style="max-width:240px">
                                <option value="0">— Unassigned —</option>
                                <?php foreach ($doctors as $d): ?>
                                    <option value="<?= (int)$d['clinician_id'] ?>" <?= (int)$p['clinician_id'] === (int)$d['clinician_id'] ? 'selected' : '' ?>><?= e($d['full_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-sm btn-primary">Save</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
