<?php
// ── Shared mentorship rules ─────────────────────────────────────────────
// Used by student/mentorship.php (students book, cancel, and — if an
// admin made them a peer mentor — handle requests) and by
// lecturer/lecturer_dashboard.php (lecturers handle requests).
//
// Who can mentor a subject:
//   • the lecturer assigned to it this semester (lecturer_subjects), or
//   • a student the admin flagged as a peer mentor (students.is_mentor)
//     who achieved "Excellent" in that subject.
// The subject must not be archived.

define('SESSION_CLASH_MINUTES', 60);   // a mentor can't have two sessions this close together
define('SESSION_MAX_DAYS_AHEAD', 180);
define('SESSION_NOTES_MAX', 500);

// Every mentor available for a subject, excluding the requesting user.
function find_mentors($conn, $subject_id, $exclude_user_id) {
    $stmt = mysqli_prepare($conn,
        "SELECT u.id, u.full_name, u.email, 'Lecturer' AS mentor_type
         FROM users u
         JOIN lecturer_subjects ls ON ls.user_id = u.id AND ls.semester_id = (SELECT id FROM semesters WHERE is_active = 1)
         JOIN subjects sub ON sub.id = ls.subject_id AND sub.is_archived = 0
         WHERE u.role = 'lecturer' AND ls.subject_id = ?
         UNION
         SELECT DISTINCT u.id, u.full_name, u.email, 'Peer Mentor' AS mentor_type
         FROM users u
         JOIN students s ON s.user_id = u.id AND s.is_mentor = 1
         JOIN predictions pr ON pr.student_id = s.id AND pr.subject_id = ? AND pr.predicted_status = 'Excellent'
         JOIN subjects sub ON sub.id = pr.subject_id AND sub.is_archived = 0
         WHERE u.id <> ?
         ORDER BY mentor_type, full_name");
    mysqli_stmt_bind_param($stmt, "iii", $subject_id, $subject_id, $exclude_user_id);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
    return $rows;
}

// The matching mentor row if $mentor_id may mentor $subject_id, else null.
function valid_mentor_for_subject($conn, $mentor_id, $subject_id, $exclude_user_id) {
    foreach (find_mentors($conn, $subject_id, $exclude_user_id) as $m) {
        if ((int)$m['id'] === (int)$mentor_id) {
            return $m;
        }
    }
    return null;
}

// Parses an <input type="datetime-local"> value. Returns [DateTime|null, error].
function parse_session_datetime($raw) {
    $dt = DateTime::createFromFormat('Y-m-d\TH:i', trim((string)$raw));
    if (!$dt || $dt->format('Y-m-d\TH:i') !== trim((string)$raw)) {
        return [null, "Please choose a valid date and time."];
    }
    $now = new DateTime();
    if ($dt <= $now) {
        return [null, "The session must be in the future."];
    }
    if ($dt > (clone $now)->modify('+' . SESSION_MAX_DAYS_AHEAD . ' days')) {
        return [null, "Sessions can be booked at most " . SESSION_MAX_DAYS_AHEAD . " days ahead."];
    }
    return [$dt, ''];
}

// Does the mentor already have an approved session near this time?
function mentor_has_clash($conn, $mentor_id, DateTime $when, $ignore_session_id = 0) {
    $at = $when->format('Y-m-d H:i:s');
    $stmt = mysqli_prepare($conn, "SELECT 1 FROM mentorship_sessions
                                   WHERE mentor_id = ? AND status = 'Approved' AND session_id <> ?
                                     AND ABS(TIMESTAMPDIFF(MINUTE, session_date, ?)) < " . SESSION_CLASH_MINUTES);
    mysqli_stmt_bind_param($stmt, "iis", $mentor_id, $ignore_session_id, $at);
    mysqli_stmt_execute($stmt);
    $clash = mysqli_num_rows(mysqli_stmt_get_result($stmt)) > 0;
    mysqli_stmt_close($stmt);
    return $clash;
}

// Mentor-side actions: approve / decline / complete / reschedule.
// Only affects sessions where $mentor_id is the mentor, and only from a
// sensible current status. Returns [ok, message].
function mentor_session_action($conn, $mentor_id, $session_id, string $action, string $new_date_raw = '') {
    $stmt = mysqli_prepare($conn, "SELECT status, session_date FROM mentorship_sessions WHERE session_id = ? AND mentor_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $session_id, $mentor_id);
    mysqli_stmt_execute($stmt);
    $sess = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$sess) {
        return [false, "Session not found."];
    }

    $allowed_from = [
        'approve'    => ['Pending'],
        'decline'    => ['Pending', 'Approved'],
        'complete'   => ['Approved'],
        'reschedule' => ['Pending', 'Approved'],
    ];
    if (!isset($allowed_from[$action]) || !in_array($sess['status'], $allowed_from[$action], true)) {
        return [false, "That action isn't possible — this session is " . strtolower($sess['status']) . "."];
    }

    if ($action === 'complete' && strtotime($sess['session_date']) > time()) {
        return [false, "A session can only be marked completed after it has taken place."];
    }

    if ($action === 'approve' || $action === 'reschedule') {
        if ($action === 'reschedule') {
            [$when, $err] = parse_session_datetime($new_date_raw);
            if (!$when) return [false, $err];
        } else {
            $when = new DateTime($sess['session_date']);
            if ($when <= new DateTime()) {
                return [false, "This requested time has already passed — reschedule it instead."];
            }
        }
        if (mentor_has_clash($conn, $mentor_id, $when, $session_id)) {
            return [false, "You already have an approved session within " . SESSION_CLASH_MINUTES . " minutes of that time."];
        }
        $at = $when->format('Y-m-d H:i:s');
        $stmt = mysqli_prepare($conn, "UPDATE mentorship_sessions SET status = 'Approved', session_date = ? WHERE session_id = ? AND mentor_id = ?");
        mysqli_stmt_bind_param($stmt, "sii", $at, $session_id, $mentor_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        return [true, $action === 'approve' ? "Session approved." : "Session rescheduled to " . $when->format('d M Y, h:i A') . " and approved."];
    }

    $new_status = ($action === 'decline') ? 'Declined' : 'Completed';
    $stmt = mysqli_prepare($conn, "UPDATE mentorship_sessions SET status = ? WHERE session_id = ? AND mentor_id = ?");
    mysqli_stmt_bind_param($stmt, "sii", $new_status, $session_id, $mentor_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return [true, "Session " . strtolower($new_status) . "."];
}
?>
