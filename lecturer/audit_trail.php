<?php
require '../includes/session_check.php';
require '../config/config.php';
require '../includes/semester.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'lecturer') {
    header("Location: ../auth/login.php");
    exit();
}

$user_id    = $_SESSION['user_id'];
$full_name  = $_SESSION['full_name'];
$first_name = explode(' ', trim($full_name))[0];
$initials   = strtoupper(substr($first_name, 0, 1) . substr(explode(' ', trim($full_name))[1] ?? '', 0, 1));
$active_semester = h(get_active_semester_name($conn));

// ── SECURE QUERY: AUDIT LOGS ─────────────────────────────────────────────
$stmt_logs = mysqli_prepare($conn, "SELECT action, details, created_at FROM audit_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 200");
mysqli_stmt_bind_param($stmt_logs, "i", $user_id);
mysqli_stmt_execute($stmt_logs);
$logs = mysqli_stmt_get_result($stmt_logs);
$log_count = mysqli_num_rows($logs);
mysqli_stmt_close($stmt_logs);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Trail | SPTS Nilai University</title>
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

        /* Dashboard Cards */
        .dashboard-card { background: white; border-radius: 16px; padding: 28px; box-shadow: 0 8px 32px rgba(26,58,92,0.05); margin-bottom: 24px; }
        .card-header-title { font-size: 1.05rem; font-weight: 700; color: #1A3A5C; margin-bottom: 20px; display: flex; align-items: center; gap: 8px; }

        .action-badge { padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; display: inline-block; background: #E0F2FE; color: #0369A1; }

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

        <div class="page-header">
            <div>
                <h2>Audit Trail</h2>
                <p>A record of your recent mark entries and data changes.</p>
            </div>
            <div class="badge-semester">
                <i class="bi bi-calendar3"></i> <?php echo $active_semester; ?>
            </div>
        </div>

        <div class="dashboard-card">
            <div class="card-header-title">
                <i class="bi bi-clock-history text-primary"></i>Recent Activity
                <?php if ($log_count > 0): ?>
                    <span class="badge bg-secondary ms-2" style="font-size:0.75rem;"><?php echo $log_count; ?> entries</span>
                <?php endif; ?>
            </div>

            <?php if ($log_count > 0): ?>
            <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size:0.88rem;">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Action</th>
                        <th>Details</th>
                        <th class="pe-3">Date & Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($log = mysqli_fetch_assoc($logs)): ?>
                    <tr>
                        <td class="ps-3"><span class="action-badge"><?php echo h($log['action']); ?></span></td>
                        <td style="max-width:480px; color:#334155;"><?php echo h($log['details']); ?></td>
                        <td class="pe-3 text-muted"><i class="bi bi-clock me-1"></i><?php echo date('d M Y, h:i A', strtotime($log['created_at'])); ?></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <i class="bi bi-clock-history"></i>
                <p class="mb-0">No activity recorded yet.</p>
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
