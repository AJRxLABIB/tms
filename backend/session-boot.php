<?php
/**
 * Single place that starts the PHP session with hardened cookie settings.
 *
 * Why this exists: most pages used to call a bare session_start() on their first line, BEFORE
 * backend/config.php ran, so the HttpOnly / SameSite / Secure settings in config.php never applied
 * (verified: the PHPSESSID cookie was sent without HttpOnly or SameSite). Pages now require this file
 * instead; config.php still starts the session itself for any entry point that has not been converted.
 */
if (session_status() === PHP_SESSION_NONE) {
    $tmsHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    ini_set('session.use_strict_mode', '1');   // refuse session IDs the server did not issue (fixation)
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => $tmsHttps, 'path' => '/']);
    session_start();
}
