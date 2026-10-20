<?php
// ── Shared academic-level detection ─────────────────────────────────────
// Single source of truth for turning a Student ID into an academic level,
// reused by register.php (self sign-up) and manage_students.php (admin
// add/edit). Server-side is the source of truth — any JavaScript preview
// elsewhere is cosmetic only and must never be trusted for storage.
//
// Rule (prefix-based, case-insensitive, ignores total ID length):
//   n0002... -> Diploma
//   n0003... -> Degree   (displayed as "Bachelor's Degree")
//   n0004... -> Masters  (displayed as "Master's")
//   anything else (including malformed/empty IDs) -> PhD
//
// Values returned match the `students.academic_level` ENUM('Diploma',
// 'Degree','Masters','PhD') exactly, so the result can be inserted as-is.

function detect_academic_level($student_id) {
    $prefix = strtolower(trim((string)$student_id));

    if (str_starts_with($prefix, 'n0002')) {
        return 'Diploma';
    } elseif (str_starts_with($prefix, 'n0003')) {
        return 'Degree';
    } elseif (str_starts_with($prefix, 'n0004')) {
        return 'Masters';
    }
    return 'PhD';
}

// Student IDs are "n" followed by 7–10 digits (e.g. n00021960). Stored
// lower-case so "N00021960" and "n00021960" can't become two accounts.
function normalize_student_id($student_id) {
    return strtolower(trim((string)$student_id));
}

function valid_student_id_format($student_id) {
    return (bool)preg_match('/^n\d{7,10}$/', normalize_student_id($student_id));
}

// Validates everything about a student's academic details at once.
// Returns an error message, or '' when valid.
function student_profile_error($student_id, $programme, $year) {
    if (!valid_student_id_format($student_id)) {
        return "Student ID must be the letter n followed by 7–10 digits (e.g. n00021960).";
    }
    $level = detect_academic_level($student_id);
    if (!in_array($programme, programme_catalog()[$level], true)) {
        return "Please choose a " . academic_level_label($level) . " programme — the Student ID shows this is a " . academic_level_label($level) . " student.";
    }
    if ($year < 1 || $year > 3) {
        return "Invalid year of study selected.";
    }
    return '';
}

// Human-readable label for a stored academic_level value.
function academic_level_label($level) {
    return match ($level) {
        'Diploma' => 'Diploma',
        'Degree'  => "Bachelor's Degree",
        'Masters' => "Master's",
        'PhD'     => 'PhD',
        default   => $level,
    };
}

// Programme catalogue grouped by academic level, read from the courses
// table (each programme belongs to exactly one level; migration 010), and
// shared by every programme dropdown.
function programme_catalog() {
    static $catalog = null;
    if ($catalog === null) {
        $catalog = ['Diploma' => [], 'Degree' => [], 'Masters' => [], 'PhD' => []];
        $res = mysqli_query($GLOBALS['conn'], "SELECT course_name, academic_level FROM courses ORDER BY academic_level, course_name");
        while ($c = mysqli_fetch_assoc($res)) {
            $catalog[$c['academic_level']][] = $c['course_name'];
        }
    }
    return $catalog;
}

// courses.id for a programme name, or 0 if there is no such programme.
function course_id_for($conn, $course_name) {
    $stmt = mysqli_prepare($conn, "SELECT id FROM courses WHERE course_name = ?");
    mysqli_stmt_bind_param($stmt, "s", $course_name);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $row ? (int)$row['id'] : 0;
}

// Renders <optgroup> blocks for every level, grouped and labeled, with the
// currently selected value (if any) marked selected. Echoes HTML directly.
function render_programme_options($selected = '') {
    $catalog = programme_catalog();
    $group_labels = [
        'Diploma' => 'Diploma Programmes',
        'Degree'  => "Bachelor's Degree Programmes",
        'Masters' => "Master's Programmes",
        'PhD'     => 'PhD Programmes',
    ];
    foreach ($catalog as $level => $programmes) {
        echo '<optgroup label="' . h($group_labels[$level]) . '">';
        foreach ($programmes as $programme) {
            $sel = ($selected === $programme) ? ' selected' : '';
            echo '<option value="' . h($programme) . '"' . $sel . '>' . h($programme) . '</option>';
        }
        echo '</optgroup>';
    }
}
?>
