<?php
// Patient register: who is in the system, who their doctor is, and what the desk can do next.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/receptionist.php';
require_role('receptionist');

$pdo = get_db_connection();
$q = trim((string)($_GET['q'] ?? ''));
[$term, $byId] = patient_search_input($q);

$sql = "
    SELECT p.patient_id, p.full_name, p.gender, p.date_of_birth, p.phone, p.clinician_id,
           c.full_name AS doctor,
           (SELECT MAX(v.recorded_at) FROM vitals v WHERE v.patient_id = p.patient_id) AS last_vitals,
           (SELECT COUNT(*) FROM referrals r WHERE r.patient_id = p.patient_id AND r.status = 'pending') AS open_referrals
    FROM patients p
    JOIN users u ON u.user_id = p.user_id
    LEFT JOIN clinicians c ON c.clinician_id = p.clinician_id
    WHERE u.org_id = ? AND u.username NOT LIKE 'sim\\_%'";
$params = [current_org_id()];

if ($byId > 0) {
    $sql .= ' AND p.patient_id = ?';
    $params[] = $byId;
} elseif ($term !== '') {
    $sql .= ' AND (p.full_name ILIKE ? OR p.phone LIKE ?)';
    $params[] = '%' . $term . '%';
    $params[] = '%' . preg_replace('/\D+/', '', $term) . '%';
}
$sql .= ' ORDER BY p.full_name LIMIT 50';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$patients = $stmt->fetchAll();

$page_title = 'Patients';
require __DIR__ . '/../includes/header.php';
?>
<div class="space-y-6">
    <?= ui_page_header(
        'Patient register',
        'Search by name, phone number or the reference a patient quotes (for example P-0004). Unassigned patients have no doctor yet — an administrator assigns one.',
        ui_button('Register a patient', ['href' => 'new_patient.php', 'icon' => 'user-plus'])
    ) ?>

    <form method="get" class="flex flex-wrap items-center gap-2">
        <label for="patient-search" class="sr-only">Search patients</label>
        <div class="relative min-w-[14rem] flex-1">
            <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground"><?= icon('search', 'h-3.5 w-3.5') ?></span>
            <input id="patient-search" type="search" name="q" value="<?= e($q) ?>" placeholder="Name, phone or reference…"
                   class="h-10 w-full rounded-md border border-input bg-white pl-9 pr-3 text-sm shadow-sm placeholder:text-muted-foreground/70 focus-visible:border-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/30">
        </div>
        <?= ui_button('Search', ['type' => 'submit', 'variant' => 'outline', 'icon' => 'search']) ?>
        <?php if ($q !== ''): ?>
            <?= ui_button('Clear', ['href' => 'patients.php', 'variant' => 'ghost']) ?>
        <?php endif; ?>
    </form>

    <section class="<?= UI_CARD ?>" aria-labelledby="register-list">
        <div class="<?= UI_CARD_HEADER ?>">
            <h2 id="register-list" class="<?= UI_CARD_TITLE ?>"><?= $q === '' ? 'All patients' : 'Search results' ?></h2>
            <span class="text-xs text-muted-foreground"><?= count($patients) ?> shown<?= count($patients) === 50 ? ' (first 50 — narrow the search)' : '' ?></span>
        </div>
        <div class="overflow-x-auto scroll-area">
            <table class="w-full min-w-[820px] border-collapse text-sm">
                <caption class="sr-only">Patients with reference, contact number, assigned doctor, last observations and open referrals.</caption>
                <thead>
                    <tr class="border-b border-border bg-muted/50 text-left text-[0.68rem] uppercase tracking-[0.07em] text-muted-foreground">
                        <th scope="col" class="px-5 py-2.5 font-semibold">Patient</th>
                        <th scope="col" class="px-3 py-2.5 font-semibold">Contact</th>
                        <th scope="col" class="px-3 py-2.5 font-semibold">Doctor</th>
                        <th scope="col" class="px-3 py-2.5 font-semibold">Last observations</th>
                        <th scope="col" class="px-5 py-2.5 text-right font-semibold"><span class="sr-only">Actions</span></th>
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
                                    <p class="truncate font-medium"><?= e($p['full_name']) ?></p>
                                    <p class="text-xs capitalize text-muted-foreground">
                                        <span class="font-mono"><?= e(patient_code($pid)) ?></span>
                                        · <?= e($p['gender']) ?> · <?= calc_age($p['date_of_birth']) ?> yrs
                                    </p>
                                </div>
                            </div>
                        </td>
                        <td class="px-3 py-3 text-muted-foreground">
                            <span class="tabular"><?= e(phone_display($p['phone'])) ?></span>
                        </td>
                        <td class="px-3 py-3">
                            <?= $p['doctor'] ? e($p['doctor']) : ui_badge('Unassigned', 'warning') ?>
                        </td>
                        <td class="px-3 py-3 text-xs text-muted-foreground">
                            <?= $p['last_vitals'] ? e(date('d M Y, H:i', strtotime($p['last_vitals']))) : 'None recorded' ?>
                            <?php if ((int)$p['open_referrals'] > 0): ?>
                                <div class="mt-1"><?= ui_badge((int)$p['open_referrals'] . ' referral' . ((int)$p['open_referrals'] > 1 ? 's' : '') . ' pending', 'warning') ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-5 py-3">
                            <div class="flex flex-wrap items-center justify-end gap-1.5">
                                <?= ui_button('Vitals', ['href' => 'vitals.php?patient_id=' . $pid, 'variant' => 'outline', 'size' => 'sm']) ?>
                                <?= ui_button('Refer', ['href' => 'referrals.php?patient_id=' . $pid, 'variant' => 'outline', 'size' => 'sm']) ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$patients): ?>
                    <tr><td colspan="5"><?= ui_empty(
                        'users',
                        $q === '' ? 'No patients registered yet' : 'No patient matches that search',
                        $q === '' ? 'Use “Register a patient” to add the first record.' : 'Try a different spelling, the phone number, or the P-0000 reference.'
                    ) ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
