<?php
require '../includes/session_check.php';
require '../config/config.php';
require '../includes/grading.php';
require '../includes/semester.php';

if (!isset($_SESSION['user_id']) or$_SESSION['role'] != 'admin') {
    header("Location: ../auth/login.php");
    exit();
}

$full_name  = $_SESSION['full_name'];
$first_name = explode(' ', trim($full_name))[0];
$initials   = strtoupper(substr($first_name, 0, 1) . substr(explode(' ', trim($full_name))[1] ?? '', 0, 1));
$active_semester = h(get_active_semester_name($conn));

// Results are per semester, so the dashboard shows the active semester.
// $semester_id is an int from the DB, safe to inline below.
$semester_id = get_active_semester_id($conn);

$total_students    = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM students"))['total'];
$total_assessments = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM predictions WHERE semester_id = $semester_id"))['total'];
$total_subjects    = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM subjects WHERE is_archived = 0"))['total'];
$total_lecturers   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM users WHERE role='lecturer'"))['total'];

$status_counts = ['Excellent' => 0, 'Pass' => 0, 'At Risk' => 0, 'Fail' => 0];
$res_status = mysqli_query($conn, "SELECT predicted_status, COUNT(*) AS total FROM predictions WHERE semester_id = $semester_id GROUP BY predicted_status");
while ($row = mysqli_fetch_assoc($res_status)) {
    $status_counts[$row['predicted_status']] = (int)$row['total'];
}
$excellent = $status_counts['Excellent'];
$pass      = $status_counts['Pass'];
$at_risk   = $status_counts['At Risk'];
$fail      = $status_counts['Fail'];

$subject_chart = mysqli_query($conn, "SELECT sub.subject_name, AVG(pr.total_score) as avg_score
                                       FROM predictions pr
                                       JOIN subjects sub ON pr.subject_id = sub.id AND sub.is_archived = 0
                                       WHERE pr.semester_id = $semester_id
                                       GROUP BY pr.subject_id
                                       ORDER BY sub.subject_code");
$subject_labels = [];
$subject_scores = [];
$subject_colors = [];
while ($row = mysqli_fetch_assoc($subject_chart)) {
    $subject_labels[] = $row['subject_name'];
    $avg = round($row['avg_score'], 2);
    $subject_scores[] = $avg;
    $subject_colors[] = status_hex_color(predicted_status($avg));
}

// Per-student summary for the active semester. Subqueries (not two
// LEFT JOINs) so the counts and averages aren't multiplied by each other.
$students_query = mysqli_query($conn, "SELECT s.id as std_db_id, s.student_id, u.full_name, s.course, s.year_of_study,
                                        (SELECT COUNT(*) FROM student_enrollments e WHERE e.student_id = s.id AND e.semester_id = $semester_id) AS subjects_entered,
                                        (SELECT AVG(pr.total_score) FROM predictions pr WHERE pr.student_id = s.id AND pr.semester_id = $semester_id) AS avg_score
                                        FROM students s
                                        JOIN users u ON s.user_id = u.id
                                        ORDER BY u.full_name");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | SPTS Nilai University</title>
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
    <script src="../assets/vendor/chartjs/chart.umd.min.js"></script>
    <style>
        /* ── Base & Branding ────────────────────────────────────────────── */
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

        /* ── Dashboard Cards & Stat Blocks ──────────────────────────────── */
        .dashboard-card {
            background: #fff;
            border: none;
            border-radius: 16px;
            padding: 28px;
            box-shadow: 0 8px 32px rgba(26,58,92,0.05), 0 2px 8px rgba(26,58,92,0.02);
            height: 100%;
        }

        .card-header-title {
            font-size: 1.05rem;
            font-weight: 700;
            color: #1A3A5C;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .stat-card {
            background: #fff;
            border: none;
            border-radius: 16px;
            padding: 22px 24px;
            box-shadow: 0 8px 24px rgba(26,58,92,0.04), 0 2px 6px rgba(26,58,92,0.02);
            height: 100%;
            position: relative;
            overflow: hidden;
            transition: transform 0.25s ease, box-shadow 0.25s ease;
        }
        .stat-card:hover { 
            transform: translateY(-4px); 
            box-shadow: 0 12px 32px rgba(13,115,119,0.08), 0 4px 12px rgba(13,115,119,0.04);
        }
        .stat-card h2 { font-size: 2.1rem; font-weight: 800; color: #1A3A5C; margin: 6px 0 2px; line-height: 1; letter-spacing: -0.5px; }
        .stat-card p  { margin: 0; font-size: 0.82rem; color: #64748B; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }

        .stat-card .bg-icon {
            position: absolute; right: -12px; bottom: -12px;
            font-size: 5rem; opacity: 0.16; line-height: 1;
        }

        /* ── Tables ─────────────────────────────────────────────────────── */
        .table-custom-header th {
            background: #1A3A5C !important;
            color: #ffffff !important;
            font-weight: 600;
            font-size: 0.82rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: none;
            padding: 16px 12px;
            vertical-align: middle;
        }
        .table > tbody > tr > td {
            vertical-align: middle;
            font-size: 0.88rem;
            color: #334155;
            padding: 16px 12px;
            border-color: #F8FAFC;
        }
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
    <div class="container mb-5" style="max-width:100%; margin-left:0;">

    <!-- Summary Cards -->
    <div class="row g-4 mb-4">
        <div class="col-md-3">
            <div class="stat-card">
                <p>Active Students</p>
                <h2><?php echo $total_students; ?></h2>
                <div class="bg-icon" style="color:#2563EB;"><i class="bi bi-people-fill"></i></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <p>Tracked Subjects</p>
                <h2><?php echo $total_subjects; ?></h2>
                <div class="bg-icon" style="color:#0D7377;"><i class="bi bi-journal-code"></i></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <p>Assessments Logged</p>
                <h2><?php echo $total_assessments; ?></h2>
                <div class="bg-icon" style="color:#D4A017;"><i class="bi bi-calculator-fill"></i></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <p style="color:#DC2626;">Students Failing</p>
                <h2 style="color: #DC2626;"><?php echo $fail; ?></h2>
                <div class="bg-icon" style="color:#DC2626;"><i class="bi bi-x-octagon-fill"></i></div>
            </div>
        </div>
    </div>

    

    <!-- Charts Row -->
    <div class="row g-4 mb-4">
        <div class="col-md-4">
            <div class="dashboard-card">
                <div class="card-header-title">
                    <i class="bi bi-pie-chart-fill text-primary"></i>Academic Standing Distribution
                </div>
                <?php if ($total_assessments > 0): ?>
                    <!-- FIX: Added relative wrapper for Chart.js maintainAspectRatio constraint -->
                    <div style="position: relative; height: 260px; width: 100%;">
                        <canvas id="pieChart"></canvas>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info mt-3" style="font-size:0.88rem; background:#F8FAFC; border:none;">No assessment data yet.</div>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-md-8">
            <div class="dashboard-card">
                <div class="card-header-title">
                    <i class="bi bi-bar-chart-fill text-primary"></i>Department Subject Averages
                </div>
                <?php if (count($subject_labels) > 0): ?>
                    <!-- FIX: Added relative wrapper for Chart.js maintainAspectRatio constraint -->
                    <div style="position: relative; height: 260px; width: 100%;">
                        <canvas id="barChart"></canvas>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info mt-3" style="font-size:0.88rem; background:#F8FAFC; border:none;">No subject score data yet.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Students Table -->
    <div class="dashboard-card p-0 overflow-hidden mb-5">
        <div class="p-4 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div class="card-header-title mb-0">
                <i class="bi bi-list-columns-reverse text-primary"></i>Current Student Roster
            </div>
            <div style="width: 280px;">
                <div class="input-group">
                    <span class="input-group-text bg-light border-0" style="color:#94A3B8;"><i class="bi bi-search"></i></span>
                    <input type="text" id="studentSearch" class="form-control bg-light border-0" placeholder="Search by name or ID..." style="font-size:0.88rem; box-shadow:none;">
                </div>
            </div>
        </div>
        <div class="table-responsive">
        <table class="table table-hover mb-0" id="studentTable">
            <thead class="table-custom-header">
                <tr>
                    <th class="ps-4">Student ID</th>
                    <th>Full Name</th>
                    <th>Course</th>
                    <th>Year</th>
                    <th class="text-center">Subjects</th>
                    <th>Avg Score</th>
                    <th class="text-center pe-4">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = mysqli_fetch_assoc($students_query)): ?>
                <tr>
                    <td class="ps-4 fw-bold" style="color:#1A3A5C; font-size:0.85rem;"><?php echo h($row['student_id']); ?></td>
                    <td class="fw-semibold text-dark"><?php echo h($row['full_name']); ?></td>
                    <td><span class="text-muted" style="font-size:0.82rem;"><?php echo h($row['course']); ?></span></td>
                    <td><span class="badge" style="background:#F1F5F9; color:#475569;">Year <?php echo $row['year_of_study']; ?></span></td>
                    <td class="text-center fw-medium text-muted"><?php echo $row['subjects_entered']; ?></td>
                    <td>
                        <?php
                        $avg =$row['avg_score'];
                        $badge = status_badge_class(predicted_status($avg));
                        $badge_text = ($badge === 'warning') ? 'text-dark' : 'text-white';

                        echo $avg !== null
                            ? '<span class="badge bg-'.$badge.' '.$badge_text.' px-2 py-1" style="font-weight:600;">'.number_format($avg,2).'%</span>'
                            : '<span class="text-muted" style="font-size:0.8rem;">Pending</span>';
                        ?>
                    </td>
                    <td class="text-center pe-4">
                        <a href="view_student.php?id=<?php echo $row['std_db_id']; ?>"
                           class="btn btn-sm px-3 py-1 rounded-2 text-white" style="background:#0D7377; font-size:0.75rem; font-weight:600; letter-spacing:0.3px;">
                           <i class="bi bi-eye me-1"></i>View Profile
                        </a>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
        </div>
    </div>

    </div>
    </main>
</div>

<script>
const sptsDark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
const sptsHeadingColor = sptsDark ? '#E2E8F0' : '#1A3A5C';
const sptsMutedColor   = sptsDark ? '#94A3B8' : '#64748B';
const sptsGridColor    = sptsDark ? '#263447' : '#F1F5F9';

// Doughnut chart with center total
<?php if ($total_assessments > 0): ?>
const pieCtx = document.getElementById('pieChart').getContext('2d');
const totalAssessments = <?php echo $total_assessments; ?>;

const centerTextPlugin = {
    id: 'centerText',
    beforeDraw(chart) {
        const { width, height, ctx } = chart;
        ctx.restore();
        ctx.font = 'bold 28px Inter, sans-serif';
        ctx.fillStyle = sptsHeadingColor;
        ctx.textBaseline = 'middle';
        ctx.textAlign = 'center';
        ctx.fillText(totalAssessments, width / 2, height / 2 - 10);
        ctx.font = '500 12px Inter, sans-serif';
        ctx.fillStyle = sptsMutedColor;
        ctx.fillText('Records', width / 2, height / 2 + 14);
        ctx.save();
    }
};

new Chart(pieCtx, {
    type: 'doughnut',
    data: {
        labels: ['Excellent', 'Pass', 'At Risk', 'Fail'],
        datasets: [{
            data: [<?php echo $excellent; ?>, <?php echo$pass; ?>, <?php echo $at_risk; ?>, <?php echo$fail; ?>],
            backgroundColor: ['#198754', '#2563EB', '#F59E0B', '#DC2626'],
            borderWidth: 0,
            hoverOffset: 4
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '72%',
        plugins: { legend: { position: 'bottom', labels: { color: sptsMutedColor, font: { family: 'Inter', size: 11 }, padding: 20, usePointStyle: true } } }
    },
    plugins: [centerTextPlugin]
});
<?php endif; ?>

// Smart color-coded bar chart
<?php if (count($subject_labels) > 0): ?>
const barCtx = document.getElementById('barChart').getContext('2d');
new Chart(barCtx, {
    type: 'bar',
    data: {
        labels: <?php echo json_encode($subject_labels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        datasets: [{
            label: 'Average Score (%)',
            data: <?php echo json_encode($subject_scores, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            backgroundColor: <?php echo json_encode($subject_colors, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            borderRadius: 6,
            borderWidth: 0,
            barThickness: 60
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            y: {
                beginAtZero: true, max: 100,
                ticks: { callback: val => val + '%', font: { family: 'Inter', size: 11 }, color: sptsMutedColor },
                grid: { color: sptsGridColor, drawBorder: false }
            },
            x: {
                grid: { display: false },
                ticks: { font: { family: 'Inter', size: 11 }, color: sptsMutedColor }
            }
        },
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: '#1E293B',
                padding: 12,
                callbacks: {
                    label: ctx => ` ${ctx.parsed.y}% — ${
                        ctx.parsed.y >= 80 ? 'Excellent' :
                        ctx.parsed.y >= 60 ? 'Pass' :
                        ctx.parsed.y >= 50 ? 'At Risk' : 'Fail'
                    }`
                }
            }
        }
    }
});
<?php endif; ?>

// Client-side student search
document.getElementById('studentSearch').addEventListener('input', function () {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#studentTable tbody tr').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
});
</script>

<script src="../assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="../assets/js/theme-toggle.js"></script>
<script src="../assets/js/app.js?v=2"></script>
</body>
</html>