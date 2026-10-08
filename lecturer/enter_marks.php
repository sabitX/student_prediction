<?php
require '../includes/session_check.php';
require '../config/config.php';
require '../includes/csrf.php';
require '../includes/semester.php';
require '../includes/academic_level.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'lecturer') {
    header("Location: ../auth/login.php");
    exit();
}

$user_id    = $_SESSION['user_id'];
$full_name  = $_SESSION['full_name'];
$first_name = explode(' ', trim($full_name))[0];
$initials   = strtoupper(substr($first_name, 0, 1) . substr(explode(' ', trim($full_name))[1] ?? '', 0, 1));
$active_semester = h(get_active_semester_name($conn));

require '../includes/marks.php';

$semester_id = get_active_semester_id($conn);

// ── AUTHORIZATION: only the (non-archived) subjects this lecturer is
// actually assigned to. subject_id from the URL is never trusted directly.
$stmt_my_subs = mysqli_prepare($conn, "SELECT sub.id, sub.subject_code, sub.subject_name
                                       FROM lecturer_subjects ls JOIN subjects sub ON sub.id = ls.subject_id
                                       WHERE ls.user_id = ? AND sub.is_archived = 0
                                       ORDER BY sub.subject_code");
mysqli_stmt_bind_param($stmt_my_subs, "i", $user_id);
mysqli_stmt_execute($stmt_my_subs);
$my_subjects = mysqli_fetch_all(mysqli_stmt_get_result($stmt_my_subs), MYSQLI_ASSOC);
mysqli_stmt_close($stmt_my_subs);
$my_subject_ids = array_map(fn($r) => (int)$r['id'], $my_subjects);

if (isset($_GET['subject_id'])) {
    $selected_subject_id = (int)$_GET['subject_id'];
    // Requested a subject this lecturer doesn't teach — bounce them out.
    if (!in_array($selected_subject_id, $my_subject_ids, true)) {
        header("Location: lecturer_dashboard.php");
        exit();
    }
} else {
    // No subject requested (e.g. sidebar link) — default to their first assigned subject.
    $selected_subject_id = $my_subject_ids[0] ?? 0;
}

$no_subjects_assigned = ($selected_subject_id === 0);
$sub_info = null;
$students_data = [];
$student_count = 0;
$stats = ['Excellent' => 0, 'Pass' => 0, 'At Risk' => 0, 'Fail' => 0];
$exc_pct = $risk_pct = $fail_pct = 0;
$level_counts = ['Diploma' => 0, 'Degree' => 0, 'Masters' => 0, 'PhD' => 0];

if (!$no_subjects_assigned) {
    foreach ($my_subjects as $sub) {
        if ((int)$sub['id'] === $selected_subject_id) $sub_info = $sub;
    }

    // Only students enrolled in this subject for the active semester.
    $stmt_grid = mysqli_prepare($conn,
        "SELECT s.id as std_db_id, s.student_id, s.academic_level, u.full_name,
                 p.assignment, p.midterm, p.final_exam, p.attendance,
                 pr.total_score, pr.predicted_status
         FROM student_enrollments e
         JOIN students s ON s.id = e.student_id
         JOIN users u ON s.user_id = u.id
         LEFT JOIN performance p ON p.student_id = s.id AND p.subject_id = e.subject_id AND p.semester_id = e.semester_id
         LEFT JOIN predictions pr ON pr.student_id = s.id AND pr.subject_id = e.subject_id AND pr.semester_id = e.semester_id
         WHERE e.subject_id = ? AND e.semester_id = ?
         ORDER BY u.full_name");
    mysqli_stmt_bind_param($stmt_grid, "ii", $selected_subject_id, $semester_id);
    mysqli_stmt_execute($stmt_grid);
    $all_students = mysqli_stmt_get_result($stmt_grid);
    $student_count = mysqli_num_rows($all_students);
    mysqli_stmt_close($stmt_grid);

    // Calculate Stats for the Top Cards
    while ($row = mysqli_fetch_assoc($all_students)) {
        if ($row['predicted_status']) {
            $stats[$row['predicted_status']]++;
        }
        if (isset($level_counts[$row['academic_level']])) {
            $level_counts[$row['academic_level']]++;
        }
        $students_data[] = $row;
    }

    $exc_pct = $student_count > 0 ? round(($stats['Excellent'] / $student_count) * 100, 1) : 0;
    $risk_pct = $student_count > 0 ? round(($stats['At Risk'] / $student_count) * 100, 1) : 0;
    $fail_pct = $student_count > 0 ? round(($stats['Fail'] / $student_count) * 100, 1) : 0;
}

// Input value for a stored mark: blank when not assessed, no trailing ".00".
function mark_input_value($v) {
    return $v === null ? '' : format_mark($v);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enter Marks | SPTS Nilai University</title>
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
        body { font-family: 'Inter', system-ui, sans-serif; background: #F4F7F9; margin: 0; padding: 0; overflow-x: hidden; }

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
        .topbar { background: #1A3A5C; height: 60px; display: flex; align-items: center; justify-content: space-between; padding: 0 24px; color: white; position: fixed; width: 100%; top: 0; z-index: 1030; }
        .topbar .brand { font-weight: 700; font-size: 1.1rem; letter-spacing: 0.5px; }
        .topbar .user-profile { display: flex; align-items: center; gap: 12px; font-size: 0.85rem; color: #CBD5E1; }
        .avatar { background: #0D7377; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 600; color: white; }

        /* Layout */
        .layout-wrapper { display: flex; margin-top: 60px; min-height: calc(100vh - 60px); position: relative; z-index: 100; }
        
        /* Sidebar */
        .sidebar { width: 240px; background: #1E293B; flex-shrink: 0; position: fixed; height: calc(100vh - 60px); overflow-y: auto; display: flex; flex-direction: column; justify-content: space-between; }
        .sidebar-nav { padding-top: 20px; }
        .nav-item { padding: 14px 24px; color: #94A3B8; display: flex; align-items: center; gap: 12px; text-decoration: none; font-size: 0.9rem; font-weight: 500; transition: all 0.2s; }
        .nav-item:hover { color: white; background: rgba(255,255,255,0.05); }
        .nav-item.active { background: #0D7377; color: white; border-left: 4px solid white; }
        
        .active-semester { padding: 20px; background: rgba(0,0,0,0.15); text-align: center; border-top: 1px solid rgba(255,255,255,0.05); }
        .active-semester p { margin: 0; font-size: 0.75rem; color: #64748B; text-transform: uppercase; letter-spacing: 0.5px; }
        .active-semester h6 { margin: 4px 0 0; color: #F59E0B; font-weight: 700; font-size: 0.9rem; }

        /* Main Content */
        .main-content { margin-left: 240px; flex-grow: 1; padding: 32px; }
        
        /* Header */
        .page-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px; }
        .page-header h2 { font-weight: 700; color: #1A3A5C; margin: 0 0 4px; font-size: 1.5rem; }
        .page-header p { color: #64748B; margin: 0; font-size: 0.9rem; }
        
        .badge-semester { background: #0D7377; color: white; padding: 8px 16px; border-radius: 6px; font-weight: 600; font-size: 0.85rem; display: flex; align-items: center; gap: 8px; }

        /* Stat Cards */
        .stat-card { background: white; border-radius: 8px; padding: 20px; box-shadow: 0 2px 12px rgba(0,0,0,0.04); display: flex; flex-direction: column; justify-content: center; }
        .stat-card h3 { font-size: 1.8rem; font-weight: 700; margin: 0 0 4px; line-height: 1; }
        .stat-card p { font-size: 0.8rem; font-weight: 600; color: #1A3A5C; margin: 0; text-transform: uppercase; }
        .stat-card small { font-size: 0.75rem; color: #94A3B8; }
        
        .border-blue { border-left: 5px solid #3B82F6; }
        .border-green { border-left: 5px solid #10B981; }
        .border-yellow { border-left: 5px solid #F59E0B; }
        .border-red { border-left: 5px solid #EF4444; }

        .text-blue { color: #3B82F6; }
        .text-green { color: #10B981; }
        .text-yellow { color: #F59E0B; }
        .text-red { color: #EF4444; }

        /* Academic-level filter tabs (compact toggle group, not stat cards) */
        .level-filter-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            background: #EEF2F6;
            padding: 6px;
            border-radius: 10px;
        }
        .level-filter-btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            background: transparent;
            border: none;
            padding: 8px 14px;
            border-radius: 7px;
            font-size: 0.83rem;
            font-weight: 600;
            color: #64748B;
            cursor: pointer;
            transition: all 0.15s ease;
            white-space: nowrap;
        }
        .level-filter-btn i { font-size: 0.95rem; color: #0D7377; transition: color 0.15s ease; }
        .level-filter-btn:hover { background: rgba(13,115,119,0.08); color: #1A3A5C; }
        .level-filter-btn.active { background: #0D7377; color: #fff; box-shadow: 0 2px 10px rgba(13,115,119,0.25); }
        .level-filter-btn.active i { color: #fff; }
        .filter-count {
            background: rgba(100,116,139,0.14);
            color: #475569;
            font-size: 0.72rem;
            font-weight: 700;
            padding: 1px 8px;
            border-radius: 20px;
            min-width: 20px;
            text-align: center;
        }
        .level-filter-btn.active .filter-count { background: rgba(255,255,255,0.25); color: #fff; }

        /* Table Area */
        .table-container { background: white; border-radius: 12px; box-shadow: 0 4px 16px rgba(0,0,0,0.03); overflow: hidden; margin-top: 24px; padding: 20px; }
        .table-toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        
        .table-custom { width: 100%; border-collapse: separate; border-spacing: 0; }
        .table-custom th { background: #1A3A5C; color: white; padding: 12px 16px; font-weight: 600; font-size: 0.8rem; text-align: left; }
        .table-custom th:first-child { border-radius: 8px 0 0 8px; }
        .table-custom th:last-child { border-radius: 0 8px 8px 0; }
        
        .table-custom td { padding: 16px; border-bottom: 1px solid #F1F5F9; vertical-align: middle; font-size: 0.88rem; color: #334155; }
        .table-custom tr:last-child td { border-bottom: none; }
        
        /* Inputs & Badges */
        .mark-input { width: 70px; padding: 6px; border: 1px solid #E2E8F0; border-radius: 6px; text-align: center; font-size: 0.85rem; color: #64748B; transition: border-color 0.2s, box-shadow 0.2s; }
        .mark-input:focus { border-color: #0D7377; color: #1A3A5C; font-weight: 600; }

        .student-id { color: #0D7377; font-weight: 500; text-decoration: none; }
        .student-name { font-weight: 600; color: #1E293B; }

        /* ── Color-agnostic status pills ──────────────────────────────────
           Each status now carries a distinct icon shape (check / triangle /
           x / dash) so standing is legible even without color perception,
           and text colors were darkened for AA contrast against their pill
           background instead of relying on hue alone. */
        .status-pill { padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; gap: 5px; text-align: center; min-width: 90px; }
        .status-pill i { font-size: 0.85rem; line-height: 1; }
        .pill-exc  { background: #D1FAE5; color: #047857; }
        .pill-pass { background: #E0F2FE; color: #075985; }
        .pill-risk { background: #FEF3C7; color: #92400E; }
        .pill-fail { background: #FEE2E2; color: #991B1B; }
        .pill-none { background: #F1F5F9; color: #475569; }

        .btn-save { background: #0D7377; color: white; padding: 8px 20px; border-radius: 6px; font-weight: 600; font-size: 0.85rem; border: none; transition: background 0.2s; }
        .btn-save:hover { background: #0a5e62; }

        /* ── Keyboard focus visibility (motor-disability support) ──────────
           Tab order already follows DOM order row-by-row, left-to-right
           (Assignment → Midterm → Final Exam → Attendance, then next
           student) since this is a plain <table> with no tabindex or CSS
           `order` overrides — so a strong focus ring is enough to make that
           order visible to keyboard-only users. Combining :focus with
           :focus-visible keeps the ring working even in browsers that
           don't support :focus-visible yet. */
        .mark-input:focus,
        .mark-input:focus-visible,
        .level-filter-btn:focus,
        .level-filter-btn:focus-visible,
        .btn-save:focus,
        .btn-save:focus-visible {
            outline: 3px solid #0D7377;
            outline-offset: 2px;
            box-shadow: 0 0 0 3px rgba(13, 115, 119, 0.25);
        }
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
            <span>Lecturer Dashboard</span>
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

            <?php if (isset($_GET['saved'])): ?>
                <?php $skipped = (int)($_GET['skipped'] ?? 0); ?>
                <div class="alert py-2 mb-3" style="background:#F0FDF4; border:1px solid #BBF7D0; color:#166534; font-size:0.88rem; border-radius:8px;">
                    <i class="bi bi-check-circle-fill me-2 text-success"></i>
                    <?php echo (int)$_GET['saved']; ?> student record(s) saved successfully.
                    <?php if ($skipped > 0): ?>
                        <?php echo $skipped; ?> row(s) skipped due to invalid marks (each mark must be a number from 0 to 100, or left blank).
                    <?php endif; ?>
                </div>
            <?php elseif (isset($_GET['save_error'])): ?>
                <div class="alert py-2 mb-3" style="background:#FEF2F2; border:1px solid #FECACA; color:#991B1B; font-size:0.88rem; border-radius:8px;">
                    <i class="bi bi-exclamation-circle-fill me-2 text-danger"></i>
                    <?php if ($_GET['save_error'] === 'nosemester'): ?>
                        No semester is active, so marks can't be saved. Ask an administrator to activate a semester.
                    <?php else: ?>
                        Failed to save marks due to an unexpected error. Nothing was changed — please try again.
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="page-header">
                <div>
                    <h2>Mark Entry Grid</h2>
                    <p>
                        <?php if ($no_subjects_assigned): ?>
                            No subjects assigned yet
                        <?php else: ?>
                            <?php echo h($sub_info['subject_code']); ?> — <?php echo h($sub_info['subject_name']); ?> &nbsp;|&nbsp; <?php echo $student_count; ?> students
                        <?php endif; ?>
                    </p>
                </div>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <?php if (count($my_subjects) > 1): ?>
                        <form method="GET" class="m-0">
                            <label for="subjectPicker" class="visually-hidden">Subject</label>
                            <select name="subject_id" id="subjectPicker" class="form-select form-select-sm" onchange="this.form.submit()">
                                <?php foreach ($my_subjects as $sub): ?>
                                    <option value="<?php echo (int)$sub['id']; ?>" <?php echo (int)$sub['id'] === $selected_subject_id ? 'selected' : ''; ?>>
                                        <?php echo h($sub['subject_code'] . ' — ' . $sub['subject_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    <?php endif; ?>
                    <div class="badge-semester">
                        <i class="bi bi-calendar3"></i> <?php echo $active_semester; ?>
                    </div>
                </div>
            </div>

            <?php if ($no_subjects_assigned): ?>
            <div class="table-container text-center" style="padding: 60px 20px; color: #64748B;">
                <i class="bi bi-journal-x" style="font-size: 2.5rem; opacity: 0.3; display: block; margin-bottom: 12px;"></i>
                <p class="mb-0">You have no subjects assigned yet. Contact an administrator to be assigned to a subject.</p>
            </div>
            <?php else: ?>

            <!-- Stat Cards -->
            <div class="row g-4 mb-4">
                <div class="col-md-3">
                    <div class="stat-card border-blue">
                        <h3 class="text-blue"><?php echo $student_count; ?></h3>
                        <p>Total Students</p>
                        <small>enrolled</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card border-green">
                        <h3 class="text-green"><?php echo $stats['Excellent']; ?></h3>
                        <p>Excellent</p>
                        <small><?php echo $exc_pct; ?>%</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card border-yellow">
                        <h3 class="text-yellow"><?php echo $stats['At Risk']; ?></h3>
                        <p>At Risk</p>
                        <small><?php echo $risk_pct; ?>%</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card border-red">
                        <h3 class="text-red"><?php echo $stats['Fail']; ?></h3>
                        <p>Fail</p>
                        <small><?php echo $fail_pct; ?>%</small>
                    </div>
                </div>
            </div>

            <?php if ($semester_id === 0): ?>
            <div class="alert alert-warning">No semester is active. Marks can't be entered until an administrator activates one.</div>
            <?php elseif ($student_count === 0): ?>
            <div class="alert alert-info">No students are enrolled in this subject for the active semester yet. An administrator can enrol students from <strong>Enrolments</strong>.</div>
            <?php endif; ?>

            <p class="text-muted small mb-2"><i class="bi bi-info-circle me-1"></i>Leave a mark blank if it hasn't been assessed yet. The total and status appear once all four marks are entered.</p>

            <!-- Table Container -->
            <form method="POST" action="../actions/save_marks.php" class="table-container">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="subject_id" value="<?php echo $selected_subject_id; ?>">

                <!-- Academic-level filter tabs — toggle to instantly filter the roster below, no reload -->
                <div class="level-filter-bar mb-3" id="flashCards" role="tablist">
                    <button type="button" class="level-filter-btn active" data-filter="all" role="tab" aria-selected="true">
                        <i class="bi bi-mortarboard-fill"></i>All Students
                        <span class="filter-count"><?php echo $student_count; ?></span>
                    </button>
                    <button type="button" class="level-filter-btn" data-filter="Diploma" role="tab" aria-selected="false">
                        <i class="bi bi-mortarboard-fill"></i>Diploma
                        <span class="filter-count"><?php echo $level_counts['Diploma']; ?></span>
                    </button>
                    <button type="button" class="level-filter-btn" data-filter="Degree" role="tab" aria-selected="false">
                        <i class="bi bi-mortarboard-fill"></i>Bachelor's Degree
                        <span class="filter-count"><?php echo $level_counts['Degree']; ?></span>
                    </button>
                    <button type="button" class="level-filter-btn" data-filter="Masters" role="tab" aria-selected="false">
                        <i class="bi bi-mortarboard-fill"></i>Master's
                        <span class="filter-count"><?php echo $level_counts['Masters']; ?></span>
                    </button>
                    <button type="button" class="level-filter-btn" data-filter="PhD" role="tab" aria-selected="false">
                        <i class="bi bi-mortarboard-fill"></i>PhD
                        <span class="filter-count"><?php echo $level_counts['PhD']; ?></span>
                    </button>
                </div>

                <div class="table-toolbar">
                    <div style="color: #64748B; font-size: 0.9rem; font-weight: 500;">
                        <i class="bi bi-journal-code me-2"></i><?php echo h($sub_info['subject_code']); ?> — <?php echo h($sub_info['subject_name']); ?>
                    </div>
                    <button type="submit" name="save_all" class="btn-save">
                        <i class="bi bi-save2 me-2"></i>Save All Marks
                    </button>
                </div>

                <div class="table-responsive">
                    <table class="table-custom">
                        <thead>
                            <tr>
                                <th style="width: 40px;">#</th>
                                <th>Student ID</th>
                                <th>Name</th>
                                <th class="text-center">Assignment</th>
                                <th class="text-center">Midterm</th>
                                <th class="text-center">Final Exam</th>
                                <th class="text-center">Attendance</th>
                                <th class="text-center">Total</th>
                                <th class="text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $i = 1;
                            foreach ($students_data as $row):
                                $a = mark_input_value($row['assignment']);
                                $m = mark_input_value($row['midterm']);
                                $f = mark_input_value($row['final_exam']);
                                $t = mark_input_value($row['attendance']);
                                $total  = $row['total_score'] ?? '';
                                $status = $row['predicted_status'] ?? '';

                                // Escaped once per row and reused across all four
                                // aria-labels below (WCAG: every input needs an
                                // accessible name a screen reader can announce).
                                $student_name_esc = h($row['full_name']);

                                $pill_class = match($status) {
                                    'Excellent' => 'pill-exc',
                                    'Pass'      => 'pill-pass',
                                    'At Risk'   => 'pill-risk',
                                    'Fail'      => 'pill-fail',
                                    default     => 'pill-none'
                                };
                                // Icon per status so standing is never conveyed by
                                // color/background alone (colour-blind support).
                                $pill_icon = match($status) {
                                    'Excellent' => 'bi-check-circle-fill',
                                    'Pass'      => 'bi-check-circle',
                                    'At Risk'   => 'bi-exclamation-triangle-fill',
                                    'Fail'      => 'bi-x-circle-fill',
                                    default     => 'bi-dash-circle'
                                };
                            ?>
                            <tr data-level="<?php echo h($row['academic_level']); ?>">
                                <td><?php echo $i++; ?></td>
                                <td><a href="#" class="student-id"><?php echo h($row['student_id']); ?></a></td>
                                <td class="student-name"><?php echo $student_name_esc; ?></td>
                                <td class="text-center">
                                    <input type="text" inputmode="decimal" name="assignment_<?php echo $row['std_db_id']; ?>" class="mark-input" value="<?php echo h($a); ?>" aria-label="Enter Assignment mark for <?php echo $student_name_esc; ?>"> <span style="color:#94A3B8; font-size:0.78rem;">%</span>
                                </td>
                                <td class="text-center">
                                    <input type="text" inputmode="decimal" name="midterm_<?php echo $row['std_db_id']; ?>" class="mark-input" value="<?php echo h($m); ?>" aria-label="Enter Midterm mark for <?php echo $student_name_esc; ?>"> <span style="color:#94A3B8; font-size:0.78rem;">%</span>
                                </td>
                                <td class="text-center">
                                    <input type="text" inputmode="decimal" name="final_exam_<?php echo $row['std_db_id']; ?>" class="mark-input" value="<?php echo h($f); ?>" aria-label="Enter Final Exam mark for <?php echo $student_name_esc; ?>"> <span style="color:#94A3B8; font-size:0.78rem;">%</span>
                                </td>
                                <td class="text-center">
                                    <input type="text" inputmode="decimal" name="attendance_<?php echo $row['std_db_id']; ?>" class="mark-input" value="<?php echo h($t); ?>" aria-label="Enter Attendance mark for <?php echo $student_name_esc; ?>"> <span style="color:#94A3B8; font-size:0.78rem;">%</span>
                                </td>
                                <td class="text-center fw-bold" style="color: #1A3A5C;">
                                    <?php echo $total !== '' ? number_format((float)$total,1).'%' : '—'; ?>
                                </td>
                                <td class="text-center">
                                    <span class="status-pill <?php echo $pill_class; ?>">
                                        <i class="bi <?php echo $pill_icon; ?>" aria-hidden="true"></i>
                                        <?php echo $status ? h($status) : 'Pending'; ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <tr id="noLevelMatch" style="display:none;">
                                <td colspan="9" class="text-center" style="padding: 30px; color: #94A3B8;">
                                    <i class="bi bi-inbox me-2"></i>No students in this category.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </form>
            <?php endif; ?>
        </main>
    </div>

    <script src="../assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/theme-toggle.js"></script>
    <script src="../assets/js/app.js?v=2"></script>
    <script>
    // Academic-level filter tabs — purely client-side visibility toggling.
    // Rows (and the mark inputs inside them) stay in the DOM at all times,
    // so switching tabs back and forth never loses anything the lecturer
    // has typed; only display:none is toggled, which does not remove a
    // field's value or exclude it from the form submission.
    (function () {
        const tabs = document.querySelectorAll('#flashCards .level-filter-btn');
        const noMatchRow = document.getElementById('noLevelMatch');

        function applyFilter(filter) {
            let visibleCount = 0;
            document.querySelectorAll('tr[data-level]').forEach(function (row) {
                const show = (filter === 'all') || (row.dataset.level === filter);
                row.style.display = show ? '' : 'none';
                if (show) visibleCount++;
            });
            if (noMatchRow) {
                noMatchRow.style.display = visibleCount === 0 ? '' : 'none';
            }
        }

        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                tabs.forEach(function (t) {
                    t.classList.remove('active');
                    t.setAttribute('aria-selected', 'false');
                });
                tab.classList.add('active');
                tab.setAttribute('aria-selected', 'true');
                applyFilter(tab.dataset.filter);
            });
        });
    })();
    </script>
</body>
</html>