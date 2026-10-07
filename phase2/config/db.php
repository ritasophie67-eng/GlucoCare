<?php
// Database connection (PDO). Adjust the values below to match your MySQL server.

define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3307');
define('DB_NAME', getenv('CDSS_DB') ?: 'glucocare');
define('DB_USER', 'root');
define('DB_PASS', '#Bwambale6');
define('DB_CHARSET', 'utf8mb4');

define('APP_NAME', 'GlucoCare');
define('HOSPITAL_NAME', 'Sir Albert Cook Hospital');
define('PHASE', 2);

date_default_timezone_set('Africa/Kampala');

// Base URL of this phase folder, e.g. /Sophie/phase1/ (works for sub-folders such as /admin)
if (!defined('BASE_URL')) {
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $base = preg_replace('#/(patient|clinician|admin|config|includes|api)$#i', '', $scriptDir);
    define('BASE_URL', rtrim($base, '/') . '/');
}

function get_db_connection(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec("SET time_zone = '+03:00'");
    } catch (PDOException $e) {
        error_log('DB connection failed: ' . $e->getMessage());
        $msg = ((int)$e->getCode() === 1049)
            ? 'The database <strong>' . DB_NAME . '</strong> does not exist yet. Import <code>database/schema.sql</code> first.'
            : 'Could not connect to the database. Check the settings in <code>config/db.php</code> and make sure MySQL is running.';
        http_response_code(500);
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Setup needed</title>'
           . '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"></head>'
           . '<body class="bg-light"><div class="container py-5" style="max-width:640px">'
           . '<div class="card shadow-sm border-0"><div class="card-body p-4">'
           . '<h4 class="text-primary">Database setup needed</h4><p>' . $msg . '</p>'
           . '<a href="" class="btn btn-primary">Retry</a></div></div></div></body></html>';
        exit;
    }
    return $pdo;
}
