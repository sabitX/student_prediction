<?php
// ── Audit trail for administrator actions ───────────────────────────────
// Mark changes are logged by includes/marks.php; this records everything
// an administrator does to accounts, subjects, enrolments and semesters,
// so the Audit Log shows who changed what. actor_name is kept on the row,
// so the history survives the admin's account being deleted later.
//
// Writes as part of the caller's transaction when one is open.

function audit_log($conn, $action, $details) {
    $user_id = (int)($_SESSION['user_id'] ?? 0) ?: null;
    $actor   = $_SESSION['full_name'] ?? null;
    $stmt = mysqli_prepare($conn, "INSERT INTO audit_logs (user_id, actor_name, action, details) VALUES (?, ?, ?, ?)");
    mysqli_stmt_bind_param($stmt, "isss", $user_id, $actor, $action, $details);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

// Declines a mentor's open sessions (they are leaving, or are no longer a
// mentor) so mentees aren't left waiting on requests nobody can answer.
// Returns how many sessions were declined.
function decline_open_mentor_sessions($conn, $mentor_user_id) {
    $stmt = mysqli_prepare($conn, "UPDATE mentorship_sessions SET status = 'Declined' WHERE mentor_id = ? AND status IN ('Pending','Approved')");
    mysqli_stmt_bind_param($stmt, "i", $mentor_user_id);
    mysqli_stmt_execute($stmt);
    $n = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    return $n;
}
?>
