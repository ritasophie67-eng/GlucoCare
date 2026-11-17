<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/readings.php';
require_role('patient');

$pdo = get_db_connection();
$patientId = (int)$_SESSION['patient_id'];

$stmt = $pdo->prepare('
    SELECT p.*, u.created_at AS registered_at, c.full_name AS doctor_name, c.specialization,
           t.target_fasting_min, t.target_fasting_max, t.target_postprandial_max, t.hypo_threshold,
           t.severe_hypo_threshold, t.critical_high_threshold
    FROM patients p
    JOIN users u ON u.user_id = p.user_id
    LEFT JOIN clinicians c ON c.clinician_id = p.clinician_id
    LEFT JOIN patient_thresholds t ON t.patient_id = p.patient_id
    WHERE p.patient_id = ?');
$stmt->execute([$patientId]);
$me = $stmt->fetch();

$stmt = $pdo->prepare('SELECT * FROM glucose_readings WHERE patient_id = ? ORDER BY logged_at DESC LIMIT 14');
$stmt->execute([$patientId]);
$recent = $stmt->fetchAll();
$latest = $recent[0] ?? null;

$adh7  = adherence_stats($pdo, $patientId, $me['registered_at'], 7);
$adh30 = adherence_stats($pdo, $patientId, $me['registered_at'], 30);
$gapHours = hours_since_last_reading($pdo, $patientId);

$inRange = 0;
foreach ($recent as $r) {
    if (reading_band((float)$r['glucose_value'], $r['meal_context'], $me) === 'in_range') {
        $inRange++;
    }
}
$inRangePct = $recent ? round($inRange / count($recent) * 100) : null;
$unit = patient_unit($me);

$page_title = 'My Dashboard';
$use_charts = true;
$page_scripts = $recent ? '<script src="' . BASE_URL . 'assets/js/glucose-chart.js"></script><script nonce="' . (defined('APP_CSP_NONCE') ? APP_CSP_NONCE : '') . '">'
    . 'glucoseChart("mini", ' . json_encode(chart_rows($recent, $me, $unit)) . ', ' . json_encode(chart_limits($me, $unit)) . ');</script>' : '';
require __DIR__ . '/../includes/header.php';
?>
<div class="space-y-6">
    <!-- Greeting / primary action -->
    <section class="relative overflow-hidden rounded-lg border border-primary/20 bg-gradient-to-br from-primary to-sky-600 px-5 py-6 text-primary-foreground shadow-card sm:px-7">
        <div class="pointer-events-none absolute -right-10 -top-16 h-52 w-52 rounded-full bg-white/10"></div>
        <div class="relative flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0">
                <p class="text-[0.7rem] font-semibold uppercase tracking-[0.12em] text-white/70"><?= e(date('l, j F')) ?></p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight">Hello, <?= e(explode(' ', $me['full_name'])[0]) ?></h1>
                <p class="mt-1.5 text-sm text-white/85">
                    <?php if ($me['doctor_name']): ?>
                        Your care doctor is <strong class="font-semibold text-white"><?= e($me['doctor_name']) ?></strong> · <?= e($me['specialization']) ?>
                    <?php else: ?>
                        Your care team has not assigned a doctor to you yet.
                    <?php endif; ?>
                </p>
            </div>
            <a href="log_glucose.php" class="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-md bg-white px-5 text-sm font-semibold text-primary shadow-sm transition hover:bg-white/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-primary">
                <?= icon('plus', 'h-4 w-4') ?> Log a reading
            </a>
        </div>
    </section>

    <?php if ($gapHours === null || $gapHours >= MISSING_ALERT_HOURS): ?>
        <?= ui_alert('warning', $gapHours === null ? 'You have not logged any readings yet.' : "It has been {$gapHours} hours since your last reading.",
            'Regular readings are what let your care team spot patterns. Aim for ' . TARGET_READINGS_PER_DAY . ' a day.') ?>
    <?php endif; ?>

    <div class="grid gap-4 lg:grid-cols-3">
        <!-- Latest reading -->
        <section class="<?= UI_CARD ?>">
            <div class="<?= UI_CARD_HEADER ?>">
                <h2 class="<?= UI_CARD_TITLE ?>">Latest reading</h2>
                <?= icon('droplet', 'h-4 w-4 text-primary') ?>
            </div>
            <div class="<?= UI_CARD_BODY ?>">
                <?php if ($latest):
                    $band = reading_band((float)$latest['glucose_value'], $latest['meal_context'], $me);
                    [$bandText] = band_label($band); ?>
                    <div class="flex items-end gap-1.5">
                        <span class="text-4xl font-bold leading-none tabular tracking-tight"><?= e(fmt_glucose((float)$latest['glucose_value'], $unit)) ?></span>
                        <span class="pb-1 text-sm font-medium text-muted-foreground"><?= e($unit) ?></span>
                    </div>
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <?= band_badge($band, $bandText) ?>
                        <?= ui_badge(MEAL_CONTEXTS[$latest['meal_context']], 'secondary') ?>
                    </div>
                    <p class="mt-2 flex items-center gap-1.5 text-xs text-muted-foreground">
                        <?= icon('clock', 'h-3.5 w-3.5') ?><?= e(date('D j M, H:i', strtotime($latest['logged_at']))) ?>
                    </p>
                    <?php if (normalize_unit($latest['entered_unit'] ?? null) !== $unit): ?>
                        <p class="mt-1 text-[0.7rem] text-muted-foreground">Entered as <?= e(fmt_glucose((float)$latest['glucose_value'], normalize_unit($latest['entered_unit'] ?? null), true)) ?></p>
                    <?php endif; ?>
                <?php else: ?>
                    <?= ui_empty('droplet', 'Nothing logged yet', 'Your first reading starts the trend chart and lets your doctor assess your control.') ?>
                <?php endif; ?>
            </div>
        </section>

        <!-- Adherence + reliability -->
        <section class="lg:col-span-2 <?= UI_CARD ?>">
            <div class="<?= UI_CARD_HEADER ?>">
                <div>
                    <h2 class="<?= UI_CARD_TITLE ?>">Adherence &amp; data reliability</h2>
                    <p class="mt-1 text-xs text-muted-foreground">Your doctor treats your data as high confidence at <?= HIGH_CONFIDENCE_PCT ?>% adherence or above.</p>
                </div>
                <?= ui_badge($adh7['pct'] >= HIGH_CONFIDENCE_PCT ? 'High confidence' : 'Needs more readings', $adh7['pct'] >= HIGH_CONFIDENCE_PCT ? 'success' : 'warning') ?>
            </div>
            <div class="<?= UI_CARD_BODY ?> grid gap-6 sm:grid-cols-2">
                <div class="space-y-3">
                    <p class="text-[0.7rem] font-semibold uppercase tracking-[0.08em] text-muted-foreground">Rolling 7 days</p>
                    <?= ui_gauge((float)$adh7['pct'], 'Last 7 days') ?>
                    <p class="text-xs text-muted-foreground"><?= (int)$adh7['count'] ?> of <?= (int)$adh7['expected'] ?> expected readings</p>
                </div>
                <div class="space-y-3">
                    <p class="text-[0.7rem] font-semibold uppercase tracking-[0.08em] text-muted-foreground">Rolling 30 days</p>
                    <div class="flex items-end justify-between gap-3">
                        <span class="text-3xl font-bold tabular leading-none"><?= $adh30['pct'] + 0 ?>%</span>
                        <span class="text-xs text-muted-foreground"><?= (int)$adh30['days_logged'] ?>/<?= (int)$adh30['days'] ?> days logged</span>
                    </div>
                    <?= ui_progress((float)$adh30['pct'], $adh30['pct'] >= HIGH_CONFIDENCE_PCT) ?>
                    <dl class="space-y-2 pt-1 text-xs">
                        <div class="flex items-center justify-between gap-2">
                            <dt class="text-muted-foreground">Readings logged</dt>
                            <dd class="font-semibold tabular"><?= (int)$adh30['count'] ?></dd>
                        </div>
                        <div class="flex items-center justify-between gap-2">
                            <dt class="text-muted-foreground">Target per day</dt>
                            <dd class="font-semibold tabular"><?= TARGET_READINGS_PER_DAY ?></dd>
                        </div>
                        <div class="flex items-center justify-between gap-2">
                            <dt class="text-muted-foreground">In range (last <?= count($recent) ?>)</dt>
                            <dd class="font-semibold tabular"><?= $inRangePct === null ? '—' : $inRangePct . '%' ?></dd>
                        </div>
                    </dl>
                </div>
            </div>
        </section>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <!-- Trend -->
        <section class="lg:col-span-2 <?= UI_CARD ?>">
            <div class="<?= UI_CARD_HEADER ?>">
                <div>
                    <h2 class="<?= UI_CARD_TITLE ?>">Glucose trend</h2>
                    <p class="mt-1 text-xs text-muted-foreground">Green band: ADA pre-meal target <?= e(fmt_glucose((float)$me['target_fasting_min'], $unit)) ?>–<?= e(fmt_glucose((float)$me['target_fasting_max'], $unit)) ?> <?= e($unit) ?></p>
                </div>
                <a href="history.php" class="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline">
                    Full history <?= icon('chevron-right', 'h-3.5 w-3.5') ?>
                </a>
            </div>
            <div class="<?= UI_CARD_BODY ?>">
                <?php if ($recent): ?>
                    <div class="h-[300px] w-full"><canvas id="mini" aria-label="Blood glucose trend over your last readings" role="img"></canvas></div>
                <?php else: ?>
                    <?= ui_empty('line-chart', 'No trend yet', 'Log your first reading to see your glucose curve against your targets.') ?>
                <?php endif; ?>
            </div>
        </section>

        <!-- Targets + legend -->
        <section class="<?= UI_CARD ?>">
            <div class="<?= UI_CARD_HEADER ?>">
                <div>
                    <h2 class="<?= UI_CARD_TITLE ?>">Your target ranges</h2>
                    <p class="mt-1 text-xs text-muted-foreground">Shown in <?= e($unit) ?></p>
                </div>
                <?= icon('target', 'h-4 w-4 text-primary') ?>
            </div>
            <div class="<?= UI_CARD_BODY ?> space-y-4">
                <dl class="space-y-3 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <dt class="flex items-center gap-2 text-muted-foreground"><span class="h-2.5 w-2.5 rounded-full bg-success"></span>Fasting / pre-meal</dt>
                        <dd class="font-semibold tabular"><?= e(fmt_glucose((float)$me['target_fasting_min'], $unit)) ?>–<?= e(fmt_glucose((float)$me['target_fasting_max'], $unit)) ?></dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="flex items-center gap-2 text-muted-foreground"><span class="h-2.5 w-2.5 rounded-full bg-primary"></span>2-hour post-meal</dt>
                        <dd class="font-semibold tabular">&lt; <?= e(fmt_glucose((float)$me['target_postprandial_max'], $unit)) ?></dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="flex items-center gap-2 text-muted-foreground"><span class="h-2.5 w-2.5 rounded-full bg-warning"></span>Hypoglycaemia</dt>
                        <dd class="font-semibold tabular text-warning">&lt; <?= e(fmt_glucose((float)$me['hypo_threshold'], $unit)) ?></dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="flex items-center gap-2 text-muted-foreground"><span class="h-2.5 w-2.5 rounded-full bg-destructive"></span>Severe low</dt>
                        <dd class="font-semibold tabular text-destructive">&lt; <?= e(fmt_glucose((float)$me['severe_hypo_threshold'], $unit)) ?></dd>
                    </div>
                </dl>
                <div class="rounded-md border border-border bg-muted/50 px-3.5 py-3 text-xs leading-relaxed text-muted-foreground">
                    <p class="mb-1 font-semibold text-foreground">Reading the chart</p>
                    The shaded band is your pre-meal target. Amber dashed line marks the post-meal limit; red lines mark hypo and severe hypo.
                </div>
                <a href="log_glucose.php" class="inline-flex h-10 w-full items-center justify-center gap-2 rounded-md border border-input bg-white text-sm font-medium transition hover:bg-secondary/60">
                    <?= icon('plus', 'h-4 w-4') ?> Add today's reading
                </a>
            </div>
        </section>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
