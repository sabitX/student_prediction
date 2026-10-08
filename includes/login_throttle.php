<?php
// ── Login / reset rate limiting ──────────────────────────────────────────
// Lightweight, DB-backed throttle against unlimited password guessing.
// This is a sliding time window, not a permanent lockout — it simply
// expires on its own, so a legitimate user who mistypes their password a
// few times is never stuck waiting on an admin to unlock them.
//
// The login_attempts table is created by database/schema.sql (or the
// 001_fault_fixes.sql migration). The application's DB account has no
// CREATE privilege, so it is no longer created on the fly here.
//
// $scope keeps separate counters per form ('login', 'reset') so failed
// password-reset requests can't be used to lock someone out of login.

define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_WINDOW_MINUTES', 15);

// Keyed on email + IP together, so guessing many passwords for one email
// from a single machine is throttled, without one shared IP (e.g. a
// campus NAT) locking out every student who happens to share it.
// Stored as a SHA-256 hash: always 64 characters, whatever was typed into
// the email box (the column is VARCHAR(191) and the database is strict).
function login_throttle_identifier($email, $scope = 'login') {
    $ip     = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $prefix = ($scope === 'login') ? '' : $scope . ':';
    return hash('sha256', $prefix . strtolower(trim((string)$email)) . '|' . $ip);
}

function login_is_throttled($conn, $email, $scope = 'login') {
    $id   = login_throttle_identifier($email, $scope);
    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) as c FROM login_attempts WHERE identifier = ? AND attempted_at > (NOW() - INTERVAL " . LOGIN_WINDOW_MINUTES . " MINUTE)");
    mysqli_stmt_bind_param($stmt, "s", $id);
    mysqli_stmt_execute($stmt);
    $count = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['c'];
    mysqli_stmt_close($stmt);
    return $count >= LOGIN_MAX_ATTEMPTS;
}

function login_record_failed_attempt($conn, $email, $scope = 'login') {
    $id   = login_throttle_identifier($email, $scope);
    $stmt = mysqli_prepare($conn, "INSERT INTO login_attempts (identifier) VALUES (?)");
    mysqli_stmt_bind_param($stmt, "s", $id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    // Only the last LOGIN_WINDOW_MINUTES matter; drop anything older than a
    // day so the table doesn't grow forever (uses idx_attempted_at).
    mysqli_query($conn, "DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 1 DAY");
}

function login_clear_attempts($conn, $email, $scope = 'login') {
    $id   = login_throttle_identifier($email, $scope);
    $stmt = mysqli_prepare($conn, "DELETE FROM login_attempts WHERE identifier = ?");
    mysqli_stmt_bind_param($stmt, "s", $id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}
?>
