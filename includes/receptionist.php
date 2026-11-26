<?php
// Front-desk (receptionist) helpers.
//
// The guiding rule for this role is: run the desk, never see the clinical picture.
// A receptionist can register a patient, record vitals and send a referral, but the
// alert feed they read is deliberately stripped of names and clinical values.

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/ui.php';

const REFERRAL_URGENCIES = ['routine' => 'Routine', 'urgent' => 'Urgent', 'emergency' => 'Emergency'];
const REFERRAL_STATUSES  = ['pending' => 'Pending', 'accepted' => 'Accepted', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];

/** The short reference a receptionist can read out or search for: P-0012. */
function patient_code(int $patientId): string
{
    return 'P-' . str_pad((string)$patientId, 4, '0', STR_PAD_LEFT);
}

/**
 * Turn a typed search into (name term, patient id). Accepts a name, a phone number or a
 * patient reference such as "P-0012" / "12".
 */
function patient_search_input(string $q): array
{
    $q = trim($q);
    if ($q !== '' && preg_match('/^P?-?0*(\d{1,9})$/i', $q, $m)) {
        return ['', (int)$m[1]];
    }
    return [$q, 0];
}

/**
 * Pending alerts for this organisation with NO identifying or clinical detail: no name,
 * no glucose value, no alert message. Only what the desk needs to act: a patient
 * reference, the alert category, its urgency and who it is waiting on.
 */
function deidentified_alerts(PDO $pdo, int $limit = 6): array
{
    $limit = max(1, min(50, $limit));
    $stmt = $pdo->prepare("
        SELECT a.alert_id, a.patient_id, a.alert_type, a.risk_level, a.status, a.created_at,
               c.full_name AS clinician_name
        FROM clinical_alerts a
        JOIN patients p ON p.patient_id = a.patient_id
        JOIN users u ON u.user_id = p.user_id
        LEFT JOIN clinicians c ON c.clinician_id = a.clinician_id
        WHERE u.org_id = ? AND a.status = 'pending'
        ORDER BY CASE a.risk_level WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'moderate' THEN 3 ELSE 4 END,
                 a.created_at DESC
        LIMIT " . $limit);
    $stmt->execute([current_org_id()]);
    return $stmt->fetchAll();
}

/** Count of open alerts by urgency, for the front-desk tiles. */
function open_alert_counts(PDO $pdo): array
{
    $stmt = $pdo->prepare("SELECT a.risk_level, COUNT(*) AS n
                           FROM clinical_alerts a
                           JOIN patients p ON p.patient_id = a.patient_id
                           JOIN users u ON u.user_id = p.user_id
                           WHERE u.org_id = ? AND a.status = 'pending'
                           GROUP BY a.risk_level");
    $stmt->execute([current_org_id()]);
    $out = ['critical' => 0, 'high' => 0, 'moderate' => 0, 'low' => 0, 'total' => 0];
    foreach ($stmt->fetchAll() as $row) {
        $out[$row['risk_level']] = (int)$row['n'];
        $out['total'] += (int)$row['n'];
    }
    return $out;
}

/** Front-desk counters for today. */
function receptionist_stats(PDO $pdo): array
{
    $org = current_org_id();

    $registered = $pdo->prepare('SELECT COUNT(*) FROM patients p JOIN users u ON u.user_id = p.user_id
                                 WHERE u.org_id = ? AND u.created_at >= CURRENT_DATE');
    $registered->execute([$org]);

    $vitals = $pdo->prepare('SELECT COUNT(*) FROM vitals v JOIN patients p ON p.patient_id = v.patient_id
                             JOIN users u ON u.user_id = p.user_id
                             WHERE u.org_id = ? AND v.recorded_at >= CURRENT_DATE');
    $vitals->execute([$org]);

    $referrals = $pdo->prepare('SELECT COUNT(*) FROM referrals r JOIN patients p ON p.patient_id = r.patient_id
                                JOIN users u ON u.user_id = p.user_id
                                WHERE u.org_id = ? AND r.status = \'pending\'');
    $referrals->execute([$org]);

    return [
        'registered_today' => (int)$registered->fetchColumn(),
        'vitals_today'     => (int)$vitals->fetchColumn(),
        'pending_referrals'=> (int)$referrals->fetchColumn(),
    ];
}

/**
 * Validate a referral before it is written. A referral must name a target (an active
 * doctor or an active department) and say why in a few words.
 */
function referral_validate(PDO $pdo, array $in): array
{
    $errors = [];
    $target = ($in['target'] ?? 'department') === 'clinician' ? 'clinician' : 'department';
    $clinicianId = (int)($in['clinician_id'] ?? 0);
    $departmentId = (int)($in['department_id'] ?? 0);
    $urgency = (string)($in['urgency'] ?? 'routine');
    $reason = trim((string)($in['reason'] ?? ''));

    if (!isset(REFERRAL_URGENCIES[$urgency])) {
        $errors[] = 'Choose an urgency level.';
    }
    if (mb_strlen($reason) < 4) {
        $errors[] = 'Give a short reason for the referral (at least 4 characters).';
    } elseif (mb_strlen($reason) > 255) {
        $errors[] = 'The reason must be 255 characters or fewer.';
    }

    if ($target === 'clinician') {
        $departmentId = 0;
        $stmt = $pdo->prepare('SELECT 1 FROM clinicians c JOIN users u ON u.user_id = c.user_id
                               WHERE c.clinician_id = ? AND u.is_active = 1');
        $stmt->execute([$clinicianId]);
        if (!$stmt->fetchColumn()) {
            $errors[] = 'Choose an active doctor to refer to.';
        }
    } else {
        $clinicianId = 0;
        $stmt = $pdo->prepare('SELECT 1 FROM departments WHERE department_id = ? AND is_active = 1 AND org_id = ?');
        $stmt->execute([$departmentId, current_org_id()]);
        if (!$stmt->fetchColumn()) {
            $errors[] = 'Choose a department to refer to.';
        }
    }

    return ['errors' => $errors, 'data' => [
        'clinician_id'  => $clinicianId,
        'department_id' => $departmentId,
        'urgency'       => $urgency,
        'reason'        => $reason,
    ]];
}

function referral_urgency_badge(string $urgency): string
{
    return ui_badge(REFERRAL_URGENCIES[$urgency] ?? $urgency, match ($urgency) {
        'emergency' => 'destructive',
        'urgent'    => 'warning',
        default     => 'secondary',
    });
}

function referral_status_badge(string $status): string
{
    return ui_badge(REFERRAL_STATUSES[$status] ?? $status, match ($status) {
        'pending'   => 'warning',
        'accepted'  => 'default',
        'completed' => 'success',
        default     => 'muted',
    });
}

/**
 * Pending referrals that no doctor can see: sent to a department (not a named doctor) for
 * a patient who has no assigned doctor. Without this queue they would be silently lost,
 * because a clinician's inbox only ever shows their own assigned patients.
 * The administrator owns them until a doctor is assigned.
 */
function unowned_referrals(PDO $pdo, ?int $limit = null): array
{
    $sql = "SELECT r.*, p.full_name, p.phone, p.clinician_id AS patient_clinician_id,
                   d.name AS department_name, u.username AS referred_by
            FROM referrals r
            JOIN patients p ON p.patient_id = r.patient_id
            JOIN users pu ON pu.user_id = p.user_id
            LEFT JOIN departments d ON d.department_id = r.department_id
            LEFT JOIN users u ON u.user_id = r.referred_by_user_id
            WHERE pu.org_id = ? AND r.status = 'pending'
              AND r.clinician_id IS NULL AND p.clinician_id IS NULL
            ORDER BY CASE r.urgency WHEN 'emergency' THEN 1 WHEN 'urgent' THEN 2 ELSE 3 END, r.created_at ASC";
    if ($limit !== null) {
        $sql .= ' LIMIT ' . max(1, $limit);
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([current_org_id()]);
    return $stmt->fetchAll();
}

function unowned_referral_count(PDO $pdo): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*)
                           FROM referrals r
                           JOIN patients p ON p.patient_id = r.patient_id
                           JOIN users pu ON pu.user_id = p.user_id
                           WHERE pu.org_id = ? AND r.status = 'pending'
                             AND r.clinician_id IS NULL AND p.clinician_id IS NULL");
    $stmt->execute([current_org_id()]);
    return (int)$stmt->fetchColumn();
}

/** Human wording for a referral's target: "Dr. X" or "Cardiology". */
function referral_target(array $r): string
{
    return $r['clinician_name'] ?: ($r['department_name'] ?: 'Unassigned');
}
