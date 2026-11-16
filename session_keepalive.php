<?php
// session_keepalive.php
// Keeps the POS login persistent at the application level.
// Users remain logged in until they explicitly log out or the server/browser clears the session.
if (session_status() !== PHP_SESSION_ACTIVE) {
    @ini_set('session.gc_maxlifetime', '31536000');      // 365 days
    @ini_set('session.cookie_lifetime', '31536000');    // 365 days
    @ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => 31536000,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
?>
