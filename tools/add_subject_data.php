<?php
require '../config/config.php';

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

$subjects = [
    ['EC2301', 'Database Development'],
    ['EC2302', 'Internet Programming'],
    ['EC2303', 'Object Oriented Programming'],
    ['EC2304', 'Computer Networks'],
    ['EC2305', 'Human Computer Interaction'],
    ['EC2306', 'Operating Systems'],
    ['EC2320', 'Summative Computing Project'],
];

$inserted = 0;
$stmt_ins = mysqli_prepare($conn, "INSERT INTO subjects (subject_code, subject_name) VALUES (?, ?)");
foreach ($subjects as $sub) {
    mysqli_stmt_bind_param($stmt_ins, "ss", $sub[0], $sub[1]);
    if (mysqli_stmt_execute($stmt_ins)) {
        $inserted++;
    }
}
mysqli_stmt_close($stmt_ins);

echo "Done! $inserted subjects added to the database. This script is now permanently disabled.";
?>
