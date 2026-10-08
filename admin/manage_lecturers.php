<?php
require '../includes/session_check.php';
require '../config/config.php';
require '../includes/csrf.php';
require '../includes/semester.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: ../auth/login.php");
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

require '../includes/passwords.php';
require '../includes/audit.php';

// ── SECURE INSERT: Add staff account (lecturer or administrator) ───────
if (isset($_POST['add_lecturer'])) {
    if (!csrf_verify()) {
        $error = "Your session expired or the request could not be verified. Please try again.";
    } else {
        // Separate names so the signed-in admin's own $full_name (topbar) is untouched.
        $new_name     = trim($_POST['full_name'] ?? '');
        $new_email    = trim($_POST['email'] ?? '');
        $password_raw = $_POST['password'] ?? '';
        $new_role     = (($_POST['account_role'] ?? '') === 'admin') ? 'admin' : 'lecturer';

        if ($new_name === '' || $new_email === '') {
            $error = "Full name and email are required.";
        } elseif (mb_strlen($new_name) > 100) {
            $error = "Full name is too long (max 100 characters).";
        } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL) || strlen($new_email) > 100) {
            $error = "Please enter a valid email address (max 100 characters).";
        } elseif (($policy = password_policy_error($password_raw)) !== '') {
            $error = $policy;
        } else {
            $stmt_check = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ?");
            mysqli_stmt_bind_param($stmt_check, "s", $new_email);
            mysqli_stmt_execute($stmt_check);
            $exists = mysqli_num_rows(mysqli_stmt_get_result($stmt_check)) > 0;
            mysqli_stmt_close($stmt_check);

            if ($exists) {
                $error = "Email already registered.";
            } else {
                // The starting password is temporary: it must be changed at first login.
                $password = password_hash($password_raw, PASSWORD_DEFAULT);
                $stmt_insert = mysqli_prepare($conn, "INSERT INTO users (full_name, email, password, role, must_change_password) VALUES (?, ?, ?, ?, 1)");
                mysqli_stmt_bind_param($stmt_insert, "ssss", $new_name, $new_email, $password, $new_role);
                mysqli_stmt_execute($stmt_insert);
                mysqli_stmt_close($stmt_insert);
                audit_log($conn, 'Staff account created', ucfirst($new_role) . ": $new_name ($new_email)");
                $success = ($new_role === 'admin' ? "Administrator" : "Lecturer") . " account created. They must change the password at first login.";
            }
        }
    }
}

// ── SECURE UPDATE: Reset a lecturer's password (generated, temporary) ──
if (isset($_POST['reset_password'])) {
    if (!csrf_verify()) {
        $error = "Your session expired or the request could not be verified. Please try again.";
    } else {
        $lec_id = (int)$_POST['lec_id'];
        $stmt_role = mysqli_prepare($conn, "SELECT full_name FROM users WHERE id = ? AND role = 'lecturer'");
        mysqli_stmt_bind_param($stmt_role, "i", $lec_id);
        mysqli_stmt_execute($stmt_role);
        $lecturer = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_role));
        mysqli_stmt_close($stmt_role);

        $temp = generate_temp_password();
        if ($lecturer && set_temporary_password($conn, $lec_id, $temp)) {
            audit_log($conn, 'Password reset by admin', "Lecturer: {$lecturer['full_name']}");
            $issued  = ['name' => $lecturer['full_name'], 'password' => $temp];
            $success = "Password reset. The lecturer must change it at their next login.";
        } else {
            $error = "Password could not be reset.";
        }
    }
}

// ── SECURE DELETE: POST + CSRF-token protected ──────────────────────────
// History is preserved: audit_logs.user_id and mentorship_sessions.mentor_id
// are ON DELETE SET NULL and keep the lecturer's name in actor_name /
// mentor_name. Sessions that hadn't happened yet are declined so students
// aren't left waiting on someone who has left.
if (isset($_POST['delete_lecturer'])) {
    if (!csrf_verify()) {
        $error = "Your session expired or the request could not be verified. Please try again.";
    } else {
        $id = (int)$_POST['delete_id'];

        $stmt_who = mysqli_prepare($conn, "SELECT full_name, email FROM users WHERE id = ? AND role = 'lecturer'");
        mysqli_stmt_bind_param($stmt_who, "i", $id);
        mysqli_stmt_execute($stmt_who);
        $who = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_who));
        mysqli_stmt_close($stmt_who);

        if ($who) {
            mysqli_begin_transaction($conn);
            $declined = decline_open_mentor_sessions($conn, $id);
            $stmt_del = mysqli_prepare($conn, "DELETE FROM users WHERE id = ? AND role = 'lecturer'");
            mysqli_stmt_bind_param($stmt_del, "i", $id);
            mysqli_stmt_execute($stmt_del);
            mysqli_stmt_close($stmt_del);
            audit_log($conn, 'Staff account deleted', "Lecturer: {$who['full_name']} ({$who['email']})" . ($declined ? " | $declined open mentorship session(s) declined" : ''));
            mysqli_commit($conn);
        }

        header("Location: manage_lecturers.php?deleted=1");
        exit();
    }
}

if (isset($_GET['deleted'])) $success = "Lecturer deleted. Their audit history and past mentorship sessions have been kept.";

redirect_after_success($success, $error, ['issued' => $issued]);

// ── SECURE QUERY: Fetch staff (lecturers and administrators) ───────────
$lecturers_query = "SELECT u.*, GROUP_CONCAT(s.subject_name SEPARATOR ', ') as subjects
                    FROM users u
                    LEFT JOIN lecturer_subjects ls ON ls.user_id = u.id
                    LEFT JOIN subjects s ON s.id = ls.subject_id
                    WHERE u.role IN ('lecturer', 'admin')
                    GROUP BY u.id
                    ORDER BY u.role DESC, u.full_name";
$lecturers = mysqli_query($conn, $lecturers_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Lecturers | SPTS Nilai University</title>
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

        /* ── Dashboard Cards & Forms (Softened Edges) ───────────────────── */
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

        .form-label { font-size: 0.88rem; color: #1A3A5C; font-weight: 600; }

        .form-control, .form-select {
            background: #F8FAFC;
            border: 1px solid #E2E8F0;
            font-size: 0.9rem;
            border-radius: 8px;
        }

        .form-control:focus, .form-select:focus {
            box-shadow: 0 0 0 3px rgba(13,115,119,0.15);
            border-color: #0D7377;
            background: #fff;
        }

        /* ── Action Buttons ─────────────────────────────────────────────── */
        .btn-primary-custom {
            background: #0D7377;
            border: 2px solid #0D7377;
            color: #fff;
            font-weight: 600;
            transition: all 0.2s;
        }
        .btn-primary-custom:hover { background: #0a5e62; border-color: #0a5e62; color: #fff; }

        .btn-action {
            font-size: 0.75rem;
            font-weight: 600;
            padding: 4px 12px;
            border-radius: 6px;
            letter-spacing: 0.3px;
            transition: all 0.2s;
        }

        .btn-reset { background: #FFFBEB; color: #D97706; border: 1px solid #FDE68A; }
        .btn-reset:hover { background: #FEF3C7; color: #D97706; }

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
    <div class="row g-4">

        <!-- Add Lecturer Form -->
        <div class="col-md-4">
            <div class="dashboard-card">
                <div class="card-header-title" style="color: #0D7377;">
                    <i class="bi bi-person-plus me-1"></i>Add Staff Account
                </div>

                <?php if ($success): ?>
                    <div class="alert py-2" style="background:#F0FDF4; border:1px solid #BBF7D0; color:#166534; font-size:0.88rem; border-radius:8px;">
                        <i class="bi bi-check-circle-fill me-2 text-success"></i><?php echo h($success); ?>
                    </div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="alert py-2" style="background:#FEF2F2; border:1px solid #FECACA; color:#991B1B; font-size:0.88rem; border-radius:8px;">
                        <i class="bi bi-exclamation-circle-fill me-2 text-danger"></i><?php echo h($error); ?>
                    </div>
                <?php endif; ?>
                <?php if ($issued): ?>
                    <div class="alert alert-warning py-2" style="border-radius:8px; font-size:0.88rem;">
                        <strong>Temporary password for <?php echo h($issued['name']); ?>:</strong>
                        <code class="fs-6 ms-1 user-select-all"><?php echo h($issued['password']); ?></code>
                        <div class="small mt-1">Shown only once. Give it to the lecturer directly.</div>
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <div class="mb-3">
                        <label class="form-label">Full Name</label>
                        <input type="text" name="full_name" class="form-control"
                               placeholder="e.g. Mr. Seow Soon Loong" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email Address</label>
                        <input type="email" name="email" class="form-control"
                               placeholder="e.g. seowsl@nilai.edu.my" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Account Type</label>
                        <select name="account_role" class="form-select">
                            <option value="lecturer">Lecturer</option>
                            <option value="admin">Administrator</option>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label class="form-label">Temporary Password</label>
                        <input type="password" name="password" class="form-control"
                               placeholder="Set a starting password" required minlength="<?php echo PASSWORD_MIN_LENGTH; ?>" autocomplete="new-password">
                        <div class="form-text"><?php echo h(PASSWORD_RULE_TEXT); ?> They will be asked to change it at first login.</div>
                    </div>
                    <button type="submit" name="add_lecturer" class="btn btn-primary-custom w-100 py-2 rounded-2">
                        <i class="bi bi-person-plus me-1"></i>Create Account
                    </button>
                </form>
            </div>
        </div>

        <!-- Lecturers Table -->
        <div class="col-md-8">
            <div class="dashboard-card p-0 overflow-hidden">
                <div class="p-4 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div class="card-header-title mb-0">
                        <i class="bi bi-people text-primary"></i>Department Staff
                    </div>
                    <div style="width: 240px;">
                        <div class="input-group">
                            <span class="input-group-text bg-light border-0" style="color:#94A3B8;"><i class="bi bi-search"></i></span>
                            <input type="text" id="lecturerSearch" class="form-control bg-light border-0" placeholder="Search faculty..." style="font-size:0.88rem; box-shadow:none;">
                        </div>
                    </div>
                </div>

                <?php if (mysqli_num_rows($lecturers) > 0): ?>
                <div class="table-responsive">
                <table class="table table-hover mb-0" id="lecturerTable">
                    <thead class="table-custom-header">
                        <tr>
                            <th class="ps-4">#</th>
                            <th>Faculty Profile</th>
                            <th>Assigned Subjects</th>
                            <th>Created</th>
                            <th class="text-center pe-4" style="min-width: 170px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 1; while ($row = mysqli_fetch_assoc($lecturers)): ?>
                        <tr>
                            <td class="ps-4 text-muted" style="font-size:0.8rem;"><?php echo $i++; ?></td>
                            <td>
                                <strong class="text-dark d-block"><?php echo h($row['full_name']); ?>
                                    <?php if ($row['role'] === 'admin'): ?><span class="badge bg-dark ms-1" style="font-size:0.65rem;">Administrator</span><?php endif; ?>
                                </strong>
                                <span class="text-muted" style="font-size:0.75rem;"><i class="bi bi-envelope me-1"></i><?php echo h($row['email']); ?></span>
                            </td>
                            <td>
                                <?php if ($row['subjects']): ?>
                                    <span style="font-size:0.82rem; color:#475569; line-height:1.4; display:block;">
                                        <?php echo h($row['subjects']); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted" style="font-size:0.8rem; font-style:italic;">None assigned</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge" style="background:#F1F5F9; color:#64748B;">
                                    <?php echo date('d M Y', strtotime($row['created_at'])); ?>
                                </span>
                            </td>
                            <td class="text-center pe-4">
                                <?php if ($row['role'] !== 'lecturer'): ?>
                                    <span class="text-muted" style="font-size:0.8rem;"><?php echo ((int)$row['id'] === (int)$_SESSION['user_id']) ? 'You — use Change Password' : '—'; ?></span>
                                <?php else: ?>
                                <div class="d-flex gap-2 justify-content-center">
                                    <button class="btn btn-action btn-reset"
                                            data-bs-toggle="modal"
                                            data-bs-target="#resetModal"
                                            data-id="<?php echo $row['id']; ?>"
                                            data-name="<?php echo h($row['full_name']); ?>">
                                        <i class="bi bi-key me-1"></i>Reset
                                    </button>

                                    <!-- FIX: Changed GET link to secure POST form to prevent CSRF -->
                                    <form method="POST" class="d-inline m-0" onsubmit="return confirm('⚠️ Delete <?php echo h($row['full_name']); ?>? Their audit history and past sessions are kept; upcoming sessions will be declined.');">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="delete_id" value="<?php echo $row['id']; ?>">
                                        <button type="submit" name="delete_lecturer" class="btn btn-action btn-delete">
                                            <i class="bi bi-trash me-1"></i>Delete
                                        </button>
                                    </form>
                                </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
                </div>
                <?php else: ?>
                    <div class="p-5 text-center text-muted">
                        <i class="bi bi-person-x" style="font-size:2.5rem; opacity:0.3;"></i>
                        <p class="mt-3 mb-0">No staff accounts found.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>
    </div>
    </main>
</div>

<!-- Reset Password Modal -->
<div class="modal fade" id="resetModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:12px; border:none;">
            <div class="modal-header" style="background:#F8FAFC; border-bottom:1px solid #E2E8F0; border-radius:12px 12px 0 0;">
                <h5 class="modal-title fw-bold" style="color:#1A3A5C;">
                    <i class="bi bi-key me-2 text-warning"></i>Reset Faculty Password
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <div class="modal-body p-4">
                    <input type="hidden" name="lec_id" id="modal_lec_id">
                    <div class="alert py-2 mb-4 d-flex align-items-center gap-2" style="background:#FFFBEB; border:1px solid #FDE68A; color:#92400E; font-size:0.85rem;">
                        <i class="bi bi-person-badge fs-5"></i>
                        <div>Resetting password for: <strong id="modal_lec_name"></strong></div>
                    </div>
                    <p class="mb-0 small text-muted">A one-time temporary password will be generated and shown to you once. Their current password stops working, and they must choose a new one at next login.</p>
                </div>
                <div class="modal-footer" style="background:#F8FAFC; border-top:1px solid #E2E8F0; border-radius:0 0 12px 12px;">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="reset_password" class="btn btn-warning" style="font-weight:600; color:#92400E;">
                        <i class="bi bi-key me-1"></i>Confirm Reset
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('lecturerSearch').addEventListener('input', function () {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#lecturerTable tbody tr').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
});

document.getElementById('resetModal').addEventListener('show.bs.modal', function (e) {
    const btn = e.relatedTarget;
    document.getElementById('modal_lec_id').value   = btn.dataset.id;
    document.getElementById('modal_lec_name').textContent = btn.dataset.name;
});
</script>
<script src="../assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="../assets/js/theme-toggle.js"></script>
<script src="../assets/js/app.js?v=2"></script>
</body>
</html>