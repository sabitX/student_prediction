<?php
// ── Application bootstrap ───────────────────────────────────────────────
// Every page requires this file. It loads the environment settings,
// sets the timezone, configures error handling and opens the database.

$env_file = __DIR__ . '/env.php';
if (!is_file($env_file)) {
    http_response_code(503);
    error_log('SPTS: config/env.php is missing — copy config/env.example.php and fill it in.');
    die("The system is not configured yet. Please contact the administrator.");
}
$env = require $env_file;

define('APP_ENV', $env['APP_ENV'] ?? 'production');
define('APP_DEBUG', APP_ENV === 'development');

// One timezone for PHP and MySQL, so "now", session times and printed
// dates all agree (PHP and MySQL defaults can differ by hours).
date_default_timezone_set($env['TIMEZONE'] ?? 'Asia/Kuala_Lumpur');

// Log everything; only show errors in the page during development.
// In production, details (paths, SQL) never reach the visitor.
error_reporting(E_ALL);
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');

// Friendly error page for anything unexpected (uncaught exceptions such
// as a failed query), instead of a blank screen or a stack trace.
// URL path of the app root (e.g. "/student_prediction"), from any page.
function app_base_path() {
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    // Pages live at most one folder deep; step up to the app root.
    if (preg_match('#/(admin|lecturer|student|auth|actions|tools)$#', $base)) {
        $base = dirname($base);
    }
    return rtrim(str_replace('\\', '/', $base), '/');
}

function render_error_page($code, $title, $message) {
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: text/html; charset=utf-8');
    }
    $base = app_base_path();
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">'
       . '<title>' . htmlspecialchars($title) . ' | SPTS Nilai University</title>'
       . '<link href="' . htmlspecialchars($base) . '/assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet"></head>'
       . '<body class="d-flex align-items-center justify-content-center min-vh-100 bg-light"><div class="text-center px-3" style="max-width:480px;">'
       . '<h1 class="display-4 fw-bold text-primary">' . (int)$code . '</h1><h4 class="mb-3">' . htmlspecialchars($title) . '</h4>'
       . '<p class="text-muted">' . htmlspecialchars($message) . '</p>'
       . '<a href="' . htmlspecialchars($base) . '/index.php" class="btn btn-primary mt-2">Go to Home</a></div></body></html>';
}

set_exception_handler(function (Throwable $e) {
    error_log('SPTS uncaught ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (APP_DEBUG) {
        echo '<pre>' . htmlspecialchars((string)$e) . '</pre>';
        return;
    }
    render_error_page(500, 'Something went wrong', 'An unexpected error occurred and has been logged. Please try again, or contact the administrator if it keeps happening.');
});

// ── Database ────────────────────────────────────────────────────────────
// Connects as a least-privilege account (see README "Database user").
try {
    $conn = mysqli_connect($env['DB_HOST'], $env['DB_USER'], $env['DB_PASSWORD'], $env['DB_NAME']);
    mysqli_set_charset($conn, 'utf8mb4');
    // Compare text the same way the tables do. Server defaults differ
    // (MariaDB 11.8 uses utf8mb4_uca1400_ai_ci, XAMPP utf8mb4_general_ci),
    // and mixing collations makes some comparisons fail outright.
    mysqli_query($conn, "SET collation_connection = 'utf8mb4_unicode_ci'");
    // Strict mode: invalid values (bad enum, NULL in NOT NULL, too-long
    // text) are rejected instead of being silently changed. XAMPP's default
    // server mode isn't strict, so it is set here for every connection.
    mysqli_query($conn, "SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION'");
    // Match MySQL's NOW() to PHP's timezone.
    mysqli_query($conn, "SET time_zone = '" . (new DateTime())->format('P') . "'");
} catch (mysqli_sql_exception $e) {
    error_log('SPTS DB connection failed: ' . $e->getMessage());
    render_error_page(503, 'Temporarily unavailable', 'The system is temporarily unavailable. Please try again later.');
    exit();
}

// Settings other code reads later (mail, APP_URL), without the database
// credentials, which aren't needed once connected.
$GLOBALS['spts_settings'] = array_diff_key($env, array_flip(['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD']));
unset($env);

function setting($key, $default = null) {
    $value = $GLOBALS['spts_settings'][$key] ?? null;
    return ($value === null || $value === '') ? $default : $value;
}

// Can visitors create their own student accounts? Off unless env.php turns
// it on: an open form lets anyone claim any Student ID with an unverified
// email. When off, the admin office creates students (Students page).
function registration_open() {
    return (bool)setting('ALLOW_SELF_REGISTRATION', false);
}

// ── Signed-in account still valid? ──────────────────────────────────────
// Every page loads the session before this file, so this runs on every
// signed-in request. The session ends at once if the account was deleted,
// its role changed, or its password changed somewhere else (e.g. an admin
// reset it) — instead of staying usable until the idle timeout.
// pw_fp is a fingerprint of the password hash, set at sign-in.
if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['user_id'])) {
    $stmt = mysqli_prepare($conn, "SELECT role, full_name, password_hash FROM users WHERE id = ?");
    $acct_id = (int)$_SESSION['user_id'];
    mysqli_stmt_bind_param($stmt, "i", $acct_id);
    mysqli_stmt_execute($stmt);
    $acct = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    $acct_fp = $acct ? hash('sha256', $acct['password_hash']) : '';
    $_SESSION['pw_fp'] ??= $acct_fp;   // sessions from before this check existed

    if (!$acct || $acct['role'] !== ($_SESSION['role'] ?? null) || !hash_equals($_SESSION['pw_fp'], $acct_fp)) {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['account_changed'] = true;
        header('Location: ' . app_base_path() . '/auth/login.php');
        exit();
    }
    $_SESSION['full_name'] = $acct['full_name'];
    unset($acct, $acct_fp, $acct_id, $stmt);
}

// XSS Protection helper — use h() instead of echo for all user data
function h($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}
?>
