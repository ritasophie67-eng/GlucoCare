<?php
// Time-based one-time passwords (RFC 6238) for administrator sign-in.
// No external dependency: base32 secret, HMAC-SHA1, 30-second step, +/-1 step drift.

const TOTP_PERIOD = 30;
const TOTP_DIGITS = 6;

function totp_base32_decode(string $secret): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $secret));
    $bits = '';
    foreach (str_split($secret) as $char) {
        $pos = strpos($alphabet, $char);
        if ($pos === false) {
            continue;
        }
        $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $out .= chr(bindec($byte));
        }
    }
    return $out;
}

function totp_base32_encode(string $bytes): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bytes) as $char) {
        $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= $alphabet[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
    }
    return $out;
}

function totp_new_secret(): string
{
    return totp_base32_encode(random_bytes(20));
}

function totp_code(string $secret, ?int $slice = null): string
{
    $slice ??= (int)floor(time() / TOTP_PERIOD);
    $hash = hash_hmac('sha1', pack('J', $slice), totp_base32_decode($secret), true);
    $offset = ord(substr($hash, -1)) & 0x0F;
    $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;
    return str_pad((string)($value % (10 ** TOTP_DIGITS)), TOTP_DIGITS, '0', STR_PAD_LEFT);
}

/** Accept the current code and the one step either side, to tolerate clock drift. */
function totp_verify(string $secret, string $code): bool
{
    $code = preg_replace('/\D+/', '', $code);
    if ($secret === '' || strlen($code) !== TOTP_DIGITS) {
        return false;
    }
    $slice = (int)floor(time() / TOTP_PERIOD);
    for ($i = -1; $i <= 1; $i++) {
        if (hash_equals(totp_code($secret, $slice + $i), $code)) {
            return true;
        }
    }
    return false;
}

/** otpauth:// URI for authenticator apps (Google Authenticator, Authy, 1Password…). */
function totp_uri(string $secret, string $account, string $issuer = APP_NAME): string
{
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account)
        . '?secret=' . rawurlencode($secret)
        . '&issuer=' . rawurlencode($issuer)
        . '&digits=' . TOTP_DIGITS
        . '&period=' . TOTP_PERIOD;
}

/** Group a secret for easier manual entry: ABCD EFGH IJKL MNOP. */
function totp_format_secret(string $secret): string
{
    return trim(chunk_split($secret, 4, ' '));
}
