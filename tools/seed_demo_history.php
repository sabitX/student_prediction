<?php
// ── Demo history: two earlier semesters of marks ────────────────────────
// Command line only:  php tools/seed_demo_history.php            (add)
//                     php tools/seed_demo_history.php --remove   (take out)
//
// Progress trends (GPA/CGPA by semester), the early warning's use of a
// student's earlier results, and the Warning Accuracy page all need more
// than one semester of data. This adds two past semesters, clearly named
// "Demo — …" so they're never mistaken for real results, and enrols every
// student in four subjects in each with generated marks: each student gets
// an ability level (from their current results if they have any) and a
// direction (improving or declining), plus random variation, so the trends
// differ between students. Running it again does nothing; --remove deletes
// the demo semesters with their enrolments and marks.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}

require __DIR__ . '/../config/config.php';

const DEMO_SEMESTERS = [
    ['Demo — January 2026', '2026-01-05', '2026-04-24'],
    ['Demo — May 2026',     '2026-05-04', '2026-08-21'],
];

$demo_names = array_column(DEMO_SEMESTERS, 0);
$marks = implode(',', array_fill(0, count($demo_names), '?'));

if (in_array('--remove', $argv, true)) {
    mysqli_begin_transaction($conn);
    foreach (['student_enrollments', 'lecturer_subjects', 'subject_weights'] as $table) {
        $stmt = mysqli_prepare($conn, "DELETE t FROM $table t JOIN semesters s ON s.id = t.semester_id WHERE s.semester_name IN ($marks)");
        mysqli_stmt_bind_param($stmt, str_repeat('s', count($demo_names)), ...$demo_names);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
    $stmt = mysqli_prepare($conn, "DELETE FROM semesters WHERE semester_name IN ($marks) AND is_active = 0");
    mysqli_stmt_bind_param($stmt, str_repeat('s', count($demo_names)), ...$demo_names);
    mysqli_stmt_execute($stmt);
    $removed = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    mysqli_commit($conn);
    echo "Removed $removed demo semester(s) with their enrolments and marks.\n";
    exit();
}

$stmt = mysqli_prepare($conn, "SELECT COUNT(*) FROM semesters WHERE semester_name IN ($marks)");
mysqli_stmt_bind_param($stmt, str_repeat('s', count($demo_names)), ...$demo_names);
mysqli_stmt_execute($stmt);
if (mysqli_fetch_row(mysqli_stmt_get_result($stmt))[0] > 0) {
    echo "Demo semesters already exist. Run with --remove first to regenerate them.\n";
    exit();
}
mysqli_stmt_close($stmt);

$subjects = array_column(mysqli_fetch_all(mysqli_query($conn, "SELECT id FROM subjects WHERE is_archived = 0 ORDER BY subject_code"), MYSQLI_ASSOC), 'id');
$students = mysqli_fetch_all(mysqli_query($conn,
    "SELECT s.id, s.student_id, AVG(pr.total_score) AS current_avg
     FROM students s LEFT JOIN predictions pr ON pr.student_id = s.id
     GROUP BY s.id ORDER BY s.id"), MYSQLI_ASSOC);
$active = mysqli_fetch_row(mysqli_query($conn, "SELECT id FROM semesters WHERE is_active = 1"))[0] ?? null;
if (count($subjects) < 4 || !$students) {
    echo "Needs at least 4 active subjects and one student.\n";
    exit(1);
}

$clamp = fn($v) => max(0, min(100, round($v, 2)));
mysqli_begin_transaction($conn);
try {
    $sem_ids = [];
    foreach (DEMO_SEMESTERS as [$name, $start, $end]) {
        $stmt = mysqli_prepare($conn, "INSERT INTO semesters (semester_name, start_date, end_date, is_active) VALUES (?, ?, ?, 0)");
        mysqli_stmt_bind_param($stmt, "sss", $name, $start, $end);
        mysqli_stmt_execute($stmt);
        $sem_ids[] = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);
        // The same lecturers taught the subjects then.
        if ($active) {
            $sem = end($sem_ids);
            mysqli_query($conn, "INSERT INTO lecturer_subjects (user_id, subject_id, semester_id)
                                 SELECT user_id, subject_id, $sem FROM lecturer_subjects WHERE semester_id = " . (int)$active);
        }
    }

    $enrol = mysqli_prepare($conn, "INSERT INTO student_enrollments (student_id, subject_id, semester_id) VALUES (?, ?, ?)");
    $mark  = mysqli_prepare($conn, "INSERT INTO performance (enrollment_id, assignment, midterm, final_exam, attendance) VALUES (?, ?, ?, ?, ?)");
    $count = 0;
    foreach ($students as $i => $st) {
        mt_srand(crc32($st['student_id']));   // the same student always gets the same history
        $ability = $st['current_avg'] !== null ? (float)$st['current_avg'] : mt_rand(55, 85);
        $drift   = mt_rand(-8, 8);            // + improving, − declining over the two semesters
        foreach ($sem_ids as $k => $sem) {
            $level = $ability - $drift * (count($sem_ids) - $k);
            for ($j = 0; $j < 4; $j++) {
                $subject = $subjects[($i + $k * 4 + $j) % count($subjects)];
                $base    = $level + mt_rand(-10, 10);
                mysqli_stmt_bind_param($enrol, "iii", $st['id'], $subject, $sem);
                mysqli_stmt_execute($enrol);
                $eid = mysqli_insert_id($conn);
                $a = $clamp($base + mt_rand(-8, 10));
                $m = $clamp($base + mt_rand(-12, 8));
                $f = $clamp($base + mt_rand(-12, 10));
                $t = $clamp(max(55, $base + 15 + mt_rand(-10, 10)));
                mysqli_stmt_bind_param($mark, "idddd", $eid, $a, $m, $f, $t);
                mysqli_stmt_execute($mark);
                $count++;
            }
        }
    }
    mysqli_stmt_close($enrol);
    mysqli_stmt_close($mark);
    mysqli_commit($conn);
} catch (Throwable $e) {
    mysqli_rollback($conn);
    fwrite(STDERR, "Nothing was added: " . $e->getMessage() . "\n");
    exit(1);
}
echo "Added " . count($sem_ids) . " demo semesters with $count marked enrolments for " . count($students) . " students.\n";
?>
