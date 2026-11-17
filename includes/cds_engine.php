<?php
// Clinical Decision-Support engine.
// cds_analyze() is PURE (no database, no clock) so the same rules can be tested on simulated data.
// It only flags things for a clinician to review — it never diagnoses or changes treatment.

require_once __DIR__ . '/readings.php';

const CDS_WINDOW_DAYS         = 7;   // pattern look-back
const CDS_ADHERENCE_DAYS      = 14;  // window used for data confidence
const CDS_NOCT_WARN           = 2;   // nocturnal lows in window -> warning
const CDS_NOCT_CRITICAL       = 3;   // nocturnal lows in window -> critical
const CDS_POSTMEAL_RUN        = 3;   // consecutive high post-meal readings -> warning
const CDS_POSTMEAL_CRIT_EXTRA = 70;  // run average above (post-meal max + this) -> critical
const CDS_RECURRENT_HYPO      = 3;   // daytime lows in window -> warning
const CDS_NIGHT_START_HOUR    = 0;   // 00:00 ...
const CDS_NIGHT_END_HOUR      = 5;   // ... up to (not including) 05:00

// Alert lifecycle: which finding types auto-resolve when the window no longer shows them.
const CDS_PATTERN_TYPES      = ['nocturnal_hypo', 'postprandial_hyper', 'recurrent_hypo'];
const CDS_SINGLE_EVENT_TYPES = ['severe_hypo', 'critical_high'];

function cds_risk_label(string $severity, string $confidence): string
{
    $conf = $confidence === 'high' ? 'High Data Confidence' : 'Low Data Confidence';
    return match ($severity) {
        'critical' => "Critical Risk – {$conf}",
        'warning'  => "Warning Risk – {$conf}",
        default    => $confidence === 'high' ? 'Stable / Low Risk' : 'Inconclusive Data',
    };
}

function cds_risk_level(string $severity, string $confidence): string
{
    return match (true) {
        $severity === 'critical'                          => 'critical',
        $severity === 'warning' && $confidence === 'high' => 'high',
        $severity === 'warning'                           => 'moderate',
        default                                           => 'low',
    };
}

function cds_is_night(string $loggedAt, string $context): bool
{
    $h = (int)date('G', strtotime($loggedAt));
    return $context === 'nocturnal' || ($h >= CDS_NIGHT_START_HOUR && $h < CDS_NIGHT_END_HOUR);
}

function cds_fmt(float $v): string
{
    return rtrim(rtrim(number_format($v, 1), '0'), '.');
}

/**
 * @param array $readings   readings in the look-back window, OLDEST FIRST
 *                           (each: reading_id?, glucose_value, meal_context, logged_at)
 * @param array $t           patient thresholds
 * @param float $adherencePct data-reliability percentage used for the confidence rating
 * @return array list of findings: type, severity, confidence, adherence, label, message, reading_ids
 */
function cds_analyze(array $readings, array $t, float $adherencePct, int $windowDays = CDS_WINDOW_DAYS, int $adhDays = CDS_ADHERENCE_DAYS): array
{
    $conf = data_confidence($adherencePct);
    $adhText = 'Data confidence is ' . ($conf === 'high' ? 'High' : 'Low') . ' (' . cds_fmt($adherencePct) . "% {$adhDays}-day adherence).";
    $hypo = (float)$t['hypo_threshold'];
    $postMax = (float)$t['target_postprandial_max'];
    $findings = [];

    $make = function (string $type, string $severity, string $body, array $ids) use ($conf, $adherencePct, $adhText) {
        $label = cds_risk_label($severity, $conf);
        return [
            'type' => $type, 'severity' => $severity, 'confidence' => $conf, 'adherence' => $adherencePct,
            'label' => $label, 'reading_ids' => $ids,
            'message' => "Flagged as {$label}: {$body} {$adhText}",
        ];
    };

    // 1. Single, clearly dangerous readings (each one is its own finding).
    foreach ($readings as $r) {
        $v = (float)$r['glucose_value'];
        $when = date('d M H:i', strtotime($r['logged_at']));
        $id = isset($r['reading_id']) ? [(int)$r['reading_id']] : [];
        if ($v < (float)$t['severe_hypo_threshold']) {
            $findings[] = $make('severe_hypo', 'critical',
                'Patient recorded a severe low of ' . glucose_dual($v) . ', below the severe-hypoglycaemia limit of ' . glucose_dual((float)$t['severe_hypo_threshold']) . ", on {$when}"
                . ($r['symptoms'] ?? '' ? " with symptoms: {$r['symptoms']}." : '.'), $id);
        } elseif ($v > (float)$t['critical_high_threshold']) {
            $findings[] = $make('critical_high', 'critical',
                'Patient recorded a very high reading of ' . glucose_dual($v) . ', above the critical limit of ' . glucose_dual((float)$t['critical_high_threshold']) . ", on {$when}.", $id);
        }
    }

    // 2. Nocturnal hypoglycaemia pattern.
    $night = array_values(array_filter($readings, fn($r) => (float)$r['glucose_value'] < $hypo && cds_is_night($r['logged_at'], $r['meal_context'])));
    if (count($night) >= CDS_NOCT_WARN) {
        $n = count($night);
        $times = array_map(fn($r) => date('H:i', strtotime($r['logged_at'])), $night);
        $vals = implode(', ', array_map(fn($r) => glucose_dual((float)$r['glucose_value']), $night));
        $findings[] = $make('nocturnal_hypo', $n >= CDS_NOCT_CRITICAL ? 'critical' : 'warning',
            "Patient recorded {$n} nocturnal readings below " . glucose_dual($hypo) . ' between ' . min($times) . ' and ' . max($times)
            . " over the last {$windowDays} days (values: {$vals}).", array_map(fn($r) => (int)($r['reading_id'] ?? 0), $night));
    }

    // 3. Persistent post-meal hyperglycaemia: a run of consecutive high post-meal readings.
    $post = array_values(array_filter($readings, fn($r) => $r['meal_context'] === 'post_meal'));
    $run = [];
    $bestRun = [];
    foreach ($post as $r) {
        if ((float)$r['glucose_value'] > $postMax) {
            $run[] = $r;
            if (count($run) > count($bestRun)) {
                $bestRun = $run;
            }
        } else {
            $run = [];
        }
    }
    if (count($bestRun) >= CDS_POSTMEAL_RUN) {
        $vals = array_map(fn($r) => (float)$r['glucose_value'], $bestRun);
        $avg = array_sum($vals) / count($vals);
        $sev = $avg > $postMax + CDS_POSTMEAL_CRIT_EXTRA ? 'critical' : 'warning';
        $findings[] = $make('postprandial_hyper', $sev,
            'Patient had ' . count($bestRun) . ' consecutive 2-hour post-meal readings above ' . glucose_dual($postMax) . ' (values: '
            . implode(', ', array_map(fn($x) => glucose_dual((float)$x), $vals)) . '; average ' . glucose_dual($avg) . ") over the last {$windowDays} days.",
            array_map(fn($r) => (int)($r['reading_id'] ?? 0), $bestRun));
    }

    // 4. Recurrent daytime lows (nocturnal lows are handled above).
    $day = array_values(array_filter($readings, fn($r) => (float)$r['glucose_value'] < $hypo && !cds_is_night($r['logged_at'], $r['meal_context'])));
    if (count($day) >= CDS_RECURRENT_HYPO) {
        $findings[] = $make('recurrent_hypo', 'warning',
            'Patient recorded ' . count($day) . ' readings below ' . glucose_dual($hypo) . " during the day over the last {$windowDays} days (values: "
            . implode(', ', array_map(fn($r) => glucose_dual((float)$r['glucose_value']), $day)) . ').',
            array_map(fn($r) => (int)($r['reading_id'] ?? 0), $day));
    }

    return $findings;
}

/**
 * Re-evaluate one patient and bring their alert queue in line with the data.
 *
 * - Pattern alerts (nocturnal lows, post-meal run, recurrent daytime lows) are kept in
 *   sync: created, refreshed, or auto-resolved when the pattern is no longer present.
 * - Single severe events are never auto-resolved; each reading raises at most one alert
 *   of a given type, so repeated scans cannot duplicate them.
 *
 * Returns ['created' => int, 'updated' => int, 'resolved' => int, 'findings' => int].
 */
function cds_reassess_patient(PDO $pdo, int $patientId): array
{
    $result = ['created' => 0, 'updated' => 0, 'resolved' => 0, 'findings' => 0];

    $stmt = $pdo->prepare('SELECT p.clinician_id, u.created_at AS registered_at FROM patients p JOIN users u ON u.user_id = p.user_id WHERE p.patient_id = ?');
    $stmt->execute([$patientId]);
    $p = $stmt->fetch();

    $stmt = $pdo->prepare('SELECT * FROM patient_thresholds WHERE patient_id = ?');
    $stmt->execute([$patientId]);
    $t = $stmt->fetch();

    if (!$p || !$t) {
        return $result;                       // nothing to evaluate
    }

    $stmt = $pdo->prepare('SELECT * FROM glucose_readings WHERE patient_id = ? AND logged_at >= (NOW() - make_interval(days => ' . (int)CDS_WINDOW_DAYS . ')) ORDER BY logged_at ASC, reading_id ASC');
    $stmt->execute([$patientId]);
    $readings = $stmt->fetchAll();

    $adh = adherence_stats($pdo, $patientId, $p['registered_at'], CDS_ADHERENCE_DAYS);
    $findings = cds_analyze($readings, $t, $adh['pct']);
    $findingTypes = array_column($findings, 'type');
    $result['findings'] = count($findings);

    $clinicianId = $p['clinician_id'];
    $create = function (array $f, string $level, ?int $anchor) use ($pdo, $patientId, $clinicianId): void {
        $pdo->prepare('INSERT INTO clinical_alerts (reading_id, patient_id, clinician_id, alert_type, risk_level, data_confidence, adherence_pct, alert_message)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$anchor, $patientId, $clinicianId, $f['type'], $level, $f['confidence'], $f['adherence'], $f['message']]);
        log_audit($pdo, null, 'clinical_alerts', 'ALERT_CREATED', "{$f['label']} alert ({$f['type']}) for patient #{$patientId}.");
    };

    foreach ($findings as $f) {
        $level = cds_risk_level($f['severity'], $f['confidence']);
        $anchor = $f['reading_ids'][0] ?? null;

        if (in_array($f['type'], CDS_SINGLE_EVENT_TYPES, true)) {
            // One alert per reading + type, forever: a resolved severe event is never re-raised.
            $stmt = $pdo->prepare('SELECT 1 FROM clinical_alerts WHERE patient_id = ? AND alert_type = ? AND reading_id IS NOT DISTINCT FROM ? LIMIT 1');
            $stmt->execute([$patientId, $f['type'], $anchor]);
            if (!$stmt->fetchColumn()) {
                $create($f, $level, $anchor);
                $result['created']++;
            }
            continue;
        }

        // One live alert per pattern: refresh it instead of piling up duplicates.
        $stmt = $pdo->prepare("SELECT alert_id FROM clinical_alerts WHERE patient_id = ? AND alert_type = ? AND status = 'pending' LIMIT 1");
        $stmt->execute([$patientId, $f['type']]);
        if ($existing = $stmt->fetchColumn()) {
            $pdo->prepare('UPDATE clinical_alerts SET reading_id = ?, risk_level = ?, data_confidence = ?, adherence_pct = ?, alert_message = ?, clinician_id = ? WHERE alert_id = ?')
                ->execute([$anchor, $level, $f['confidence'], $f['adherence'], $f['message'], $clinicianId, $existing]);
            $result['updated']++;
            continue;
        }

        $create($f, $level, $anchor);
        $result['created']++;
    }

    // Auto-resolve pattern alerts that no longer hold for the current window.
    $stmt = $pdo->prepare("SELECT alert_id, alert_type FROM clinical_alerts WHERE patient_id = ? AND status = 'pending'");
    $stmt->execute([$patientId]);
    foreach ($stmt->fetchAll() as $alert) {
        if (!in_array($alert['alert_type'], CDS_PATTERN_TYPES, true) || in_array($alert['alert_type'], $findingTypes, true)) {
            continue;                       // not a pattern, or still present
        }
        $pdo->prepare("UPDATE clinical_alerts SET status = 'resolved', resolved_at = NOW(),
                       reviewed_at = COALESCE(reviewed_at, NOW()),
                       clinician_note = COALESCE(clinician_note, 'Auto-resolved: the pattern is no longer present in the look-back window.')
                       WHERE alert_id = ?")->execute([$alert['alert_id']]);
        $result['resolved']++;
        log_audit($pdo, null, 'clinical_alerts', 'ALERT_AUTO_RESOLVED',
            "Alert #{$alert['alert_id']} ({$alert['alert_type']}) for patient #{$patientId} auto-resolved: pattern no longer present.");
    }

    return $result;
}

/**
 * Run the engine after a new reading is saved. The whole window is re-evaluated, so the
 * new reading can also refresh or resolve pattern alerts. Returns alerts created.
 */
function cds_process_reading(PDO $pdo, int $patientId): int
{
    return cds_reassess_patient($pdo, $patientId)['created'];
}

const ALERT_TYPE_LABELS = [
    'severe_hypo'        => 'Severe low glucose',
    'critical_high'      => 'Critically high glucose',
    'nocturnal_hypo'     => 'Nocturnal hypoglycaemia pattern',
    'postprandial_hyper' => 'Persistent post-meal highs',
    'recurrent_hypo'     => 'Recurrent daytime lows',
    'high_bp'            => 'High blood pressure',
];

function risk_chip(string $level): string
{
    return match ($level) {
        'critical' => 'chip-danger',
        'high'     => 'chip-warn',
        'moderate' => '',
        default    => 'chip-muted',
    };
}
