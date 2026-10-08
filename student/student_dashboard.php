<?php
require '../includes/session_check.php';
require '../config/config.php';
require '../includes/semester.php';

$user_id = $_SESSION['user_id'];

$full_name  = $_SESSION['full_name'];
$first_name = explode(' ', trim($full_name))[0];
$initials   = strtoupper(substr($first_name, 0, 1) . substr(explode(' ', trim($full_name))[1] ?? '', 0, 1));
$active_semester = h(get_active_semester_name($conn));

// ── SECURE QUERY: Fetch Student Profile ───────────────────────────────
$stmt_student = mysqli_prepare($conn, "SELECT s.*, u.full_name, u.email
                                       FROM students s
                                       JOIN users u ON s.user_id = u.id
                                       WHERE s.user_id = ?");
mysqli_stmt_bind_param($stmt_student, "i", $user_id);
mysqli_stmt_execute($stmt_student);
$student = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_student));
mysqli_stmt_close($stmt_student);

if (!$student) {
    require '../includes/no_student_profile.php';
    exit();
}
$student_db_id = $student['id'];

// ── SECURE QUERY: This semester's subjects and results ────────────────
// Every subject the student is enrolled in for the active semester, with
// whatever marks exist so far (a subject with no marks yet shows Pending).
$semester_id = get_active_semester_id($conn);
$stmt_perf = mysqli_prepare($conn, "SELECT sub.subject_name, sub.subject_code,
                                     p.assignment, p.midterm, p.final_exam, p.attendance,
                                     pr.predicted_status, pr.total_score
                                     FROM student_enrollments e
                                     JOIN subjects sub ON e.subject_id = sub.id
                                     LEFT JOIN performance p ON p.student_id = e.student_id AND p.subject_id = e.subject_id AND p.semester_id = e.semester_id
                                     LEFT JOIN predictions pr ON pr.student_id = e.student_id AND pr.subject_id = e.subject_id AND pr.semester_id = e.semester_id
                                     WHERE e.student_id = ? AND e.semester_id = ?
                                     ORDER BY sub.subject_code");
mysqli_stmt_bind_param($stmt_perf, "ii", $student_db_id, $semester_id);
mysqli_stmt_execute($stmt_perf);
$rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt_perf), MYSQLI_ASSOC);
mysqli_stmt_close($stmt_perf);

// Summary stats only count subjects that have a final score. A real score
// of 0 counts (it used to be ignored, and "Lowest" defaulted to 100%).
$total_subjects = count($rows);
$scores = [];
foreach ($rows as $row) {
    if ($row['total_score'] !== null) {
        $scores[] = (float)$row['total_score'];
    }
}
$graded_count = count($scores);
$avg_score    = $graded_count ? number_format(array_sum($scores) / $graded_count, 2) . '%' : '—';
$best_score   = $graded_count ? number_format(max($scores), 2) . '%' : '—';
$worst_score  = $graded_count ? number_format(min($scores), 2) . '%' : '—';

// Mark cell: "—" when that component hasn't been assessed yet.
function mark_cell($v) {
    return $v === null ? '<span class="text-muted">—</span>' : h(rtrim(rtrim($v, '0'), '.')) . '%';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard | SPTS Nilai University</title>
    <script>(function(){try{if(localStorage.getItem('spts-theme')==='dark')document.documentElement.setAttribute('data-bs-theme','dark');}catch(e){}})();</script>
    <link href="../assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="../assets/css/dark-mode.css" rel="stylesheet">
    <link href="../assets/css/app.css?v=3" rel="stylesheet">
    <link rel="icon" href="../assets/images/favicon-32.png" type="image/png" sizes="32x32">
    <link rel="icon" href="../assets/images/favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="../assets/images/apple-touch-icon.png">
    <style>
        /* ── Base ───────────────────────────────────────────────────────── */
        *, *::before, *::after { box-sizing: border-box; }

        body {
            font-family: 'Inter', system-ui, sans-serif;
            background: #F0F4F8;
            color: #1A1A2E;
            margin: 0;
            padding: 0;
            overflow-x: hidden;
        }

        /* Institutional Watermark */
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

        .container {
            position: relative;
            z-index: 100; /* Ensure form sits above watermark */
        }

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

        /* ── Dashboard Cards ────────────────────────────────────────────── */
        .dashboard-card {
            background: #fff;
            border: 1px solid #E2E8F0;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 4px 24px rgba(26,58,92,0.04);
            height: 100%;
        }

        .card-header-title {
            font-size: 1.05rem;
            font-weight: 700;
            color: #1A3A5C;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* ── Stat Cards ─────────────────────────────────────────────────── */
        .stat-card {
            background: #fff;
            border: 1px solid #E2E8F0;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 4px 20px rgba(26,58,92,0.03);
            height: 100%;
            transition: transform 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            border-color: #0D7377;
        }

        .icon-wrap {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
        }

        .stat-value {
            font-size: 1.6rem;
            font-weight: 800;
            color: #1A3A5C;
            margin: 12px 0 2px;
            line-height: 1;
        }

        .stat-label {
            font-size: 0.82rem;
            color: #6C757D;
            font-weight: 500;
            margin: 0;
        }

        /* ── Table Styling ──────────────────────────────────────────────── */
        .table-custom-header th {
            background: #1A3A5C !important;
            color: #ffffff !important;
            font-weight: 600;
            font-size: 0.82rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: none;
            padding: 14px 12px;
            vertical-align: middle;
        }

        .table > tbody > tr > td {
            vertical-align: middle;
            font-size: 0.88rem;
            color: #334155;
            padding: 16px 12px;
            border-color: #F1F5F9;
        }

        .progress-bar { transition: width 1.2s ease-in-out; }
    </style>
</head>
<body>

<div class="bg-logo"></div>

<!-- Topbar -->
<header class="topbar">
    <div class="brand">
        <i class="bi bi-mortarboard-fill me-2"></i>SPTS — Nilai University
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
    <div class="container mb-5" style="max-width:100%; margin-left:0;">

    <!-- Profile + Summary Row -->
    <div class="row g-4 mb-4">
        <!-- Profile Card -->
        <div class="col-md-5">
            <div class="dashboard-card">
                <div class="card-header-title">
                    <i class="bi bi-person-badge text-primary"></i>
                    My Academic Profile
                </div>
                <div class="d-flex flex-column gap-2" style="font-size:0.9rem; color:#475569;">
                    <div class="d-flex justify-content-between border-bottom pb-2">
                        <span class="text-muted">Student ID</span>
                        <span class="fw-semibold" style="color:#1A3A5C;"><?php echo h($student['student_id']); ?></span>
                    </div>
                    <div class="d-flex justify-content-between border-bottom pb-2 pt-1">
                        <span class="text-muted">Programme</span>
                        <span class="fw-semibold text-end" style="color:#1A3A5C;"><?php echo h($student['course']); ?></span>
                    </div>
                    <div class="d-flex justify-content-between border-bottom pb-2 pt-1">
                        <span class="text-muted">Year of Study</span>
                        <span class="fw-semibold" style="color:#1A3A5C;">Year <?php echo h($student['year_of_study']); ?></span>
                    </div>
                    <div class="d-flex justify-content-between pt-1">
                        <span class="text-muted">Email Address</span>
                        <span class="fw-semibold" style="color:#1A3A5C;"><?php echo h($student['email']); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Stat Cards -->
        <div class="col-md-7">
            <div class="row g-3 h-100">
                <div class="col-6 col-sm-3">
                    <div class="stat-card d-flex flex-column align-items-center justify-content-center">
                        <div class="icon-wrap" style="background:#EAF4FB; color:#2563EB;">
                            <i class="bi bi-journal-check"></i>
                        </div>
                        <div class="stat-value"><?php echo $total_subjects; ?></div>
                        <div class="stat-label">Subjects</div>
                    </div>
                </div>
                <div class="col-6 col-sm-3">
                    <div class="stat-card d-flex flex-column align-items-center justify-content-center">
                        <div class="icon-wrap" style="background:#E0F4F4; color:#0D7377;">
                            <i class="bi bi-bar-chart-fill"></i>
                        </div>
                        <div class="stat-value"><?php echo $avg_score; ?></div>
                        <div class="stat-label">Average</div>
                    </div>
                </div>
                <div class="col-6 col-sm-3">
                    <div class="stat-card d-flex flex-column align-items-center justify-content-center">
                        <div class="icon-wrap" style="background:#FFF8E1; color:#D4A017;">
                            <i class="bi bi-trophy-fill"></i>
                        </div>
                        <div class="stat-value"><?php echo $best_score; ?></div>
                        <div class="stat-label">Best Score</div>
                    </div>
                </div>
                <div class="col-6 col-sm-3">
                    <div class="stat-card d-flex flex-column align-items-center justify-content-center">
                        <div class="icon-wrap" style="background:#FCE8E8; color:#DC3545;">
                            <i class="bi bi-graph-down-arrow"></i>
                        </div>
                        <div class="stat-value"><?php echo $worst_score; ?></div>
                        <div class="stat-label">Lowest Score</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Performance Table -->
    <div class="dashboard-card p-0 overflow-hidden">
        <div class="p-4 border-bottom">
            <div class="card-header-title mb-0">
                <i class="bi bi-clipboard-data text-primary"></i>
                Official Performance Results — <?php echo $active_semester; ?>
            </div>
        </div>

        <?php if (count($rows) > 0): ?>
        <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-custom-header">
                <tr>
                    <th class="ps-4">Subject</th>
                    <th class="text-center">Assignment<br><span style="color:#7DD3D6; font-size:0.7rem;">(20%)</span></th>
                    <th class="text-center">Midterm<br><span style="color:#7DD3D6; font-size:0.7rem;">(30%)</span></th>
                    <th class="text-center">Final Exam<br><span style="color:#7DD3D6; font-size:0.7rem;">(40%)</span></th>
                    <th class="text-center">Attendance<br><span style="color:#7DD3D6; font-size:0.7rem;">(10%)</span></th>
                    <th style="min-width:180px;">Total Score</th>
                    <th class="text-center pe-4">Result</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row):
                    $has_score = $row['total_score'] !== null;
                    $score  = (float)$row['total_score'];
                    $status = $row['predicted_status'] ?? 'Pending';
                    
                    // Badge Styling
                    $badge  = match($status) {
                        'Excellent' => 'success',
                        'Pass'      => 'primary',
                        'At Risk'   => 'warning',
                        'Fail'      => 'danger',
                        default     => 'secondary'
                    };
                    $badge_text = match($status) {
                        'At Risk' => 'text-dark',
                        default   => 'text-white'
                    };
                    $bar_color = match($status) {
                        'Excellent' => '#198754',
                        'Pass'      => '#2563EB',
                        'At Risk'   => '#F59E0B',
                        'Fail'      => '#DC2626',
                        default     => '#64748B'
                    };
                ?>
                <tr>
                    <td class="ps-4">
                        <strong style="color:#1A3A5C;"><?php echo h($row['subject_code']); ?></strong><br>
                        <span class="text-muted" style="font-size:0.8rem;"><?php echo h($row['subject_name']); ?></span>
                    </td>
                    <td class="text-center"><?php echo mark_cell($row['assignment']); ?></td>
                    <td class="text-center"><?php echo mark_cell($row['midterm']); ?></td>
                    <td class="text-center"><?php echo mark_cell($row['final_exam']); ?></td>
                    <td class="text-center"><?php echo mark_cell($row['attendance']); ?></td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <div class="progress flex-grow-1" style="height:10px; border-radius:6px; background:#F1F5F9;">
                                <div class="progress-bar"
                                     role="progressbar"
                                     data-width="<?php echo min($score, 100); ?>"
                                     style="width:0%; background:<?php echo $bar_color; ?>; border-radius:6px;">
                                </div>
                            </div>
                            <span class="fw-bold" style="min-width:48px; color:#1A3A5C; font-size:0.9rem;">
                                <?php echo $has_score ? number_format($score,1).'%' : '—'; ?>
                            </span>
                        </div>
                    </td>
                    <td class="text-center pe-4">
                        <span class="badge bg-<?php echo $badge; ?> <?php echo $badge_text; ?> px-2 py-1" style="font-weight:600; letter-spacing:0.3px;">
                            <?php echo h($status); ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php else: ?>
            <div class="p-4">
                <div class="alert alert-info d-flex align-items-center gap-2 m-0" style="background:#EAF4FB; border-color:#BAE6FD; color:#0369A1;">
                    <i class="bi bi-info-circle-fill"></i>
                    You are not enrolled in any subjects for <?php echo $active_semester; ?> yet. Contact the admin office if this is wrong.
                </div>
            </div>
        <?php endif; ?>
    </div>

    </div>
    </main>
</div>

<script src="../assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="../assets/js/theme-toggle.js"></script>
<script src="../assets/js/app.js?v=3"></script>
<?php require '../includes/onboarding.php'; ?>
<script>
window.addEventListener('load', () => {
    document.querySelectorAll('.progress-bar[data-width]').forEach(bar => {
        const target = bar.getAttribute('data-width');
        setTimeout(() => { bar.style.width = target + '%'; }, 200);
    });
});
</script>
</body>
</html>