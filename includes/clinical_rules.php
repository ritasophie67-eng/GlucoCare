<?php
// Vitals validation and the "no prescription without recent vitals" rule.

const VITALS_VALID_HOURS = 24;     // vitals must be this recent before a prescription can be issued
const BP_SYSTOLIC_LIMIT  = 160;    // above either limit -> high blood pressure flag
const BP_DIASTOLIC_LIMIT = 100;

const COMMON_DIABETES_MEDS = [
    'Metformin', 'Glibenclamide', 'Gliclazide', 'Glimepiride', 'Pioglitazone', 'Sitagliptin',
    'Empagliflozin', 'Insulin human (regular)', 'Insulin NPH', 'Insulin glargine', 'Insulin aspart',
];

function validate_vitals(array $in): array
{
    $errors = [];
    $int = fn($k) => (isset($in[$k]) && $in[$k] !== '' && ctype_digit((string)$in[$k])) ? (int)$in[$k] : null;
    $dec = fn($k) => (isset($in[$k]) && $in[$k] !== '' && is_numeric($in[$k])) ? round((float)$in[$k], 1) : null;

    $sys = $int('systolic_bp');
    $dia = $int('diastolic_bp');
    $hr  = $int('heart_rate');
    $wt  = $dec('weight_kg');
    $tmp = $dec('temperature_c');
    $notes = trim((string)($in['notes'] ?? ''));

    if ($sys === null || $sys < 60 || $sys > 260) {
        $errors[] = 'Systolic pressure must be 60–260 mmHg.';
    }
    if ($dia === null || $dia < 30 || $dia > 160) {
        $errors[] = 'Diastolic pressure must be 30–160 mmHg.';
    }
    if ($sys !== null && $dia !== null && $sys <= $dia) {
        $errors[] = 'Systolic pressure must be higher than diastolic.';
    }
    if ($hr === null || $hr < 30 || $hr > 220) {
        $errors[] = 'Heart rate must be 30–220 bpm.';
    }
    if (($in['weight_kg'] ?? '') !== '' && ($wt === null || $wt < 2 || $wt > 400)) {
        $errors[] = 'Weight must be 2–400 kg.';
    }
    if (($in['temperature_c'] ?? '') !== '' && ($tmp === null || $tmp < 30 || $tmp > 43)) {
        $errors[] = 'Temperature must be 30–43 °C.';
    }
    if (mb_strlen($notes) > 255) {
        $errors[] = 'Notes must be 255 characters or fewer.';
    }

    return ['errors' => $errors, 'data' => [
        'systolic' => $sys, 'diastolic' => $dia, 'heart_rate' => $hr,
        'weight' => $wt, 'temperature' => $tmp, 'notes' => $notes,
    ]];
}

function bp_is_high(int $sys, int $dia): bool
{
    return $sys > BP_SYSTOLIC_LIMIT || $dia > BP_DIASTOLIC_LIMIT;
}

/** Most recent vitals record for a patient, or null. */
function latest_vitals(PDO $pdo, int $patientId): ?array
{
    $stmt = $pdo->prepare('SELECT v.*, u.username AS recorder FROM vitals v JOIN users u ON u.user_id = v.recorded_by_user_id
                           WHERE v.patient_id = ? ORDER BY v.recorded_at DESC, v.vital_id DESC LIMIT 1');
    $stmt->execute([$patientId]);
    return $stmt->fetch() ?: null;
}

/** The latest vitals record if it is recent enough to prescribe against, otherwise null. */
function valid_vitals_for_prescribing(PDO $pdo, int $patientId): ?array
{
    $v = latest_vitals($pdo, $patientId);
    if (!$v || strtotime($v['recorded_at']) < time() - VITALS_VALID_HOURS * 3600) {
        return null;
    }
    return $v;
}
