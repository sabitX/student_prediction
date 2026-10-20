<?php
// ── Shared mark saving ──────────────────────────────────────────────────
// Used by both the lecturer mark-entry grid (actions/save_marks.php) and
// the CSV upload (lecturer/csv_upload.php) so the two can never disagree.
//
// Rules:
//  • Each component is either a number 0–100 or blank. Blank = "not
//    assessed yet" and is stored as NULL (never silently as 0), so marks
//    can be entered progressively through the semester.
//  • A weighted total + status (the predictions view) exists only once
//    all four components are present; the database calculates it from
//    the marks, so it is never stored separately.
//  • A marks row belongs to one enrolment (keyed by enrollment_id), so
//    writes are single atomic upserts — no duplicate rows.
//  • An audit entry records old → new values, and only when something
//    actually changed.

require_once __DIR__ . '/grading.php';

const MARK_FIELDS = [
    'assignment' => 'Assignment',
    'midterm'    => 'Midterm',
    'final_exam' => 'Final Exam',
    'attendance' => 'Attendance',
];

// Parses one raw mark. Returns [ok, value] where value is float|null.
// Accepts blank (null) or a plain number in 0–100; rejects anything else.
function parse_mark($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') {
        return [true, null];
    }
    if (!is_numeric($raw)) {
        return [false, null];
    }
    $v = (float)$raw;
    if ($v < 0 || $v > 100) {
        return [false, null];
    }
    return [true, round($v, 2)];
}

// Validates a set of raw marks keyed like MARK_FIELDS.
// Returns [parsed_marks, error_list]; error_list is empty when valid.
function parse_mark_set(array $raw) {
    $marks  = [];
    $errors = [];
    foreach (MARK_FIELDS as $key => $label) {
        [$ok, $val] = parse_mark($raw[$key] ?? '');
        if (!$ok) {
            $errors[] = "$label '" . trim((string)($raw[$key] ?? '')) . "' must be a number from 0 to 100 or left blank";
        }
        $marks[$key] = $val;
    }
    return [$marks, $errors];
}

function marks_complete(array $marks) {
    foreach (MARK_FIELDS as $key => $label) {
        if ($marks[$key] === null) return false;
    }
    return true;
}

function format_mark($v) {
    return $v === null ? '—' : rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
}

// Is this student enrolled in this subject for this semester?
function is_enrolled($conn, $student_db_id, $subject_id, $semester_id) {
    $stmt = mysqli_prepare($conn, "SELECT 1 FROM student_enrollments WHERE student_id = ? AND subject_id = ? AND semester_id = ?");
    mysqli_stmt_bind_param($stmt, "iii", $student_db_id, $subject_id, $semester_id);
    mysqli_stmt_execute($stmt);
    $ok = mysqli_num_rows(mysqli_stmt_get_result($stmt)) > 0;
    mysqli_stmt_close($stmt);
    return $ok;
}

// Saves one student's marks for one subject in one semester.
// $ctx = ['user_id', 'user_name', 'source' ('Manual Mark Entry'|'CSV Upload'),
//         'student_code', 'subject_code']
// Returns 'unchanged' or 'saved'. Throws mysqli_sql_exception on DB failure
// so callers can roll back their transaction.
function save_student_marks($conn, $student_db_id, $subject_id, $semester_id, array $marks, array $ctx) {
    // Existing values, for change detection and the audit trail.
    $stmt = mysqli_prepare($conn, "SELECT p.assignment, p.midterm, p.final_exam, p.attendance
                                   FROM performance p JOIN student_enrollments e ON e.id = p.enrollment_id
                                   WHERE e.student_id = ? AND e.subject_id = ? AND e.semester_id = ?");
    mysqli_stmt_bind_param($stmt, "iii", $student_db_id, $subject_id, $semester_id);
    mysqli_stmt_execute($stmt);
    $old = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    $changes = [];
    foreach (MARK_FIELDS as $key => $label) {
        $before = ($old && $old[$key] !== null) ? (float)$old[$key] : null;
        $after  = $marks[$key];
        if ($before !== $after) {
            $changes[] = "$label: " . format_mark($before) . " → " . format_mark($after);
        }
    }
    if ($old && empty($changes)) {
        return 'unchanged';
    }

    // Marks belong to the enrolment (performance.enrollment_id, migration
    // 010); callers have already checked the student is enrolled.
    $stmt = mysqli_prepare($conn, "INSERT INTO performance (enrollment_id, assignment, midterm, final_exam, attendance)
                                   SELECT id, ?, ?, ?, ? FROM student_enrollments
                                   WHERE student_id = ? AND subject_id = ? AND semester_id = ?
                                   ON DUPLICATE KEY UPDATE assignment = VALUES(assignment), midterm = VALUES(midterm),
                                                           final_exam = VALUES(final_exam), attendance = VALUES(attendance)");
    mysqli_stmt_bind_param($stmt, "ddddiii", $marks['assignment'], $marks['midterm'], $marks['final_exam'], $marks['attendance'],
                           $student_db_id, $subject_id, $semester_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    // The total and status are not stored: the predictions view calculates
    // them from these marks (database/migrations/006_predictions_view.sql).
    // Reading it back keeps the audit entry identical to what pages show.
    $stmt = mysqli_prepare($conn, "SELECT total_score, predicted_status FROM predictions WHERE student_id = ? AND subject_id = ? AND semester_id = ?");
    mysqli_stmt_bind_param($stmt, "iii", $student_db_id, $subject_id, $semester_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    $result_text = $result
        ? "Score: " . number_format((float)$result['total_score'], 2) . " | Status: {$result['predicted_status']}"
        : "Status: Pending (incomplete marks)";

    $details = "Student: {$ctx['student_code']} | Subject: {$ctx['subject_code']} | "
             . implode('; ', $changes) . " | $result_text";
    $stmt = mysqli_prepare($conn, "INSERT INTO audit_logs (user_id, actor_name, action, details) VALUES (?, ?, ?, ?)");
    mysqli_stmt_bind_param($stmt, "isss", $ctx['user_id'], $ctx['user_name'], $ctx['source'], $details);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    return 'saved';
}
?>
