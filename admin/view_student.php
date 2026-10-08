<?php
require '../includes/session_check.php';
require '../config/config.php';
require '../includes/semester.php';
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
$stmt_student = mysqli_prepare($conn, "SELECT s.*, u.full_name, u.email
                                       FROM students s
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
                                    LEFT JOIN performance p ON p.student_id = e.student_id AND p.subject_id = e.subject_id AND p.semester_id = e.semester_id
                                    LEFT JOIN predictions pr ON pr.student_id = e.student_id AND pr.subject_id = e.subject_id AND pr.semester_id = e.semester_id
                                    WHERE e.student_id = ?
                                    ORDER BY sem.start_date DESC, sub.subject_code");
mysqli_stmt_bind_param($stmt_perf, "i", $id);
mysqli_stmt_execute($stmt_perf);
$perf_query = mysqli_stmt_get_result($stmt_perf);
$perf_count = mysqli_num_rows($perf_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Profile | SPTS Nilai University</title>
    <script>(function(){try{if(localStorage.getItem('spts-theme')==='dark')document.documentElement.setAttribute('data-bs-theme','dark');}catch(e){}})();</script>
    <link href="../assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="../assets/css/dark-mode.css" rel="stylesheet">
    <link href="../assets/css/app.css" rel="stylesheet">
    <link rel="icon" href="../assets/images/favicon-32.png" type="image/png" sizes="32x32">
    <link rel="icon" href="../assets/images/favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="../assets/images/apple-touch-icon.png">
    <style>
        *, *::before, *::after { box-sizing: border-box; }

        body {
            font-family: 'Inter', system-ui, sans-serif;
            background: #F0F4F8;
            color: #1A1A2E;
            margin: 0;
            padding: 0;
            overflow-x: hidden;
        }

        .bg-logo {
            position: fixed;
            top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            width: 80vw; height: 80vh;
            background-image: url('../assets/images/nilai_logo.png');
            background-size: contain;
            background-repeat: no-repeat;
            background-position: center;
            opacity: 0.08;
            pointer-events: none;
            z-index: 99;
            filter: grayscale(100%);
        }

        .container { position: relative; z-index: 100; }

        /* Topbar */
        .topbar { background: #1A3A5C; height: 60px; display: flex; align-items: center; justify-content: space-between; padding: 0 24px; color: white; position: fixed; width: 100%; top: 0; z-index: 1030; border-bottom: 3px solid #0D7377; }
        .topbar .brand { font-weight: 700; font-size: 1.1rem; letter-spacing: 0.5px; display: flex; align-items: center; gap: 8px; }
        .topbar .user-profile { display: flex; align-items: center; gap: 12px; font-size: 0.85rem; color: #CBD5E1; }
        .avatar { background: #0D7377; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 600; color: white; }

        /* Layout */
        .layout-wrapper { display: flex; margin-top: 60px; min-height: calc(100vh - 60px); position: relative; z-index: 100; }

        /* Sidebar */
        .sidebar { width: 240px; background: #1E293B; flex-shrink: 0; position: fixed; height: calc(100vh - 60px); overflow-y: auto; display: flex; flex-direction: column; justify-content: space-between; z-index: 1020; }
        .sidebar-nav { padding-top: 20px; }
        .nav-item { padding: 14px 24px; color: #94A3B8; display: flex; align-items: center; gap: 12px; text-decoration: none; font-size: 0.9rem; font-weight: 500; transition: all 0.2s; }
        .nav-item:hover { color: white; background: rgba(255,255,255,0.05); }
        .nav-item.active { background: #0D7377; color: white; border-left: 4px solid white; }

        .active-semester { padding: 20px; background: rgba(0,0,0,0.15); text-align: center; border-top: 1px solid rgba(255,255,255,0.05); }
        .active-semester p { margin: 0; font-size: 0.75rem; color: #64748B; text-transform: uppercase; letter-spacing: 0.5px; }
        .active-semester h6 { margin: 4px 0 0; color: #F59E0B; font-weight: 700; font-size: 0.9rem; }

        /* Main Content */
        .main-content { margin-left: 240px; flex-grow: 1; padding: 32px; }

        .page-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px; }
        .page-header h2 { font-weight: 700; color: #1A3A5C; margin: 0 0 4px; font-size: 1.5rem; }
        .page-header p { color: #64748B; margin: 0; font-size: 0.9rem; }

        .dashboard-card { background: #fff; border: none; border-radius: 16px; padding: 28px; box-shadow: 0 8px 32px rgba(26,58,92,0.05), 0 2px 8px rgba(26,58,92,0.02); margin-bottom: 24px; }
        .card-header-title { font-size: 1.05rem; font-weight: 700; color: #1A3A5C; margin-bottom: 20px; display: flex; align-items: center; gap: 8px; }

        .table-custom-header th { background: #1A3A5C !important; color: #ffffff !important; font-weight: 600; font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: none; padding: 14px 12px; vertical-align: middle; }
        .table > tbody > tr > td { vertical-align: middle; font-size: 0.88rem; color: #334155; padding: 16px 12px; border-color: #F8FAFC; }

        .empty-state { text-align: center; padding: 30px; color: #64748B; }
        .empty-state i { font-size: 2rem; display: block; margin-bottom: 8px; opacity: 0.4; }
    </style>
</head>
<body>

<div class="bg-logo"></div>

<!-- Topbar -->
<header class="topbar">
    <div class="brand">
        <i class="bi bi-shield-lock-fill me-2"></i>SPTS — Nilai University
    </div>
    <div class="user-profile">
        <button type="button" class="theme-toggle" onclick="toggleTheme()" title="Toggle dark mode">
            <i class="bi bi-moon-stars-fill theme-toggle-icon"></i>
        </button>
        <span>Welcome, <?php echo h($first_name); ?></span>
        <div class="avatar"><?php echo h($initials); ?></div>
    </div>
</header>

<div class="layout-wrapper">
    <!-- Sidebar -->
    <aside class="sidebar">
        <?php require '../includes/sidebar.php'; ?>
        <div class="active-semester">
            <p>Active Semester</p>
            <h6><?php echo $active_semester; ?></h6>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="main-content">

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
                        $badge = match($status) {
                            'Excellent' => 'success',
                            'Pass'      => 'primary',
                            'At Risk'   => 'warning',
                            'Fail'      => 'danger',
                            default     => 'secondary'
                        };
                        $badge_text = ($status == 'At Risk') ? 'text-dark' : 'text-white';
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
                            <span class="badge bg-<?php echo $badge; ?> <?php echo $badge_text; ?> px-2 py-1" style="font-weight:600;"><?php echo h($status); ?></span>
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

    </main>
</div>

<script src="../assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="../assets/js/theme-toggle.js"></script>
<script src="../assets/js/app.js?v=2"></script>
</body>
</html>
