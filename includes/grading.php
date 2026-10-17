<?php
// ── Shared grading / weighted scoring model ─────────────────────────────
// Single source of truth for the formula and thresholds that were
// previously duplicated across csv_upload.php, save_marks.php,
// admin_dashboard.php and manage_students.php. Values are unchanged from
// the original implementation (csv_upload.php) — this is a pure refactor.
//
//   total_score = Assignment×20% + Midterm×30% + Final Exam×40% + Attendance×10%
//   >= 80 Excellent | >= 60 Pass | >= 50 At Risk | < 50 Fail
//
// predict.php's What-If Calculator mirrors this same formula in
// client-side JavaScript on purpose — it has to run instantly in the
// browser without a server round trip, so it can't call this file.
// transcript.php uses a separate, intentionally different letter-grade
// scale (A+/A/A-/B+.../F) for official GPA calculation — that's a
// distinct business rule, not a duplicate of this one, and is untouched.

// Weight of each mark component in the total. The predictions and
// early_risk_predictions views (database/migrations) use the same numbers.
const MARK_WEIGHTS = [
    'assignment' => 0.20,
    'midterm'    => 0.30,
    'final_exam' => 0.40,
    'attendance' => 0.10,
];

// Total needed for the "Pass" band; predict.php's target too.
const PASS_MARK = 60;

function compute_total_score($assignment, $midterm, $final_exam, $attendance) {
    return ($assignment * MARK_WEIGHTS['assignment']) + ($midterm * MARK_WEIGHTS['midterm'])
         + ($final_exam * MARK_WEIGHTS['final_exam']) + ($attendance * MARK_WEIGHTS['attendance']);
}

function predicted_status($total_score) {
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
