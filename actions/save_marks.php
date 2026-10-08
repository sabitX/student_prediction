<?php
require '../includes/session_check.php';
require '../config/config.php';
require '../includes/csrf.php';
require '../includes/semester.php';
require '../includes/marks.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['save_all'])) {
    header("Location: ../lecturer/enter_marks.php");
    exit();
}

if (!csrf_verify()) {
    header("Location: ../lecturer/enter_marks.php?save_error=1");
    exit();
}

$user_id     = (int)$_SESSION['user_id'];
$subject_id  = (int)($_POST['subject_id'] ?? 0);
$semester_id = get_active_semester_id($conn);
$back        = "../lecturer/enter_marks.php?subject_id=" . $subject_id;

// ── AUTHORIZATION: never trust subject_id from a hidden field — verify this
// lecturer is actually assigned to it, and that it isn't archived. ────────
$stmt_own = mysqli_prepare($conn, "SELECT sub.subject_code FROM lecturer_subjects ls
                                   JOIN subjects sub ON sub.id = ls.subject_id
                                   WHERE ls.user_id = ? AND ls.subject_id = ? AND sub.is_archived = 0");
mysqli_stmt_bind_param($stmt_own, "ii", $user_id, $subject_id);
mysqli_stmt_execute($stmt_own);
$subject = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_own));
mysqli_stmt_close($stmt_own);

if (!$subject) {
    header("Location: ../lecturer/lecturer_dashboard.php");
    exit();
}

// Marks always belong to a semester; without an active one there is
// nowhere valid to store them.
if ($semester_id === 0) {
    header("Location: $back&save_error=nosemester");
    exit();
}

// ── Re-fetch the roster server-side — only students actually enrolled in
// this subject for the active semester are ever processed. ──────────────
$stmt_roster = mysqli_prepare($conn, "SELECT s.id AS std_db_id, s.student_id
                                      FROM student_enrollments e JOIN students s ON s.id = e.student_id
                                      WHERE e.subject_id = ? AND e.semester_id = ?
                                      ORDER BY s.id");
mysqli_stmt_bind_param($stmt_roster, "ii", $subject_id, $semester_id);
mysqli_stmt_execute($stmt_roster);
$roster = mysqli_fetch_all(mysqli_stmt_get_result($stmt_roster), MYSQLI_ASSOC);
mysqli_stmt_close($stmt_roster);

$ctx = [
    'user_id'      => $user_id,
    'user_name'    => $_SESSION['full_name'],
    'source'       => 'Manual Mark Entry',
    'subject_code' => $subject['subject_code'],
];

$saved_count   = 0;
$skipped_count = 0;

mysqli_begin_transaction($conn);
try {
    foreach ($roster as $student) {
        $std_db_id = (int)$student['std_db_id'];

        // This student's row wasn't part of the submitted grid at all — skip.
        if (!isset($_POST["assignment_$std_db_id"])) {
            continue;
        }

        $raw = [];
        foreach (MARK_FIELDS as $key => $label) {
            $raw[$key] = $_POST["{$key}_$std_db_id"] ?? '';
        }

        // Any invalid value skips the whole row so nothing half-valid is stored.
        [$marks, $errors] = parse_mark_set($raw);
        if (!empty($errors)) {
            $skipped_count++;
            continue;
        }

        $ctx['student_code'] = $student['student_id'];
        $all_blank = !array_filter($marks, fn($v) => $v !== null);
        if ($all_blank) {
            // The grid is pre-filled, so an all-blank row means either "no
            // marks yet" (skip — never create an empty row) or the lecturer
            // deliberately cleared existing marks (save, and it's audited).
            $stmt_has = mysqli_prepare($conn, "SELECT 1 FROM performance WHERE student_id = ? AND subject_id = ? AND semester_id = ?");
            mysqli_stmt_bind_param($stmt_has, "iii", $std_db_id, $subject_id, $semester_id);
            mysqli_stmt_execute($stmt_has);
            $has_marks = mysqli_num_rows(mysqli_stmt_get_result($stmt_has)) > 0;
            mysqli_stmt_close($stmt_has);
            if (!$has_marks) {
                continue;
            }
        }

        if (save_student_marks($conn, $std_db_id, $subject_id, $semester_id, $marks, $ctx) === 'saved') {
            $saved_count++;
        }
    }
    mysqli_commit($conn);
} catch (mysqli_sql_exception $e) {
    mysqli_rollback($conn);
    error_log('save_marks failed: ' . $e->getMessage());
    header("Location: $back&save_error=1");
    exit();
}

header("Location: $back&saved=" . $saved_count . "&skipped=" . $skipped_count);
exit();
