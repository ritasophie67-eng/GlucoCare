<?php
// Outbound SMS for patient notifications.
//
//   SMS_DRIVER=log   (default) write .txt files under logs/sms — works offline, nothing leaves the server
//   SMS_DRIVER=http  POST JSON to SMS_HTTP_URL with an optional bearer token (SMS_HTTP_TOKEN)
//   SMS_DRIVER=none  disable sending entirely
//
// Message text is kept short and free of clinical detail on purpose: an SMS may be read
// by anyone holding the phone, so it says who to contact, never what the reading was.

require_once __DIR__ . '/functions.php';

function sms_driver(): string
{
    return strtolower(trim((string)app_env('SMS_DRIVER', 'log')));
}

/** Normalise a stored phone number for an SMS gateway; returns null when it is not usable. */
function sms_recipient(?string $stored): ?string
{
    $e164 = phone_normalize($stored);
    return $e164 === null ? null : $e164;
}

/**
 * @return array{ok: bool, driver: string, error: string}
 */
function sms_send(?string $to, string $message): array
{
    $driver = sms_driver();
    $number = sms_recipient($to);

    if ($driver === 'none') {
        return ['ok' => false, 'driver' => 'none', 'error' => 'SMS sending is disabled (SMS_DRIVER=none)'];
    }
    if ($number === null) {
        return ['ok' => false, 'driver' => $driver, 'error' => 'No valid phone number on file'];
    }
    $message = trim(preg_replace('/\s+/', ' ', $message));

    if ($driver === 'http') {
        $url = trim((string)app_env('SMS_HTTP_URL', ''));
        if ($url === '') {
            return ['ok' => false, 'driver' => 'http', 'error' => 'SMS_HTTP_URL is not configured'];
        }
        $payload = json_encode(['to' => $number, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers = ['Content-Type: application/json'];
        $token = trim((string)app_env('SMS_HTTP_TOKEN', ''));
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['ok' => false, 'driver' => 'http', 'error' => 'Gateway unreachable: ' . $curlError];
        }
        if ($status < 200 || $status >= 300) {
            return ['ok' => false, 'driver' => 'http', 'error' => 'Gateway returned HTTP ' . $status . ': ' . mb_substr((string)$body, 0, 120)];
        }
        app_log_line("SMS queued to {$number} via gateway.");
        return ['ok' => true, 'driver' => 'http', 'error' => ''];
    }

    // log driver
    $dir = dirname(__DIR__) . '/logs/sms';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return ['ok' => false, 'driver' => 'log', 'error' => 'Cannot create logs/sms'];
    }
    $file = $dir . '/' . date('Ymd-His') . '-' . preg_replace('/\D+/', '', $number) . '-' . bin2hex(random_bytes(3)) . '.txt';
    $written = @file_put_contents($file, "To: {$number}\nSent: " . date('c') . "\n\n{$message}\n");
    if ($written === false) {
        return ['ok' => false, 'driver' => 'log', 'error' => 'Cannot write to logs/sms'];
    }
    app_log_line("SMS written to logs/sms for {$number}.");
    return ['ok' => true, 'driver' => 'log', 'error' => ''];
}
