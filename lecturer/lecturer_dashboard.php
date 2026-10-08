<?php
require '../includes/session_check.php';
require '../config/config.php';
require '../includes/csrf.php';
require '../includes/semester.php';

if (!isset($_SESSION['user_id']) or $_SESSION['role'] != 'lecturer') {
    header("Location: ../auth/login.php");
    exit();
}

$user_id    = $_SESSION['user_id'];
$full_name  = $_SESSION['full_name'];
$first_name = explode(' ', trim($full_name))[0];
$initials   = strtoupper(substr($first_name, 0, 1) . substr(explode(' ', trim($full_name))[1] ?? '', 0, 1));
$active_semester = h(get_active_semester_name($conn));

require '../includes/mentorship.php';

$semester_id = get_active_semester_id($conn);

// ── SECURE POST: MENTORSHIP SESSION ACTIONS (CSRF-token protected) ─────────
// Rules (valid status transitions, clash check, future dates) live in
// includes/mentorship.php and only touch this lecturer's own sessions.
if (isset($_POST['session_action']) && isset($_POST['sid']) && csrf_verify()) {
    [$ok, $msg] = mentor_session_action($conn, (int)$user_id, (int)$_POST['sid'], is_string($_POST['session_action']) ? $_POST['session_action'] : '', is_string($_POST['new_date'] ?? null) ? $_POST['new_date'] : '');
    $_SESSION['dashboard_flash'] = ['ok' => $ok, 'msg' => $msg];
    header("Location: lecturer_dashboard.php");
    exit();
}
$flash = $_SESSION['dashboard_flash'] ?? null;
unset($_SESSION['dashboard_flash']);

// ── Stats scoped to THIS lecturer's active subjects in the active semester
// — the same enrolled roster Enter Marks shows, so the two pages agree.
$stmt_stats = mysqli_prepare($conn,
    "SELECT COUNT(DISTINCT e.student_id) AS students,
            SUM(pr.predicted_status = 'Excellent') AS excellent,
            SUM(pr.predicted_status = 'Pass')      AS pass,
            SUM(pr.predicted_status = 'At Risk')   AS at_risk,
            SUM(pr.predicted_status = 'Fail')      AS fail
     FROM lecturer_subjects ls
     JOIN subjects sub ON sub.id = ls.subject_id AND sub.is_archived = 0
     JOIN student_enrollments e ON e.subject_id = ls.subject_id AND e.semester_id = ?
     LEFT JOIN predictions pr ON pr.student_id = e.student_id AND pr.subject_id = e.subject_id AND pr.semester_id = e.semester_id
     WHERE ls.user_id = ?");
mysqli_stmt_bind_param($stmt_stats, "ii", $semester_id, $user_id);
mysqli_stmt_execute($stmt_stats);
$st = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_stats));
mysqli_stmt_close($stmt_stats);

$total_students = (int)$st['students'];
$excellent      = (int)$st['excellent'];
$pass           = (int)$st['pass'];
$at_risk        = (int)$st['at_risk'];
$fail           = (int)$st['fail'];

// ── SECURE QUERY: MENTORSHIP DATA ───────────────────────────────────────────
$stmt_pending = mysqli_prepare($conn,
    "SELECT ms.*, sub.subject_name, sub.subject_code,
             u.full_name as mentee_name, s.student_id as mentee_student_id
     FROM mentorship_sessions ms
     JOIN subjects sub ON ms.subject_id = sub.id
     JOIN students s ON ms.mentee_id = s.id
     JOIN users u ON s.user_id = u.id
     WHERE ms.mentor_id = ? AND ms.status = 'Pending'
     ORDER BY ms.created_at DESC");
mysqli_stmt_bind_param($stmt_pending, "i", $user_id);
mysqli_stmt_execute($stmt_pending);
$pending_sessions = mysqli_stmt_get_result($stmt_pending);
$pending_count = mysqli_num_rows($pending_sessions);
mysqli_stmt_close($stmt_pending);

$stmt_appr = mysqli_prepare($conn,
    "SELECT ms.*, sub.subject_name, sub.subject_code,
             u.full_name as mentee_name, s.student_id as mentee_student_id
     FROM mentorship_sessions ms
     JOIN subjects sub ON ms.subject_id = sub.id
     JOIN students s ON ms.mentee_id = s.id
     JOIN users u ON s.user_id = u.id
     WHERE ms.mentor_id = ? AND ms.status = 'Approved'
     ORDER BY ms.session_date ASC");
mysqli_stmt_bind_param($stmt_appr, "i", $user_id);
mysqli_stmt_execute($stmt_appr);
$approved_sessions = mysqli_stmt_get_result($stmt_appr);
mysqli_stmt_close($stmt_appr);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lecturer Dashboard | SPTS Nilai University</title>
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
        *, *::before, *::after { box-sizing: border-box; }

        body {
            font-family: 'Inter', system-ui, sans-serif;
            background: #F4F7F9;
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

        /* Page Header */
        .page-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px; }
        .page-header h2 { font-weight: 700; color: #1A3A5C; margin: 0 0 4px; font-size: 1.5rem; }
        .page-header p { color: #64748B; margin: 0; font-size: 0.9rem; }

        .badge-semester { background: #0D7377; color: white; padding: 8px 16px; border-radius: 6px; font-weight: 600; font-size: 0.85rem; display: flex; align-items: center; gap: 8px; }

        /* Stat Cards */
        .stat-card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 4px 20px rgba(26,58,92,0.04); display: flex; flex-direction: column; justify-content: center; height: 100%; position: relative; overflow: hidden; }
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

        /* Dashboard Cards */
        .dashboard-card { background: white; border-radius: 16px; padding: 28px; box-shadow: 0 8px 32px rgba(26,58,92,0.05); margin-bottom: 24px; }
        .card-header-title { font-size: 1.05rem; font-weight: 700; color: #1A3A5C; margin-bottom: 20px; display: flex; align-items: center; gap: 8px; }

        /* Action Buttons */
        .btn-action { font-size: 0.75rem; font-weight: 600; padding: 4px 12px; border-radius: 6px; border: none; transition: all 0.2s; }
        .btn-approve { background: #ECFDF5; color: #059669; border: 1px solid #A7F3D0; }
        .btn-approve:hover { background: #D1FAE5; }
        .btn-complete { background: #E0F2FE; color: #0369A1; border: 1px solid #BAE6FD; }
        .btn-complete:hover { background: #BAE6FD; }
        .btn-decline { background: #FEF2F2; color: #DC2626; border: 1px solid #FECACA; }
        .btn-decline:hover { background: #FEE2E2; }

        .empty-state { text-align: center; padding: 30px; color: #64748B; }
        .empty-state i { font-size: 2rem; display: block; margin-bottom: 8px; opacity: 0.4; }
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

        <?php if ($flash): ?>
            <div class="alert <?php echo $flash['ok'] ? 'alert-success' : 'alert-danger'; ?> py-2 mb-4" style="font-size:0.88rem; border-radius:8px;">
                <?php echo h($flash['msg']); ?>
            </div>
        <?php endif; ?>

        <div class="page-header">
            <div>
                <h2>Lecturer Overview</h2>
                <p>Welcome back, <?php echo h($full_name); ?>. Monitor student standing and session requests.</p>
            </div>
            <div class="badge-semester">
                <i class="bi bi-calendar3"></i> <?php echo $active_semester; ?>
            </div>
        </div>

        <!-- Stat Cards -->
        <div class="row g-4 mb-4">
            <div class="col-md-3">
                <div class="stat-card border-blue">
                    <h3 class="text-blue"><?php echo $total_students; ?></h3>
                    <p>Total Students</p>
                    <small>enrolled this semester</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card border-green">
                    <h3 class="text-green"><?php echo $excellent + $pass; ?></h3>
                    <p>Passing Students</p>
                    <small>on track</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card border-yellow">
                    <h3 class="text-yellow"><?php echo $at_risk; ?></h3>
                    <p>At Risk</p>
                    <small>needs attention</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card border-red">
                    <h3 class="text-red"><?php echo $fail; ?></h3>
                    <p>Failing</p>
                    <small>critical standing</small>
                </div>
            </div>
        </div>

        <!-- Mentorship Session Requests -->
        <div class="dashboard-card">
            <div class="card-header-title">
                <i class="bi bi-calendar-check text-primary"></i>Mentorship Sessions
                <?php if ($pending_count > 0): ?>
                    <span class="badge bg-warning text-dark ms-2" style="font-size:0.75rem;"><?php echo $pending_count; ?> Pending</span>
                <?php endif; ?>
            </div>

            <h6 class="fw-bold mb-3" style="font-size:0.82rem; color:#64748B; text-transform:uppercase; letter-spacing:0.5px;">Requires Action</h6>
            <?php if ($pending_count > 0): ?>
            <div class="table-responsive mb-4">
            <table class="table table-hover align-middle mb-0" style="font-size:0.88rem;">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Student</th>
                        <th>Subject</th>
                        <th>Requested Date & Time</th>
                        <th>Notes</th>
                        <th class="text-center pe-3">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($req = mysqli_fetch_assoc($pending_sessions)): ?>
                    <tr>
                        <td class="ps-3 border-start border-warning border-3">
                            <strong style="color:#1A3A5C;"><?php echo h($req['mentee_name']); ?></strong><br>
                            <span class="text-muted" style="font-size:0.75rem;"><?php echo h($req['mentee_student_id']); ?></span>
                        </td>
                        <td><strong><?php echo h($req['subject_code']); ?></strong><br><span class="text-muted" style="font-size:0.75rem;"><?php echo h($req['subject_name']); ?></span></td>
                        <td><i class="bi bi-clock me-1 text-muted"></i><?php echo date('d M Y, h:i A', strtotime($req['session_date'])); ?></td>
                        <td style="max-width:200px; font-size:0.8rem; color:#64748B;">
                            <?php echo $req['meeting_notes'] ? h($req['meeting_notes']) : '—'; ?>
                        </td>
                        <td class="text-center pe-3">
                            <form method="POST" class="d-inline">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="sid" value="<?php echo $req['session_id']; ?>">
                                <input type="hidden" name="session_action" value="approve">
                                <button type="submit" class="btn btn-action btn-approve me-1"><i class="bi bi-check-lg me-1"></i>Approve</button>
                            </form>
                            <button type="button" class="btn btn-action btn-complete me-1" data-bs-toggle="modal" data-bs-target="#rescheduleModal"
                                    data-sid="<?php echo (int)$req['session_id']; ?>" data-student="<?php echo h($req['mentee_name']); ?>">
                                <i class="bi bi-calendar-range me-1"></i>Reschedule
                            </button>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Decline this session request?');">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="sid" value="<?php echo $req['session_id']; ?>">
                                <input type="hidden" name="session_action" value="decline">
                                <button type="submit" class="btn btn-action btn-decline"><i class="bi bi-x-lg me-1"></i>Decline</button>
                            </form>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
            </div>
            <?php else: ?>
            <div class="empty-state mb-4">
                <i class="bi bi-calendar2-check"></i>
                <p class="mb-0">No pending session requests.</p>
            </div>
            <?php endif; ?>

            <h6 class="fw-bold mb-3" style="font-size:0.82rem; color:#64748B; text-transform:uppercase; letter-spacing:0.5px;">Upcoming Approved Sessions</h6>
            <?php if (mysqli_num_rows($approved_sessions) > 0): ?>
            <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size:0.88rem;">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Student</th>
                        <th>Subject</th>
                        <th>Date & Time</th>
                        <th>Notes</th>
                        <th class="text-center pe-3">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($sess = mysqli_fetch_assoc($approved_sessions)): ?>
                    <tr>
                        <td class="ps-3 border-start border-success border-3">
                            <strong style="color:#1A3A5C;"><?php echo h($sess['mentee_name']); ?></strong><br>
                            <span class="text-muted" style="font-size:0.75rem;"><?php echo h($sess['mentee_student_id']); ?></span>
                        </td>
                        <td><strong><?php echo h($sess['subject_code']); ?></strong><br><span class="text-muted" style="font-size:0.75rem;"><?php echo h($sess['subject_name']); ?></span></td>
                        <td><i class="bi bi-calendar2-event me-1 text-muted"></i><?php echo date('d M Y, h:i A', strtotime($sess['session_date'])); ?></td>
                        <td style="max-width:200px; font-size:0.8rem; color:#64748B;">
                            <?php echo $sess['meeting_notes'] ? h($sess['meeting_notes']) : '—'; ?>
                        </td>
                        <td class="text-center pe-3 text-nowrap">
                            <button type="button" class="btn btn-action btn-complete me-1" data-bs-toggle="modal" data-bs-target="#rescheduleModal"
                                    data-sid="<?php echo (int)$sess['session_id']; ?>" data-student="<?php echo h($sess['mentee_name']); ?>">
                                <i class="bi bi-calendar-range me-1"></i>Reschedule
                            </button>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Cancel this approved session? The student will see it as declined.');">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="sid" value="<?php echo $sess['session_id']; ?>">
                                <input type="hidden" name="session_action" value="decline">
                                <button type="submit" class="btn btn-action btn-decline me-1"><i class="bi bi-x-lg me-1"></i>Cancel</button>
                            </form>
                            <?php if (strtotime($sess['session_date']) <= time()): ?>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Mark this session as completed?');">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="sid" value="<?php echo $sess['session_id']; ?>">
                                <input type="hidden" name="session_action" value="complete">
                                <button type="submit" class="btn btn-action btn-complete"><i class="bi bi-check2-all me-1"></i>Complete</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <i class="bi bi-calendar"></i>
                <p class="mb-0">No upcoming approved sessions.</p>
            </div>
            <?php endif; ?>
        </div>

    </main>
</div>

<!-- Reschedule Modal -->
<div class="modal fade" id="rescheduleModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:12px; border:none;">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" style="color:#1A3A5C;"><i class="bi bi-calendar-range me-2"></i>Reschedule Session</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="sid" id="reschedule_sid">
                <input type="hidden" name="session_action" value="reschedule">
                <div class="modal-body">
                    <p class="small text-muted">New time for <strong id="reschedule_student"></strong>. The session will be approved at this time.</p>
                    <label class="form-label fw-semibold" for="reschedule_date">New Date & Time</label>
                    <input type="datetime-local" name="new_date" id="reschedule_date" class="form-control" required min="<?php echo date('Y-m-d\TH:i'); ?>">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save New Time</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="../assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="../assets/js/theme-toggle.js"></script>
<script src="../assets/js/app.js?v=3"></script>
<?php require '../includes/onboarding.php'; ?>
<script>
document.getElementById('rescheduleModal').addEventListener('show.bs.modal', function (e) {
    document.getElementById('reschedule_sid').value = e.relatedTarget.dataset.sid;
    document.getElementById('reschedule_student').textContent = e.relatedTarget.dataset.student;
});
</script>
</body>
</html>