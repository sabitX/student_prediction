<?php
require '../config/config.php';
require '../includes/passwords.php';

// ── ONE-TIME SETUP GUARD ────────────────────────────────────────────────
// Bootstrap utility for a fresh install only. The proper, ongoing way to
// add lecturers is the authenticated "Add New Lecturer" form in
// manage_lecturers.php. Once ANY lecturer account exists, this script
// permanently refuses to run again so it can't be used as a standing
// backdoor to (re)create a known-credential lecturer account.
$stmt_any_lecturer = mysqli_prepare($conn, "SELECT id FROM users WHERE role = 'lecturer' LIMIT 1");
mysqli_stmt_execute($stmt_any_lecturer);
$any_lecturer_exists = mysqli_num_rows(mysqli_stmt_get_result($stmt_any_lecturer)) > 0;
mysqli_stmt_close($stmt_any_lecturer);

if ($any_lecturer_exists) {
    http_response_code(403);
    echo "Setup already completed — a lecturer account already exists. This script is now disabled. Add further lecturers from the admin panel (Manage Lecturers).";
    exit();
}

$full_name = "Lecturer";
$email     = "lecturer@nilai.edu.my";
// A random temporary password, shown once below — never a fixed,
// published one. It must be changed at first sign-in.
$temp      = generate_temp_password(12);
$password  = password_hash($temp, PASSWORD_DEFAULT);
$role      = "lecturer";

$stmt_ins = mysqli_prepare($conn, "INSERT INTO users (full_name, email, password, role, must_change_password) VALUES (?, ?, ?, ?, 1)");
mysqli_stmt_bind_param($stmt_ins, "ssss", $full_name, $email, $password, $role);

if (mysqli_stmt_execute($stmt_ins)) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "Lecturer account created.

Email:              lecturer@nilai.edu.my
Temporary password: $temp

"
       . "Copy the password now: it is shown only once. You will be asked to choose your own password at first sign-in.
"
       . "This script is now permanently disabled.";
} else {
    echo "Failed to create lecturer account.";
}
mysqli_stmt_close($stmt_ins);
?>
