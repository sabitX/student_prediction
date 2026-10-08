<?php
require '../includes/session_check.php';
require '../config/config.php';
require '../includes/csrf.php';
require '../includes/grading.php';
require '../includes/semester.php';

if (!isset($_SESSION['user_id']) or $_SESSION['role'] != 'lecturer') {
    header("Location: ../auth/login.php");
    exit();
}

$full_name  = $_SESSION['full_name'];
$first_name = explode(' ', trim($full_name))[0];
$active_semester = h(get_active_semester_name($conn));

require '../includes/marks.php';

$user_id      = (int)$_SESSION['user_id'];
$semester_id  = get_active_semester_id($conn);
$success_rows = [];
$unchanged    = 0;
$error_rows   = [];   // plain text — escaped when displayed
$uploaded     = false;

const CSV_COLUMNS  = ['student_id', 'subject_code', 'assignment', 'midterm', 'final_exam', 'attendance'];
const CSV_MAX_BYTES = 2 * 1024 * 1024;   // 2 MB is thousands of rows
const CSV_MAX_ROWS  = 5000;

// Subjects this lecturer may upload marks for: assigned to them and not
// archived. Keyed by upper-case subject code.
$stmt_mine = mysqli_prepare($conn, "SELECT sub.id, sub.subject_code FROM lecturer_subjects ls
                                    JOIN subjects sub ON sub.id = ls.subject_id
                                    WHERE ls.user_id = ? AND sub.is_archived = 0");
mysqli_stmt_bind_param($stmt_mine, "i", $user_id);
mysqli_stmt_execute($stmt_mine);
$my_subjects = [];
foreach (mysqli_fetch_all(mysqli_stmt_get_result($stmt_mine), MYSQLI_ASSOC) as $row) {
    $my_subjects[strtoupper($row['subject_code'])] = $row;
}
mysqli_stmt_close($stmt_mine);

// ── DOWNLOAD TEMPLATE ─────────────────────────────────────────
// Pre-filled with the real roster: one row per student enrolled in each
// of this lecturer's subjects for the active semester, including any
// marks already saved, so it can be completed and uploaded as-is.
if (isset($_GET['download_template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="marks_template.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, CSV_COLUMNS);
    if ($semester_id && $my_subjects) {
        $ids = implode(',', array_map(fn($r) => (int)$r['id'], $my_subjects)); // ints from the DB
        $roster = mysqli_query($conn, "SELECT s.student_id, sub.subject_code, p.assignment, p.midterm, p.final_exam, p.attendance
                                       FROM student_enrollments e
                                       JOIN students s ON s.id = e.student_id
                                       JOIN subjects sub ON sub.id = e.subject_id
                                       LEFT JOIN performance p ON p.student_id = e.student_id AND p.subject_id = e.subject_id AND p.semester_id = e.semester_id
                                       WHERE e.semester_id = $semester_id AND e.subject_id IN ($ids)
                                       ORDER BY sub.subject_code, s.student_id");
        while ($r = mysqli_fetch_assoc($roster)) {
            fputcsv($out, [$r['student_id'], $r['subject_code'],
                           $r['assignment'] === null ? '' : format_mark($r['assignment']),
                           $r['midterm'] === null ? '' : format_mark($r['midterm']),
                           $r['final_exam'] === null ? '' : format_mark($r['final_exam']),
                           $r['attendance'] === null ? '' : format_mark($r['attendance'])]);
        }
    }
    fclose($out);
    exit();
}

// ── PROCESS UPLOAD ────────────────────────────────────────────
if (isset($_POST['upload'])) {
    $file = $_FILES['csv_file'] ?? null;

    if (!csrf_verify()) {
        $upload_error = "Your session expired or the request could not be verified. Please try again.";
    } elseif (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        $upload_error = "Please choose a .csv file to upload.";
    } elseif (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'csv') {
        $upload_error = "Only .csv files are allowed.";
    } elseif ($file['size'] > CSV_MAX_BYTES) {
        $upload_error = "The file is too large (max 2 MB). Split it into smaller files.";
    } elseif ($semester_id === 0) {
        $upload_error = "No semester is active, so marks can't be saved. Ask an administrator to activate a semester.";
    } else {
        $handle = fopen($file['tmp_name'], 'r');
        $header = fgetcsv($handle);

        if ($header === false || $header === [null]) {
            $upload_error = "The file is empty.";
        } else {
            // Normalise the header: strip Excel's UTF-8 byte-order mark,
            // whitespace and case, then map each column by name so extra or
            // reordered columns don't break anything.
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
            $header    = array_map(fn($h) => strtolower(trim((string)$h)), $header);
            $col       = array_flip($header);
            $missing   = array_diff(CSV_COLUMNS, $header);
        }

        if (!isset($upload_error) && !empty($missing)) {
            $upload_error = "Invalid CSV format. Missing columns: " . implode(', ', $missing) . ". Download the template for the correct header row.";
        }

        if (!isset($upload_error)) {
            $stmt_student = mysqli_prepare($conn, "SELECT id, student_id FROM students WHERE student_id = ?");
            $ctx = ['user_id' => $user_id, 'user_name' => $_SESSION['full_name'], 'source' => 'CSV Upload'];

            // All valid rows are saved together; a database failure part-way
            // rolls back the whole file so it can simply be uploaded again.
            mysqli_begin_transaction($conn);
            try {
                $line = 1;
                while (($data = fgetcsv($handle)) !== false) {
                    $line++;
                    if ($line - 1 > CSV_MAX_ROWS) {
                        $error_rows[] = "Stopped after " . CSV_MAX_ROWS . " rows — upload the rest in another file.";
                        break;
                    }
                    // Skip fully blank lines (e.g. trailing newlines from Excel).
                    if (count(array_filter($data, fn($v) => trim((string)$v) !== '')) === 0) {
                        continue;
                    }

                    $get = fn($name) => trim((string)($data[$col[$name]] ?? ''));
                    $student_code = strtolower($get('student_id'));   // stored lower-case
                    $subject_code = strtoupper($get('subject_code'));
                    $label        = "Row $line" . ($student_code !== '' ? " ($student_code)" : '');

                    if ($student_code === '' || $subject_code === '') {
                        $error_rows[] = "$label: student_id and subject_code are required — skipped.";
                        continue;
                    }

                    [$marks, $mark_errors] = parse_mark_set([
                        'assignment' => $get('assignment'),
                        'midterm'    => $get('midterm'),
                        'final_exam' => $get('final_exam'),
                        'attendance' => $get('attendance'),
                    ]);
                    if ($mark_errors) {
                        $error_rows[] = "$label: " . implode('; ', $mark_errors) . " — skipped.";
                        continue;
                    }
                    if (!array_filter($marks, fn($v) => $v !== null)) {
                        continue; // no marks on this row yet
                    }

                    if (!isset($my_subjects[$subject_code])) {
                        $error_rows[] = "$label: subject '$subject_code' is not one of your active subjects — skipped.";
                        continue;
                    }
                    $subject = $my_subjects[$subject_code];

                    mysqli_stmt_bind_param($stmt_student, "s", $student_code);
                    mysqli_stmt_execute($stmt_student);
                    $student = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_student));
                    if (!$student) {
                        $error_rows[] = "$label: student ID not found in the system — skipped.";
                        continue;
                    }
                    if (!is_enrolled($conn, (int)$student['id'], (int)$subject['id'], $semester_id)) {
                        $error_rows[] = "$label: student is not enrolled in $subject_code this semester — skipped.";
                        continue;
                    }

                    $ctx['student_code'] = $student['student_id'];
                    $ctx['subject_code'] = $subject['subject_code'];
                    $result = save_student_marks($conn, (int)$student['id'], (int)$subject['id'], $semester_id, $marks, $ctx);
                    if ($result === 'unchanged') {
                        $unchanged++;
                        continue;
                    }

                    // Result as the database calculates it (predictions view),
                    // so this report matches every other page exactly.
                    $stmt_res = mysqli_prepare($conn, "SELECT total_score, predicted_status FROM predictions WHERE student_id = ? AND subject_id = ? AND semester_id = ?");
                    $student_db_id = (int)$student['id'];
                    $subject_db_id = (int)$subject['id'];
                    mysqli_stmt_bind_param($stmt_res, "iii", $student_db_id, $subject_db_id, $semester_id);
                    mysqli_stmt_execute($stmt_res);
                    $res = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_res));
                    mysqli_stmt_close($stmt_res);
                    $success_rows[] = [
                        'student_id'   => $student['student_id'],
                        'subject_code' => $subject['subject_code'],
                        'marks'        => $marks,
                        'total_score'  => $res ? number_format((float)$res['total_score'], 2) : null,
                        'status'       => $res ? $res['predicted_status'] : 'Pending',
                    ];
                }
                mysqli_commit($conn);
                $uploaded = true;
            } catch (mysqli_sql_exception $e) {
                mysqli_rollback($conn);
                error_log('csv_upload failed: ' . $e->getMessage());
                $success_rows = [];
                $upload_error = "A database error occurred, so no rows from this file were saved. Please try again.";
            }
            mysqli_stmt_close($stmt_student);
        }
        fclose($handle);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CSV Bulk Upload | SPTS Nilai University</title>
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

        /* ── Dashboard Cards & UI ───────────────────────────────────────── */
        .dashboard-card {
            background: #fff;
            border: none;
            border-radius: 16px;
            padding: 28px;
            box-shadow: 0 8px 32px rgba(26,58,92,0.05), 0 2px 8px rgba(26,58,92,0.02);
            margin-bottom: 24px;
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

        .btn-primary-custom {
            background: #0D7377;
            border: 2px solid #0D7377;
            color: #fff;
            font-weight: 600;
            transition: all 0.2s;
        }
        .btn-primary-custom:hover { background: #0a5e62; border-color: #0a5e62; color: #fff; }

        /* ── Upload Zone ────────────────────────────────────────────────── */
        .upload-zone {
            border: 2px dashed #0D7377;
            border-radius: 12px;
            padding: 50px 40px;
            text-align: center;
            background: #F4FBFC;
            cursor: pointer;
            transition: all 0.25s ease;
        }
        .upload-zone.drag-over {
            background: #EAF7F8;
            border-color: #0a5e62;
            box-shadow: 0 0 0 4px rgba(13,115,119,0.15);
            transform: scale(1.01);
        }
        .upload-zone:hover {
            background: #EAF7F8;
            border-color: #0a5e62;
        }
        .upload-icon { font-size: 3rem; margin-bottom: 12px; color: #0D7377; }

        /* ── Tables ─────────────────────────────────────────────────────── */
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
            padding: 12px;
            border-color: #F8FAFC;
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
        <span>Welcome, <?php echo h($first_name); ?></span>
        <div class="avatar"><?php echo strtoupper(substr($first_name, 0, 1) . substr(explode(' ', trim($full_name))[1] ?? '', 0, 1)); ?></div>
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
    <div class="container mb-5" style="max-width: 960px; margin: 0 auto;">

    <div class="d-flex align-items-center gap-3 mb-4">
        <div style="background:#0D7377; color:white; padding:12px; border-radius:12px; box-shadow:0 4px 12px rgba(13,115,119,0.2);">
            <i class="bi bi-file-earmark-spreadsheet fs-4"></i>
        </div>
        <div>
            <h4 class="fw-bold mb-1" style="color:#1A3A5C; letter-spacing:-0.5px;">CSV Bulk Mark Upload</h4>
            <p class="text-muted mb-0" style="font-size:0.9rem;">Upload a properly formatted .csv file to batch-process student assessments.</p>
        </div>
    </div>

    <!-- Instructions -->
    <div class="dashboard-card">
        <div class="card-header-title">
            <i class="bi bi-info-circle text-primary"></i>Upload Instructions
        </div>
        <div class="row g-4 text-center mb-4">
            <div class="col-md-3">
                <div style="font-size:1.8rem; font-weight:800; color:#CBD5E1; line-height:1; margin-bottom:8px;">01</div>
                <p class="mb-0" style="font-size:0.85rem; color:#475569; font-weight:500;">Download the template — it lists your enrolled students</p>
            </div>
            <div class="col-md-3">
                <div style="font-size:1.8rem; font-weight:800; color:#CBD5E1; line-height:1; margin-bottom:8px;">02</div>
                <p class="mb-0" style="font-size:0.85rem; color:#475569; font-weight:500;">Fill in the marks (leave blank if not assessed yet)</p>
            </div>
            <div class="col-md-3">
                <div style="font-size:1.8rem; font-weight:800; color:#CBD5E1; line-height:1; margin-bottom:8px;">03</div>
                <p class="mb-0" style="font-size:0.85rem; color:#475569; font-weight:500;">Export file exactly as .csv format</p>
            </div>
            <div class="col-md-3">
                <div style="font-size:1.8rem; font-weight:800; color:#0D7377; line-height:1; margin-bottom:8px;">04</div>
                <p class="mb-0" style="font-size:0.85rem; color:#1A3A5C; font-weight:600;">System validates & saves all data</p>
            </div>
        </div>

        <div class="alert py-3 mb-4 d-flex gap-3 align-items-start" style="background:#FFFBEB; border:1px solid #FDE68A; color:#92400E; border-radius:10px;">
            <i class="bi bi-exclamation-triangle-fill mt-1 fs-5"></i>
            <div style="font-size:0.88rem;">
                <strong class="d-block mb-1">Header Row Required</strong>
                Columns are matched by the header row, so keep it. The required columns are: <code>student_id</code>, <code>subject_code</code>, <code>assignment</code>, <code>midterm</code>, <code>final_exam</code>, and <code>attendance</code>. Only your own subjects and students enrolled this semester are accepted.
            </div>
        </div>

        <h6 class="fw-bold mb-3" style="font-size:0.85rem; color:#475569; text-transform:uppercase; letter-spacing:0.5px;">Required File Format</h6>
        <div class="table-responsive">
        <table class="table table-bordered mb-2" style="font-size:0.85rem;">
            <thead class="table-custom-header">
                <tr>
                    <th class="ps-3">student_id</th>
                    <th>subject_code</th>
                    <th class="text-center">assignment</th>
                    <th class="text-center">midterm</th>
                    <th class="text-center">final_exam</th>
                    <th class="text-center">attendance</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="ps-3 fw-medium">n00021960</td>
                    <td class="fw-medium">EC2301</td>
                    <td class="text-center text-muted">75</td>
                    <td class="text-center text-muted">68</td>
                    <td class="text-center text-muted">72.5</td>
                    <td class="text-center text-muted">90</td>
                </tr>
            </tbody>
        </table>
        </div>
        <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
            <small class="text-muted" style="font-size:0.75rem;">
                Marks must be numbers from 0 to 100 (decimals allowed), or left blank if not assessed yet.
            </small>
            <a href="csv_upload.php?download_template=1" class="btn btn-sm" style="background:#F1F5F9; color:#475569; border:1px solid #E2E8F0; font-weight:600;">
                <i class="bi bi-download me-1"></i>Download CSV Template
            </a>
        </div>
    </div>

    <!-- Upload Form -->
    <div class="dashboard-card">
        <div class="card-header-title">
            <i class="bi bi-cloud-arrow-up text-primary"></i>Upload Data File
        </div>

        <?php if (isset($upload_error)): ?>
            <div class="alert alert-danger d-flex gap-2 align-items-start mb-4" style="border-radius:10px;">
                <i class="bi bi-x-circle-fill mt-1"></i>
                <span style="font-size:0.9rem;"><?php echo h($upload_error); ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" id="upload-form">
            <?php echo csrf_field(); ?>
            <!-- Drag & Drop Zone -->
            <div class="upload-zone mb-3" id="drop-zone" onclick="document.getElementById('csv_file').click();">
                <div class="upload-icon">
                    <i class="bi bi-file-earmark-arrow-up"></i>
                </div>
                <h6 class="fw-bold" style="color:#0D7377;">Click to browse or drag & drop your file here</h6>
                <p class="text-muted mb-0" style="font-size:0.85rem;">Must be a standard .csv format</p>
                <input type="file" id="csv_file" name="csv_file" accept=".csv" style="display:none;" onchange="showFileName(this)">
            </div>

            <div id="file-name" class="text-center fw-semibold mb-4" style="font-size:0.9rem; color:#0D7377; display:none; padding:10px; background:#F4FBFC; border-radius:8px;">
                <i class="bi bi-check-circle-fill me-2"></i><span id="file-name-text"></span> selected ready for processing
            </div>

            <!-- Results Summary (shown after upload) -->
            <?php if ($uploaded): ?>
            <div class="alert <?php echo count($error_rows) > 0 ? 'alert-warning' : 'alert-success'; ?> d-flex gap-3 align-items-start mb-4" style="border-radius:10px; border-width:1px;">
                <i class="bi bi-bar-chart-steps mt-1 fs-5"></i>
                <div style="font-size:0.9rem;">
                    <strong class="d-block mb-1">Batch Processing Complete</strong>
                    <i class="bi bi-check-circle-fill text-success me-1"></i> <strong><?php echo count($success_rows); ?> record(s)</strong> saved.<br>
                    <?php if ($unchanged > 0): ?>
                        <i class="bi bi-dash-circle me-1"></i> <strong><?php echo $unchanged; ?> row(s)</strong> already up to date.<br>
                    <?php endif; ?>
                    <?php if (count($error_rows) > 0): ?>
                        <i class="bi bi-x-circle-fill text-danger me-1 mt-1"></i> <strong><?php echo count($error_rows); ?> row(s)</strong> rejected during validation check.
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="text-center">
                <button type="submit" name="upload" class="btn btn-primary-custom px-5 py-2 rounded-2" style="letter-spacing:0.3px;">
                    <i class="bi bi-rocket-takeoff me-2"></i>Execute Batch Upload
                </button>
            </div>
        </form>
    </div>

    <!-- Results Data Tables -->
    <?php if ($uploaded): ?>

        <!-- Success Table -->
        <?php if (!empty($success_rows)): ?>
        <div class="dashboard-card p-0 overflow-hidden mb-4">
            <div class="p-4 border-bottom">
                <div class="card-header-title mb-0" style="color:#059669;">
                    <i class="bi bi-check-circle-fill"></i>Successfully Committed Records (<?php echo count($success_rows); ?>)
                </div>
            </div>
            <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-custom-header">
                    <tr>
                        <th class="ps-4">Student ID</th>
                        <th>Subject</th>
                        <th class="text-center">Assignment</th>
                        <th class="text-center">Midterm</th>
                        <th class="text-center">Final</th>
                        <th class="text-center">Attendance</th>
                        <th class="text-center">Total</th>
                        <th class="text-center pe-4">Result</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($success_rows as $r):
                        $badge = match($r['status']) {
                            'Excellent' => 'success',
                            'Pass'      => 'primary',
                            'At Risk'   => 'warning',
                            'Fail'      => 'danger',
                            default     => 'secondary'
                        };
                        $badge_text = ($r['status'] == 'At Risk') ? 'text-dark' : 'text-white';
                    ?>
                    <tr>
                        <td class="ps-4 fw-bold" style="color:#1A3A5C;"><?php echo h($r['student_id']); ?></td>
                        <td class="fw-medium text-dark"><?php echo h($r['subject_code']); ?></td>
                        <?php foreach (MARK_FIELDS as $key => $label): ?>
                            <td class="text-center text-muted" style="font-size:0.85rem;"><?php echo $r['marks'][$key] === null ? '—' : format_mark($r['marks'][$key]) . '%'; ?></td>
                        <?php endforeach; ?>
                        <td class="text-center fw-bold" style="color:#1A3A5C;"><?php echo $r['total_score'] === null ? '—' : $r['total_score'] . '%'; ?></td>
                        <td class="text-center pe-4">
                            <span class="badge bg-<?php echo $badge; ?> <?php echo $badge_text; ?> px-2 py-1" style="font-weight:600;"><?php echo h($r['status']); ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Error List -->
        <?php if (!empty($error_rows)): ?>
        <div class="dashboard-card mb-4" style="border: 1px solid #FECACA;">
            <div class="card-header-title mb-3" style="color:#DC2626;">
                <i class="bi bi-exclamation-octagon-fill"></i>Validation Failures (<?php echo count($error_rows); ?>)
            </div>
            <?php foreach ($error_rows as $err): ?>
                <div class="alert py-2 mb-2 d-flex gap-2 align-items-start" style="background:#FEF2F2; color:#991B1B; border:none; font-size:0.85rem; font-family:monospace;">
                    <i class="bi bi-arrow-right-short mt-1"></i>
                    <span><?php echo h($err); ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="text-center mb-4">
            <a href="lecturer_dashboard.php" class="btn btn-primary-custom px-4 rounded-2">
                <i class="bi bi-arrow-left me-2"></i>Return to Dashboard
            </a>
        </div>

    <?php endif; ?>

    </div>
    </main>
</div>

<script src="../assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="../assets/js/theme-toggle.js"></script>
<script src="../assets/js/app.js"></script>
<script>
// Show selected file name
function showFileName(input) {
    const nameBox  = document.getElementById('file-name');
    const nameText = document.getElementById('file-name-text');
    if (input.files[0]) {
        nameText.textContent = input.files[0].name;
        nameBox.style.display = 'block';
    } else {
        nameBox.style.display = 'none';
    }
}

// Drag & Drop active state
const dropZone = document.getElementById('drop-zone');
const fileInput = document.getElementById('csv_file');

dropZone.addEventListener('dragover', (e) => {
    e.preventDefault();
    dropZone.classList.add('drag-over');
});

dropZone.addEventListener('dragleave', () => {
    dropZone.classList.remove('drag-over');
});

dropZone.addEventListener('drop', (e) => {
    e.preventDefault();
    dropZone.classList.remove('drag-over');
    const files = e.dataTransfer.files;
    if (files.length > 0) {
        fileInput.files = files;
        showFileName(fileInput);
    }
});
</script>

</body>
</html>