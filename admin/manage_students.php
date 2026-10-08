<?php
require '../includes/session_check.php';
require '../config/config.php';
require '../includes/csrf.php';
require '../includes/grading.php';
require '../includes/semester.php';
require '../includes/academic_level.php';
require '../includes/passwords.php';

if ($_SESSION['role'] != 'admin') {
    header("Location: ../student/student_dashboard.php");
    exit();
}

$full_name  = $_SESSION['full_name'];
$first_name = explode(' ', trim($full_name))[0];
$initials   = strtoupper(substr($first_name, 0, 1) . substr(explode(' ', trim($full_name))[1] ?? '', 0, 1));
$active_semester = h(get_active_semester_name($conn));

$error   = "";
require_once '../includes/flash.php';
$flash   = flash_take();
$success = $flash['success'] ?? "";
$issued  = $flash['issued'] ?? null;   // temporary password shown to the admin once

// ── SECURE POST: Assign / Revoke mentor status / Delete ───────────────
if (isset($_POST['action']) && isset($_POST['student_id']) && csrf_verify()) {
    $id = (int)$_POST['student_id'];
    require_once '../includes/audit.php';

    // Who this action is about, for messages and the audit trail.
    $stmt_who = mysqli_prepare($conn, "SELECT u.id AS user_id, u.full_name, s.student_id FROM students s JOIN users u ON u.id = s.user_id WHERE s.id = ?");
    mysqli_stmt_bind_param($stmt_who, "i", $id);
    mysqli_stmt_execute($stmt_who);
    $who = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_who));
    mysqli_stmt_close($stmt_who);
    $who_label = $who ? "{$who['full_name']} ({$who['student_id']})" : '';

    if ($_POST['action'] === 'assign_mentor') {
        $stmt = mysqli_prepare($conn, "UPDATE students SET is_mentor = 1 WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);
        if ($who && mysqli_stmt_affected_rows($stmt) > 0) {
            audit_log($conn, 'Peer mentor assigned', "Student: $who_label");
        }
        $success = "Mentor status assigned successfully.";
        mysqli_stmt_close($stmt);
    }
    elseif ($_POST['action'] === 'revoke_mentor') {
        mysqli_begin_transaction($conn);
        $stmt = mysqli_prepare($conn, "UPDATE students SET is_mentor = 0 WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);
        $changed = mysqli_stmt_affected_rows($stmt) > 0;
        mysqli_stmt_close($stmt);
        // Their open requests can no longer be answered (the inbox is for
        // peer mentors only), so they are declined rather than left waiting.
        $declined = ($who && $changed) ? decline_open_mentor_sessions($conn, (int)$who['user_id']) : 0;
        if ($who && $changed) {
            audit_log($conn, 'Peer mentor revoked', "Student: $who_label" . ($declined ? " | $declined open session(s) declined" : ''));
        }
        mysqli_commit($conn);
        $success = "Mentor status revoked successfully." . ($declined ? " $declined open session request(s) were declined." : '');
    }
    elseif ($_POST['action'] === 'reset_password') {
        $stmt_u = mysqli_prepare($conn, "SELECT u.id, u.full_name, s.student_id FROM students s JOIN users u ON u.id = s.user_id WHERE s.id = ?");
        mysqli_stmt_bind_param($stmt_u, "i", $id);
        mysqli_stmt_execute($stmt_u);
        $target = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_u));
        mysqli_stmt_close($stmt_u);
        if ($target) {
            $temp = generate_temp_password();
            set_temporary_password($conn, $target['id'], $temp);
            audit_log($conn, 'Password reset by admin', "Student: {$target['full_name']} ({$target['student_id']})");
            $issued  = ['name' => $target['full_name'] . ' (' . $target['student_id'] . ')', 'password' => $temp];
            $success = "Temporary password issued. The student must change it at their next login.";
        }
    }
    elseif ($_POST['action'] === 'delete_student') {
        // First get the user_id
        $stmt_get = mysqli_prepare($conn, "SELECT user_id FROM students WHERE id = ?");
        mysqli_stmt_bind_param($stmt_get, "i", $id);
        mysqli_stmt_execute($stmt_get);
        $res = mysqli_stmt_get_result($stmt_get);
        if ($user = mysqli_fetch_assoc($res)) {
            // Delete from users table (cascades to the student profile,
            // enrolments, marks and their own mentorship requests). Sessions
            // where they were the peer mentor are declined first.
            mysqli_begin_transaction($conn);
            $declined = decline_open_mentor_sessions($conn, (int)$user['user_id']);
            $stmt_del = mysqli_prepare($conn, "DELETE FROM users WHERE id = ?");
            mysqli_stmt_bind_param($stmt_del, "i", $user['user_id']);
            mysqli_stmt_execute($stmt_del);
            mysqli_stmt_close($stmt_del);
            audit_log($conn, 'Student deleted', "Student: $who_label" . ($declined ? " | $declined open mentorship session(s) declined" : ''));
            mysqli_commit($conn);
            $success = "Student record deleted successfully.";
        }
        mysqli_stmt_close($stmt_get);
    }
}

// ── SECURE POST: Add New Student ──────────────────────────────────────
if (isset($_POST['add_student']) && csrf_verify()) {
    $reg_full_name = trim($_POST['full_name'] ?? '');
    $reg_email     = trim($_POST['email'] ?? '');
    $reg_password  = $_POST['password'] ?? '';
    $student_id    = normalize_student_id($_POST['student_id'] ?? '');
    $course        = trim($_POST['course'] ?? '');
    $year          = (int)($_POST['year_of_study'] ?? 0);

    if (empty($reg_full_name) || empty($reg_email) || empty($student_id) || empty($course)) {
        $error = "All fields are required.";
    } elseif (mb_strlen($reg_full_name) > 100) {
        $error = "Full name is too long (max 100 characters).";
    } elseif (!filter_var($reg_email, FILTER_VALIDATE_EMAIL) || strlen($reg_email) > 100) {
        $error = "Please enter a valid email address (max 100 characters).";
    } elseif (($policy = password_policy_error($reg_password)) !== '') {
        $error = $policy;
    } elseif (($profile_error = student_profile_error($student_id, $course, $year)) !== '') {
        $error = $profile_error;
    } else {
        // Academic level is never taken from an admin dropdown — it is
        // derived server-side from the Student ID prefix (academic_level.php),
        // so there is no way for it to conflict with the Student ID.
        $academic_level = detect_academic_level($student_id);

        $stmt_email = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ?");
        mysqli_stmt_bind_param($stmt_email, "s", $reg_email);
        mysqli_stmt_execute($stmt_email);
        $res_email = mysqli_stmt_get_result($stmt_email);

        $stmt_sid = mysqli_prepare($conn, "SELECT id FROM students WHERE student_id = ?");
        mysqli_stmt_bind_param($stmt_sid, "s", $student_id);
        mysqli_stmt_execute($stmt_sid);
        $res_sid = mysqli_stmt_get_result($stmt_sid);

        if (mysqli_num_rows($res_email) > 0) {
            $error = "An account with this email address already exists.";
        } elseif (mysqli_num_rows($res_sid) > 0) {
            $error = "This Student ID is already registered.";
        } else {
            mysqli_begin_transaction($conn);
            try {
                $hashed = password_hash($reg_password, PASSWORD_DEFAULT);
                $role   = 'student';

                // Admin-set password is temporary: changed at first login.
                $stmt_user = mysqli_prepare($conn, "INSERT INTO users (full_name, email, password, role, must_change_password) VALUES (?, ?, ?, ?, 1)");
                mysqli_stmt_bind_param($stmt_user, "ssss", $reg_full_name, $reg_email, $hashed, $role);
                if (!mysqli_stmt_execute($stmt_user)) {
                    throw new Exception("Failed to create user account.");
                }
                $new_user_id = mysqli_insert_id($conn);

                $stmt_student = mysqli_prepare($conn, "INSERT INTO students (user_id, student_id, course, academic_level, year_of_study) VALUES (?, ?, ?, ?, ?)");
                mysqli_stmt_bind_param($stmt_student, "isssi", $new_user_id, $student_id, $course, $academic_level, $year);
                if (!mysqli_stmt_execute($stmt_student)) {
                    throw new Exception("Failed to create student profile.");
                }

                require_once '../includes/audit.php';
                audit_log($conn, 'Student account created', "Student: $reg_full_name ($student_id, $reg_email)");
                mysqli_commit($conn);
                $success = "Student registered successfully as " . academic_level_label($academic_level) . ".";

                mysqli_stmt_close($stmt_user);
                mysqli_stmt_close($stmt_student);
            } catch (Exception $e) {
                mysqli_rollback($conn);
                error_log('Admin add student failed: ' . $e->getMessage());
                $error = "Registration failed. Please try again.";
            }
        }
        mysqli_stmt_close($stmt_email);
        mysqli_stmt_close($stmt_sid);
    }
}

// ── SECURE POST: Edit Student (programme / year only) ──────────────────
// Student ID is intentionally not editable here — it is the value the
// academic level is derived from, so keeping it fixed once registered
// means there is never a stale/conflicting academic_level to reconcile.
if (isset($_POST['edit_student']) && csrf_verify()) {
    $edit_id     = (int)($_POST['edit_id'] ?? 0);
    $edit_course = trim($_POST['edit_course'] ?? '');
    $edit_year   = (int)($_POST['edit_year_of_study'] ?? 0);

    // The programme must still match the level implied by the (fixed) Student ID.
    $stmt_sid = mysqli_prepare($conn, "SELECT student_id FROM students WHERE id = ?");
    mysqli_stmt_bind_param($stmt_sid, "i", $edit_id);
    mysqli_stmt_execute($stmt_sid);
    $edit_row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_sid));
    mysqli_stmt_close($stmt_sid);

    if (!$edit_row) {
        $error = "Student not found.";
    } elseif (empty($edit_course)) {
        $error = "Programme is required.";
    } elseif (!in_array($edit_course, programme_catalog()[$edit_level = detect_academic_level($edit_row['student_id'])], true)) {
        $error = "Please choose a " . academic_level_label($edit_level) . " programme for this student.";
    } elseif ($edit_year < 1 || $edit_year > 3) {
        $error = "Invalid year of study selected.";
    } else {
        $stmt_edit = mysqli_prepare($conn, "UPDATE students SET course = ?, year_of_study = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt_edit, "sii", $edit_course, $edit_year, $edit_id);
        if (mysqli_stmt_execute($stmt_edit)) {
            require_once '../includes/audit.php';
            audit_log($conn, 'Student profile edited', "Student ID: {$edit_row['student_id']} | Programme: $edit_course | Year: $edit_year");
            $success = "Student profile updated successfully.";
        } else {
            $error = "Failed to update student profile.";
        }
        mysqli_stmt_close($stmt_edit);
    }
}

redirect_after_success($success, $error, ['issued' => $issued]);

// ── SECURE QUERY: Fetch all students (figures for the active semester) ─
// Subqueries rather than two LEFT JOINs so counts/averages aren't
// multiplied by each other. $semester_id is an int from the DB.
$semester_id = get_active_semester_id($conn);
$students_query = "SELECT s.*, u.full_name, u.email,
                   (SELECT COUNT(*) FROM student_enrollments e WHERE e.student_id = s.id AND e.semester_id = $semester_id) AS subjects_entered,
                   (SELECT AVG(pr.total_score) FROM predictions pr WHERE pr.student_id = s.id AND pr.semester_id = $semester_id) AS avg_score
                   FROM students s
                   JOIN users u ON s.user_id = u.id
                   ORDER BY u.full_name";
$students = mysqli_query($conn, $students_query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Students | SPTS Nilai University</title>
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

        /* ── Dashboard Cards & Forms ────────────────────────────────────── */
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

        /* ── Action Buttons ─────────────────────────────────────────────── */
        .btn-action {
            font-size: 0.75rem;
            font-weight: 600;
            padding: 4px 12px;
            border-radius: 6px;
            letter-spacing: 0.3px;
            transition: all 0.2s;
        }

        .btn-view { background: #0D7377; color: #fff; border: 1px solid #0D7377; }
        .btn-view:hover { background: #0a5e62; color: #fff; }

        .btn-edit { background: #EFF6FF; color: #2563EB; border: 1px solid #BFDBFE; }
        .btn-edit:hover { background: #DBEAFE; color: #2563EB; }

        .btn-mentor-add { background: #ECFDF5; color: #059669; border: 1px solid #A7F3D0; }
        .btn-mentor-add:hover { background: #D1FAE5; color: #059669; }

        .btn-mentor-rev { background: #FFFBEB; color: #D97706; border: 1px solid #FDE68A; }
        .btn-mentor-rev:hover { background: #FEF3C7; color: #D97706; }

        .btn-delete { background: #FEF2F2; color: #DC2626; border: 1px solid #FECACA; }
        .btn-delete:hover { background: #FEE2E2; color: #DC2626; }

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

    <?php if ($success): ?>
        <div class="alert py-2 mb-4" style="background:#F0FDF4; border:1px solid #BBF7D0; color:#166534; font-size:0.88rem; border-radius:8px;">
            <i class="bi bi-check-circle-fill me-2 text-success"></i><?php echo h($success); ?>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert py-2 mb-4" style="background:#FEF2F2; border:1px solid #FECACA; color:#991B1B; font-size:0.88rem; border-radius:8px;">
            <i class="bi bi-exclamation-circle-fill me-2 text-danger"></i><?php echo h($error); ?>
        </div>
    <?php endif; ?>
    <?php if ($issued): ?>
        <div class="alert alert-warning mb-4" style="border-radius:10px;">
            <strong>Temporary password for <?php echo h($issued['name']); ?>:</strong>
            <code class="fs-5 ms-2 user-select-all"><?php echo h($issued['password']); ?></code>
            <div class="small mt-1">Shown only once. Give it to the student directly after checking their identity.</div>
        </div>
    <?php endif; ?>

    <div class="row g-4">

    <!-- Add Student Form -->
    <div class="col-md-4">
        <div class="dashboard-card">
            <div class="card-header-title" style="color: #0D7377;">
                <i class="bi bi-person-plus me-1"></i>Register New Student
            </div>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <div class="mb-3">
                    <label class="form-label">Full Name</label>
                    <input type="text" name="full_name" class="form-control" placeholder="Student's full name" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Email Address</label>
                    <input type="email" name="email" class="form-control" placeholder="student@nilai.edu.my" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Temporary Password</label>
                    <input type="password" name="password" class="form-control" placeholder="Temporary password" required minlength="<?php echo PASSWORD_MIN_LENGTH; ?>" autocomplete="new-password">
                    <div class="form-text" style="font-size:0.72rem;"><?php echo h(PASSWORD_RULE_TEXT); ?> They change it at first login.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Student ID</label>
                    <input type="text" name="student_id" id="addStudentIdField" class="form-control" placeholder="e.g. n00021960" required>
                    <small class="text-muted d-block mt-1" style="font-size:0.75rem;">
                        Academic level is auto-detected — no need to select it.
                        <span id="addLevelPreview" class="fw-semibold" style="color:#0D7377;"></span>
                    </small>
                </div>
                <div class="mb-3">
                    <label class="form-label">Programme</label>
                    <select name="course" class="form-select" required>
                        <option value="">-- Select programme --</option>
                        <?php render_programme_options(); ?>
                    </select>
                </div>
                <div class="mb-4">
                    <label class="form-label">Year of Study</label>
                    <select name="year_of_study" class="form-select" required>
                        <option value="">-- Select year --</option>
                        <option value="1">Year 1</option>
                        <option value="2">Year 2</option>
                        <option value="3">Year 3</option>
                    </select>
                </div>
                <button type="submit" name="add_student" class="btn btn-primary-custom w-100 py-2 rounded-2">
                    <i class="bi bi-person-plus me-1"></i>Register Student
                </button>
            </form>
        </div>
    </div>

    <!-- Students Table -->
    <div class="col-md-8">
    <div class="dashboard-card p-0 overflow-hidden">
        <div class="p-4 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div class="card-header-title mb-0">
                <i class="bi bi-person-lines-fill text-primary"></i>All Registered Students
            </div>
            <div style="width: 280px;">
                <div class="input-group">
                    <span class="input-group-text bg-light border-0" style="color:#94A3B8;"><i class="bi bi-search"></i></span>
                    <input type="text" id="studentSearch" class="form-control bg-light border-0" placeholder="Search by name, ID, or email..." style="font-size:0.88rem; box-shadow:none;">
                </div>
            </div>
        </div>

        <div class="table-responsive">
        <table class="table table-hover mb-0" id="studentTable">
            <thead class="table-custom-header">
                <tr>
                    <th class="ps-4">#</th>
                    <th>Student ID</th>
                    <th>Full Name / Email</th>
                    <th>Course / Level</th>
                    <th>Year</th>
                    <th class="text-center">Subjects</th>
                    <th>Avg Score</th>
                    <th class="text-center">Role</th>
                    <th class="text-center pe-4" style="min-width: 260px;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php $i = 1; while ($row = mysqli_fetch_assoc($students)): ?>
                <tr>
                    <td class="ps-4 text-muted" style="font-size:0.8rem;"><?php echo $i++; ?></td>
                    <td class="fw-bold" style="color:#1A3A5C; font-size:0.85rem;"><?php echo h($row['student_id']); ?></td>
                    <td>
                        <strong class="text-dark d-block"><?php echo h($row['full_name']); ?></strong>
                        <span class="text-muted" style="font-size:0.75rem;"><?php echo h($row['email']); ?></span>
                    </td>
                    <td>
                        <span class="text-dark d-block" style="font-size:0.82rem; font-weight: 500;"><?php echo h($row['course']); ?></span>
                        <span class="text-muted" style="font-size:0.75rem;"><?php echo h(academic_level_label($row['academic_level'])); ?></span>
                    </td>
                    <td><span class="badge" style="background:#F1F5F9; color:#475569;">Year <?php echo $row['year_of_study']; ?></span></td>
                    <td class="text-center fw-medium text-muted"><?php echo $row['subjects_entered']; ?></td>
                    <td>
                        <?php
                        $avg = $row['avg_score'];
                        $badge = status_badge_class(predicted_status($avg));
                        $badge_text = ($badge === 'warning') ? 'text-dark' : 'text-white';

                        echo $avg !== null
                            ? '<span class="badge bg-'.$badge.' '.$badge_text.' px-2 py-1" style="font-weight:600;">'.number_format($avg,2).'%</span>'
                            : '<span class="text-muted" style="font-size:0.8rem;">Pending</span>';
                        ?>
                    </td>
                    <td class="text-center">
                        <?php if ($row['is_mentor']): ?>
                            <span class="badge" style="background:#ECFDF5; color:#059669; border:1px solid #A7F3D0;">
                                <i class="bi bi-star-fill me-1"></i>Mentor
                            </span>
                        <?php else: ?>
                            <span class="text-muted" style="font-size:0.82rem; opacity:0.5;">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center pe-4">
                        <div class="d-flex gap-2 justify-content-center">
                            <!-- View Button -->
                            <a href="view_student.php?id=<?php echo $row['id']; ?>" class="btn btn-action btn-view">
                                <i class="bi bi-eye me-1"></i>View
                            </a>

                            <!-- Edit Button (opens modal) -->
                            <button type="button" class="btn btn-action btn-edit"
                                    data-bs-toggle="modal" data-bs-target="#editModal"
                                    data-id="<?php echo $row['id']; ?>"
                                    data-name="<?php echo h($row['full_name']); ?>"
                                    data-student-id="<?php echo h($row['student_id']); ?>"
                                    data-course="<?php echo h($row['course']); ?>"
                                    data-year="<?php echo (int)$row['year_of_study']; ?>">
                                <i class="bi bi-pencil-square me-1"></i>Edit
                            </button>

                            <!-- Mentor Action (Secure POST) -->
                            <form method="POST" class="d-inline m-0">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="student_id" value="<?php echo $row['id']; ?>">
                                <?php if ($row['is_mentor']): ?>
                                    <input type="hidden" name="action" value="revoke_mentor">
                                    <button type="submit" class="btn btn-action btn-mentor-rev" title="Peer mentors can mentor subjects they scored Excellent in" onclick="return confirm('Remove peer mentor status from this student?');">
                                        <i class="bi bi-star-half me-1"></i>Revoke
                                    </button>
                                <?php else: ?>
                                    <input type="hidden" name="action" value="assign_mentor">
                                    <button type="submit" class="btn btn-action btn-mentor-add" title="Peer mentors can mentor subjects they scored Excellent in" onclick="return confirm('Make this student a peer mentor? They will be offered as a mentor in subjects where they scored Excellent.');">
                                        <i class="bi bi-star me-1"></i>Mentor
                                    </button>
                                <?php endif; ?>
                            </form>

                            <!-- Reset Password (issues a one-time temporary password) -->
                            <form method="POST" class="d-inline m-0">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="student_id" value="<?php echo $row['id']; ?>">
                                <input type="hidden" name="action" value="reset_password">
                                <button type="submit" class="btn btn-action btn-edit" onclick="return confirm('Issue a new temporary password for this student? Their current password will stop working.');">
                                    <i class="bi bi-key me-1"></i>Reset
                                </button>
                            </form>

                            <!-- Delete Action (Secure POST) -->
                            <form method="POST" class="d-inline m-0">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="student_id" value="<?php echo $row['id']; ?>">
                                <input type="hidden" name="action" value="delete_student">
                                <button type="submit" class="btn btn-action btn-delete" onclick="return confirm('⚠️ Delete this student and all their records? This cannot be undone.');">
                                    <i class="bi bi-trash me-1"></i>Delete
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
        </div>
    </div>
    </div>

    </div>
    </div>
    </main>
</div>

<!-- Edit Student Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:12px; border:none;">
            <div class="modal-header" style="background:#F8FAFC; border-bottom:1px solid #E2E8F0; border-radius:12px 12px 0 0;">
                <h5 class="modal-title fw-bold" style="color:#1A3A5C;">
                    <i class="bi bi-pencil-square me-2" style="color:#0D7377;"></i>Edit Student Profile
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <div class="modal-body p-4">
                    <input type="hidden" name="edit_id" id="modal_edit_id">
                    <div class="alert py-2 mb-4 d-flex align-items-center gap-2" style="background:#EAF7F8; border:1px solid #BAE6FD; color:#0369A1; font-size:0.85rem;">
                        <i class="bi bi-person-badge fs-5"></i>
                        <div>Editing: <strong id="modal_edit_name"></strong> (<span id="modal_edit_sid"></span>)</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" style="color:#1A3A5C; font-weight:600; font-size:0.88rem;">Programme</label>
                        <select name="edit_course" id="modal_edit_course" class="form-select" required style="background:#F8FAFC; border-color:#E2E8F0;">
                            <?php render_programme_options(); ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label" style="color:#1A3A5C; font-weight:600; font-size:0.88rem;">Year of Study</label>
                        <select name="edit_year_of_study" id="modal_edit_year" class="form-select" required style="background:#F8FAFC; border-color:#E2E8F0;">
                            <option value="1">Year 1</option>
                            <option value="2">Year 2</option>
                            <option value="3">Year 3</option>
                        </select>
                    </div>
                    <small class="text-muted d-block mt-2" style="font-size:0.75rem;">
                        <i class="bi bi-info-circle me-1"></i>Student ID and academic level are fixed once registered and cannot be edited here.
                    </small>
                </div>
                <div class="modal-footer" style="background:#F8FAFC; border-top:1px solid #E2E8F0; border-radius:0 0 12px 12px;">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="edit_student" class="btn btn-primary-custom" style="font-weight:600;">
                        <i class="bi bi-check-lg me-1"></i>Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('studentSearch').addEventListener('input', function () {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#studentTable tbody tr').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
});

document.getElementById('editModal').addEventListener('show.bs.modal', function (e) {
    const btn = e.relatedTarget;
    document.getElementById('modal_edit_id').value   = btn.dataset.id;
    document.getElementById('modal_edit_name').textContent = btn.dataset.name;
    document.getElementById('modal_edit_sid').textContent  = btn.dataset.studentId;
    document.getElementById('modal_edit_course').value = btn.dataset.course;
    document.getElementById('modal_edit_year').value   = btn.dataset.year;
});

// Cosmetic-only preview of the detected academic level while adding a
// student — the server (academic_level.php) always makes the real decision.
const addStudentIdField = document.getElementById('addStudentIdField');
if (addStudentIdField) {
    addStudentIdField.addEventListener('input', function () {
        const preview = document.getElementById('addLevelPreview');
        const val = this.value.trim().toLowerCase();
        let level = '';
        if (val.startsWith('n0002')) level = 'Diploma';
        else if (val.startsWith('n0003')) level = "Bachelor's Degree";
        else if (val.startsWith('n0004')) level = "Master's";
        else if (val.length > 0) level = 'PhD';
        preview.textContent = level ? ('Detected level: ' + level) : '';
    });
}
</script>
<script src="../assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="../assets/js/theme-toggle.js"></script>
<script src="../assets/js/app.js"></script>
</body>
</html>