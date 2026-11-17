<?php
// Central error handling: no stack traces or SQL reach the browser.
// Also hosts the .env loader, the error log, and schema health checks.

if (!defined('APP_LOG_DIR')) {
    define('APP_LOG_DIR', dirname(__DIR__) . '/logs');
}

/** Escape helper usable before includes/functions.php is loaded. */
function app_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** Read a configuration value from the environment (with fallback). */
function app_env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    if ($value !== false && $value !== '') {
        return $value;
    }
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
        return (string)$_ENV[$key];
    }
    return $default;
}

function app_debug(): bool
{
    return in_array(strtolower((string)app_env('APP_DEBUG', '0')), ['1', 'true', 'yes', 'on'], true);
}

/** Minimal .env loader — no dependency, and real environment variables win. */
function app_load_env(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($key === '' || getenv($key) !== false) {
            continue;
        }
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[0] === substr($value, -1)) {
            $value = substr($value, 1, -1);
        }
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
    }
}

/** Append a line to logs/app-YYYY-MM-DD.log (never to the page). */
function app_log_line(string $line): void
{
    if (!is_dir(APP_LOG_DIR)) {
        @mkdir(APP_LOG_DIR, 0770, true);
    }
    $context = PHP_SAPI === 'cli'
        ? 'cli: ' . implode(' ', array_slice($_SERVER['argv'] ?? [], 1))
        : ($_SERVER['REQUEST_METHOD'] ?? 'GET') . ' ' . ($_SERVER['REQUEST_URI'] ?? '');
    @file_put_contents(
        APP_LOG_DIR . '/app-' . date('Y-m-d') . '.log',
        '[' . date('Y-m-d H:i:s') . '] ' . $line . PHP_EOL . 'CONTEXT: ' . $context . PHP_EOL . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

function app_log_exception(Throwable $e): void
{
    app_log_line(sprintf(
        '%s: %s in %s:%d%sSTACK:%s%s',
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        PHP_EOL,
        PHP_EOL,
        $e->getTraceAsString()
    ));
}

/** Short public reference shown to the user so a log entry can be found. */
function app_reference(Throwable $e): string
{
    return strtoupper(substr(sha1(get_class($e) . $e->getMessage() . $e->getFile() . $e->getLine()), 0, 8));
}

/** Tables this version of the application expects, read from database/schema.sql. */
function app_schema_tables(): array
{
    static $tables = null;
    if ($tables !== null) {
        return $tables;
    }
    $tables = [];
    $sql = @file_get_contents(dirname(__DIR__) . '/database/schema.postgres.sql')
        ?: @file_get_contents(dirname(__DIR__) . '/database/schema.sql');
    if ($sql && preg_match_all('/CREATE TABLE IF NOT EXISTS\s+`?([A-Za-z0-9_]+)`?/i', $sql, $matches)) {
        $tables = $matches[1];
    }
    return $tables;
}

function app_existing_tables(PDO $pdo): array
{
    try {
        return $pdo->query("SELECT table_name FROM information_schema.tables
                            WHERE table_schema = 'public' AND table_type = 'BASE TABLE'")
            ->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable) {
        return [];
    }
}

function app_missing_tables(PDO $pdo): array
{
    $existing = array_map('strtolower', app_existing_tables($pdo));
    return array_values(array_filter(app_schema_tables(), fn($table) => !in_array(strtolower($table), $existing, true)));
}

/**
 * Render a safe, styled error page. Never includes SQL, paths or trace details.
 * Works for the CLI too (plain text on STDERR, exit code 1).
 */
function app_error_page(string $title, string $message, string $hint = '', int $status = 500, string $reference = ''): never
{
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, PHP_EOL . $title . PHP_EOL . strip_tags($message) . PHP_EOL . ($hint !== '' ? strip_tags($hint) . PHP_EOL : ''));
        exit(1);
    }

    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
    }

    $base = defined('BASE_URL') ? BASE_URL : './';
    $appName = defined('APP_NAME') ? APP_NAME : 'GlucoCare';
    $css = $base . 'assets/css/app.css';
    $ref = $reference !== '' ? $reference : '';

    echo '<!DOCTYPE html><html lang="en" class="h-full"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . app_h($title) . ' · ' . app_h($appName) . '</title>'
        . '<link href="' . app_h($css) . '" rel="stylesheet"></head>'
        . '<body class="flex min-h-screen items-center justify-center bg-background p-5 font-sans text-foreground">'
        . '<main class="w-full max-w-lg rounded-lg border border-border bg-card p-6 shadow-card">'
        . '<div class="flex items-start gap-3">'
        . '<span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-warning/15 text-warning" aria-hidden="true">'
        . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
        . 'stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/>'
        . '<path d="M12 9v4"/><path d="M12 17h.01"/></svg></span>'
        . '<div class="min-w-0"><h1 class="text-lg font-semibold tracking-tight">' . app_h($title) . '</h1>'
        . '<p class="mt-1.5 text-sm leading-relaxed text-muted-foreground">' . $message . '</p></div></div>'
        . ($hint !== '' ? '<div class="mt-4 rounded-md border border-border bg-muted/50 px-3.5 py-3 text-xs leading-relaxed text-muted-foreground">' . $hint . '</div>' : '')
        . '<div class="mt-5 flex flex-wrap items-center gap-2">'
        . '<a class="inline-flex h-10 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90" href="">Try again</a>'
        . '<a class="inline-flex h-10 items-center rounded-md border border-input bg-white px-4 text-sm font-medium hover:bg-secondary/60" href="' . app_h($base) . '">Back to home</a>'
        . '</div>'
        . ($ref !== '' ? '<p class="mt-4 border-t border-border pt-3 font-mono text-[0.68rem] text-muted-foreground">Reference ' . app_h($ref)
            . ' — quote this if you contact support.</p>' : '')
        . '</main></body></html>';
    exit;
}

/** Global exception handler: logs the detail, shows a safe page. */
function app_handle_exception(Throwable $e): void
{
    app_log_exception($e);
    $ref = app_reference($e);
    $sqlState = $e instanceof PDOException ? (string)$e->getCode() : '';

    if ($e instanceof PDOException) {
        // Missing table / missing column: almost always a stale database schema.
        if (in_array($sqlState, ['42S02', '42S22', '42P01', '42703'], true)
            || str_contains($e->getMessage(), "doesn't exist")
            || preg_match('/(relation|column)\s+"[^"]+"\s+does not exist/i', $e->getMessage())) {
            $missing = [];
            try {
                $missing = app_missing_tables(get_db_connection());
            } catch (Throwable) {
                // connection itself is broken; fall through with an empty list
            }
            $list = $missing
                ? '<ul class="list-disc pl-4">' . implode('', array_map(fn($t) => '<li><code>' . app_h($t) . '</code></li>', $missing)) . '</ul>'
                : 'The database is missing a table or column this version needs.';
            app_error_page(
                'Database needs updating',
                'The database is out of date, so this page could not load. No data was changed.',
                $list . '<p class="mt-2">Run <code>php bin/migrate.php</code> from the project folder to add the missing tables, then reload.</p>',
                503,
                $ref
            );
        }

        if (in_array($sqlState, ['1049', '3D000'], true) || str_contains($e->getMessage(), 'Unknown database')) {
            app_error_page(
                'Database setup needed',
                'The database <strong>' . app_h(defined('DB_NAME') ? DB_NAME : 'glucocare') . '</strong> has not been created yet.',
                'Create it and load the schema: run <code>php bin/migrate.php</code> from the project folder.',
                503,
                $ref
            );
        }
    }

    app_error_page(
        'Something went wrong',
        'This page could not be completed. The error has been recorded for the administrator — please try again.',
        app_debug()
            ? '<p><strong>Debug:</strong> ' . app_h($e->getMessage()) . '</p><p class="mt-1">' . app_h($e->getFile() . ':' . $e->getLine()) . '</p>'
            : '<p>If this keeps happening, contact your system administrator with the reference below.</p>',
        500,
        $ref
    );
}

/** Configure error reporting, log fatals, and send baseline security headers. */
function app_register_handlers(): void
{
    static $registered = false;
    if ($registered) {
        return;
    }
    $registered = true;

    error_reporting(E_ALL);
    ini_set('display_errors', '0');          // never print SQL / paths to the browser
    ini_set('log_errors', '1');

    set_exception_handler('app_handle_exception');

    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: same-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

        // Strict CSP: scripts must be files or carry the per-request nonce. Inline event
        // handlers (onclick=) are not allowed anywhere; use data-* attributes + app.js.
        if (!defined('APP_CSP_NONCE')) {
            define('APP_CSP_NONCE', rtrim(strtr(base64_encode(random_bytes(12)), '+/', '-_'), '='));
        }
        header("Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; "
            . "form-action 'self'; img-src 'self' data:; font-src 'self' https://fonts.gstatic.com data:; "
            . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
            . "script-src 'self' 'nonce-" . APP_CSP_NONCE . "'; connect-src 'self'");
    }

    // Fatals (e.g. out-of-memory, parse-time failures in an included file) bypass the exception handler.
    register_shutdown_function(function (): void {
        $error = error_get_last();
        if (!$error || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }
        app_log_line(sprintf('FATAL: %s in %s:%d', $error['message'], $error['file'], $error['line']));
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        app_error_page(
            'Something went wrong',
            'This page stopped unexpectedly. The error has been recorded for the administrator.',
            'Please try again. If it repeats, contact the administrator with the reference below.',
            500,
            strtoupper(substr(sha1($error['message'] . $error['file'] . $error['line']), 0, 8))
        );
    });
}
