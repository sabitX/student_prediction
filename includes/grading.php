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

function compute_total_score($assignment, $midterm, $final_exam, $attendance) {
    return ($assignment * 0.20) + ($midterm * 0.30) + ($final_exam * 0.40) + ($attendance * 0.10);
}

function predicted_status($total_score) {
    if ($total_score >= 80) return 'Excellent';
    if ($total_score >= 60) return 'Pass';
    if ($total_score >= 50) return 'At Risk';
    return 'Fail';
}

// Bootstrap badge color name for a given status (used with bg-<name>).
function status_badge_class($status) {
    return match ($status) {
        'Excellent' => 'success',
        'Pass'      => 'primary',
        'At Risk'   => 'warning',
        'Fail'      => 'danger',
        default     => 'secondary',
    };
}

// Hex color for chart contexts (Chart.js, etc.) that need a raw color value.
function status_hex_color($status) {
    return match ($status) {
        'Excellent' => '#198754',
        'Pass'      => '#2563EB',
        'At Risk'   => '#F59E0B',
        'Fail'      => '#DC2626',
        default     => '#64748B',
    };
}
?>
