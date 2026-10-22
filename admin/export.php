<?php
require '../includes/session_check.php';
require '../config/config.php';
require '../includes/semester.php';
require '../includes/audit.php';
require '../includes/export.php';

// ── Admin CSV downloads for one semester ────────────────────────────────
//   ?type=results  every enrolment: marks, weights, total, status, remark
//   ?type=at_risk  final results At Risk / Fail, plus early warnings for
//                  students whose final exam isn't marked yet
// &semester_id= picks the semester (default: the active one).

$type        = ($_GET['type'] ?? '') === 'at_risk' ? 'at_risk' : 'results';
$semester_id = (int)($_GET['semester_id'] ?? 0) ?: get_active_semester_id($conn);

$stmt = mysqli_prepare($conn, "SELECT semester_name FROM semesters WHERE id = ?");
mysqli_stmt_bind_param($stmt, "i", $semester_id);
mysqli_stmt_execute($stmt);
$semester = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$semester) {
    set_page_notice('denied', "That semester doesn't exist.");
    header("Location: manage_semesters.php");
    exit();
}

$name = $semester['semester_name'];
if ($type === 'at_risk') {
    audit_log($conn, 'At-risk list exported', "Semester: $name");
    csv_download("{$name}_at_risk.csv", EXPORT_AT_RISK_HEADER, export_at_risk_rows($conn, $semester_id));
}
audit_log($conn, 'Results exported', "All subjects | Semester: $name");
csv_download("{$name}_results.csv", EXPORT_RESULT_HEADER, export_result_rows($conn, $semester_id));
?>
