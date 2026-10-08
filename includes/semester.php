<?php
// ── Active semester helper ───────────────────────────────────────────────
// Single source of truth for "what semester is active right now", read
// from the semesters table (managed in admin/manage_semesters.php).
// Marks, results and enrolments are all stored against a semester, so
// every page that reads or writes them scopes to the active one.

function get_active_semester($conn) {
    $result = mysqli_query($conn, "SELECT id, semester_name, start_date, end_date FROM semesters WHERE is_active = 1 LIMIT 1");
    return $result ? mysqli_fetch_assoc($result) : null;
}

// 0 when no semester is active — callers must refuse to save marks then.
function get_active_semester_id($conn) {
    $sem = get_active_semester($conn);
    return $sem ? (int)$sem['id'] : 0;
}

function get_active_semester_name($conn) {
    $sem = get_active_semester($conn);
    return $sem ? $sem['semester_name'] : 'No active semester set';
}
?>
