<?php
// Generates simulated patients and measures how accurately the rule engine flags them.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/simulation.php';
require_role('admin');

$pdo = get_db_connection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        set_flash('danger', 'Your session expired. Please try again.');
    } elseif (($_POST['action'] ?? '') === 'generate') {
        $summary = sim_seed($pdo);
        log_audit($pdo, (int)$_SESSION['user_id'], 'sim_ground_truth', 'SIM_GENERATED', 'Generated simulated 30-day datasets: ' . array_sum($summary) . ' readings across ' . count($summary) . ' profiles.');
        set_flash('success', 'Simulated dataset generated: ' . array_sum($summary) . ' readings across ' . count($summary) . ' synthetic patients.');
    } elseif (($_POST['action'] ?? '') === 'clear') {
        $n = sim_clear($pdo);
        log_audit($pdo, (int)$_SESSION['user_id'], 'sim_ground_truth', 'SIM_CLEARED', "Removed {$n} simulated patient accounts.");
        set_flash('info', 'Simulated data removed.');
    }
    redirect('admin/evaluation.php');
}

$hasData = sim_exists($pdo);
$res = $hasData ? sim_evaluate($pdo) : null;
$pct = fn($v) => $v === null ? '—' : $v + 0 . '%';

$page_title = 'Engine Evaluation';
require __DIR__ . '/../includes/header.php';
?>
<div class="space-y-6">
    <?= ui_page_header(
        'Rule-engine evaluation',
        'Five synthetic 30-day patient profiles with known ground truth. The engine analyses each 5-day window and the output is compared with what was injected.',
        '<form method="post" class="inline-flex">' . csrf_field() . '<input type="hidden" name="action" value="generate">'
        . ui_button($hasData ? 'Regenerate dataset' : 'Generate dataset', ['type' => 'submit', 'icon' => 'sparkles'])
        . '</form>'
        . ($hasData ? '<form method="post" class="inline-flex" onsubmit="return confirm(\'Remove all simulated patients and readings?\')">'
            . csrf_field() . '<input type="hidden" name="action" value="clear">'
            . ui_button('Clear data', ['type' => 'submit', 'variant' => 'outline', 'icon' => 'trash']) . '</form>' : '')
    ) ?>

    <section class="<?= UI_CARD ?>">
        <div class="<?= UI_CARD_BODY ?>">
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                <?php foreach (SIM_PROFILES as $p): ?>
                    <div class="rounded-md border border-border bg-muted/40 px-3.5 py-3">
                        <p class="text-[0.68rem] font-semibold uppercase tracking-[0.07em] text-muted-foreground"><?= e($p['name']) ?></p>
                        <p class="mt-1 text-xs leading-relaxed text-foreground/80"><?= e($p['expect']) ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="mt-4 text-xs leading-relaxed text-muted-foreground">
                Simulated patients are locked accounts assigned to no doctor, so they never appear in a clinician's list.
                A window is "positive" when a dangerous pattern or severe single event was injected into it.
            </p>
        </div>
    </section>

    <?php if (!$res): ?>
        <div class="rounded-lg border border-border bg-card">
            <?= ui_empty('bar-chart', 'No simulated data yet', 'Select “Generate dataset” to create the synthetic profiles and run the evaluation.') ?>
        </div>
    <?php else: $cm = $res['cm']; ?>
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <?php foreach ([
                ['Sensitivity', $pct($res['sensitivity']), 'TP / (TP + FN)', 'text-success'],
                ['Specificity', $pct($res['specificity']), 'TN / (TN + FP)', 'text-primary'],
                ['False-alert rate', $pct($res['false_alert_rate']), 'Isolated spikes flagged: ' . $res['isolated_flagged'] . ' of ' . $res['isolated_windows'], 'text-warning'],
                ['Missed-case rate', $pct($res['missed_case_rate']), $res['severe_missed'] . ' of ' . $res['severe_injected'] . ' severe events missed', $res['severe_missed'] ? 'text-destructive' : 'text-foreground'],
            ] as [$label, $value, $foot, $tone]): ?>
                <div class="<?= UI_CARD ?> px-5 py-4">
                    <p class="text-[0.7rem] font-semibold uppercase tracking-[0.07em] text-muted-foreground"><?= e($label) ?></p>
                    <p class="mt-1 text-3xl font-bold leading-none tabular tracking-tight <?= $tone ?>"><?= e($value) ?></p>
                    <p class="mt-2 text-xs leading-relaxed text-muted-foreground"><?= e($foot) ?></p>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="grid gap-6 lg:grid-cols-5">
            <section class="lg:col-span-2 <?= UI_CARD ?>" aria-labelledby="matrix">
                <div class="<?= UI_CARD_HEADER ?>">
                    <h2 id="matrix" class="<?= UI_CARD_TITLE ?>">Confusion matrix</h2>
                    <span class="text-xs text-muted-foreground"><?= (int)$res['total_windows'] ?> windows</span>
                </div>
                <div class="<?= UI_CARD_BODY ?>">
                    <table class="w-full border-collapse overflow-hidden rounded-md text-center text-sm">
                        <caption class="sr-only">Confusion matrix of engine flags versus injected ground truth.</caption>
                        <thead>
                            <tr class="bg-muted/60 text-[0.68rem] uppercase tracking-[0.06em] text-muted-foreground">
                                <th scope="col" class="px-2 py-2 text-left font-semibold"></th>
                                <th scope="col" class="px-2 py-2 font-semibold">Flagged</th>
                                <th scope="col" class="px-2 py-2 font-semibold">Not flagged</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr>
                                <th scope="row" class="px-2 py-3 text-left text-xs font-semibold">Truly dangerous</th>
                                <td class="bg-success-subtle px-2 py-3"><span class="text-2xl font-bold tabular text-success"><?= (int)$cm['tp'] ?></span><p class="text-[0.65rem] text-muted-foreground">True positive</p></td>
                                <td class="bg-destructive-subtle px-2 py-3"><span class="text-2xl font-bold tabular text-destructive"><?= (int)$cm['fn'] ?></span><p class="text-[0.65rem] text-muted-foreground">False negative</p></td>
                            </tr>
                            <tr>
                                <th scope="row" class="px-2 py-3 text-left text-xs font-semibold">Stable</th>
                                <td class="bg-warning-subtle px-2 py-3"><span class="text-2xl font-bold tabular text-warning"><?= (int)$cm['fp'] ?></span><p class="text-[0.65rem] text-muted-foreground">False positive</p></td>
                                <td class="bg-success-subtle px-2 py-3"><span class="text-2xl font-bold tabular text-success"><?= (int)$cm['tn'] ?></span><p class="text-[0.65rem] text-muted-foreground">True negative</p></td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="mt-3 text-xs leading-relaxed text-muted-foreground">
                        <?= (int)$res['low_conf_windows'] ?> of <?= (int)$res['total_windows'] ?> windows had Low data confidence (&lt; <?= HIGH_CONFIDENCE_PCT ?>% adherence) — mostly the sparse-logging profile.
                    </p>
                </div>
            </section>

            <section class="lg:col-span-3 <?= UI_CARD ?>" aria-labelledby="by-profile">
                <div class="<?= UI_CARD_HEADER ?>"><h2 id="by-profile" class="<?= UI_CARD_TITLE ?>">By profile</h2></div>
                <div class="overflow-x-auto scroll-area">
                    <table class="w-full min-w-[480px] border-collapse text-sm">
                        <caption class="sr-only">Per-profile detection outcomes.</caption>
                        <thead>
                            <tr class="border-b border-border bg-muted/50 text-left text-[0.68rem] uppercase tracking-[0.07em] text-muted-foreground">
                                <th scope="col" class="px-5 py-2.5 font-semibold">Profile</th>
                                <th scope="col" class="px-3 py-2.5 font-semibold">Adherence</th>
                                <th scope="col" class="px-3 py-2.5 font-semibold">TP</th>
                                <th scope="col" class="px-3 py-2.5 font-semibold">FP</th>
                                <th scope="col" class="px-3 py-2.5 font-semibold">TN</th>
                                <th scope="col" class="px-5 py-2.5 font-semibold">FN</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                        <?php foreach ($res['profiles'] as $p): ?>
                            <tr class="hover:bg-muted/40">
                                <td class="px-5 py-2.5 font-medium"><?= e(str_replace('SIM · ', '', $p['name'])) ?></td>
                                <td class="px-3 py-2.5 tabular"><?= $p['adh_avg'] + 0 ?>%</td>
                                <td class="px-3 py-2.5 tabular text-success"><?= (int)$p['tp'] ?></td>
                                <td class="px-3 py-2.5 tabular text-warning"><?= (int)$p['fp'] ?></td>
                                <td class="px-3 py-2.5 tabular"><?= (int)$p['tn'] ?></td>
                                <td class="px-5 py-2.5 tabular text-destructive"><?= (int)$p['fn'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <section class="<?= UI_CARD ?>" aria-labelledby="window-detail">
            <div class="<?= UI_CARD_HEADER ?>"><h2 id="window-detail" class="<?= UI_CARD_TITLE ?>">Window-by-window detail</h2></div>
            <div class="max-h-[30rem] overflow-auto scroll-area">
                <table class="w-full min-w-[820px] border-collapse text-sm">
                    <caption class="sr-only">Each simulated window with its ground truth and engine output.</caption>
                    <thead class="sticky top-0 z-10">
                        <tr class="border-b border-border bg-muted/80 text-left text-[0.68rem] uppercase tracking-[0.07em] text-muted-foreground backdrop-blur">
                            <th scope="col" class="px-5 py-2.5 font-semibold">Profile</th>
                            <th scope="col" class="px-3 py-2.5 font-semibold">Window</th>
                            <th scope="col" class="px-3 py-2.5 font-semibold">Truth</th>
                            <th scope="col" class="px-3 py-2.5 font-semibold">Readings</th>
                            <th scope="col" class="px-3 py-2.5 font-semibold">Adherence</th>
                            <th scope="col" class="px-3 py-2.5 font-semibold">Engine output</th>
                            <th scope="col" class="px-5 py-2.5 font-semibold">Result</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                    <?php foreach ($res['profiles'] as $p): foreach ($p['windows'] as $w):
                        $variant = ['tp' => 'success', 'tn' => 'success', 'fp' => 'warning', 'fn' => 'destructive'][$w['cell']]; ?>
                        <tr class="hover:bg-muted/40">
                            <td class="px-5 py-2.5 text-xs text-muted-foreground"><?= e(str_replace('SIM · ', '', $p['name'])) ?></td>
                            <td class="px-3 py-2.5 tabular">#<?= (int)$w['index'] ?></td>
                            <td class="px-3 py-2.5">
                                <?= $w['truth'] ? ui_badge('Dangerous', 'destructive') : ui_badge('Stable', 'muted') ?>
                                <?= $w['note'] === 'isolated_spike' ? '<span class="ml-1 text-[0.7rem] text-muted-foreground">isolated spike</span>' : '' ?>
                            </td>
                            <td class="px-3 py-2.5 tabular"><?= (int)$w['readings'] ?></td>
                            <td class="px-3 py-2.5 tabular"><?= $w['adh'] + 0 ?>%</td>
                            <td class="px-3 py-2.5 text-xs leading-relaxed text-muted-foreground">
                                <?= $w['findings'] ? implode('<br>', array_map('e', $w['findings'])) : 'No alert' ?>
                            </td>
                            <td class="px-5 py-2.5"><?= ui_badge(strtoupper($w['cell']), $variant) ?></td>
                        </tr>
                    <?php endforeach; endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
