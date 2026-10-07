<?php
// Glucose reading rules: multi-stage validation, banding against targets, and data reliability.

const READING_ABS_MIN        = 20;    // below this is treated as impossible
const READING_ABS_MAX        = 600;   // above this is treated as impossible
const READING_EXTREME_LOW    = 50;    // needs the patient to confirm
const READING_EXTREME_HIGH   = 300;   // needs the patient to confirm
const TARGET_READINGS_PER_DAY = 2;    // expected logging frequency
const HIGH_CONFIDENCE_PCT    = 70;    // adherence at or above this = high data confidence
const MISSING_ALERT_HOURS    = 48;    // no reading for this long => reminder flag

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
    $ctx = (string)($in['meal_context'] ?? '');
    $rawTime = trim((string)($in['logged_at'] ?? ''));
    $symptoms = trim((string)($in['symptoms'] ?? ''));

    $value = null;
    if ($rawValue === '' || !is_numeric($rawValue)) {
        $errors[] = 'Enter your glucose reading as a number (mg/dL).';
    } else {
        $value = round((float)$rawValue, 1);
        if ($value < READING_ABS_MIN || $value > READING_ABS_MAX) {
            $errors[] = sprintf('%s mg/dL is outside the possible range (%d–%d). Please check your meter and re-enter the value.',
                rtrim(rtrim(number_format($value, 1), '0'), '.'), READING_ABS_MIN, READING_ABS_MAX);
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
               OR (glucose_value = :v AND meal_context = :c AND ABS(TIMESTAMPDIFF(MINUTE, logged_at, :t2)) < 10))
            LIMIT 1');
        $stmt->execute([':p' => $patientId, ':t' => $loggedAt, ':v' => $value, ':c' => $ctx, ':t2' => $loggedAt]);
        if ($stmt->fetch()) {
            $errors[] = 'This looks like a duplicate — a reading at that time (or the same value and context) is already saved.';
        }
    }

    if (!$errors && !$confirmed && $value !== null) {
        if ($value < READING_EXTREME_LOW) {
            $confirm = "You entered {$value} mg/dL (very low). Please confirm this glucometer reading before saving.";
        } elseif ($value > READING_EXTREME_HIGH) {
            $confirm = "You entered {$value} mg/dL (very high). Please confirm this glucometer reading before saving.";
        }
    }

    return [
        'errors'  => $errors,
        'confirm' => $confirm,
        'data'    => ['value' => $value, 'context' => $ctx, 'logged_at' => $loggedAt, 'symptoms' => $symptoms],
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
                           FROM glucose_readings WHERE patient_id = ? AND logged_at >= (NOW() - INTERVAL ? DAY)');
    $stmt->execute([$patientId, $effective]);
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
    $stmt = $pdo->prepare('SELECT TIMESTAMPDIFF(HOUR, MAX(logged_at), NOW()) FROM glucose_readings WHERE patient_id = ?');
    $stmt->execute([$patientId]);
    $h = $stmt->fetchColumn();
    return $h === null || $h === false ? null : (int)$h;
}

/** Rows shaped for the Chart.js helper. */
function chart_rows(array $readings, array $t): array
{
    $rows = [];
    foreach (array_reverse($readings) as $r) {   // readings arrive newest-first
        $rows[] = [
            'label' => date('d M H:i', strtotime($r['logged_at'])),
            'value' => (float)$r['glucose_value'],
            'band'  => reading_band((float)$r['glucose_value'], $r['meal_context'], $t),
        ];
    }
    return $rows;
}
