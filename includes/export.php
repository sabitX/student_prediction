<?php
// ── CSV downloads (result sheets, at-risk lists) ────────────────────────
// Used by lecturer/export.php and admin/export.php. Files open directly in
// Excel: UTF-8 with a byte-order mark, so names with accents survive.

require_once __DIR__ . '/early_warning.php';

// A cell that starts with = + - @ (or a tab / carriage return) would run
// as a formula in Excel; prefix it with ' so it is shown as text. Numbers
// are left alone so negative values still work as numbers.
function csv_safe_cell($value) {
    if ($value === null) {
        return '';
    }
    if (is_int($value) || is_float($value)) {
        return $value;
    }
    $value = (string)$value;
    if ($value !== '' && !is_numeric($value) && strpbrk($value[0], "=+-@\t\r") !== false) {
        return "'" . $value;
    }
    return $value;
}

// Sends the rows as a CSV attachment and ends the request.
function csv_download($filename, array $header, array $rows) {
    $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    // Standard CSV (RFC 4180): quotes doubled, no backslash escape. Passed
    // explicitly: PHP 8.4 deprecates relying on the default.
    fputcsv($out, $header, ',', '"', '');
    foreach ($rows as $row) {
        fputcsv($out, array_map('csv_safe_cell', $row), ',', '"', '');
    }
    fclose($out);
    exit();
}

// Every enrolment in one semester (optionally one subject), with marks so
// far, total, status and remark, for a result sheet.
function export_result_rows($conn, $semester_id, $subject_id = null) {
    $sql = "SELECT sub.subject_code, sub.subject_name, s.student_id, u.full_name, c.course_name,
                   p.assignment, p.midterm, p.final_exam, p.attendance, pr.total_score, pr.result_status, " . WEIGHTS_COLS . "
            FROM student_enrollments e
            JOIN subjects sub ON sub.id = e.subject_id
            JOIN students s ON s.id = e.student_id
            JOIN users u ON u.id = s.user_id
            JOIN courses c ON c.id = s.course_id
            LEFT JOIN performance p ON p.enrollment_id = e.id
            " . WEIGHTS_JOIN . "
            LEFT JOIN predictions pr ON pr.student_id = e.student_id AND pr.subject_id = e.subject_id AND pr.semester_id = e.semester_id
            WHERE e.semester_id = ?" . ($subject_id ? " AND e.subject_id = ?" : '') . "
            ORDER BY sub.subject_code, s.student_id";
    $stmt = mysqli_prepare($conn, $sql);
    if ($subject_id) {
        mysqli_stmt_bind_param($stmt, "ii", $semester_id, $subject_id);
    } else {
        mysqli_stmt_bind_param($stmt, "i", $semester_id);
    }
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);

    $out = [];
    foreach ($rows as $r) {
        $remark = '';
        if ($r['attendance'] !== null && (float)$r['attendance'] < ATTENDANCE_BAR) {
            $remark = 'BE (attendance below ' . ATTENDANCE_BAR . '%)';
        } elseif ($r['total_score'] === null) {
            $remark = 'Marks incomplete';
        }
        $out[] = [
            $r['subject_code'], $r['subject_name'], $r['student_id'], $r['full_name'], $r['course_name'],
            $r['assignment'], $r['midterm'], $r['final_exam'], $r['attendance'],
            weights_label(row_weights($r), true),
            $r['total_score'], $r['result_status'] ?? 'Pending', $remark,
        ];
    }
    return $out;
}

const EXPORT_RESULT_HEADER = ['Subject Code', 'Subject', 'Student ID', 'Name', 'Programme',
                              'Assignment', 'Midterm', 'Final Exam', 'Attendance', 'Weights',
                              'Total', 'Status', 'Remark'];

// Students needing attention in one semester: final results At Risk or
// Fail, and early warnings for those whose final exam isn't marked yet.
function export_at_risk_rows($conn, $semester_id) {
    $out = [];
    foreach (early_warnings($conn, $semester_id, 'all', 0) as $ew) {
        $a = $ew['analysis'];
        if ($a['final_marked']) {
            $status = result_status((float)$a['current']);
            if (!in_array($status, ['At Risk', 'Fail'], true)) continue;
            $stage = 'Final result';
        } else {
            if ($a['level'] === 'ok') continue;
            $status = risk_label($a['level']);
            $stage = 'Before final exam';
        }
        $out[] = [
            $ew['subject_code'], $ew['student_code'], $ew['full_name'], $ew['email'], $stage, $status,
            $a['final_marked'] ? $a['current'] : $a['projected'],
            $a['min_final'] === null ? '' : ($a['min_final'] > 100 ? 'over 100' : $a['min_final']),
            implode(' | ', array_column($a['reasons'], 'text')),
            $ew['notified_at'] ? date('Y-m-d', strtotime($ew['notified_at'])) : '',
        ];
    }
    return $out;
}

const EXPORT_AT_RISK_HEADER = ['Subject Code', 'Student ID', 'Name', 'Email', 'Stage', 'Status',
                               'Total / Projected', 'Final Needed to Pass', 'Reasons', 'Student Emailed'];
?>
