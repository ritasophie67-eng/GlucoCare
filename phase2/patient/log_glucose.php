<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/readings.php';
require_role('patient');

$pdo = get_db_connection();
$patientId = (int)$_SESSION['patient_id'];

$stmt = $pdo->prepare('SELECT * FROM patient_thresholds WHERE patient_id = ?');
$stmt->execute([$patientId]);
$thresholds = $stmt->fetch();

$errors = [];
$confirmMsg = null;
$form = ['glucose_value' => '', 'meal_context' => '', 'logged_at' => date('Y-m-d\TH:i'), 'symptoms' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($form as $k => $_) {
        $form[$k] = trim((string)($_POST[$k] ?? ''));
    }
    if (!csrf_valid()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $confirmed = ($_POST['confirmed'] ?? '') === '1';
        $result = validate_reading($pdo, $patientId, $_POST, $confirmed);
        $errors = $result['errors'];
        $confirmMsg = $result['confirm'];

        if (!$errors && !$confirmMsg) {
            $d = $result['data'];
            $pdo->prepare('INSERT INTO glucose_readings (patient_id, glucose_value, meal_context, symptoms, was_confirmed, logged_at)
                           VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$patientId, $d['value'], $d['context'], $d['symptoms'] ?: null, $confirmed ? 1 : 0, $d['logged_at']]);
            $readingId = (int)$pdo->lastInsertId();
            log_audit($pdo, (int)$_SESSION['user_id'], 'glucose_readings', 'READING_LOGGED',
                "Patient #{$patientId} logged {$d['value']} mg/dL ({$d['context']}), reading #{$readingId}.");

            $band = reading_band((float)$d['value'], $d['context'], $thresholds);
            $note = match ($band) {
                'severe_low', 'low' => ' This is below your low limit — treat it as your care plan advises and contact your care team if you feel unwell.',
                'very_high'         => ' This is very high — contact your care team if you feel unwell.',
                'high'              => ' This is above your target range.',
                default             => ' This is within your target range.',
            };
            set_flash($band === 'in_range' ? 'success' : 'warning', "Reading of {$d['value']} mg/dL saved." . $note);
            redirect('patient/history.php');
        }
    }
}

$page_title = 'Log a Reading';
$page_scripts = <<<'HTML'
<script>
(function () {
    const form = document.getElementById('readingForm');
    const modalEl = document.getElementById('confirmModal');
    const modal = new bootstrap.Modal(modalEl);
    const value = document.getElementById('glucose_value');
    const msg = document.getElementById('confirmText');
    let confirmed = document.getElementById('confirmed');

    function extremeMessage(v) {
        if (v < 50)  return 'You entered ' + v + ' mg/dL (very low). Please confirm this glucometer reading before saving.';
        if (v > 300) return 'You entered ' + v + ' mg/dL (very high). Please confirm this glucometer reading before saving.';
        return null;
    }
    form.addEventListener('submit', function (ev) {
        const v = parseFloat(value.value);
        if (confirmed.value !== '1' && !isNaN(v) && v >= 20 && v <= 600) {
            const m = extremeMessage(v);
            if (m) { ev.preventDefault(); msg.textContent = m; modal.show(); }
        }
    });
    document.getElementById('confirmYes').addEventListener('click', function () { confirmed.value = '1'; form.submit(); });
    value.addEventListener('input', function () { confirmed.value = '0'; });
    // Server-side fallback: re-open the modal when the server asked for confirmation.
    if (window.SERVER_CONFIRM) { msg.textContent = window.SERVER_CONFIRM; modal.show(); }
})();
</script>
HTML;
if ($confirmMsg) {
    $page_scripts = '<script>window.SERVER_CONFIRM = ' . json_encode($confirmMsg) . ';</script>' . $page_scripts;
}
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
    <div><h2>Log a glucose reading</h2><p>Takes under a minute. Your doctor sees this reading and your logging consistency.</p></div>
</div>

<div class="row justify-content-center">
    <div class="col-lg-7 col-xl-6">
        <?php foreach ($errors as $err): ?>
            <div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i> <?= e($err) ?></div>
        <?php endforeach; ?>

        <div class="card">
            <div class="card-body p-4">
                <form method="post" id="readingForm" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="confirmed" id="confirmed" value="0">

                    <div class="mb-3">
                        <label class="form-label" for="glucose_value">Glucose value (mg/dL)</label>
                        <input class="form-control form-control-lg" id="glucose_value" name="glucose_value" type="number" step="0.1" min="20" max="600"
                               value="<?= e($form['glucose_value']) ?>" placeholder="e.g. 112" required autofocus>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="meal_context">When was it taken?</label>
                        <select class="form-select" id="meal_context" name="meal_context" required>
                            <option value="">Select…</option>
                            <?php foreach (MEAL_CONTEXTS as $key => $label): ?>
                                <option value="<?= $key ?>" <?= $form['meal_context'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="logged_at">Date &amp; time of reading</label>
                        <input class="form-control" id="logged_at" name="logged_at" type="datetime-local"
                               max="<?= date('Y-m-d\TH:i') ?>" min="<?= date('Y-m-d\TH:i', strtotime('-30 days')) ?>" value="<?= e($form['logged_at']) ?>" required>
                    </div>
                    <div class="mb-4">
                        <label class="form-label" for="symptoms">Symptoms <span class="text-muted fw-normal">(optional)</span></label>
                        <input class="form-control" id="symptoms" name="symptoms" maxlength="255" list="symptomList"
                               value="<?= e($form['symptoms']) ?>" placeholder="e.g. dizzy, sweating, thirsty">
                        <datalist id="symptomList">
                            <option value="Dizzy"><option value="Sweating"><option value="Shaky"><option value="Very thirsty"><option value="Blurred vision"><option value="Tired">
                        </datalist>
                    </div>
                    <button class="btn btn-primary w-100 py-2" type="submit"><i class="bi bi-check2-circle me-1"></i>Save reading</button>
                </form>
            </div>
        </div>

        <p class="small text-muted mt-3">
            Valid readings are 20–600 mg/dL. Very low (&lt; 50) or very high (&gt; 300) values ask you to confirm before saving.
            If you feel unwell, follow your care plan or seek help immediately — do not wait for this system.
        </p>
    </div>
</div>

<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title"><i class="bi bi-exclamation-triangle-fill text-warning me-2"></i>Please confirm this reading</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body" id="confirmText"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-primary" data-bs-dismiss="modal">Go back and edit</button>
                <button type="button" class="btn btn-primary" id="confirmYes">Yes, the reading is correct</button>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
