<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/readings.php';
require_once __DIR__ . '/../includes/cds_engine.php';
require_role('patient');

$pdo = get_db_connection();
$patientId = (int)$_SESSION['patient_id'];

// A patient may correct a reading for a short window after logging it. Edits are
// audited and the rule engine is re-run, because a wrong value can raise a false alert.
const READING_EDIT_HOURS = 24;
const HISTORY_PER_PAGE = 20;

$days = (int)($_GET['days'] ?? 14);
if (!in_array($days, [7, 14, 30, 90], true)) {
    $days = 14;
}

$stmt = $pdo->prepare('SELECT * FROM patient_thresholds WHERE patient_id = ?');
$stmt->execute([$patientId]);
$t = $stmt->fetch();

$stmt = $pdo->prepare('SELECT preferred_unit FROM patients WHERE patient_id = ?');
$stmt->execute([$patientId]);
$unit = patient_unit($stmt->fetch() ?: []);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_reading') {
    $readingId = (int)($_POST['reading_id'] ?? 0);
    $errors = [];

    if (!csrf_valid()) {
        $errors[] = 'Your session expired. Please try again.';
    }
    $stmt = $pdo->prepare('SELECT * FROM glucose_readings WHERE reading_id = ? AND patient_id = ?');
    $stmt->execute([$readingId, $patientId]);
    $reading = $stmt->fetch();

    if (!$reading) {
        $errors[] = 'That reading is not on your record.';
    } elseif (strtotime($reading['created_at']) < time() - READING_EDIT_HOURS * 3600) {
        $errors[] = 'Readings can only be corrected within ' . READING_EDIT_HOURS . ' hours of logging them. Ask your care team to review older entries.';
    }

    $enteredUnit = normalize_unit($_POST['unit'] ?? UNIT_MGDL);
    $value = is_numeric($_POST['glucose_value'] ?? '') ? round((float)$_POST['glucose_value'], 1) : null;
    $context = (string)($_POST['meal_context'] ?? '');
    $symptoms = trim((string)($_POST['symptoms'] ?? ''));
    $loggedAt = null;
    $dt = DateTime::createFromFormat('Y-m-d\TH:i', (string)($_POST['logged_at'] ?? ''));

    if ($value === null) {
        $errors[] = 'Enter the reading as a number.';
    } else {
        $mgdl = round(glucose_to_mgdl($value, $enteredUnit), 1);
        if ($mgdl < READING_ABS_MIN || $mgdl > READING_ABS_MAX) {
            $b = glucose_bounds($enteredUnit);
            $errors[] = 'The value is outside the possible range (' . fmt_number($b['min']) . '–' . fmt_number($b['max']) . ' ' . $enteredUnit . ').';
        }
    }
    if (!isset(MEAL_CONTEXTS[$context])) {
        $errors[] = 'Choose when this reading was taken.';
    }
    if (!$dt) {
        $errors[] = 'Enter a valid date and time.';
    } elseif ($dt > new DateTime('+5 minutes')) {
        $errors[] = 'The reading time cannot be in the future.';
    } elseif ($dt < new DateTime('-30 days')) {
        $errors[] = 'Readings older than 30 days cannot be added.';
    } else {
        $loggedAt = $dt->format('Y-m-d H:i:00');
    }
    if (mb_strlen($symptoms) > 255) {
        $errors[] = 'Symptoms must be 255 characters or fewer.';
    }

    if ($errors) {
        foreach ($errors as $err) {
            set_flash('danger', $err);
        }
    } else {
        $mgdl = round(glucose_to_mgdl($value, $enteredUnit), 1);
        $pdo->prepare('UPDATE glucose_readings
                          SET glucose_value = ?, entered_unit = ?, meal_context = ?, symptoms = ?, logged_at = ?,
                              was_edited = 1, edited_at = NOW()
                        WHERE reading_id = ? AND patient_id = ?')
            ->execute([$mgdl, $enteredUnit, $context, $symptoms !== '' ? $symptoms : null, $loggedAt, $readingId, $patientId]);
        log_audit($pdo, (int)$_SESSION['user_id'], 'glucose_readings', 'READING_EDIT',
            "Patient #{$patientId} corrected reading #{$readingId}: "
            . fmt_glucose((float)$reading['glucose_value'], $unit, true) . ' / ' . $reading['meal_context'] . ' / ' . date('d M H:i', strtotime($reading['logged_at']))
            . ' → ' . fmt_glucose($mgdl, $unit, true) . ' / ' . $context . ' / ' . date('d M H:i', strtotime($loggedAt)) . '.');
        cds_reassess_patient($pdo, $patientId);
        set_flash('success', 'Reading updated. Any alerts based on it were recalculated.');
    }
    redirect('patient/history.php?' . http_build_query(['days' => $days]));
}

// CSV export of the selected window.
if (($_GET['export'] ?? '') === 'csv') {
    $stmt = $pdo->prepare('SELECT logged_at, glucose_value, entered_unit, meal_context, symptoms, was_edited
                           FROM glucose_readings WHERE patient_id = ? AND logged_at >= (NOW() - make_interval(days => ' . (int)$days . '))
                           ORDER BY logged_at DESC');
    $stmt->execute([$patientId]);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="glucocare-readings-' . date('Ymd') . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Logged at', 'Value (' . $unit . ')', 'Entered unit', 'Context', 'Symptoms', 'Corrected']);
    foreach ($stmt as $row) {
        fputcsv($out, [
            date('Y-m-d H:i', strtotime($row['logged_at'])),
            fmt_number(glucose_from_mgdl((float)$row['glucose_value'], $unit)),
            $row['entered_unit'],
            MEAL_CONTEXTS[$row['meal_context']] ?? $row['meal_context'],
            (string)($row['symptoms'] ?? ''),
            $row['was_edited'] ? 'yes' : 'no',
        ]);
    }
    exit;
}

$page = max(1, (int)($_GET['page'] ?? 1));
$stmt = $pdo->prepare('SELECT COUNT(*) FROM glucose_readings WHERE patient_id = ? AND logged_at >= (NOW() - make_interval(days => ' . (int)$days . '))');
$stmt->execute([$patientId]);
$total = (int)$stmt->fetchColumn();

$readings = [];
if ($total > 0) {
    $stmt = $pdo->prepare('SELECT * FROM glucose_readings WHERE patient_id = ? AND logged_at >= (NOW() - make_interval(days => ' . (int)$days . '))
                           ORDER BY logged_at DESC LIMIT ' . HISTORY_PER_PAGE . ' OFFSET ' . (($page - 1) * HISTORY_PER_PAGE));
    $stmt->execute([$patientId]);
    $readings = $stmt->fetchAll();
}
if (!$readings && $page > 1) {
    redirect('patient/history.php?' . http_build_query(['days' => $days, 'page' => max(1, (int)ceil($total / HISTORY_PER_PAGE))]));
}

// Chart and summary use the whole window, not just the visible page.
$stmt = $pdo->prepare('SELECT * FROM glucose_readings WHERE patient_id = ? AND logged_at >= (NOW() - make_interval(days => ' . (int)$days . ')) ORDER BY logged_at DESC');
$stmt->execute([$patientId]);
$window = $stmt->fetchAll();
$values = array_map(fn($r) => (float)$r['glucose_value'], $window);
$inRange = 0;
foreach ($window as $r) {
    if (reading_band((float)$r['glucose_value'], $r['meal_context'], $t) === 'in_range') {
        $inRange++;
    }
}

$page_title = 'Glucose History';
$use_charts = true;
$page_scripts = '<script src="' . BASE_URL . 'assets/js/glucose-chart.js"></script><script nonce="' . (defined('APP_CSP_NONCE') ? APP_CSP_NONCE : '') . '">'
    . 'glucoseChart("trend", ' . json_encode(chart_rows($window, $t, $unit)) . ', ' . json_encode(chart_limits($t, $unit)) . ');</script>';
require __DIR__ . '/../includes/header.php';
?>
<div class="space-y-6">
    <?= ui_page_header(
        'Glucose history',
        'Every reading you have logged, plotted against your target ranges.',
        ui_button('New reading', ['href' => 'log_glucose.php', 'icon' => 'plus'])
    ) ?>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="inline-flex items-center gap-1 rounded-lg border border-border bg-muted/60 p-1" role="tablist" aria-label="History window">
            <?php foreach ([7, 14, 30, 90] as $d): ?>
                <a href="?days=<?= $d ?>" role="tab" aria-selected="<?= $d === $days ? 'true' : 'false' ?>"
                   class="inline-flex h-8 items-center rounded-md px-3 text-xs font-medium transition-colors <?= $d === $days ? 'bg-white text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground' ?>">
                    <?= $d ?> days
                </a>
            <?php endforeach; ?>
        </div>
        <p class="text-xs text-muted-foreground"><?= count($window) ?> readings in this period</p>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <?php foreach ([
            ['Readings', count($window), 'line-chart', ''],
            ['Average', $values ? fmt_glucose(array_sum($values) / count($values), $unit) : '—', 'activity', $unit],
            ['Lowest', $values ? fmt_glucose(min($values), $unit) : '—', 'trending-down', $unit],
            ['Highest', $values ? fmt_glucose(max($values), $unit) : '—', 'trending-up', $unit],
        ] as [$label, $value, $iconName, $unitLabel]): ?>
            <div class="<?= UI_CARD ?> px-5 py-4">
                <div class="flex items-start justify-between gap-2">
                    <p class="text-[0.7rem] font-semibold uppercase tracking-[0.07em] text-muted-foreground"><?= e($label) ?></p>
                    <span class="text-slate-400"><?= icon($iconName, 'h-4 w-4') ?></span>
                </div>
                <p class="mt-1.5 text-2xl font-bold tabular tracking-tight"><?= e((string)$value) ?>
                    <?php if ($unitLabel && $value !== '—'): ?><span class="text-xs font-medium text-muted-foreground"><?= e($unitLabel) ?></span><?php endif; ?>
                </p>
            </div>
        <?php endforeach; ?>
    </div>

    <section class="<?= UI_CARD ?>" aria-labelledby="trend-title">
        <div class="<?= UI_CARD_HEADER ?>">
            <div>
                <h2 id="trend-title" class="<?= UI_CARD_TITLE ?>">Trend — last <?= $days ?> days</h2>
                <p class="mt-1 text-xs text-muted-foreground">Shaded band = ADA pre-meal target (<?= e(fmt_glucose((float)$t['target_fasting_min'], $unit)) ?>–<?= e(fmt_glucose((float)$t['target_fasting_max'], $unit)) ?> <?= e($unit) ?>)</p>
            </div>
            <?= ui_badge($window ? round($inRange / max(1, count($window)) * 100) . '% in range' : 'No data', $window && $inRange / max(1, count($window)) >= 0.7 ? 'success' : 'secondary') ?>
        </div>
        <div class="<?= UI_CARD_BODY ?>">
            <?php if ($window): ?>
                <div class="h-[340px] w-full"><canvas id="trend" role="img" aria-label="Glucose readings over the selected period against target ranges"></canvas></div>
            <?php else: ?>
                <?= ui_empty('line-chart', 'No readings in this period', 'Try a longer window or log a new reading.') ?>
            <?php endif; ?>
        </div>
    </section>

    <section class="<?= UI_CARD ?>" aria-labelledby="readings-title">
        <div class="<?= UI_CARD_HEADER ?>">
            <div>
                <h2 id="readings-title" class="<?= UI_CARD_TITLE ?>">All readings</h2>
                <p class="mt-1 text-xs text-muted-foreground">Mistyped a number? You can correct a reading within <?= READING_EDIT_HOURS ?> hours.</p>
            </div>
            <?= ui_button('Export CSV', ['href' => '?days=' . $days . '&export=csv', 'variant' => 'outline', 'size' => 'sm', 'icon' => 'clipboard-list']) ?>
        </div>
        <div class="overflow-x-auto scroll-area">
            <table class="w-full min-w-[680px] border-collapse text-sm">
                <caption class="sr-only">All glucose readings in the selected period.</caption>
                <thead>
                    <tr class="border-b border-border bg-muted/50 text-left text-[0.68rem] uppercase tracking-[0.07em] text-muted-foreground">
                        <th scope="col" class="px-5 py-2.5 font-semibold">Date &amp; time</th>
                        <th scope="col" class="px-3 py-2.5 font-semibold">Value</th>
                        <th scope="col" class="px-3 py-2.5 font-semibold">Context</th>
                        <th scope="col" class="px-3 py-2.5 font-semibold">Status</th>
                        <th scope="col" class="px-5 py-2.5 font-semibold">Symptoms</th>
                        <th scope="col" class="px-5 py-2.5 text-right font-semibold"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                <?php foreach ($readings as $r):
                    $band = reading_band((float)$r['glucose_value'], $r['meal_context'], $t);
                    [$bandText] = band_label($band);
                    $editable = strtotime($r['created_at']) >= time() - READING_EDIT_HOURS * 3600; ?>
                    <tr class="hover:bg-muted/40">
                        <td class="whitespace-nowrap px-5 py-2.5 text-muted-foreground">
                            <?= e(date('d M Y, H:i', strtotime($r['logged_at']))) ?>
                            <?php if ($r['was_edited']): ?><span class="ml-1"><?= ui_badge('Corrected', 'secondary') ?></span><?php endif; ?>
                        </td>
                        <td class="px-3 py-2.5 font-semibold tabular">
                            <?= e(fmt_glucose((float)$r['glucose_value'], $unit)) ?> <span class="text-xs font-normal text-muted-foreground"><?= e($unit) ?></span>
                            <?php if (normalize_unit($r['entered_unit'] ?? null) !== $unit): ?>
                                <span class="block text-[0.65rem] font-normal text-muted-foreground">entered <?= e(fmt_glucose((float)$r['glucose_value'], normalize_unit($r['entered_unit'] ?? null), true)) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="px-3 py-2.5 text-muted-foreground"><?= e(MEAL_CONTEXTS[$r['meal_context']]) ?></td>
                        <td class="px-3 py-2.5"><?= band_badge($band, $bandText) ?></td>
                        <td class="px-5 py-2.5 text-xs text-muted-foreground"><?= e($r['symptoms'] ?? '—') ?></td>
                        <td class="px-5 py-2.5 text-right">
                            <?php if ($editable): ?>
                                <button type="button" data-dialog-target="edit-reading-<?= (int)$r['reading_id'] ?>"
                                        aria-label="Correct reading from <?= e(date('d M Y, H:i', strtotime($r['logged_at']))) ?>"
                                        class="inline-flex h-7 items-center gap-1 rounded-md border border-border bg-white px-2 text-[0.7rem] font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground">
                                    <?= icon('pencil', 'h-3 w-3') ?> Correct
                                </button>
                            <?php else: ?>
                                <span class="text-[0.7rem] text-muted-foreground/60">Locked</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$readings): ?>
                    <tr><td colspan="6"><?= ui_empty('droplet', 'Nothing logged in this period', 'Readings you log will appear here immediately.') ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?= ui_pager($page, HISTORY_PER_PAGE, $total, ['days' => $days]) ?>
    </section>
</div>

<?php
// One correction dialog per editable row.
foreach ($readings as $r):
    if (strtotime($r['created_at']) < time() - READING_EDIT_HOURS * 3600) {
        continue;
    }
    $rid = (int)$r['reading_id'];
    $body = '<div class="grid gap-4 sm:grid-cols-2">'
        . ui_field(['id' => 'edit-value-' . $rid, 'name' => 'glucose_value', 'type' => 'number',
            'label' => 'Reading (' . $unit . ')', 'value' => fmt_number(glucose_from_mgdl((float)$r['glucose_value'], $unit)),
            'required' => true, 'attrs' => 'step="0.1" min="' . glucose_bounds($unit)['min'] . '" max="' . glucose_bounds($unit)['max'] . '" inputmode="decimal"'])
        . ui_field(['id' => 'edit-at-' . $rid, 'name' => 'logged_at', 'type' => 'datetime-local', 'label' => 'Taken at',
            'value' => date('Y-m-d\TH:i', strtotime($r['logged_at'])), 'required' => true])
        . '<input type="hidden" name="unit" value="' . e($unit) . '">'
        . '<div class="sm:col-span-2">' . ui_field(['id' => 'edit-ctx-' . $rid, 'name' => 'meal_context', 'tag' => 'select',
            'label' => 'When was it taken', 'required' => true,
            'children' => implode('', array_map(fn($k, $v) => '<option value="' . e($k) . '"' . ($r['meal_context'] === $k ? ' selected' : '') . '>' . e($v) . '</option>',
                array_keys(MEAL_CONTEXTS), array_values(MEAL_CONTEXTS)))]) . '</div>'
        . '<div class="sm:col-span-2">' . ui_field(['id' => 'edit-sym-' . $rid, 'name' => 'symptoms', 'label' => 'Symptoms (optional)',
            'value' => (string)($r['symptoms'] ?? ''), 'attrs' => 'maxlength="255"']) . '</div>'
        . '</div>'
        . '<p class="mt-4 flex items-start gap-2 text-xs leading-relaxed text-muted-foreground">'
        . icon('info', 'mt-0.5 h-3.5 w-3.5 shrink-0')
        . '<span>Corrections are recorded in the audit trail and the rule engine re-checks your alerts afterwards.</span></p>';

    $footer = ui_button('Cancel', ['variant' => 'outline', 'attrs' => 'data-dialog-close'])
        . ui_button('Save correction', ['type' => 'submit', 'icon' => 'check']);

    echo '<dialog id="edit-reading-' . $rid . '" class="app-dialog" aria-labelledby="edit-reading-' . $rid . '-title">'
        . '<form method="post">' . csrf_field()
        . '<input type="hidden" name="action" value="update_reading">'
        . '<input type="hidden" name="reading_id" value="' . $rid . '">'
        . '<div class="flex items-start justify-between gap-4 px-6 pb-4 pt-6">'
        . '<div class="flex items-start gap-3">'
        . '<span class="mt-0.5 grid h-9 w-9 shrink-0 place-items-center rounded-full bg-primary/10 text-primary">' . icon('pencil', 'h-4 w-4') . '</span>'
        . '<div><h2 id="edit-reading-' . $rid . '-title" class="text-base font-semibold leading-6 text-foreground">Correct this reading</h2>'
        . '<p class="mt-1 text-sm leading-relaxed text-muted-foreground">Logged ' . e(date('d M Y, H:i', strtotime($r['logged_at']))) . '</p></div></div>'
        . '<button type="button" data-dialog-close aria-label="Close dialog" class="grid h-8 w-8 shrink-0 place-items-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground">' . icon('x', 'h-4 w-4') . '</button>'
        . '</div>'
        . '<div class="app-dialog-body border-t border-border">' . $body . '</div>'
        . '<div class="flex flex-wrap items-center justify-end gap-2 border-t border-border bg-muted/40 px-6 py-4">' . $footer . '</div>'
        . '</form></dialog>';
endforeach;
?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
