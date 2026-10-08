<?php
// ── Authentication + role gate for every signed-in page ─────────────────
// Access is decided by the folder a page lives in, so new pages are
// protected automatically instead of having to be added to a list:
//   admin/     → admin only
//   lecturer/  → lecturer only
//   actions/   → lecturer only (form handlers for lecturer pages)
//   student/   → student only
//   anything else (e.g. auth/change_password.php) → any signed-in user
require_once __DIR__ . '/session.php';

$area_roles = [
    'admin'    => 'admin',
    'lecturer' => 'lecturer',
    'actions'  => 'lecturer',
    'student'  => 'student',
];
$area = basename(dirname($_SERVER['PHP_SELF']));

// One-time message shown as a toast on the next page (assets/js/app.js
// reads and clears it). A cookie rather than the session so it reaches
// every page, including those that don't share a layout include.
function set_page_notice($type, $message) {
    setrawcookie('spts_notice', rawurlencode($type . '|' . $message), [
        'expires'  => time() + 60,
        'path'     => '/',
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
}

if (!isset($_SESSION['user_id'])) {
    // A pasted staff link opens the staff sign-in instead of the student one.
    if (in_array($area_roles[$area] ?? '', ['admin', 'lecturer'], true)) {
        set_page_notice('denied', 'Access denied — that page is for staff only. Please sign in with a staff or admin account.');
        header("Location: ../auth/login.php?portal=staff");
    } else {
        // A timed-out session already gets its own message on the login page.
        if (empty($_SESSION['timed_out'])) {
            set_page_notice('info', 'Please sign in to continue.');
        }
        header("Location: ../auth/login.php");
    }
    exit();
}

$current_page = basename($_SERVER['PHP_SELF']);

function role_dashboard_path($role) {
    return match ($role) {
        'admin'    => '../admin/admin_dashboard.php',
        'lecturer' => '../lecturer/lecturer_dashboard.php',
        default    => '../student/student_dashboard.php',
    };
}

// Someone signed in with an admin-issued temporary password can't use the
// system until they have replaced it.
if (!empty($_SESSION['must_change_password']) && $current_page !== 'change_password.php') {
    header("Location: ../auth/change_password.php");
    exit();
}

if (isset($area_roles[$area]) && $_SESSION['role'] !== $area_roles[$area]) {
    $area_names = ['admin' => 'administrators', 'lecturer' => 'lecturers', 'student' => 'students'];
    set_page_notice('denied', "Access denied — that page is for {$area_names[$area_roles[$area]]} only. You've been returned to your dashboard.");
    header("Location: " . role_dashboard_path($_SESSION['role']));
    exit();
}
?>
