<?php
// ── Hardened session start + inactivity timeout ─────────────────────────
// Every page starts its session through this file instead of calling
// session_start() directly, so cookie flags and the idle timeout are
// applied consistently everywhere.

define('SESSION_IDLE_MINUTES', 30);

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', 1);
    session_set_cookie_params([
        'lifetime' => 0,               // browser-session cookie
        'path'     => '/',
        'httponly' => true,            // not readable from JavaScript
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}

// Signed-in users are logged out after SESSION_IDLE_MINUTES of inactivity.
// The flag lets login.php explain why the user was sent back to it.
if (isset($_SESSION['user_id'])) {
    $idle = time() - ($_SESSION['last_activity'] ?? time());
    if ($idle > SESSION_IDLE_MINUTES * 60) {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['timed_out'] = true;
    } else {
        $_SESSION['last_activity'] = time();
    }
}
?>
