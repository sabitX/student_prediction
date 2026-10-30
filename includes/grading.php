<?php
// ── Shared grading / weighted scoring model ─────────────────────────────
// Single source of truth for the formula and thresholds.
//
//   total_score = Assignment×A% + Midterm×M% + Final Exam×F% + Attendance×T%
//   >= 80 Excellent | >= 60 Pass | >= 50 At Risk | < 50 Fail
//
// The weights belong to each subject for each semester (assessment_weights,
// migration 012); a subject without its own uses MARK_WEIGHTS. The
// predictions and early_risk_predictions views apply the same rule.
// transcript.php uses a separate, intentionally different letter-grade
// scale (A+/A/A-/B+.../F) for official GPA calculation — that's a
// distinct business rule, not a duplicate of this one.

// Default 20/30/40/10 scheme, stored here as fractions totalling 1.00.
// Saved overrides live in assessment_weights as percentages totalling 100.
// WEIGHTS_COLS below and the SQL views in database/schema.sql mirror these
// defaults as COALESCE fallbacks; keep them aligned when changing defaults.
const MARK_WEIGHTS = [
    'assignment' => 0.20,
    'midterm'    => 0.30,
    'final_exam' => 0.40,
    'attendance' => 0.10,
];

// Each component's weight must be within this range (percent); together 100.
const WEIGHT_MIN = 5;
const WEIGHT_MAX = 80;

// SQL to read a row's weights next to performance p / enrolment e:
// add WEIGHTS_JOIN after the enrolment join and WEIGHTS_COLS to the SELECT.
const WEIGHTS_JOIN = "LEFT JOIN assessment_weights w ON w.subject_id = e.subject_id AND w.semester_id = e.semester_id";
const WEIGHTS_COLS = "COALESCE(w.weight_assignment, 20) / 100 AS w_assignment, COALESCE(w.weight_midterm, 30) / 100 AS w_midterm,
                      COALESCE(w.weight_final_exam, 40) / 100 AS w_final_exam, COALESCE(w.weight_attendance, 10) / 100 AS w_attendance";

// Total needed for the "Pass" band; predict.php's target too.
const PASS_MARK = 60;

// The weights (fractions) a row was read with (WEIGHTS_COLS or the
// early_risk_predictions view), or the defaults.
function row_weights(array $row) {
    if (!isset($row['w_assignment'])) {
        return MARK_WEIGHTS;
    }
    $weights = [];
    foreach (MARK_WEIGHTS as $component => $_) {
        $weights[$component] = (float)$row["w_$component"];
    }
    return $weights;
}

// One subject's weights (fractions) in one semester.
function assessment_weights($conn, $subject_id, $semester_id) {
    $stmt = mysqli_prepare($conn, "SELECT weight_assignment, weight_midterm, weight_final_exam, weight_attendance
                                   FROM assessment_weights WHERE subject_id = ? AND semester_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $subject_id, $semester_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$row) {
        return MARK_WEIGHTS;
    }
    $weights = [];
    foreach (MARK_WEIGHTS as $component => $_) {
        $weights[$component] = (float)$row["weight_$component"] / 100;
    }
    return $weights;
}

// Validates and saves one subject's weights for one semester. $percent:
// component => percentage. Returns an error message, or '' when saved.
function save_assessment_weights($conn, $subject_id, $semester_id, array $percent) {
    $values = [];
    foreach (MARK_WEIGHTS as $component => $_) {
        $v = $percent[$component] ?? '';
        if (!is_numeric($v) || round((float)$v, 2) != (float)$v) {
            return "Enter every weight as a number (up to 2 decimal places).";
        }
        $v = (float)$v;
        if ($v < WEIGHT_MIN || $v > WEIGHT_MAX) {
            return "Each weight must be between " . WEIGHT_MIN . "% and " . WEIGHT_MAX . "%.";
        }
        $values[$component] = $v;
    }
    if (abs(array_sum($values) - 100) > 0.001) {
        return "The weights add up to " . rtrim(rtrim(number_format(array_sum($values), 2), '0'), '.') . "%; they must add up to 100%.";
    }
    $stmt = mysqli_prepare($conn, "INSERT INTO assessment_weights (subject_id, semester_id, weight_assignment, weight_midterm, weight_final_exam, weight_attendance)
                                   VALUES (?, ?, ?, ?, ?, ?)
                                   ON DUPLICATE KEY UPDATE weight_assignment = VALUES(weight_assignment), weight_midterm = VALUES(weight_midterm),
                                                           weight_final_exam = VALUES(weight_final_exam), weight_attendance = VALUES(weight_attendance)");
    mysqli_stmt_bind_param($stmt, "iidddd", $subject_id, $semester_id,
        $values['assignment'], $values['midterm'], $values['final_exam'], $values['attendance']);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return '';
}

// "Assignment 20% · Midterm 30% · Final 40% · Attendance 10%"
function weights_label(array $weights, $short = false) {
    $names = $short ? ['assignment' => 'A', 'midterm' => 'M', 'final_exam' => 'F', 'attendance' => 'T']
                    : ['assignment' => 'Assignment', 'midterm' => 'Midterm', 'final_exam' => 'Final', 'attendance' => 'Attendance'];
    $parts = [];
    foreach ($names as $component => $name) {
        $parts[] = $name . ' ' . weight_pct($weights[$component]);
    }
    return implode(' · ', $parts);
}

// 0.2 → "20%", 0.125 → "12.5%"
function weight_pct($fraction) {
    return rtrim(rtrim(number_format($fraction * 100, 2, '.', ''), '0'), '.') . '%';
}

function compute_total_score($assignment, $midterm, $final_exam, $attendance, array $weights = MARK_WEIGHTS) {
    return ($assignment * $weights['assignment']) + ($midterm * $weights['midterm'])
         + ($final_exam * $weights['final_exam']) + ($attendance * $weights['attendance']);
}

// Classification of a completed final result. Early warnings use the same
// bands for their projected_status, without turning a projection into a result.
function result_status($total_score) {
    if ($total_score >= 80) return 'Excellent';
    if ($total_score >= 60) return 'Pass';
    if ($total_score >= 50) return 'At Risk';
    return 'Fail';
}

// CSS classes for a status badge: a soft tint with dark text, defined in
// assets/css/app.css (.badge-status-*): echo it into a span's class.
function status_badge_class($status) {
    return 'badge-status badge-status-' . match ($status) {
        'Excellent' => 'excellent',
        'Pass'      => 'pass',
        'At Risk'   => 'risk',
        'Fail'      => 'fail',
        default     => 'pending',
    };
}

// Hex color for charts and progress bars: a muted semantic palette (deep
// emerald / slate blue / ochre / brick), each at least 3:1 against white.
// $dark: lighter versions with 3:1+ against the dark-mode card (#1E293B).
function status_hex_color($status, $dark = false) {
    return match ($status) {
        'Excellent' => $dark ? '#4FA67E' : '#2F7D5B',
        'Pass'      => $dark ? '#7FA3CC' : '#4F79A8',
        'At Risk'   => $dark ? '#D2A24E' : '#B8862E',
        'Fail'      => $dark ? '#D27575' : '#B04A4A',
        default     => $dark ? '#64748B' : '#94A3B8',
    };
}

// The whole palette, keyed by status, for charts drawn in JavaScript.
function status_palette($dark = false) {
    $palette = [];
    foreach (['Excellent', 'Pass', 'At Risk', 'Fail'] as $status) {
        $palette[$status] = status_hex_color($status, $dark);
    }
    return $palette;
}
?>
