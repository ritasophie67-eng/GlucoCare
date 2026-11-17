<?php
// Mail delivery for alert digests.
//
//   MAIL_DRIVER=log   write .eml files under logs/mail (default: no credentials, works offline)
//   MAIL_DRIVER=smtp  deliver over SMTP using MAIL_SMTP_* (STARTTLS or implicit TLS, optional AUTH)
//
// Kept dependency-free on purpose: the digest must work on a plain XAMPP install and with any
// institutional mailbox, without adding a Composer package or a vendor SDK.

require_once __DIR__ . '/errors.php';

function mail_driver(): string
{
    return strtolower(trim((string)app_env('MAIL_DRIVER', 'log')));
}

function mail_from_address(): string
{
    return (string)app_env('MAIL_FROM', 'no-reply@glucocare.local');
}

function mail_from_name(): string
{
    return (string)app_env('MAIL_FROM_NAME', APP_NAME . ' Alerts');
}

/**
 * @return array{ok: bool, error: string, driver: string, path?: string}
 */
function send_mail(string $to, string $subject, string $html, string $text = ''): array
{
    $driver = mail_driver();
    $to = trim($to);

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Invalid recipient address', 'driver' => $driver];
    }

    try {
        return match ($driver) {
            'log'   => mail_deliver_log($to, $subject, $html, $text),
            'smtp'  => mail_deliver_smtp($to, $subject, $html, $text),
            default => ['ok' => false, 'error' => "Unsupported MAIL_DRIVER '{$driver}' (use log or smtp)", 'driver' => $driver],
        };
    } catch (Throwable $e) {
        app_log_exception($e);
        return ['ok' => false, 'error' => $e->getMessage(), 'driver' => $driver];
    }
}

/** Build a UTF-8 multipart/alternative message. */
function mail_message(string $to, string $subject, string $html, string $text): string
{
    if ($text === '') {
        $text = trim(strip_tags(preg_replace('/<(br|\/p|\/tr)>/i', "\n", $html)));
    }
    $boundary = bin2hex(random_bytes(8));
    $encode = fn(string $v): string => '=?UTF-8?B?' . base64_encode($v) . '?=';

    return implode("\r\n", [
        'From: ' . $encode(mail_from_name()) . ' <' . mail_from_address() . '>',
        'To: ' . $to,
        'Subject: ' . $encode($subject),
        'Date: ' . date('r'),
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        '',
        '--' . $boundary,
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        '',
        chunk_split(base64_encode($text), 76, "\r\n"),
        '--' . $boundary,
        'Content-Type: text/html; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        '',
        chunk_split(base64_encode($html), 76, "\r\n"),
        '--' . $boundary . '--',
        '',
    ]);
}

function mail_deliver_log(string $to, string $subject, string $html, string $text): array
{
    $dir = APP_LOG_DIR . '/mail';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return ['ok' => false, 'error' => 'Cannot create ' . $dir, 'driver' => 'log'];
    }
    $file = $dir . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.eml';
    if (file_put_contents($file, mail_message($to, $subject, $html, $text)) === false) {
        return ['ok' => false, 'error' => 'Cannot write ' . $file, 'driver' => 'log'];
    }
    app_log_line("Mail (log driver) to {$to}: {$subject} -> {$file}");
    return ['ok' => true, 'error' => '', 'driver' => 'log', 'path' => $file];
}

// ---- SMTP ----------------------------------------------------------------

function smtp_expect($fp, string $context, string $codes): string
{
    $reply = '';
    while (($line = fgets($fp, 1024)) !== false) {
        $reply .= $line;
        if (strlen($line) < 4 || $line[3] !== '-') {
            break;
        }
    }
    $code = substr($reply, 0, 3);
    if ($reply === '' || !str_contains($codes, $code)) {
        throw new RuntimeException("SMTP {$context} failed: " . trim($reply ?: 'no response'));
    }
    return $reply;
}

function smtp_send($fp, string $line, string $context, string $codes): string
{
    fwrite($fp, $line . "\r\n");
    return smtp_expect($fp, $context, $codes);
}

function mail_deliver_smtp(string $to, string $subject, string $html, string $text): array
{
    $host = trim((string)app_env('MAIL_SMTP_HOST', ''));
    $port = (int)app_env('MAIL_SMTP_PORT', '587');
    $user = (string)app_env('MAIL_SMTP_USER', '');
    $pass = (string)app_env('MAIL_SMTP_PASS', '');
    $secure = strtolower(trim((string)app_env('MAIL_SMTP_SECURE', 'tls'))); // tls | ssl | none

    if ($host === '') {
        return ['ok' => false, 'error' => 'MAIL_SMTP_HOST is not configured', 'driver' => 'smtp'];
    }

    $target = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $fp = @stream_socket_client($target, $errno, $errstr, 10, STREAM_CLIENT_CONNECT,
        stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]));
    if (!$fp) {
        return ['ok' => false, 'error' => "Cannot connect to {$host}:{$port} — {$errstr} ({$errno})", 'driver' => 'smtp'];
    }
    stream_set_timeout($fp, 10);

    try {
        smtp_expect($fp, 'greeting', '220');
        $ehlo = 'EHLO ' . (gethostname() ?: 'glucocare.local');
        $caps = smtp_send($fp, $ehlo, 'EHLO', '250');

        if ($secure === 'tls') {
            smtp_send($fp, 'STARTTLS', 'STARTTLS', '220');
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('SMTP STARTTLS negotiation failed');
            }
            $caps = smtp_send($fp, $ehlo, 'EHLO after STARTTLS', '250');
        }

        if ($user !== '') {
            if (preg_match('/AUTH[= ]([^\r\n]*)/i', $caps, $m) && stripos($m[1], 'PLAIN') !== false) {
                smtp_send($fp, 'AUTH PLAIN ' . base64_encode("\0{$user}\0{$pass}"), 'AUTH PLAIN', '235');
            } else {
                smtp_send($fp, 'AUTH LOGIN', 'AUTH LOGIN', '334');
                smtp_send($fp, base64_encode($user), 'AUTH username', '334');
                smtp_send($fp, base64_encode($pass), 'AUTH password', '235');
            }
        }

        smtp_send($fp, 'MAIL FROM:<' . mail_from_address() . '>', 'MAIL FROM', '250');
        smtp_send($fp, 'RCPT TO:<' . $to . '>', 'RCPT TO', '250,251');
        smtp_send($fp, 'DATA', 'DATA', '354');

        $message = preg_replace('/^\./m', '..', mail_message($to, $subject, $html, $text));
        fwrite($fp, $message . "\r\n.\r\n");
        smtp_expect($fp, 'message body', '250');

        smtp_send($fp, 'QUIT', 'QUIT', '221');
        fclose($fp);
    } catch (Throwable $e) {
        fclose($fp);
        throw $e;
    }

    app_log_line("Mail (smtp) to {$to}: {$subject}");
    return ['ok' => true, 'error' => '', 'driver' => 'smtp'];
}
