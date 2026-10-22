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
$subject_statuses = [];   // coloured in the chart script (light/dark palette)
while ($row = mysqli_fetch_assoc($subject_chart)) {
    $subject_labels[] = $row['subject_name'];
    $avg = round($row['avg_score'], 2);
    $subject_scores[] = $avg;
    $subject_statuses[] = predicted_status($avg);
}

// Support requests still waiting for an admin (admin/support_requests.php).
require '../includes/support.php';
$support_counts = array_fill_keys(SUPPORT_STATUSES, 0);
foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT status, COUNT(*) AS c FROM support_requests GROUP BY status"), MYSQLI_ASSOC) as $row) {
    $support_counts[$row['status']] = (int)$row['c'];
}
$support_recent = mysqli_fetch_all(mysqli_query($conn, "SELECT id, reference_no, full_name, role, category, status, created_at
                                                         FROM support_requests WHERE status <> 'Resolved'
                                                         ORDER BY FIELD(status, 'Open', 'In Progress'), created_at DESC LIMIT 5"), MYSQLI_ASSOC);

// Programme Learning Outcome attainment across all active subjects this
// semester, and the CLOs that missed their class target (includes/obe.php).
require '../includes/obe.php';
$plo_institution = obe_institution_plo($conn, $semester_id);
$plo_has_data    = array_sum(array_column($plo_institution, 'results')) > 0;
$cqi_list = [];
foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT id, subject_code FROM subjects WHERE is_archived = 0 ORDER BY subject_code"), MYSQLI_ASSOC) as $sub) {
    foreach (obe_cohort_clo($conn, (int)$sub['id'], $semester_id) as $clo) {
        if ($clo['cqi_required']) {
            $cqi_list[] = ['subject_code' => $sub['subject_code'], 'subject_id' => (int)$sub['id']] + $clo;
        }
    }
}

// Per-student summary for the active semester, one page at a time (see
// includes/pagination.php). Subqueries (not two LEFT JOINs) so the counts
// and averages aren't multiplied by each other.
require '../includes/pagination.php';
$list   = list_params();
$filter = "FROM students s JOIN users u ON s.user_id = u.id JOIN courses c ON c.id = s.course_id";
$types  = '';
$params = [];
if ($list['q'] !== '') {
    $filter .= " WHERE (u.full_name LIKE ? OR s.student_id LIKE ?)";
    $types   = 'ss';
    $params  = array_fill(0, 2, like_pattern($list['q']));
}

$stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS n $filter");
if ($params) mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$roster_total = (int)mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['n'];
mysqli_stmt_close($stmt);
$list = list_clamp($list, $roster_total);

$stmt = mysqli_prepare($conn, "SELECT s.id as std_db_id, s.student_id, u.full_name, c.course_name AS course, s.year_of_study,
                                      (SELECT COUNT(*) FROM student_enrollments e WHERE e.student_id = s.id AND e.semester_id = ?) AS subjects_entered,
                                      (SELECT AVG(pr.total_score) FROM predictions pr WHERE pr.student_id = s.id AND pr.semester_id = ?) AS avg_score
                               $filter
                               ORDER BY u.full_name
                               LIMIT ? OFFSET ?");
mysqli_stmt_bind_param($stmt, "ii{$types}ii", $semester_id, $semester_id, ...[...$params, $list['limit'], $list['offset']]);
mysqli_stmt_execute($stmt);
$students_query = mysqli_stmt_get_result($stmt);
mysqli_stmt_close($stmt);
?>
<?php
// Shared head, topbar and sidebar (includes/page_start.php); only this
// page's own CSS is here.
$page_title      = 'Admin Dashboard';
$page_own_alerts = true;   // this page places its own messages
$page_styles     = <<<'CSS'
        .container {
            position: relative;
            z-index: 100; /* Ensure form sits above watermark */
        }

        /* ── Dashboard Cards & Stat Blocks ──────────────────────────────── */
        .dashboard-card {
            background: #fff;
            border-radius: 16px;
            padding: 28px;
            box-shadow: var(--spts-card-shadow); border: 1px solid var(--spts-card-border);
            height: 100%;
        }

        .stat-card {
            background: #fff;
            border-radius: 16px;
            padding: 22px 24px;
            box-shadow: var(--spts-card-shadow); border: 1px solid var(--spts-card-border);
            height: 100%;
            position: relative;
            overflow: hidden;
            
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
        /* Shared rules this page does not use (keeps its original look). */
        .dashboard-card { margin-bottom: 0; }
CSS;
require '../includes/page_start.php';
?>
    <div class="container mb-5" style="max-width:100%; margin-left:0;">

    <div class="d-flex justify-content-end flex-wrap gap-2 mb-3">
        <a href="export.php?type=results" class="btn btn-sm btn-outline-secondary"><i class="bi bi-download me-1"></i>Results CSV</a>
        <a href="export.php?type=at_risk" class="btn btn-sm btn-outline-secondary"><i class="bi bi-exclamation-triangle me-1"></i>At-risk list CSV</a>
    </div>

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

    <!-- Support Requests -->
    <div class="dashboard-card mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <div class="card-header-title mb-0">
                <i class="bi bi-life-preserver text-primary"></i>Support Requests
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-center" style="font-size:0.82rem;">
                <span class="badge <?php echo support_status_class('Open'); ?>"><?php echo $support_counts['Open']; ?> Open</span>
                <span class="badge <?php echo support_status_class('In Progress'); ?>"><?php echo $support_counts['In Progress']; ?> In Progress</span>
                <a href="support_requests.php" class="btn btn-sm btn-outline-secondary">View all</a>
            </div>
        </div>
        <?php if ($support_recent): ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0" style="font-size:0.85rem;">
                    <tbody>
                    <?php foreach ($support_recent as $sr): ?>
                        <tr>
                            <td style="padding:10px 8px;"><a href="support_requests.php?id=<?php echo (int)$sr['id']; ?>" class="fw-bold text-decoration-none"><?php echo h($sr['reference_no']); ?></a></td>
                            <td style="padding:10px 8px;"><?php echo h($sr['full_name']); ?> <span class="text-muted small">(<?php echo h(ucfirst($sr['role'])); ?>)</span></td>
                            <td style="padding:10px 8px;"><?php echo h(support_category_label($sr['category'])); ?></td>
                            <td style="padding:10px 8px;"><span class="badge <?php echo support_status_class($sr['status']); ?>"><?php echo h($sr['status']); ?></span></td>
                            <td class="text-end text-muted small text-nowrap" style="padding:10px 8px;"><?php echo date('d M Y, h:i A', strtotime($sr['created_at'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="text-muted mb-0" style="font-size:0.88rem;"><i class="bi bi-check2-circle me-1"></i>No open support requests.</p>
        <?php endif; ?>
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

    <!-- Outcome-Based Education -->
    <div class="row g-4 mb-4">
        <div class="col-md-7">
            <div class="dashboard-card">
                <div class="card-header-title">
                    <i class="bi bi-mortarboard-fill text-primary"></i>Programme Learning Outcome Attainment
                </div>
                <?php if ($plo_has_data): ?>
                    <div style="position: relative; height: 260px; width: 100%;">
                        <canvas id="ploChart" role="img" aria-label="Share of student results attaining each programme learning outcome"></canvas>
                    </div>
                    <p class="text-muted mb-0 mt-2" style="font-size:0.78rem;">
                        <?php echo implode(' · ', array_map(fn($p) => '<strong>' . h($p['plo_code']) . '</strong> ' . h($p['plo_title']), $plo_institution)); ?><br>
                        Share of student CLO results this semester that reached each PLO's target. The marker is the class KPI.
                    </p>
                <?php else: ?>
                    <div class="alert alert-info mt-3" style="font-size:0.88rem; background:#F8FAFC; border:none;">No outcome data yet.</div>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-md-5">
            <div class="dashboard-card">
                <div class="card-header-title">
                    <i class="bi bi-arrow-repeat text-primary"></i>CLOs Below Target (CQI)
                </div>
                <?php if ($cqi_list): ?>
                    <div style="max-height:260px; overflow-y:auto;">
                    <table class="table mb-0" style="font-size:0.84rem;">
                        <tbody>
                        <?php foreach ($cqi_list as $clo): ?>
                            <tr>
                                <td style="padding:10px 8px;">
                                    <a href="subject_outcomes.php?id=<?php echo $clo['subject_id']; ?>" class="fw-bold text-decoration-none"><?php echo h($clo['subject_code'] . ' ' . $clo['clo_code']); ?></a>
                                    <span class="text-muted" style="font-size:0.75rem;"><?php echo h($clo['plo_code']); ?></span>
                                </td>
                                <td class="text-end" style="padding:10px 8px;">
                                    <span class="<?php echo status_badge_class('Fail'); ?>"><?php echo number_format($clo['pct'], 0); ?>% / <?php echo number_format($clo['kpi'], 0); ?>%</span>
                                </td>
                                <td class="text-end" style="padding:10px 8px; font-size:0.75rem;">
                                    <?php echo $clo['cqi'] ? '<span class="text-success"><i class="bi bi-check2-circle"></i> Action logged</span>' : '<span class="text-muted">No action yet</span>'; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="bi bi-check2-circle"></i>
                        <p class="mb-0" style="font-size:0.88rem;">Every assessed CLO meets its class target this semester.</p>
                    </div>
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
            <!-- Typing filters this page; Enter searches every student. -->
            <form method="GET" style="width: 280px;" role="search">
                <div class="input-group">
                    <span class="input-group-text bg-light border-0" style="color:#94A3B8;"><i class="bi bi-search"></i></span>
                    <input type="search" name="q" id="studentSearch" value="<?php echo h($list['q']); ?>" aria-label="Search students" class="form-control bg-light border-0" placeholder="Search by name or ID..." style="font-size:0.88rem; box-shadow:none;">
                </div>
            </form>
        </div>
        <?php if ($roster_total === 0): ?>
            <div class="empty-state p-4 text-center text-muted">
                <?php echo $list['q'] !== '' ? 'No students match "' . h($list['q']) . '". <a href="admin_dashboard.php">Show all</a>' : 'No students yet.'; ?>
            </div>
        <?php endif; ?>
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
                        echo $avg !== null
                            ? '<span class="'.$badge.'">'.number_format($avg,2).'%</span>'
                            : '<span class="'.status_badge_class(null).'">Pending</span>';
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
        <?php render_pagination($list, $roster_total); ?>
    </div>

    </div>
<?php
// Modals and page scripts: page_end.php prints them after Bootstrap.
ob_start();
?>
<script src="../assets/vendor/chartjs/chart.umd.min.js"></script>
<script>
const sptsDark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
const sptsHeadingColor = sptsDark ? '#E2E8F0' : '#1A3A5C';
const sptsMutedColor   = sptsDark ? '#94A3B8' : '#64748B';
const sptsGridColor    = sptsDark ? '#263447' : '#F1F5F9';
const sptsCardColor    = sptsDark ? '#1E293B' : '#FFFFFF';
// Charts may draw before the Inter web font loads: give a fallback so
// labels never drop to the browser's serif default.
Chart.defaults.font.family = "'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif";
// Muted status palette (includes/grading.php), lighter in dark mode.
const sptsStatusColors = sptsDark
    ? <?php echo json_encode(status_palette(true)); ?>
    : <?php echo json_encode(status_palette(false)); ?>;

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
            backgroundColor: ['Excellent', 'Pass', 'At Risk', 'Fail'].map(s => sptsStatusColors[s]),
            // Thin gaps in the card colour separate the segments.
            borderWidth: 2,
            borderColor: sptsCardColor,
            hoverOffset: 4
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '78%',   // a slimmer ring
        plugins: { legend: { position: 'bottom', labels: { color: sptsMutedColor, font: { size: 11 }, padding: 20, usePointStyle: true } } }
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
            backgroundColor: <?php echo json_encode($subject_statuses, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>.map(s => sptsStatusColors[s]),
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
                ticks: { callback: val => val + '%', font: { size: 11 }, color: sptsMutedColor },
                grid: { color: sptsGridColor, drawBorder: false }
            },
            x: {
                grid: { display: false },
                ticks: { font: { size: 11 }, color: sptsMutedColor }
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

// PLO attainment vs class KPI
<?php if ($plo_has_data): ?>
const plos = <?php echo json_encode(array_values(array_map(fn($p) => [
    'code'  => $p['plo_code'],
    'title' => $p['plo_code'] . ' ' . $p['plo_title'],
    'pct'   => $p['pct'],
    'kpi'   => (float)$p['cohort_kpi_pct'],
    'n'     => $p['results'],
], $plo_institution)), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
new Chart(document.getElementById('ploChart'), {
    data: {
        labels: plos.map(p => p.code),
        datasets: [
            { type: 'bar', label: 'Attained', data: plos.map(p => p.pct),
              backgroundColor: plos.map(p => p.pct !== null && p.pct >= p.kpi ? sptsStatusColors['Excellent'] : sptsStatusColors['Fail']),
              borderRadius: 6, maxBarThickness: 48 },
            { type: 'line', label: 'Class KPI', data: plos.map(p => p.kpi), showLine: false,
              pointStyle: 'line', pointRadius: 22, pointBorderWidth: 3, borderColor: sptsHeadingColor, backgroundColor: sptsHeadingColor }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            y: { beginAtZero: true, max: 100, ticks: { callback: v => v + '%', font: { size: 11 }, color: sptsMutedColor }, grid: { color: sptsGridColor } },
            x: { grid: { display: false }, ticks: { font: { size: 11 }, color: sptsMutedColor } }
        },
        plugins: {
            legend: { position: 'bottom', labels: { color: sptsMutedColor, usePointStyle: true, font: { size: 11 } } },
            tooltip: { callbacks: { title: items => plos[items[0].dataIndex].title,
                                    label: c => c.dataset.type === 'line' ? ` KPI: ${c.raw}%`
                : ` ${c.raw === null ? 'not assessed' : c.raw + '%'} of ${plos[c.dataIndex].n} results` } }
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
<?php $page_scripts = ob_get_clean(); require '../includes/page_end.php'; ?>
