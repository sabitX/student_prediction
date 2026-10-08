<?php
require '../config/config.php';
require '../includes/passwords.php';

// ── ONE-TIME SETUP GUARD ────────────────────────────────────────────────
// This script is meant to be run exactly once, on a fresh install, per the
// README's first-time setup steps. Once ANY admin account exists — not just
// this specific default one — it permanently refuses to run again, so it
// can never be used as a standing backdoor to (re)create a known-credential
// admin account after initial setup.
$stmt_any_admin = mysqli_prepare($conn, "SELECT id FROM users WHERE role = 'admin' LIMIT 1");
mysqli_stmt_execute($stmt_any_admin);
$any_admin_exists = mysqli_num_rows(mysqli_stmt_get_result($stmt_any_admin)) > 0;
mysqli_stmt_close($stmt_any_admin);

if ($any_admin_exists) {
    http_response_code(403);
    echo "Setup already completed — an admin account already exists. This script is now disabled. If you need another admin account, create one from an existing admin session, or insert one directly via the database.";
    exit();
}

$full_name = "Admin";
$email     = "admin@student.com";
// A random temporary password, shown once below — never a fixed,
// published one. It must be changed at first sign-in.
$temp      = generate_temp_password(12);
$password  = password_hash($temp, PASSWORD_DEFAULT);
$role      = "admin";

$stmt_ins = mysqli_prepare($conn, "INSERT INTO users (full_name, email, password, role, must_change_password) VALUES (?, ?, ?, ?, 1)");
mysqli_stmt_bind_param($stmt_ins, "ssss", $full_name, $email, $password, $role);

if (mysqli_stmt_execute($stmt_ins)) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "Admin account created.

Email:              admin@student.com
Temporary password: $temp

"
       . "Copy the password now: it is shown only once. You will be asked to choose your own password at first sign-in.
"
       . "This script is now permanently disabled.";
} else {
    echo "Failed to create admin account.";
}
mysqli_stmt_close($stmt_ins);
?>
