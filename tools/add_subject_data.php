<?php
require '../config/config.php';
require '../includes/obe.php';

// ── ONE-TIME SETUP GUARD ────────────────────────────────────────────────
// Bootstrap utility for a fresh install only. The proper, ongoing way to
// add subjects is the authenticated "Add New Subject" form in
// manage_subjects.php. Once ANY subject exists, this script permanently
// refuses to run again so it isn't left as a standing unauthenticated
// endpoint that can write to the database.
$stmt_any_subject = mysqli_prepare($conn, "SELECT id FROM subjects LIMIT 1");
mysqli_stmt_execute($stmt_any_subject);
$any_subject_exists = mysqli_num_rows(mysqli_stmt_get_result($stmt_any_subject)) > 0;
mysqli_stmt_close($stmt_any_subject);

if ($any_subject_exists) {
    http_response_code(403);
    echo "Setup already completed — subjects already exist. This script is now disabled. Add further subjects from the admin panel (Manage Subjects).";
    exit();
}

// Code, name, credit hours.
$subjects = [
    ['EC2301', 'Database Development',        3.0],
    ['EC2302', 'Internet Programming',        3.0],
    ['EC2303', 'Object Oriented Programming', 3.0],
    ['EC2304', 'Computer Networks',           3.0],
    ['EC2305', 'Human Computer Interaction',  3.0],
    ['EC2306', 'Operating Systems',           3.0],
    ['EC2320', 'Summative Computing Project', 4.0],
];

$inserted = 0;
$stmt_ins = mysqli_prepare($conn, "INSERT INTO subjects (subject_code, subject_name, credit_hours) VALUES (?, ?, ?)");
foreach ($subjects as $sub) {
    mysqli_stmt_bind_param($stmt_ins, "ssd", $sub[0], $sub[1], $sub[2]);
    if (mysqli_stmt_execute($stmt_ins)) {
        // Default learning outcomes (CLO1–CLO4), edited later under Subjects.
        obe_seed_subject($conn, mysqli_insert_id($conn));
        $inserted++;
    }
}
mysqli_stmt_close($stmt_ins);

echo "Done! $inserted subjects added to the database. This script is now permanently disabled.";
?>
