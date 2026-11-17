<?php
// Development server router (php -S 127.0.0.1:8099 router.php).
// Apache handles this with .htaccess; the built-in server ignores .htaccess, so guard here.
// Never used by Apache: it only returns false so the built-in server serves the file itself.

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (preg_match('#(^|/)\.#', $path) || preg_match('#^/logs/#', $path)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "403 Forbidden\n";
    return true;
}

return false;
