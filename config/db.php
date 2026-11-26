<?php
// Database connection (PDO, PostgreSQL) and environment configuration.
// Credentials come from .env (see .env.example) or real environment variables.
//
// The database is hosted on Supabase (PostgreSQL). Supabase gives you two connection
// strings: the "Session pooler" (port 6543, works on IPv4-only networks — recommended)
// and the direct connection (port 5432, requires IPv6 or the IPv4 add-on).

require_once __DIR__ . '/../includes/errors.php';

app_load_env(dirname(__DIR__) . '/.env');

define('DB_DRIVER', 'pgsql');
define('DB_HOST', app_env('DB_HOST', '127.0.0.1'));
define('DB_PORT', app_env('DB_PORT', '5432'));
define('DB_NAME', app_env('CDSS_DB', app_env('DB_NAME', 'postgres')));
define('DB_USER', app_env('DB_USER', 'postgres'));
define('DB_PASS', app_env('DB_PASS', ''));
// Supabase requires TLS. Set DB_SSL=disable only for a local Postgres used in development.
define('DB_SSL', app_env('DB_SSL', 'require'));
define('DB_CHARSET', 'utf8');

define('APP_NAME', 'GlucoCare');
// Positioned as a network-wide platform: the product is deployed across several hospitals,
// so no single institution is hard-coded into the interface.
define('PLATFORM_TAGLINE', 'Connected diabetes care');
define('PHASE', 4);

date_default_timezone_set('Africa/Kampala');

// Base URL of this phase folder, e.g. /Sophie/phase1/ (works for sub-folders such as /admin)
if (!defined('BASE_URL')) {
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $base = preg_replace('#/(patient|clinician|receptionist|admin|config|includes|api|bin)$#i', '', $scriptDir);
    define('BASE_URL', rtrim($base, '/') . '/');
}

// Absolute base URL for links created outside a request (email digests, CLI jobs).
// Leave empty to fall back to BASE_URL, which is only correct for web requests.
define('APP_URL', rtrim((string)app_env('APP_URL', ''), '/'));

function get_db_connection(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $dsn = 'pgsql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME
        . ';sslmode=' . DB_SSL . ';connect_timeout=10';
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // The Supabase pooler runs PgBouncer in transaction mode, where server-side
            // prepared statements are not safe. Emulated prepares keep every query working.
            PDO::ATTR_EMULATE_PREPARES   => true,
        ]);
        // The app stores local Kampala times; make now() agree with PHP's date().
        $pdo->exec("SET TIME ZONE 'Africa/Kampala'");
    } catch (PDOException $e) {
        app_log_exception($e);
        $reference = app_reference($e);
        $detail = strtolower($e->getMessage());

        if (str_contains($detail, 'does not exist') && str_contains($detail, 'database')) {
            app_error_page(
                'Database setup needed',
                'The database <strong>' . app_h(DB_NAME) . '</strong> does not exist yet.',
                'Create it in the Supabase dashboard, or run <code>php bin/migrate.php</code> once it exists.',
                503,
                $reference
            );
        }

        if (str_contains($detail, 'password authentication failed') || str_contains($detail, 'no pg_hba.conf entry')) {
            app_error_page(
                'Database sign-in failed',
                'The application could not sign in to Supabase with the configured credentials.',
                'Check <code>DB_USER</code> and <code>DB_PASS</code> in <code>.env</code>. For Supabase, the user is the pooler username '
                . '(<code>postgres.&lt;project-ref&gt;</code>) and the password is the project database password. '
                . 'The technical detail has been written to <code>logs/</code>.',
                503,
                $reference
            );
        }

        if (str_contains($detail, 'could not translate host name') || str_contains($detail, 'timeout expired')
            || str_contains($detail, 'connection refused')) {
            app_error_page(
                'Cannot reach the database',
                'The application could not connect to Supabase, so no records can be shown right now.',
                'Check your internet connection and <code>DB_HOST</code>/<code>DB_PORT</code> in <code>.env</code>. '
                . 'On networks without IPv6, use the Supabase <strong>Session pooler</strong> host and port <code>6543</code>. '
                . 'The technical detail has been written to <code>logs/</code>.',
                503,
                $reference
            );
        }

        app_error_page(
            'Cannot reach the database',
            'The application could not connect to its database, so no records can be shown right now.',
            'Check the Supabase connection settings in <code>.env</code>. The technical detail has been written to <code>logs/</code>.',
            503,
            $reference
        );
    }
    return $pdo;
}

