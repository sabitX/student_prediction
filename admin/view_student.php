<?php
require '../includes/session_check.php';
require '../config/config.php';
require '../includes/semester.php';
require '../includes/grading.php';
require '../includes/academic_level.php';

if ($_SESSION['role'] != 'admin') {
    header("Location: ../student/student_dashboard.php");
    exit();
}

$full_name  = $_SESSION['full_name'];
$first_name = explode(' ', trim($full_name))[0];
$initials   = strtoupper(substr($first_name, 0, 1) . substr(explode(' ', trim($full_name))[1] ?? '', 0, 1));
$active_semester = h(get_active_semester_name($conn));

$id = (int)($_GET['id'] ?? 0);

// ── SECURE QUERY: Fetch Student Profile ────────────────────────────────
$stmt_student = mysqli_prepare($conn, "SELECT s.*, c.course_name AS course, c.academic_level, u.full_name, u.email
                                       FROM students s
                                       JOIN courses c ON c.id = s.course_id
                                       JOIN users u ON s.user_id = u.id
                                       WHERE s.id = ?");
mysqli_stmt_bind_param($stmt_student, "i", $id);
mysqli_stmt_execute($stmt_student);
$student = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_student));
mysqli_stmt_close($stmt_student);

if (!$student) {
    header("Location: manage_students.php");
    exit();
}

// ── SECURE QUERY: Fetch Performance & Predictions ──────────────────────
// Every enrolment across all semesters, newest semester first.
$stmt_perf = mysqli_prepare($conn, "SELECT sem.semester_name, sub.subject_name, sub.subject_code,
                                    p.assignment, p.midterm, p.final_exam, p.attendance,
                                    pr.predicted_status, pr.total_score
                                    FROM student_enrollments e
                                    JOIN semesters sem ON sem.id = e.semester_id
                                    JOIN subjects sub ON e.subject_id = sub.id
                                    LEFT JOIN performance p ON p.enrollment_id = e.id
                                    LEFT JOIN predictions pr ON pr.student_id = e.student_id AND pr.subject_id = e.subject_id AND pr.semester_id = e.semester_id
                                    WHERE e.student_id = ?
                                    ORDER BY sem.start_date DESC, sub.subject_code");
mysqli_stmt_bind_param($stmt_perf, "i", $id);
mysqli_stmt_execute($stmt_perf);
$perf_query = mysqli_stmt_get_result($stmt_perf);
$perf_count = mysqli_num_rows($perf_query);
?>
<?php
// Shared head, topbar and sidebar (includes/page_start.php); only this
// page's own CSS is here.
$page_title      = 'Student Profile';
$page_own_alerts = true;   // this page places its own messages
$page_styles     = <<<'CSS'
        .container { position: relative; z-index: 100; }

        .page-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px; }
        .table > tbody > tr > td { vertical-align: middle; font-size: 0.88rem; color: #334155; padding: 16px 12px; border-color: #F8FAFC; }
        /* Shared rules this page does not use (keeps its original look). */
        .page-header { gap: normal; }
CSS;
require '../includes/page_start.php';
?>

        <div class="page-header">
            <div>
                <h2><i class="bi bi-person-badge me-2"></i>Student Profile</h2>
                <p>Detailed academic record for this student.</p>
            </div>
            <a href="manage_students.php" class="btn btn-sm" style="background:#F1F5F9; color:#475569; border:1px solid #E2E8F0; font-weight:600; height:fit-content;">
                <i class="bi bi-arrow-left me-1"></i>Back to Students
            </a>
        </div>

        <!-- Student Profile Card -->
        <div class="dashboard-card">
            <div class="card-header-title">
                <i class="bi bi-person-vcard text-primary"></i>Profile Information
            </div>
            <div class="row" style="font-size:0.9rem;">
                <div class="col-md-6 mb-3">
                    <span class="text-muted d-block" style="font-size:0.78rem; text-transform:uppercase; letter-spacing:0.5px;">Full Name</span>
                    <span class="fw-semibold" style="color:#1A3A5C;"><?php echo h($student['full_name']); ?></span>
                </div>
                <div class="col-md-6 mb-3">
                    <span class="text-muted d-block" style="font-size:0.78rem; text-transform:uppercase; letter-spacing:0.5px;">Email</span>
                    <span class="fw-semibold" style="color:#1A3A5C;"><?php echo h($student['email']); ?></span>
                </div>
                <div class="col-md-4 mb-3">
                    <span class="text-muted d-block" style="font-size:0.78rem; text-transform:uppercase; letter-spacing:0.5px;">Student ID</span>
                    <span class="fw-semibold" style="color:#1A3A5C;"><?php echo h($student['student_id']); ?></span>
                </div>
                <div class="col-md-4 mb-3">
                    <span class="text-muted d-block" style="font-size:0.78rem; text-transform:uppercase; letter-spacing:0.5px;">Course</span>
                    <span class="fw-semibold" style="color:#1A3A5C;"><?php echo h($student['course']); ?></span>
                </div>
                <div class="col-md-4 mb-3">
                    <span class="text-muted d-block" style="font-size:0.78rem; text-transform:uppercase; letter-spacing:0.5px;">Academic Level</span>
                    <span class="fw-semibold" style="color:#1A3A5C;"><?php echo h(academic_level_label($student['academic_level'])); ?></span>
                </div>
                <div class="col-md-4 mb-3">
                    <span class="text-muted d-block" style="font-size:0.78rem; text-transform:uppercase; letter-spacing:0.5px;">Year of Study</span>
                    <span class="fw-semibold" style="color:#1A3A5C;">Year <?php echo h($student['year_of_study']); ?></span>
                </div>
            </div>
        </div>

        <!-- Performance & Predictions -->
        <div class="dashboard-card p-0 overflow-hidden">
            <div class="p-4 border-bottom">
                <div class="card-header-title mb-0">
                    <i class="bi bi-clipboard-data text-primary"></i>Performance & Predictions
                </div>
            </div>

            <?php if ($perf_count > 0): ?>
            <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-custom-header">
                    <tr>
                        <th class="ps-4">Semester</th>
                        <th>Subject</th>
                        <th class="text-center">Assignment</th>
                        <th class="text-center">Midterm</th>
                        <th class="text-center">Final Exam</th>
                        <th class="text-center">Attendance</th>
                        <th class="text-center">Total Score</th>
                        <th class="text-center pe-4">Prediction</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = mysqli_fetch_assoc($perf_query)):
                        $status = $row['predicted_status'] ?? 'Pending';
                        $badge = status_badge_class($status);
                    ?>
                    <tr>
                        <td class="ps-4 small text-muted"><?php echo h($row['semester_name']); ?></td>
                        <td>
                            <strong style="color:#1A3A5C;"><?php echo h($row['subject_code']); ?></strong><br>
                            <span class="text-muted" style="font-size:0.8rem;"><?php echo h($row['subject_name']); ?></span>
                        </td>
                        <?php foreach (['assignment', 'midterm', 'final_exam', 'attendance'] as $mk): ?>
                            <td class="text-center"><?php echo $row[$mk] === null ? '—' : h($row[$mk]) . '%'; ?></td>
                        <?php endforeach; ?>
                        <td class="text-center fw-bold" style="color:#1A3A5C;"><?php echo $row['total_score'] !== null ? h($row['total_score']) . '%' : '—'; ?></td>
                        <td class="text-center pe-4">
                            <span class="<?php echo $badge; ?>"><?php echo h($status); ?></span>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <i class="bi bi-clipboard-x"></i>
                <p class="mb-0">Not enrolled in any subjects yet. Use Enrolments to add subjects.</p>
            </div>
            <?php endif; ?>
        </div>

<?php require '../includes/page_end.php'; ?>
