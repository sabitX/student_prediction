<?php
require '../includes/session_check.php';
require '../config/config.php';
require '../includes/csrf.php';
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
$student       = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_student));
if (!$student) {
    require '../includes/no_student_profile.php';
    exit();
}
$student_db_id = $student['id'];
mysqli_stmt_close($stmt_student);

require '../includes/mentorship.php';

$semester_id = get_active_semester_id($conn);
require_once '../includes/flash.php';
$flash_data   = flash_take();
$book_error   = '';
$book_success = $flash_data['book_success'] ?? '';
// Result of a cancel / peer-mentor action (a booking has its own banner).
$flash        = $book_success === '' ? ($flash_data['success'] ?? '') : '';

// ── SECURE INSERT: Book a Session (fully validated server-side) ─────────
if (isset($_POST['book_session'])) {
    $mentor_id  = (int)($_POST['mentor_id'] ?? 0);
    $subject_id = (int)($_POST['subject_id'] ?? 0);
    $notes      = trim($_POST['meeting_notes'] ?? '');
    [$when, $date_error] = parse_session_datetime($_POST['session_date'] ?? '');
    $mentor = valid_mentor_for_subject($conn, $mentor_id, $subject_id, (int)$user_id);

    // Help is only for subjects this student takes this semester.
    $stmt_enr = mysqli_prepare($conn, "SELECT 1 FROM student_enrollments e JOIN subjects sub ON sub.id = e.subject_id
                                       WHERE e.student_id = ? AND e.subject_id = ? AND e.semester_id = ? AND sub.is_archived = 0");
    $enr_semester = get_active_semester_id($conn);
    mysqli_stmt_bind_param($stmt_enr, "iii", $student_db_id, $subject_id, $enr_semester);
    mysqli_stmt_execute($stmt_enr);
    $is_enrolled = mysqli_num_rows(mysqli_stmt_get_result($stmt_enr)) > 0;
    mysqli_stmt_close($stmt_enr);

    if (!csrf_verify()) {
        $book_error = "Your session expired. Please try again.";
    } elseif (!$is_enrolled) {
        $book_error = "You can only book help for subjects you're enrolled in this semester.";
    } elseif (!$mentor) {
        $book_error = "That mentor isn't available for this subject.";
    } elseif (!$when) {
        $book_error = $date_error;
    } elseif (mb_strlen($notes) > SESSION_NOTES_MAX) {
        $book_error = "Notes can be at most " . SESSION_NOTES_MAX . " characters.";
    } elseif (mentor_has_clash($conn, $mentor_id, $when)) {
        $book_error = "{$mentor['full_name']} already has a session booked near that time. Please pick another time.";
    } else {
        // One open request per mentor + subject at a time.
        $stmt_dupe = mysqli_prepare($conn, "SELECT 1 FROM mentorship_sessions
                                            WHERE mentee_id = ? AND mentor_id = ? AND subject_id = ? AND status IN ('Pending','Approved')");
        mysqli_stmt_bind_param($stmt_dupe, "iii", $student_db_id, $mentor_id, $subject_id);
        mysqli_stmt_execute($stmt_dupe);
        $has_open = mysqli_num_rows(mysqli_stmt_get_result($stmt_dupe)) > 0;
        mysqli_stmt_close($stmt_dupe);

        if ($has_open) {
            $book_error = "You already have an open request with {$mentor['full_name']} for this subject.";
        } else {
            $session_date = $when->format('Y-m-d H:i:s');
            $status       = 'Pending';
            $stmt_book = mysqli_prepare($conn, "INSERT INTO mentorship_sessions
                                                (mentee_id, mentor_id, mentor_name, subject_id, session_date, meeting_notes, status)
                                                VALUES (?, ?, ?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt_book, "iisisss", $student_db_id, $mentor_id, $mentor['full_name'], $subject_id, $session_date, $notes, $status);
            mysqli_stmt_execute($stmt_book);
            mysqli_stmt_close($stmt_book);
            $book_success = "Session request sent to {$mentor['full_name']}. You'll see the status below once they respond.";
        }
    }
}

// ── Cancel one of my own sessions ──────────────────────────────────────
if (isset($_POST['cancel_session']) && csrf_verify()) {
    $sid = (int)$_POST['sid'];
    $stmt_cancel = mysqli_prepare($conn, "UPDATE mentorship_sessions SET status = 'Cancelled'
                                          WHERE session_id = ? AND mentee_id = ? AND status IN ('Pending','Approved')");
    mysqli_stmt_bind_param($stmt_cancel, "ii", $sid, $student_db_id);
    mysqli_stmt_execute($stmt_cancel);
    $flash = mysqli_stmt_affected_rows($stmt_cancel) > 0 ? "Session cancelled." : "That session can no longer be cancelled.";
    mysqli_stmt_close($stmt_cancel);
}

// ── Peer mentor: handle requests addressed to me ───────────────────────
$is_peer_mentor = !empty($student['is_mentor']);
if ($is_peer_mentor && isset($_POST['session_action']) && csrf_verify()) {
    [, $flash] = mentor_session_action($conn, (int)$user_id, (int)$_POST['sid'], is_string($_POST['session_action']) ? $_POST['session_action'] : '', is_string($_POST['new_date'] ?? null) ? $_POST['new_date'] : '');
}

// Successful booking / cancel / peer action → redirect so refresh can't repeat it.
if ($book_success !== '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    redirect_after_success($book_success, $book_error, ['book_success' => $book_success]);
}
redirect_after_success($flash, $book_error);

// ── Subjects I can get help with: my enrolled, active subjects ─────────
$stmt_subs = mysqli_prepare($conn, "SELECT sub.* FROM student_enrollments e JOIN subjects sub ON sub.id = e.subject_id
                                    WHERE e.student_id = ? AND e.semester_id = ? AND sub.is_archived = 0
                                    ORDER BY sub.subject_name");
mysqli_stmt_bind_param($stmt_subs, "ii", $student_db_id, $semester_id);
mysqli_stmt_execute($stmt_subs);
$subjects = mysqli_fetch_all(mysqli_stmt_get_result($stmt_subs), MYSQLI_ASSOC);
mysqli_stmt_close($stmt_subs);

// ── SEARCH MENTORS (lecturers + qualified peer mentors) ────────────────
$mentors     = [];
$search_done = false;
$search_subject = isset($_GET['search_subject']) ? (int)$_GET['search_subject'] : 0;

if ($search_subject > 0) {
    $search_done = true;
    $mentors = find_mentors($conn, $search_subject, (int)$user_id);
}

// ── SECURE QUERY: My Sessions (as mentee) ─────────────────────────────
// LEFT JOIN + stored mentor_name so sessions survive a mentor's account
// being deleted.
$stmt_sess = mysqli_prepare($conn, "SELECT ms.*, sub.subject_name, sub.subject_code,
                                     COALESCE(u.full_name, ms.mentor_name, 'Former mentor') as mentor_name,
                                     u.email as mentor_email
                                     FROM mentorship_sessions ms
                                     JOIN subjects sub ON ms.subject_id = sub.id
                                     LEFT JOIN users u ON ms.mentor_id = u.id
                                     WHERE ms.mentee_id = ?
                                     ORDER BY ms.created_at DESC");
mysqli_stmt_bind_param($stmt_sess, "i", $student_db_id);
mysqli_stmt_execute($stmt_sess);
$my_sessions = mysqli_stmt_get_result($stmt_sess);

// ── Requests addressed to me as a peer mentor ─────────────────────────
$mentor_requests = [];
if ($is_peer_mentor) {
    $stmt_req = mysqli_prepare($conn, "SELECT ms.*, sub.subject_code, u.full_name AS mentee_name, s.student_id AS mentee_student_id
                                       FROM mentorship_sessions ms
                                       JOIN subjects sub ON sub.id = ms.subject_id
                                       JOIN students s ON s.id = ms.mentee_id
                                       JOIN users u ON u.id = s.user_id
                                       WHERE ms.mentor_id = ? AND ms.status IN ('Pending','Approved')
                                       ORDER BY ms.session_date");
    mysqli_stmt_bind_param($stmt_req, "i", $user_id);
    mysqli_stmt_execute($stmt_req);
    $mentor_requests = mysqli_fetch_all(mysqli_stmt_get_result($stmt_req), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt_req);
}

// ── SECURE QUERY: At Risk Subjects (this semester) ─────────────────────
$stmt_risk = mysqli_prepare($conn, "SELECT sub.subject_name, sub.subject_code, pr.predicted_status
                                    FROM predictions pr
                                    JOIN subjects sub ON pr.subject_id = sub.id
                                    WHERE pr.student_id = ? AND pr.semester_id = ?
                                    AND pr.predicted_status IN ('At Risk', 'Fail')");
mysqli_stmt_bind_param($stmt_risk, "ii", $student_db_id, $semester_id);
mysqli_stmt_execute($stmt_risk);
$at_risk_list = mysqli_fetch_all(mysqli_stmt_get_result($stmt_risk), MYSQLI_ASSOC);

// Cleanup statements
mysqli_stmt_close($stmt_sess);
mysqli_stmt_close($stmt_risk);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mentorship | SPTS Nilai University</title>
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

        /* ── Dashboard Cards & Tables ───────────────────────────────────── */
        .dashboard-card {
            background: #fff;
            border: 1px solid #E2E8F0;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 4px 24px rgba(26,58,92,0.04);
            margin-bottom: 24px;
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

        .table-custom-header th {
            background: #1A3A5C !important;
            color: #ffffff !important;
            font-weight: 600;
            font-size: 0.82rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: none;
            padding: 12px;
            vertical-align: middle;
        }

        /* ── Mentor Cards ───────────────────────────────────────────────── */
        .mentor-card {
            border-radius: 12px;
            border: 1px solid #E2E8F0;
            padding: 24px 20px;
            text-align: center;
            transition: all 0.25s ease;
            background: #F8FAFC;
        }
        .mentor-card:hover {
            border-color: #0D7377;
            box-shadow: 0 8px 30px rgba(13,115,119,0.12);
            transform: translateY(-3px);
            background: #fff;
        }
        .mentor-avatar {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            background: #1A3A5C;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.6rem;
            font-weight: 700;
            margin: 0 auto 14px;
        }

        /* ── Forms & Badges ─────────────────────────────────────────────── */
        .form-label { font-size: 0.88rem; color: #1A3A5C; }
        .form-control, .form-select {
            background: #F8FAFC;
            border: 1px solid #E2E8F0;
            font-size: 0.9rem;
        }
        .form-control:focus, .form-select:focus {
            box-shadow: 0 0 0 3px rgba(13,115,119,0.15);
            border-color: #0D7377;
            background: #fff;
        }

        .btn-primary-custom {
            background: #0D7377;
            border: 2px solid #0D7377;
            color: #fff;
            font-weight: 600;
            transition: all 0.2s;
        }
        .btn-primary-custom:hover {
            background: #0a5e62;
            border-color: #0a5e62;
            color: #fff;
        }

        .session-badge-pending   { background: #FEF3C7; color: #92400E; border: 1px solid #FDE68A; }
        .session-badge-approved  { background: #D1FAE5; color: #065F46; border: 1px solid #A7F3D0; }
        .session-badge-completed { background: #E0F2FE; color: #075985; border: 1px solid #BAE6FD; }
        .session-badge-declined  { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }
        .session-badge-cancelled { background: #F1F5F9; color: #475569; border: 1px solid #E2E8F0; }
        .session-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
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

    <!-- At Risk Alert Banners -->
    <?php foreach ($at_risk_list as $risk): ?>
    <div class="alert py-3 d-flex align-items-center gap-3 mb-3" style="background:#FFFBEB; border:1px solid #FCD34D; color:#92400E; border-radius:10px;">
        <i class="bi bi-exclamation-triangle-fill fs-4 text-warning"></i>
        <div style="font-size: 0.9rem;">
            You are currently <strong class="text-danger"><?php echo h($risk['predicted_status']); ?></strong>
            in <strong style="color:#1A3A5C;"><?php echo h($risk['subject_code']); ?> — <?php echo h($risk['subject_name']); ?></strong>.<br>
            Book a session with your lecturer or a peer mentor below to get academic support before your final exam.
        </div>
        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
    </div>
    <?php endforeach; ?>

    <?php if ($book_error || $flash): ?>
    <div class="alert py-3 mb-4 <?php echo $book_error ? 'alert-danger' : 'alert-info'; ?>" style="border-radius:10px; font-size:0.9rem;">
        <?php echo h($book_error ?: $flash); ?>
    </div>
    <?php endif; ?>

    <?php if ($book_success !== ''): ?>
    <div class="alert py-3 d-flex align-items-center gap-3 mb-4" style="background:#F0FDF4; border:1px solid #BBF7D0; color:#166534; border-radius:10px;">
        <i class="bi bi-check-circle-fill fs-5 text-success"></i>
        <div style="font-size: 0.9rem;"><?php echo h($book_success); ?></div>
        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Page Header -->
    <div class="mb-4">
        <h4 class="fw-bold mb-1" style="color:#1A3A5C;">
            <i class="bi bi-person-video2 me-2 text-primary"></i>1-on-1 Mentorship
        </h4>
        <p class="text-muted mb-0" style="font-size:0.88rem;">
            Book a private session with your lecturer, or with a peer mentor who excelled in the subject.
        </p>
    </div>

    <div class="row g-4">

        <!-- Left: Main Content -->
        <div class="col-md-8">

            <!-- Search for Lecturer -->
            <div class="dashboard-card">
                <div class="card-header-title">
                    <i class="bi bi-search text-primary"></i>Find a Mentor
                </div>

                <form method="GET" class="row g-3 align-items-end mb-4">
                    <div class="col-md-8">
                        <label class="form-label fw-semibold">Subject you need help with</label>
                        <select name="search_subject" class="form-select">
                            <option value="">-- Select a subject --</option>
                            <?php foreach ($subjects as $sub): ?>
                                <option value="<?php echo $sub['id']; ?>"
                                    <?php echo $search_subject == $sub['id'] ? 'selected' : ''; ?>>
                                    <?php echo h($sub['subject_code']); ?> — <?php echo h($sub['subject_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-primary-custom w-100 py-2 rounded-2">
                            <i class="bi bi-search me-1"></i>Search
                        </button>
                    </div>
                </form>

                <!-- Lecturer Results -->
                <?php if (!$subjects): ?>
                    <p class="text-muted small mb-0">You aren't enrolled in any active subjects this semester.</p>
                <?php endif; ?>
                <?php if ($search_done): ?>
                    <?php if (count($mentors) > 0): ?>
                    <div class="row g-3 border-top pt-4">
                        <?php foreach ($mentors as $mentor): ?>
                        <div class="col-md-4">
                            <div class="mentor-card">
                                <div class="mentor-avatar">
                                    <?php echo strtoupper(substr($mentor['full_name'], 0, 1)); ?>
                                </div>
                                <h6 class="fw-bold mb-1" style="color:#1A3A5C;"><?php echo h($mentor['full_name']); ?></h6>
                                <p class="text-muted mb-2" style="font-size:0.75rem;">
                                    <i class="bi bi-envelope me-1"></i><?php echo h($mentor['email']); ?>
                                </p>
                                <span class="badge mb-3" style="background:#E0F4F4; color:#0D7377; border:1px solid #99F6E4;">
                                    <i class="bi bi-<?php echo $mentor['mentor_type'] === 'Lecturer' ? 'person-badge' : 'mortarboard'; ?> me-1"></i><?php echo h($mentor['mentor_type']); ?>
                                </span>
                                <div>
                                    <button class="btn btn-primary-custom btn-sm w-100 rounded-2"
                                            data-bs-toggle="modal"
                                            data-bs-target="#bookModal"
                                            data-mentor-id="<?php echo $mentor['id']; ?>"
                                            data-mentor-name="<?php echo h($mentor['full_name']); ?>"
                                            data-subject-id="<?php echo $search_subject; ?>">
                                        <i class="bi bi-calendar-plus me-1"></i>Request Session
                                    </button>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-4 border-top pt-4 text-muted">
                        <i class="bi bi-person-x" style="font-size:2.5rem; opacity:0.3;"></i>
                        <p class="mt-2 mb-0" style="font-size:0.9rem;">No mentors are available for this subject yet.</p>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <?php if ($is_peer_mentor): ?>
            <!-- Requests to me as a peer mentor -->
            <div class="dashboard-card">
                <div class="card-header-title">
                    <i class="bi bi-mortarboard text-primary"></i>Requests to Me (Peer Mentor)
                </div>
                <?php if ($mentor_requests): ?>
                <div class="table-responsive">
                <table class="table align-middle mb-0" style="font-size:0.85rem;">
                    <thead class="table-custom-header"><tr><th>Student</th><th>Subject</th><th>Date & Time</th><th>Status</th><th class="text-end">Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($mentor_requests as $req): ?>
                        <tr>
                            <td><strong><?php echo h($req['mentee_name']); ?></strong><br><span class="text-muted small"><?php echo h($req['mentee_student_id']); ?></span></td>
                            <td><?php echo h($req['subject_code']); ?></td>
                            <td><?php echo date('d M Y, h:i A', strtotime($req['session_date'])); ?></td>
                            <td><?php echo h($req['status']); ?></td>
                            <td class="text-end text-nowrap">
                                <form method="POST" class="d-inline">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="sid" value="<?php echo (int)$req['session_id']; ?>">
                                    <?php if ($req['status'] === 'Pending'): ?>
                                        <button type="submit" name="session_action" value="approve" class="btn btn-sm btn-outline-success">Approve</button>
                                    <?php else: ?>
                                        <button type="submit" name="session_action" value="complete" class="btn btn-sm btn-outline-primary">Complete</button>
                                    <?php endif; ?>
                                    <button type="submit" name="session_action" value="decline" class="btn btn-sm btn-outline-danger" onclick="return confirm('Decline this session?');">Decline</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php else: ?>
                    <p class="text-muted small mb-0">No open requests.</p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- My Session History -->
            <div class="dashboard-card p-0 overflow-hidden">
                <div class="p-4 border-bottom">
                    <div class="card-header-title mb-0">
                        <i class="bi bi-clock-history text-primary"></i>My Session Requests
                    </div>
                </div>

                <?php if (mysqli_num_rows($my_sessions) > 0): ?>
                <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle" style="font-size:0.85rem;">
                    <thead class="table-custom-header">
                        <tr>
                            <th class="ps-4">Mentor</th>
                            <th>Subject</th>
                            <th>Date & Time</th>
                            <th>Notes</th>
                            <th class="text-center pe-4">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($sess = mysqli_fetch_assoc($my_sessions)):
                            $sc = strtolower($sess['status']);
                        ?>
                        <tr>
                            <td class="ps-4">
                                <strong style="color:#1A3A5C;"><?php echo h($sess['mentor_name']); ?></strong><br>
                                <span class="text-muted" style="font-size:0.75rem;"><?php echo h($sess['mentor_email'] ?? ''); ?></span>
                            </td>
                            <td style="color:#334155;">
                                <strong><?php echo h($sess['subject_code']); ?></strong><br>
                                <span class="text-muted" style="font-size:0.75rem;"><?php echo h($sess['subject_name']); ?></span>
                            </td>
                            <td style="color:#334155;">
                                <i class="bi bi-calendar2-event me-1 text-muted"></i><?php echo date('d M Y', strtotime($sess['session_date'])); ?><br>
                                <i class="bi bi-clock me-1 text-muted"></i><?php echo date('h:i A', strtotime($sess['session_date'])); ?>
                            </td>
                            <td style="max-width:180px; font-size:0.8rem; color:#64748B;">
                                <?php echo $sess['meeting_notes'] ? h($sess['meeting_notes']) : '—'; ?>
                            </td>
                            <td class="text-center pe-4">
                                <span class="session-badge session-badge-<?php echo $sc; ?>">
                                    <?php echo h($sess['status']); ?>
                                </span>
                                <?php if (in_array($sess['status'], ['Pending', 'Approved'], true)): ?>
                                    <form method="POST" class="mt-1" onsubmit="return confirm('Cancel this session?');">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="sid" value="<?php echo (int)$sess['session_id']; ?>">
                                        <button type="submit" name="cancel_session" class="btn btn-link btn-sm text-danger p-0" style="font-size:0.75rem;">Cancel</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
                </div>
                <?php else: ?>
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-calendar2-x" style="font-size:2.5rem; opacity:0.3;"></i>
                    <p class="mt-3 mb-1" style="font-size:0.95rem; font-weight:500;">No sessions requested yet.</p>
                    <small>Search for a lecturer above to book your first session.</small>
                </div>
                <?php endif; ?>
            </div>

        </div>

        <!-- Right: Profile + Info -->
        <div class="col-md-4">

            <!-- My Profile -->
            <div class="dashboard-card">
                <div class="card-header-title">
                    <i class="bi bi-person-badge text-primary"></i>My Profile
                </div>
                <div class="d-flex flex-column gap-2" style="font-size:0.88rem; color:#475569;">
                    <div class="d-flex justify-content-between border-bottom pb-2">
                        <span class="text-muted">Name</span>
                        <span class="fw-semibold" style="color:#1A3A5C; text-align:right; max-width:60%;"><?php echo h($student['full_name']); ?></span>
                    </div>
                    <div class="d-flex justify-content-between border-bottom pb-2 pt-1">
                        <span class="text-muted">Student ID</span>
                        <span class="fw-semibold" style="color:#1A3A5C;"><?php echo h($student['student_id']); ?></span>
                    </div>
                    <div class="d-flex justify-content-between border-bottom pb-2 pt-1">
                        <span class="text-muted">Programme</span>
                        <span class="fw-semibold text-end" style="color:#1A3A5C; max-width:50%;"><?php echo h($student['course']); ?></span>
                    </div>
                    <div class="d-flex justify-content-between pt-1">
                        <span class="text-muted">Year of Study</span>
                        <span class="fw-semibold" style="color:#1A3A5C;">Year <?php echo h($student['year_of_study']); ?></span>
                    </div>
                </div>
            </div>

            <!-- How It Works -->
            <div class="dashboard-card">
                <div class="card-header-title">
                    <i class="bi bi-info-circle text-primary"></i>How It Works
                </div>
                <div class="d-flex gap-3 mb-3">
                    <div class="fw-bold fs-5" style="color:#0D7377;">1.</div>
                    <div style="font-size:0.85rem; color:#475569; padding-top:4px;">Select the subject you need help with and search for available mentors.</div>
                </div>
                <div class="d-flex gap-3 mb-3">
                    <div class="fw-bold fs-5" style="color:#0D7377;">2.</div>
                    <div style="font-size:0.85rem; color:#475569; padding-top:4px;">Click "Request Session", pick a date and time, and write a brief note.</div>
                </div>
                <div class="d-flex gap-3 mb-3">
                    <div class="fw-bold fs-5" style="color:#0D7377;">3.</div>
                    <div style="font-size:0.85rem; color:#475569; padding-top:4px;">Your mentor will approve, reschedule or decline the request. You can cancel it at any time before it happens.</div>
                </div>
                <div class="d-flex gap-3">
                    <div class="fw-bold fs-5" style="color:#0D7377;">4.</div>
                    <div style="font-size:0.85rem; color:#475569; padding-top:4px;">Attend your scheduled 1-on-1 session and improve your performance.</div>
                </div>
            </div>

        </div>
    </div>
    </div>
    </main>
</div>

<!-- Book Session Modal -->
<div class="modal fade" id="bookModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:12px; border:none;">
            <div class="modal-header" style="background:#F8FAFC; border-bottom:1px solid #E2E8F0; border-radius:12px 12px 0 0;">
                <h5 class="modal-title fw-bold" style="color:#1A3A5C;">
                    <i class="bi bi-calendar-plus me-2 text-primary"></i>Request a Session
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="mentor_id" id="modal_mentor_id">
                <input type="hidden" name="subject_id" id="modal_subject_id">
                <div class="modal-body p-4">
                    <div class="alert py-2 mb-4 d-flex align-items-center gap-2" style="background:#EAF4FB; border:1px solid #BAE6FD; color:#0369A1; font-size:0.85rem;">
                        <i class="bi bi-person-badge fs-5"></i>
                        <div>Requesting session with: <strong id="modal_mentor_name"></strong></div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold" style="color:#1A3A5C;">Preferred Date & Time</label>
                        <input type="datetime-local" name="session_date" class="form-control"
                               required min="<?php echo date('Y-m-d\TH:i'); ?>" max="<?php echo date('Y-m-d\TH:i', strtotime('+' . SESSION_MAX_DAYS_AHEAD . ' days')); ?>" style="background:#F8FAFC; border-color:#E2E8F0;">
                    </div>
                    <div class="mb-2">
                        <label class="form-label fw-semibold" style="color:#1A3A5C;">What do you need help with?</label>
                        <textarea name="meeting_notes" class="form-control" rows="3"
                                  placeholder="e.g. I need help understanding SQL Joins before my final exam."
                                  maxlength="<?php echo SESSION_NOTES_MAX; ?>" style="background:#F8FAFC; border-color:#E2E8F0; resize:none;"></textarea>
                        <small class="text-muted mt-1 d-block" style="font-size:0.75rem;">Optional but recommended. Max 500 characters.</small>
                    </div>
                </div>
                <div class="modal-footer" style="background:#F8FAFC; border-top:1px solid #E2E8F0; border-radius:0 0 12px 12px;">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="book_session" class="btn btn-primary-custom px-4">
                        <i class="bi bi-send me-2"></i>Send Request
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="../assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="../assets/js/theme-toggle.js"></script>
<script src="../assets/js/app.js?v=3"></script>
<script>
document.getElementById('bookModal').addEventListener('show.bs.modal', function (e) {
    const btn = e.relatedTarget;
    document.getElementById('modal_mentor_id').value      = btn.dataset.mentorId;
    document.getElementById('modal_subject_id').value     = btn.dataset.subjectId;
    document.getElementById('modal_mentor_name').textContent = btn.dataset.mentorName;
});
</script>
</body>
</html>