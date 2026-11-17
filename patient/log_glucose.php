<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/readings.php';
require_once __DIR__ . '/../includes/cds_engine.php';
require_role('patient');

$pdo = get_db_connection();
$patientId = (int)$_SESSION['patient_id'];

$stmt = $pdo->prepare('SELECT * FROM patient_thresholds WHERE patient_id = ?');
$stmt->execute([$patientId]);
$thresholds = $stmt->fetch();

$stmt = $pdo->prepare('SELECT preferred_unit FROM patients WHERE patient_id = ?');
$stmt->execute([$patientId]);
$patientRow = $stmt->fetch() ?: [];

$errors = [];
$confirmMsg = null;
$form = [
    'glucose_value' => '',
    'unit' => patient_unit($patientRow),
    'meal_context' => '',
    'logged_at' => date('Y-m-d\TH:i'),
    'symptoms' => '',
];

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
            $stmt = $pdo->prepare('INSERT INTO glucose_readings (patient_id, glucose_value, entered_unit, meal_context, symptoms, was_confirmed, logged_at)
                                   VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING reading_id');
            $stmt->execute([$patientId, $d['value'], $d['unit'], $d['context'], $d['symptoms'] ?: null, $confirmed ? 1 : 0, $d['logged_at']]);
            $readingId = (int)$stmt->fetchColumn();
            log_audit($pdo, (int)$_SESSION['user_id'], 'glucose_readings', 'READING_LOGGED',
                "Patient #{$patientId} logged {$d['value']} mg/dL ({$d['context']}), reading #{$readingId}.");

            // Remember the unit this patient prefers, so their history stays in that unit.
            if ($d['unit'] !== patient_unit($patientRow)) {
                $pdo->prepare('UPDATE patients SET preferred_unit = ? WHERE patient_id = ?')->execute([$d['unit'], $patientId]);
            }

            // Rule engine: flag anything the care team should review.
            $flagged = cds_process_reading($pdo, $patientId);

            $band = reading_band((float)$d['value'], $d['context'], $thresholds);
            $note = match ($band) {
                'severe_low', 'low' => ' This is below your low limit — treat it as your care plan advises and contact your care team if you feel unwell.',
                'very_high'         => ' This is very high — contact your care team if you feel unwell.',
                'high'              => ' This is above your target range.',
                default             => ' This is within your target range.',
            };
            if ($flagged) {
                $note .= ' Your care team has been notified so they can review it.';
            }
            // Lead with the unit the patient actually typed, keep the other one for the clinician.
            $savedText = fmt_glucose((float)$d['value'], $d['unit'], true)
                . ' (' . fmt_glucose((float)$d['value'], $d['unit'] === UNIT_MMOL ? UNIT_MGDL : UNIT_MMOL, true) . ')';
            set_flash($band === 'in_range' ? 'success' : 'warning', 'Reading of ' . $savedText . ' saved.' . $note);
            redirect('patient/history.php');
        }
    }
}

// Field-level messages: the value/time errors sit under their inputs (shadcn FormMessage),
// everything else is surfaced in the summary alert.
$fieldErrors = ['value' => '', 'context' => '', 'time' => '', 'other' => []];
foreach ($errors as $err) {
    if (str_contains($err, 'outside the possible range') || str_contains($err, 'Enter your glucose reading as a number')) {
        $fieldErrors['value'] = $err;
    } elseif (str_contains($err, 'Choose when this reading was taken')) {
        $fieldErrors['context'] = $err;
    } elseif (str_contains($err, 'date and time') || str_contains($err, 'future') || str_contains($err, 'older than 30 days')) {
        $fieldErrors['time'] = $err;
    } else {
        $fieldErrors['other'][] = $err;
    }
}

$unit = normalize_unit($form['unit']);
$bounds = glucose_bounds($unit);
$extremeLow = glucose_from_mgdl(READING_EXTREME_LOW, $unit);
$extremeHigh = glucose_from_mgdl(READING_EXTREME_HIGH, $unit);

$page_title = 'Log a Reading';
$page_description = '';
require __DIR__ . '/../includes/header.php';
?>
<div class="space-y-6">
    <?= ui_page_header(
        'Log a glucose reading',
        'Takes under a minute. Your doctor sees this reading together with your logging consistency, so the data is interpreted with the right level of confidence.',
        ui_button('Glucose history', ['href' => 'history.php', 'variant' => 'outline', 'icon' => 'line-chart'])
    ) ?>

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_19rem]">
        <section class="<?= UI_CARD ?>" aria-labelledby="log-form-title">
            <div class="<?= UI_CARD_HEADER ?>">
                <div>
                    <h2 id="log-form-title" class="<?= UI_CARD_TITLE ?>">Reading details</h2>
                    <p class="mt-1 text-xs text-muted-foreground">All fields except symptoms are required.</p>
                </div>
                <span class="inline-flex items-center gap-1.5 rounded-full bg-primary-subtle px-2.5 py-1 text-[0.7rem] font-semibold text-primary">
                    <?= icon('droplet', 'h-3.5 w-3.5') ?> mg/dL
                </span>
            </div>

            <div class="<?= UI_CARD_BODY ?> space-y-6">
                <?php if ($fieldErrors['other']): ?>
                    <?= ui_alert('destructive', 'This reading was not saved',
                        '<ul class="list-disc space-y-1 pl-4">' . implode('', array_map(fn($x) => '<li>' . e($x) . '</li>', $fieldErrors['other'])) . '</ul>') ?>
                <?php endif; ?>

                <form method="post" id="readingForm" novalidate class="space-y-6">
                    <?= csrf_field() ?>
                    <input type="hidden" name="confirmed" id="confirmed_value" value="0">

                    <!-- Glucose value -->
                    <div class="space-y-2">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <label for="glucose_value" class="<?= UI_LABEL ?>">Glucose value <span class="text-destructive" aria-hidden="true">*</span></label>
                            <fieldset class="inline-flex items-center gap-0.5 rounded-md border border-input bg-white p-0.5" aria-label="Glucose unit">
                                <legend class="sr-only">Unit of this reading</legend>
                                <?php foreach (GLUCOSE_UNITS as $key => $label): ?>
                                    <label class="cursor-pointer rounded px-2.5 py-1 text-[0.7rem] font-semibold text-muted-foreground transition
                                                  has-[:checked]:bg-primary has-[:checked]:text-primary-foreground
                                                  has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring has-[:focus-visible]:ring-offset-1">
                                        <input type="radio" name="unit" value="<?= e($key) ?>" class="peer sr-only" data-unit-option
                                               <?= $unit === $key ? 'checked' : '' ?>>
                                        <?= e($label) ?>
                                    </label>
                                <?php endforeach; ?>
                            </fieldset>
                        </div>
                        <div class="relative">
                            <input id="glucose_value" name="glucose_value" type="number" inputmode="decimal" step="0.1"
                                   min="<?= e((string)$bounds['min']) ?>" max="<?= e((string)$bounds['max']) ?>"
                                   value="<?= e($form['glucose_value']) ?>"
                                   placeholder="<?= $unit === UNIT_MMOL ? 'e.g. 6.2' : 'e.g. 112' ?>"
                                   data-unit="<?= e($unit) ?>"
                                   data-min-mgdl="<?= READING_ABS_MIN ?>" data-max-mgdl="<?= READING_ABS_MAX ?>"
                                   data-min-mmol="<?= e((string)READING_MMOL_MIN) ?>" data-max-mmol="<?= e((string)READING_MMOL_MAX) ?>"
                                   data-low-mgdl="<?= READING_EXTREME_LOW ?>" data-high-mgdl="<?= READING_EXTREME_HIGH ?>"
                                   data-low-mmol="<?= e(fmt_number(glucose_from_mgdl(READING_EXTREME_LOW, UNIT_MMOL))) ?>"
                                   data-high-mmol="<?= e(fmt_number(glucose_from_mgdl(READING_EXTREME_HIGH, UNIT_MMOL))) ?>"
                                   class="<?= UI_INPUT ?> h-14 pr-24 text-2xl font-semibold tabular"
                                   aria-describedby="glucose_value-hint glucose_value-error"
                                   aria-invalid="<?= $fieldErrors['value'] ? 'true' : 'false' ?>"
                                   required aria-required="true" autofocus
                                   <?= $fieldErrors['value'] ? 'autofocus' : '' ?>>
                            <span id="glucose-unit-label" class="pointer-events-none absolute inset-y-0 right-4 flex items-center text-sm font-medium text-muted-foreground"><?= e($unit) ?></span>
                        </div>
                        <p id="glucose_value-hint" class="<?= UI_HINT ?>">Enter the number exactly as shown on your glucometer. <span id="glucose-range-hint">Valid range <?= e(fmt_number($bounds['min'])) ?>–<?= e(fmt_number($bounds['max'])) ?> <?= e($unit) ?>.</span></p>
                        <p id="glucose_value-error" role="alert"
                           class="flex items-start gap-1.5 text-xs font-medium text-destructive"
                           <?= $fieldErrors['value'] ? '' : 'hidden' ?>>
                            <?= icon('alert-triangle', 'mt-px h-3.5 w-3.5 shrink-0') ?>
                            <span><?= e($fieldErrors['value'] ?: 'Enter your glucose reading before saving.') ?></span>
                        </p>
                    </div>

                    <!-- Meal context (mandatory toggle group) -->
                    <fieldset class="space-y-2" <?= $fieldErrors['context'] ? 'aria-invalid="true"' : '' ?>>
                        <legend class="<?= UI_LABEL ?> mb-2">When was it taken? <span class="text-destructive" aria-hidden="true">*</span></legend>
                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                            <?php foreach (MEAL_CONTEXTS as $key => $label): ?>
                                <label class="flex cursor-pointer items-center gap-2 rounded-md border border-input bg-white px-3 py-2.5 text-sm font-medium text-slate-600 transition
                                              hover:border-primary/40 hover:bg-primary-subtle/50
                                              has-[:checked]:border-primary has-[:checked]:bg-primary-subtle has-[:checked]:text-primary
                                              has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring has-[:focus-visible]:ring-offset-2">
                                    <input type="radio" name="meal_context" value="<?= e($key) ?>"
                                           class="peer sr-only" <?= $form['meal_context'] === $key ? 'checked' : '' ?> <?= $key === 'fasting' ? 'required' : '' ?>>
                                    <span class="grid h-4 w-4 shrink-0 place-items-center rounded-full border border-current transition-all peer-checked:border-[5px]"></span>
                                    <span class="leading-tight"><?= e($label) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <p role="alert" class="flex items-start gap-1.5 text-xs font-medium text-destructive" <?= $fieldErrors['context'] ? '' : 'hidden' ?>>
                            <?= icon('alert-triangle', 'mt-px h-3.5 w-3.5 shrink-0') ?><span><?= e($fieldErrors['context'] ?: 'Choose when this reading was taken.') ?></span>
                        </p>
                    </fieldset>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <?= ui_field([
                            'id' => 'logged_at', 'name' => 'logged_at', 'type' => 'datetime-local',
                            'label' => 'Date & time of reading', 'value' => $form['logged_at'],
                            'error' => $fieldErrors['time'], 'required' => true,
                            'hint' => 'Readings up to 30 days old can be added.',
                            'attrs' => 'max="' . date('Y-m-d\TH:i') . '" min="' . date('Y-m-d\TH:i', strtotime('-30 days')) . '"',
                        ]) ?>
                        <?= ui_field([
                            'id' => 'symptoms', 'name' => 'symptoms', 'label' => 'Symptoms (optional)',
                            'value' => $form['symptoms'], 'attrs' => 'maxlength="255" list="symptomList" placeholder="e.g. dizzy, sweating"',
                            'hint' => 'Helps your care team interpret the reading.',
                        ]) ?>
                        <datalist id="symptomList">
                            <option value="Dizzy"></option><option value="Sweating"></option><option value="Shaky"></option>
                            <option value="Very thirsty"></option><option value="Blurred vision"></option><option value="Tired"></option>
                        </datalist>
                    </div>

                    <div class="flex flex-col gap-3 border-t border-border pt-5 sm:flex-row sm:items-center sm:justify-between">
                        <p class="flex items-start gap-2 text-xs leading-relaxed text-muted-foreground sm:max-w-sm">
                            <?= icon('shield-check', 'mt-0.5 h-3.5 w-3.5 shrink-0 text-success') ?>
                            Values below <?= e(fmt_number($extremeLow)) ?> or above <?= e(fmt_number($extremeHigh)) ?> <?= e($unit) ?> ask you to confirm before saving.
                        </p>
                        <?= ui_button('Save reading', ['type' => 'submit', 'icon' => 'check', 'size' => 'lg', 'class' => 'w-full sm:w-auto']) ?>
                    </div>
                </form>
            </div>
        </section>

        <aside class="space-y-4">
            <div class="<?= UI_CARD ?>">
                <div class="<?= UI_CARD_HEADER ?>">
                    <h2 class="<?= UI_CARD_TITLE ?>">Your targets</h2>
                    <span class="text-[0.7rem] font-medium text-muted-foreground"><?= e($unit) ?></span>
                </div>
                <div class="<?= UI_CARD_BODY ?> space-y-3 text-sm">
                    <?php foreach ([
                        ['Fasting / pre-meal', fmt_glucose((float)$thresholds['target_fasting_min'], $unit) . '–' . fmt_glucose((float)$thresholds['target_fasting_max'], $unit), 'bg-success'],
                        ['After meals', '< ' . fmt_glucose((float)$thresholds['target_postprandial_max'], $unit), 'bg-primary'],
                        ['Low (hypo)', '< ' . fmt_glucose((float)$thresholds['hypo_threshold'], $unit), 'bg-warning'],
                        ['Severe low', '< ' . fmt_glucose((float)$thresholds['severe_hypo_threshold'], $unit), 'bg-destructive'],
                    ] as [$label, $range, $dot]): ?>
                        <div class="flex items-center justify-between gap-3">
                            <span class="flex items-center gap-2 text-muted-foreground">
                                <span class="h-2 w-2 rounded-full <?= $dot ?>"></span><?= e($label) ?>
                            </span>
                            <span class="font-semibold tabular text-foreground"><?= e($range) ?></span>
                        </div>
                    <?php endforeach; ?>
                    <p class="pt-2 text-xs leading-relaxed text-muted-foreground">Set from ADA guidance. Your doctor can adjust them for your case.</p>
                </div>
            </div>

            <?= ui_alert('warning', 'Feeling unwell right now?',
                'Do not wait for this system. Follow your care plan and seek help immediately if you have a severe low or high.') ?>
        </aside>
    </div>
</div>

<?php
$dialogBody = '<div class="space-y-3">'
    . '<p id="confirm-reading-text" class="text-sm leading-relaxed text-foreground">' . e($confirmMsg ?: '') . '</p>'
    . '<div class="rounded-md border border-warning/30 bg-warning-subtle px-3 py-2 text-xs leading-relaxed text-warning">'
    . '<strong class="font-semibold">Before confirming:</strong> check that the number matches your glucometer exactly and that the units are mg/dL.'
    . '</div></div>';
$dialogFooter = ui_button('Go back and edit', ['variant' => 'outline', 'attrs' => 'data-dialog-close'])
    . ui_button('Yes, the reading is correct', ['variant' => 'primary', 'icon' => 'check', 'attrs' => 'id="confirm-reading-yes"']);
echo ui_dialog('confirm-reading-dialog', 'Please confirm this reading',
    'Extreme readings are saved only after you confirm them.', $dialogBody, $dialogFooter,
    ['icon' => 'alert-triangle', 'autoshow' => (bool)$confirmMsg]);
?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
