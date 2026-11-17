<?php
// Glucose reading rules: multi-stage validation, banding against targets, and data reliability.

const READING_ABS_MIN        = 20;    // below this is treated as impossible
const READING_ABS_MAX        = 600;   // above this is treated as impossible
const READING_EXTREME_LOW    = 50;    // needs the patient to confirm
const READING_EXTREME_HIGH   = 300;   // needs the patient to confirm
const TARGET_READINGS_PER_DAY = 2;    // expected logging frequency
const HIGH_CONFIDENCE_PCT    = 70;    // adherence at or above this = high data confidence
const MISSING_ALERT_HOURS    = 48;    // no reading for this long => reminder flag

// ---- Units -------------------------------------------------------------
// Readings are ALWAYS stored canonically in mg/dL. The unit the patient typed is
// kept in glucose_readings.entered_unit, and each patient has a display preference.
const UNIT_MGDL    = 'mg/dL';
const UNIT_MMOL    = 'mmol/L';
const GLUCOSE_UNITS = [UNIT_MGDL => 'mg/dL', UNIT_MMOL => 'mmol/L'];
const MGDL_PER_MMOL = 18.016;
const READING_MMOL_MIN = 1.1;   // = 20 mg/dL
const READING_MMOL_MAX = 33.3;  // = 600 mg/dL

/** Accept only known units; anything else falls back to mg/dL. */
function normalize_unit(?string $unit): string
{
    return $unit === UNIT_MMOL ? UNIT_MMOL : UNIT_MGDL;
}

/** Convert an entered value in $unit to the canonical mg/dL. */
function glucose_to_mgdl(float $value, string $unit): float
{
    return $unit === UNIT_MMOL ? $value * MGDL_PER_MMOL : $value;
}

/** Convert a canonical mg/dL value into the display unit. */
function glucose_from_mgdl(float $mgdl, string $unit): float
{
    return $unit === UNIT_MMOL ? $mgdl / MGDL_PER_MMOL : $mgdl;
}

/** Trim trailing zeros: 45.0 -> 45, 5.60 -> 5.6. */
function fmt_number(float $value, int $decimals = 1): string
{
    $text = number_format($value, $decimals, '.', '');
    if (str_contains($text, '.')) {
        $text = rtrim(rtrim($text, '0'), '.');
    }
    return $text;
}

/** Format a canonical mg/dL value in the requested unit. */
function fmt_glucose(float $mgdl, string $unit, bool $withUnit = false): string
{
    $text = fmt_number(glucose_from_mgdl($mgdl, normalize_unit($unit)));
    return $withUnit ? $text . ' ' . normalize_unit($unit) : $text;
}

/** "45 mg/dL (2.5 mmol/L)" — used in clinical explanations and alerts. */
function glucose_dual(float $mgdl): string
{
    return fmt_number($mgdl) . ' mg/dL (' . fmt_number(glucose_from_mgdl($mgdl, UNIT_MMOL)) . ' mmol/L)';
}

/** The patient's preferred display unit, defaulting to mg/dL. */
function patient_unit(?array $row): string
{
    return normalize_unit($row['preferred_unit'] ?? null);
}

/** Entry bounds for the form, in the unit being typed. */
function glucose_bounds(string $unit): array
{
    return normalize_unit($unit) === UNIT_MMOL
        ? ['min' => READING_MMOL_MIN, 'max' => READING_MMOL_MAX]
        : ['min' => READING_ABS_MIN, 'max' => READING_ABS_MAX];
}

/** Thresholds converted for charts in the display unit. */
function chart_limits(array $t, string $unit): array
{
    $unit = normalize_unit($unit);
    return [
        'unit'       => $unit,
        'fastingMin' => round(glucose_from_mgdl((float)$t['target_fasting_min'], $unit), 2),
        'fastingMax' => round(glucose_from_mgdl((float)$t['target_fasting_max'], $unit), 2),
        'post'       => round(glucose_from_mgdl((float)$t['target_postprandial_max'], $unit), 2),
        'hypo'       => round(glucose_from_mgdl((float)$t['hypo_threshold'], $unit), 2),
        'severe'     => round(glucose_from_mgdl((float)$t['severe_hypo_threshold'], $unit), 2),
    ];
}

const MEAL_CONTEXTS = [
    'fasting'   => 'Fasting',
    'pre_meal'  => 'Before a meal',
    'post_meal' => '2 hours after a meal',
    'bedtime'   => 'Bedtime',
    'nocturnal' => 'Night / early morning',
];

/**
 * Validate a submitted reading. Returns:
 *  ['errors' => [...], 'confirm' => ?string, 'data' => [value, context, logged_at, symptoms]]
 * - errors: reading cannot be saved.
 * - confirm: reading is plausible but extreme; the patient must confirm it first.
 */
function validate_reading(PDO $pdo, int $patientId, array $in, bool $confirmed): array
{
    $errors = [];
    $confirm = null;

    $rawValue = trim((string)($in['glucose_value'] ?? ''));
    $unitName = normalize_unit($in['unit'] ?? null);
    $ctx = (string)($in['meal_context'] ?? '');
    $rawTime = trim((string)($in['logged_at'] ?? ''));
    $symptoms = trim((string)($in['symptoms'] ?? ''));

    $value = null;      // canonical mg/dL
    $entered = null;    // exactly what the patient typed, in $unitName
    if ($rawValue === '' || !is_numeric($rawValue)) {
        $errors[] = 'Enter your glucose reading as a number (' . $unitName . ').';
    } else {
        $entered = round((float)$rawValue, 1);
        $value = round(glucose_to_mgdl($entered, $unitName), 1);
        if ($value < READING_ABS_MIN || $value > READING_ABS_MAX) {
            $bounds = glucose_bounds($unitName);
            $errors[] = sprintf('%s %s is outside the possible range (%s–%s %s). Please check your meter and re-enter the value.',
                fmt_number($entered), $unitName, fmt_number($bounds['min']), fmt_number($bounds['max']), $unitName);
        }
    }

    if (!isset(MEAL_CONTEXTS[$ctx])) {
        $errors[] = 'Choose when this reading was taken.';
    }

    $loggedAt = null;
    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $rawTime);
    if (!$dt) {
        $errors[] = 'Enter a valid date and time for the reading.';
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

    // Duplicate prevention: same minute, or same value + context within 10 minutes.
    if (!$errors && $value !== null && $loggedAt !== null) {
        $stmt = $pdo->prepare('SELECT 1 FROM glucose_readings
            WHERE patient_id = :p AND (logged_at = :t
               OR (glucose_value = :v AND meal_context = :c AND ABS(EXTRACT(EPOCH FROM (CAST(:t2 AS timestamp) - logged_at)) / 60) < 10))
            LIMIT 1');
        $stmt->execute([':p' => $patientId, ':t' => $loggedAt, ':v' => $value, ':c' => $ctx, ':t2' => $loggedAt]);
        if ($stmt->fetch()) {
            $errors[] = 'This looks like a duplicate — a reading at that time (or the same value and context) is already saved.';
        }
    }

    if (!$errors && !$confirmed && $value !== null) {
        $shown = fmt_glucose($value, $unitName, true);
        if ($value < READING_EXTREME_LOW) {
            $confirm = "You entered {$shown} (Low). Please confirm this glucometer reading before saving.";
        } elseif ($value > READING_EXTREME_HIGH) {
            $confirm = "You entered {$shown} (High). Please confirm this glucometer reading before saving.";
        }
    }

    return [
        'errors'  => $errors,
        'confirm' => $confirm,
        'data'    => [
            'value' => $value, 'entered_value' => $entered, 'unit' => $unitName,
            'context' => $ctx, 'logged_at' => $loggedAt, 'symptoms' => $symptoms,
        ],
    ];
}

/** Plain band of a value against the patient's targets: severe_low | low | in_range | high | very_high */
function reading_band(float $v, string $ctx, array $t): string
{
    if ($v < (float)$t['severe_hypo_threshold']) {
        return 'severe_low';
    }
    if ($v < (float)$t['hypo_threshold']) {
        return 'low';
    }
    if ($v > (float)$t['critical_high_threshold']) {
        return 'very_high';
    }
    $max = in_array($ctx, ['post_meal', 'bedtime'], true) ? (float)$t['target_postprandial_max'] : (float)$t['target_fasting_max'];
    return $v > $max ? 'high' : 'in_range';
}

function band_label(string $band): array
{
    return match ($band) {
        'severe_low' => ['Severe low', 'chip-danger'],
        'low'        => ['Low', 'chip-warn'],
        'high'       => ['High', 'chip-warn'],
        'very_high'  => ['Very high', 'chip-danger'],
        default      => ['In range', 'chip-ok'],
    };
}

/**
 * Adherence over a rolling window. For accounts younger than the window, only the days
 * since registration are expected, so new patients are not unfairly penalised.
 */
function adherence_stats(PDO $pdo, int $patientId, string $registeredAt, int $days): array
{
    $sinceReg = (int)floor((time() - strtotime($registeredAt)) / 86400) + 1;
    $effective = max(1, min($days, $sinceReg));
    $stmt = $pdo->prepare('SELECT COUNT(*) AS n, COUNT(DISTINCT DATE(logged_at)) AS days_logged
                           FROM glucose_readings WHERE patient_id = ? AND logged_at >= (NOW() - make_interval(days => ' . (int)$effective . '))');
    $stmt->execute([$patientId]);
    $row = $stmt->fetch();
    $expected = $effective * TARGET_READINGS_PER_DAY;
    return [
        'window'      => $days,
        'days'        => $effective,
        'count'       => (int)$row['n'],
        'days_logged' => (int)$row['days_logged'],
        'expected'    => $expected,
        'pct'         => min(100.0, round(((int)$row['n'] / $expected) * 100, 1)),
    ];
}

function data_confidence(float $adherencePct): string
{
    return $adherencePct >= HIGH_CONFIDENCE_PCT ? 'high' : 'low';
}

/** Hours since the last reading (null when the patient has never logged one). */
function hours_since_last_reading(PDO $pdo, int $patientId): ?int
{
    $stmt = $pdo->prepare('SELECT (EXTRACT(EPOCH FROM (NOW() - MAX(logged_at))) / 3600)::int FROM glucose_readings WHERE patient_id = ?');
    $stmt->execute([$patientId]);
    $h = $stmt->fetchColumn();
    return $h === null || $h === false ? null : (int)$h;
}

/** Rows shaped for the Chart.js helper, converted into the display unit. */
function chart_rows(array $readings, array $t, string $unit = UNIT_MGDL): array
{
    $unit = normalize_unit($unit);
    $rows = [];
    foreach (array_reverse($readings) as $r) {   // readings arrive newest-first
        $rows[] = [
            'label' => date('d M H:i', strtotime($r['logged_at'])),
            'value' => round(glucose_from_mgdl((float)$r['glucose_value'], $unit), 1),
            'band'  => reading_band((float)$r['glucose_value'], $r['meal_context'], $t),
        ];
    }
    return $rows;
}
