<?php
require '../includes/session_check.php';
require '../config/config.php';
require '../includes/semester.php';
require '../includes/audit.php';
require '../includes/export.php';

// ── Result sheet (CSV) for one of this lecturer's subjects ──────────────
// The active semester's enrolled students with their marks so far, total,
// status and remark. Only a subject this lecturer teaches this semester.

$user_id     = (int)$_SESSION['user_id'];
$subject_id  = (int)($_GET['subject_id'] ?? 0);
$semester_id = get_active_semester_id($conn);

$stmt = mysqli_prepare($conn, "SELECT sub.subject_code FROM lecturer_subjects ls JOIN subjects sub ON sub.id = ls.subject_id
                               WHERE ls.user_id = ? AND ls.subject_id = ? AND ls.semester_id = ?");
mysqli_stmt_bind_param($stmt, "iii", $user_id, $subject_id, $semester_id);
mysqli_stmt_execute($stmt);
$subject = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$subject) {
    set_page_notice('denied', "You can only download results for a subject you teach this semester.");
    header("Location: enter_marks.php");
    exit();
}

$semester_name = get_active_semester_name($conn);
audit_log($conn, 'Results exported', "Subject: {$subject['subject_code']} | Semester: $semester_name");
csv_download("{$subject['subject_code']}_{$semester_name}_results.csv", EXPORT_RESULT_HEADER,
             export_result_rows($conn, $semester_id, $subject_id));
?>
