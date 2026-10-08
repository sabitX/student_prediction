<?php
// ── Reusable CSRF protection ────────────────────────────────────────────
// Include after session_start(). One token per session, regenerated only
// when the session itself is renewed (not per-form), so multiple forms on
// the same page all work without invalidating each other.
//
// Usage:
//   In a form:       echo csrf_field();
//   In its handler:  if (!csrf_verify()) { reject, matching the page's
//                     existing error/redirect pattern }

function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function csrf_verify() {
    if (empty($_SESSION['csrf_token']) || empty($_POST['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}
?>
