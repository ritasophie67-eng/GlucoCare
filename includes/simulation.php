<?php
// Synthetic 30-day datasets for five patient profiles, plus an evaluation of the CDS engine
// against the ground truth recorded while generating them. Everything is seeded, so results repeat.

require_once __DIR__ . '/cds_engine.php';

const SIM_DAYS        = 30;
const SIM_WINDOW_DAYS = 5;          // each profile is cut into 6 evaluation windows of 5 days
const SIM_SEED        = 20260807;

const SIM_PROFILES = [
    'adherent'  => ['name' => 'Adherent & Controlled',        'log_rate' => 0.95, 'expect' => 'No alerts'],
    'nocturnal' => ['name' => 'Nocturnal Hypoglycaemia',      'log_rate' => 0.90, 'expect' => 'Nocturnal pattern in windows 2, 4 and 5'],
    'postmeal'  => ['name' => 'Post-meal Hyperglycaemia',     'log_rate' => 0.90, 'expect' => 'Post-meal pattern in windows 1, 3 and 6'],
    'sparse'    => ['name' => 'Poorly Adherent / Sparse',     'log_rate' => 0.25, 'expect' => 'No alerts; data confidence Low'],
    'erratic'   => ['name' => 'Erratic / High-Noise',         'log_rate' => 0.80, 'expect' => 'Isolated spikes ignored; severe events (windows 2, 5) flagged'],
];

function sim_clear(PDO $pdo): int
{
    // Deleting the user cascades to patient, thresholds, readings and ground truth.
    $stmt = $pdo->prepare("DELETE FROM users WHERE username LIKE 'sim\\_%'");
    $stmt->execute();
    return $stmt->rowCount();
}

function sim_exists(PDO $pdo): bool
{
    return (int)$pdo->query("SELECT COUNT(*) FROM users WHERE username LIKE 'sim\\_%'")->fetchColumn() > 0;
}

function sim_clamp(float $v, float $lo, float $hi): float
{
    return max($lo, min($hi, $v));
}

function sim_normal(float $mean, float $sd): float
{
    // Box-Muller using the seeded generator.
    $u1 = max(mt_rand() / mt_getrandmax(), 1e-9);
    $u2 = mt_rand() / mt_getrandmax();
    return $mean + $sd * sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2);
}

function sim_chance(float $p): bool
{
    return mt_rand() / mt_getrandmax() < $p;
}

/** Generate readings and ground truth for one profile (no database access). */
function sim_generate_profile(string $key): array
{
    $cfg = SIM_PROFILES[$key];
    $base = strtotime('today') - SIM_DAYS * 86400;
    $windows = (int)(SIM_DAYS / SIM_WINDOW_DAYS);
    $readings = [];
    $truth = [];

    $positive = match ($key) {
        'nocturnal' => [1, 3, 4],
        'postmeal'  => [0, 2, 5],
        'erratic'   => [1, 4],
        default     => [],
    };
    $severeAt = ['erratic' => [1 => 'low', 4 => 'high']][$key] ?? [];

    for ($w = 0; $w < $windows; $w++) {
        $isPos = in_array($w, $positive, true);
        $note = '';
        $severeEvents = 0;

        for ($d = 0; $d < SIM_WINDOW_DAYS; $d++) {
            $day = $base + ($w * SIM_WINDOW_DAYS + $d) * 86400;
            $slots = [
                ['fasting',   7 * 3600 + 600,  fn() => sim_clamp(sim_normal(105, 9), 85, 125)],
                ['post_meal', 14 * 3600 + 900, fn() => sim_clamp(sim_normal(150, 14), 115, 172)],
                ['post_meal', 20 * 3600 + 2700, fn() => sim_clamp(sim_normal(150, 14), 115, 172)],
            ];
            foreach ($slots as $i => [$ctx, $offset, $gen]) {
                $isPostMeal = $ctx === 'post_meal';
                $v = $gen();
                if ($key === 'postmeal' && $isPos && $isPostMeal) {
                    $v = mt_rand(195, 255);                       // persistent post-meal highs
                }
                if (sim_chance($cfg['log_rate']) || ($key === 'postmeal' && $isPos && $isPostMeal && $d < 4)) {
                    $readings[] = ['ctx' => $ctx, 'ts' => $day + $offset + mt_rand(-900, 900), 'v' => round($v, 1), 'sym' => null];
                }
            }

            if ($key === 'nocturnal' && $isPos && in_array($d, [0, 2, 4], true)) {
                $readings[] = ['ctx' => 'nocturnal', 'ts' => $day + mt_rand(1, 4) * 3600 + mt_rand(0, 3000),
                               'v' => (float)mt_rand(55, 68), 'sym' => 'sweating, shaky'];
            }
        }

        // Erratic profile: one isolated spike per negative window; one severe event per positive window.
        if ($key === 'erratic') {
            $day = $base + ($w * SIM_WINDOW_DAYS + 2) * 86400;
            if (isset($severeAt[$w])) {
                $readings[] = $severeAt[$w] === 'low'
                    ? ['ctx' => 'fasting',   'ts' => $day + 7 * 3600 + 1800, 'v' => 48.0, 'sym' => 'dizzy, confused']
                    : ['ctx' => 'post_meal', 'ts' => $day + 15 * 3600,       'v' => 322.0, 'sym' => 'very thirsty'];
                $severeEvents = 1;
                $note = 'severe_event';
            } else {
                $readings[] = ['ctx' => 'post_meal', 'ts' => $day + 16 * 3600 + 1200, 'v' => (float)mt_rand(205, 240), 'sym' => null];
                $note = 'isolated_spike';
            }
        }

        $truth[] = [
            'window_index' => $w,
            'start' => date('Y-m-d H:i:s', $base + $w * SIM_WINDOW_DAYS * 86400),
            'end'   => date('Y-m-d H:i:s', $base + ($w + 1) * SIM_WINDOW_DAYS * 86400),
            'positive' => $isPos ? 1 : 0, 'severe' => $severeEvents, 'note' => $note,
        ];
    }
    usort($readings, fn($a, $b) => $a['ts'] <=> $b['ts']);
    return ['readings' => $readings, 'truth' => $truth];
}

/** Replace any existing simulated data with a fresh, reproducible dataset. */
function sim_seed(PDO $pdo): array
{
    mt_srand(SIM_SEED);
    $summary = [];
    $pdo->beginTransaction();
    try {
        sim_clear($pdo);
        $locked = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);   // nobody can sign in as a sim patient

        foreach (SIM_PROFILES as $key => $cfg) {
            $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role, email, is_active)
                                   VALUES (?, ?, 'patient', ?, 0) RETURNING user_id");
            $stmt->execute(["sim_{$key}", $locked, "sim_{$key}@example.invalid"]);
            $uid = (int)$stmt->fetchColumn();
            $stmt = $pdo->prepare("INSERT INTO patients (user_id, full_name, date_of_birth, gender, phone)
                                   VALUES (?, ?, '1980-01-01', 'other', 'N/A') RETURNING patient_id");
            $stmt->execute([$uid, 'SIM · ' . $cfg['name']]);
            $pid = (int)$stmt->fetchColumn();
            $pdo->prepare('INSERT INTO patient_thresholds (patient_id) VALUES (?)')->execute([$pid]);

            $data = sim_generate_profile($key);
            $ins = $pdo->prepare('INSERT INTO glucose_readings (patient_id, glucose_value, meal_context, symptoms, logged_at) VALUES (?, ?, ?, ?, ?)');
            foreach ($data['readings'] as $r) {
                $ins->execute([$pid, $r['v'], $r['ctx'], $r['sym'], date('Y-m-d H:i:s', $r['ts'])]);
            }
            $insT = $pdo->prepare('INSERT INTO sim_ground_truth (patient_id, window_index, window_start, window_end, is_positive, severe_events, note) VALUES (?, ?, ?, ?, ?, ?, ?)');
            foreach ($data['truth'] as $w) {
                $insT->execute([$pid, $w['window_index'], $w['start'], $w['end'], $w['positive'], $w['severe'], $w['note']]);
            }
            $summary[$key] = count($data['readings']);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $summary;
}

/** Run the CDS engine over every simulated window and compare with the ground truth. */
function sim_evaluate(PDO $pdo): array
{
    $patients = $pdo->query("SELECT p.patient_id, p.full_name, u.username FROM patients p JOIN users u ON u.user_id = p.user_id
                             WHERE u.username LIKE 'sim\\_%' ORDER BY p.patient_id")->fetchAll();
    $cm = ['tp' => 0, 'fp' => 0, 'tn' => 0, 'fn' => 0];
    $severeInjected = $severeFound = 0;
    $isolatedWindows = $isolatedFlagged = 0;
    $lowConfWindows = $totalWindows = 0;
    $profiles = [];

    foreach ($patients as $p) {
        $t = $pdo->query('SELECT * FROM patient_thresholds WHERE patient_id = ' . (int)$p['patient_id'])->fetch();
        $truthRows = $pdo->query('SELECT * FROM sim_ground_truth WHERE patient_id = ' . (int)$p['patient_id'] . ' ORDER BY window_index')->fetchAll();
        $row = ['name' => $p['full_name'], 'key' => substr($p['username'], 4), 'tp' => 0, 'fp' => 0, 'tn' => 0, 'fn' => 0, 'adh' => [], 'windows' => []];

        foreach ($truthRows as $w) {
            $stmt = $pdo->prepare('SELECT * FROM glucose_readings WHERE patient_id = ? AND logged_at >= ? AND logged_at < ? ORDER BY logged_at ASC');
            $stmt->execute([$p['patient_id'], $w['window_start'], $w['window_end']]);
            $readings = $stmt->fetchAll();

            $adh = min(100.0, round(count($readings) / (SIM_WINDOW_DAYS * TARGET_READINGS_PER_DAY) * 100, 1));
            $findings = cds_analyze($readings, $t, $adh, SIM_WINDOW_DAYS, SIM_WINDOW_DAYS);
            $flagged = count($findings) > 0;
            $truth = (bool)$w['is_positive'];

            $cell = $truth ? ($flagged ? 'tp' : 'fn') : ($flagged ? 'fp' : 'tn');
            $cm[$cell]++;
            $row[$cell]++;
            $row['adh'][] = $adh;

            $foundSevere = count(array_filter($findings, fn($f) => in_array($f['type'], ['severe_hypo', 'critical_high'], true)));
            $severeInjected += (int)$w['severe_events'];
            $severeFound += min((int)$w['severe_events'], $foundSevere);
            if ($w['note'] === 'isolated_spike') {
                $isolatedWindows++;
                $isolatedFlagged += $flagged ? 1 : 0;
            }
            $totalWindows++;
            $lowConfWindows += data_confidence($adh) === 'low' ? 1 : 0;

            $row['windows'][] = [
                'index' => (int)$w['window_index'] + 1, 'truth' => $truth, 'note' => $w['note'], 'adh' => $adh,
                'readings' => count($readings), 'cell' => $cell,
                'findings' => array_map(fn($f) => $f['label'] . ' — ' . (ALERT_TYPE_LABELS[$f['type']] ?? $f['type']), $findings),
            ];
        }
        $row['adh_avg'] = $row['adh'] ? round(array_sum($row['adh']) / count($row['adh']), 1) : 0;
        $profiles[] = $row;
    }

    $div = fn($a, $b) => $b > 0 ? round($a / $b * 100, 1) : null;
    return [
        'cm' => $cm,
        'sensitivity' => $div($cm['tp'], $cm['tp'] + $cm['fn']),
        'specificity' => $div($cm['tn'], $cm['tn'] + $cm['fp']),
        'false_alert_rate' => $div($cm['fp'], $cm['fp'] + $cm['tn']),
        'isolated_windows' => $isolatedWindows, 'isolated_flagged' => $isolatedFlagged,
        'severe_injected' => $severeInjected, 'severe_missed' => $severeInjected - $severeFound,
        'missed_case_rate' => $div($severeInjected - $severeFound, $severeInjected),
        'low_conf_windows' => $lowConfWindows, 'total_windows' => $totalWindows,
        'profiles' => $profiles,
    ];
}
