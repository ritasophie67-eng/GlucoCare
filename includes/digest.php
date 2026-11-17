<?php
// Clinician alert digest.
//
// Critical alerts that are still pending after DIGEST_AFTER_HOURS are emailed once to the
// assigned clinician. Each alert records digest_sent_at, so a reminder is never repeated for
// the same alert, and acknowledging/resolving it stops it from being included at all.

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/cds_engine.php';   // ALERT_TYPE_LABELS + alert vocabulary

const DIGEST_MAX_ATTEMPTS = 3;   // delivery attempts before an alert stops being retried

function digest_after_hours(int $override = 0): int
{
    $hours = $override > 0 ? $override : (int)app_env('DIGEST_AFTER_HOURS', '4');
    return max(1, $hours);
}

/**
 * Critical, still-pending, not-yet-digested alerts older than $hours, grouped by clinician.
 *
 * @return array<int, array<int, array<string, mixed>>>
 */
function digest_due_alerts(PDO $pdo, int $hours): array
{
    $stmt = $pdo->prepare(
        "SELECT a.alert_id, a.patient_id, a.alert_type, a.alert_message, a.created_at, a.data_confidence,
                p.full_name,
                c.clinician_id, c.full_name AS clinician_name,
                cu.email AS clinician_email,
                (EXTRACT(EPOCH FROM (NOW() - a.created_at)) / 3600)::int AS hours_pending
         FROM clinical_alerts a
         JOIN patients p   ON p.patient_id = a.patient_id
         JOIN clinicians c ON c.clinician_id = p.clinician_id
         JOIN users cu     ON cu.user_id = c.user_id
         WHERE a.status = 'pending'
           AND a.risk_level = 'critical'
           AND a.digest_sent_at IS NULL
           AND a.digest_attempts < ?
           AND a.created_at <= (NOW() - make_interval(hours => ?))
           AND cu.is_active = 1
         ORDER BY a.created_at ASC"
    );
    $stmt->bindValue(1, DIGEST_MAX_ATTEMPTS, PDO::PARAM_INT);
    $stmt->bindValue(2, $hours, PDO::PARAM_INT);
    $stmt->execute();

    $groups = [];
    foreach ($stmt->fetchAll() as $row) {
        $groups[(int)$row['clinician_id']][] = $row;
    }
    return $groups;
}

/**
 * @param array<int, array<string, mixed>> $alerts
 * @return array{0: string, 1: string, 2: string} subject, html, text
 */
function digest_compose(array $alerts, int $hours): array
{
    $count = count($alerts);
    $plural = $count === 1 ? '' : 's';
    $verb = $count === 1 ? 'has' : 'have';
    $subjectNoun = $count === 1 ? 'This alert' : 'These alerts';
    $link = app_url('clinician/alerts.php');
    $subject = sprintf('%s: %d critical alert%s unacknowledged for over %d h', APP_NAME, $count, $plural, $hours);

    $rows = '';
    $lines = '';
    foreach ($alerts as $a) {
        $label = ALERT_TYPE_LABELS[$a['alert_type']] ?? $a['alert_type'];
        $rationale = preg_replace('/^Flagged as [^:]+:\s*/', '', $a['alert_message']);
        $pending = (int)$a['hours_pending'];

        $rows .= '<tr>'
            . '<td style="padding:10px 12px;border-bottom:1px solid #e2e8f0;font-weight:600;color:#0f172a;">' . e($a['full_name']) . '</td>'
            . '<td style="padding:10px 12px;border-bottom:1px solid #e2e8f0;color:#0f172a;">' . e($label) . '</td>'
            . '<td style="padding:10px 12px;border-bottom:1px solid #e2e8f0;color:#b91c1c;font-weight:600;white-space:nowrap;">' . $pending . ' h</td>'
            . '</tr>'
            . '<tr><td colspan="3" style="padding:0 12px 12px;border-bottom:1px solid #e2e8f0;color:#475569;font-size:13px;line-height:1.5;">'
            . e($rationale) . '</td></tr>';

        $lines .= "- {$a['full_name']} — {$label} — pending {$pending} h\n  {$rationale}\n";
    }

    $html = '<!doctype html><html><body style="margin:0;padding:24px;background:#f1f5f9;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;margin:0 auto;background:#ffffff;border:1px solid #e2e8f0;border-radius:10px;">'
        . '<tr><td style="padding:20px 20px 8px;">'
        . '<p style="margin:0 0 4px;font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#64748b;font-weight:700;">' . e(APP_NAME) . ' alert digest</p>'
        . '<h1 style="margin:0 0 8px;font-size:20px;line-height:1.3;color:#0f172a;">' . $count . ' critical alert' . $plural . ' awaiting acknowledgement</h1>'
        . '<p style="margin:0;color:#475569;font-size:14px;line-height:1.5;">' . $subjectNoun . ' ' . $verb . ' been pending for more than ' . $hours . ' hours. Acknowledging ' . ($count === 1 ? 'it' : 'them') . ' in ' . e(APP_NAME) . ' stops further reminders.</p>'
        . '</td></tr>'
        . '<tr><td style="padding:8px 20px 0;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:14px;">'
        . '<thead><tr style="text-align:left;color:#64748b;font-size:11px;letter-spacing:.08em;text-transform:uppercase;">'
        . '<th style="padding:8px 12px;border-bottom:1px solid #e2e8f0;">Patient</th>'
        . '<th style="padding:8px 12px;border-bottom:1px solid #e2e8f0;">Alert</th>'
        . '<th style="padding:8px 12px;border-bottom:1px solid #e2e8f0;">Pending</th>'
        . '</tr></thead><tbody>' . $rows . '</tbody></table>'
        . '</td></tr>'
        . '<tr><td style="padding:16px 20px 22px;">'
        . '<a href="' . e($link) . '" style="display:inline-block;background:#0f766e;color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;padding:10px 16px;border-radius:6px;">Review alerts queue</a>'
        . '<p style="margin:14px 0 0;color:#94a3b8;font-size:12px;line-height:1.5;">Automated message from ' . e(APP_NAME) . ' — do not reply. You receive it because you are the assigned clinician for these patients.</p>'
        . '</td></tr>'
        . '</table></body></html>';

    $text = "{$count} critical alert{$plural} in " . APP_NAME . " {$verb} been pending for more than {$hours} hours.\n\n"
        . $lines
        . "\nReview the alerts queue: {$link}\n\n"
        . "Acknowledging an alert in " . APP_NAME . " stops further reminders.\n"
        . "Automated message — do not reply.\n";

    return [$subject, $html, $text];
}

/**
 * Send one digest per clinician. Returns counts plus human-readable log lines.
 *
 * @return array{clinicians: int, sent: int, failed: int, skipped: int, alerts: int, messages: string[]}
 */
function digest_send(PDO $pdo, int $hours, bool $dryRun = false): array
{
    $result = ['clinicians' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'alerts' => 0, 'messages' => []];

    foreach (digest_due_alerts($pdo, $hours) as $alerts) {
        $email = trim((string)$alerts[0]['clinician_email']);
        if ($email === '') {
            $result['skipped']++;
            continue;
        }

        $result['clinicians']++;
        $ids = array_map(fn($a) => (int)$a['alert_id'], $alerts);
        $result['alerts'] += count($ids);

        [$subject, $html, $text] = digest_compose($alerts, $hours);

        if ($dryRun) {
            $names = implode(', ', array_unique(array_map(fn($a) => (string)$a['full_name'], $alerts)));
            $result['messages'][] = "[dry-run] {$email}: {$subject} — {$names}";
            continue;
        }

        $delivery = send_mail($email, $subject, $html, $text);
        if (!$delivery['ok']) {
            $result['failed']++;
            $result['messages'][] = "failed {$email}: {$delivery['error']}";
            app_log_line("Alert digest to {$email} failed: {$delivery['error']}");
            // Record the failed attempt on each alert so a broken mail server cannot
            // retry forever, and the admin overview can show what went wrong.
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("UPDATE clinical_alerts
                              SET digest_attempts = digest_attempts + 1, digest_error = ?
                            WHERE alert_id IN ({$placeholders})")
                ->execute(array_merge([mb_substr($delivery['error'], 0, 250)], $ids));
            log_audit($pdo, null, 'clinical_alerts', 'ALERT_DIGEST_FAILED',
                'Digest of ' . count($ids) . " alert(s) to {$email} failed: " . mb_substr($delivery['error'], 0, 120));
            continue;
        }

        // Only mark after successful delivery, so a failed send is retried on the next run.
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("UPDATE clinical_alerts
                          SET digest_sent_at = NOW(), digest_error = NULL, digest_attempts = digest_attempts + 1
                        WHERE alert_id IN ({$placeholders})")
            ->execute($ids);

        $result['sent']++;
        $result['messages'][] = "sent {$email} (" . count($ids) . ' alert' . (count($ids) === 1 ? '' : 's') . ', ' . $delivery['driver'] . ')';
        log_audit($pdo, null, 'clinical_alerts', 'ALERT_DIGEST_SENT',
            'Digest of ' . count($ids) . " critical unacknowledged alert(s) emailed to {$email}.");
    }

    // Job bookkeeping for the admin "automation health" panel.
    if (!$dryRun) {
        meta_set($pdo, 'last_digest_at', date('Y-m-d H:i:s'));
        meta_set($pdo, 'last_digest_failed', (string)$result['failed']);
        meta_set($pdo, 'last_digest_error', $result['failed'] ? mb_substr((string)($result['messages'][0] ?? ''), 0, 250) : '');
        meta_set($pdo, 'last_digest_sent', (string)$result['sent']);
    }

    return $result;
}
