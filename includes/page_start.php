<?php
// ── Shared page frame (head, topbar, sidebar) for signed-in pages ───────
// Every signed-in page uses this, so none carries its own copy of the
// layout. Set $page_title before including. Optional:
//   $page_styles      extra CSS for that page (after the shared rules)
//   $topbar_label     text beside the avatar (default "Welcome, <first name>")
//   $page_own_alerts  true if the page shows $success/$error itself
// Close the page with includes/page_end.php ($page_scripts goes there).
// Requires session_check.php, config.php and semester.php to be loaded.

$full_name       = $_SESSION['full_name'];
$first_name      = explode(' ', trim($full_name))[0];
$initials        = strtoupper(substr($first_name, 0, 1) . substr(explode(' ', trim($full_name))[1] ?? '', 0, 1));
$active_semester = h(get_active_semester_name($conn));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h($page_title); ?> | SPTS Nilai University</title>
    <script>(function(){try{if(localStorage.getItem('spts-theme')==='dark')document.documentElement.setAttribute('data-bs-theme','dark');}catch(e){}})();</script>
    <link href="../assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="../assets/css/dark-mode.css?v=4" rel="stylesheet">
    <link href="../assets/css/app.css?v=4" rel="stylesheet">
    <link rel="icon" href="../assets/images/favicon-32.png" type="image/png" sizes="32x32">
    <link rel="icon" href="../assets/images/favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="../assets/images/apple-touch-icon.png">
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { font-family: 'Inter', system-ui, sans-serif; background: #F0F4F8; color: #1A1A2E; margin: 0; padding: 0; overflow-x: hidden; }
        .bg-logo { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 80vw; height: 80vh; background-image: url('../assets/images/nilai_logo.png'); background-size: contain; background-repeat: no-repeat; background-position: center; opacity: 0.08; pointer-events: none; z-index: 99; filter: grayscale(100%); }

        /* Light chrome: white topbar, soft-gray sidebar. Teal (#0D7377) only
           marks the current page, hover and focus. Dark mode: dark-mode.css. */
        .topbar { background: #FFFFFF; height: 60px; display: flex; align-items: center; justify-content: space-between; padding: 0 24px; color: #1A3A5C; position: fixed; width: 100%; top: 0; z-index: 1030; border-bottom: 1px solid #E5E9F0; }
        .topbar .brand { display: flex; align-items: center; gap: 12px; color: #1A3A5C; text-decoration: none; }
        .brand-logo { height: 34px; width: auto; display: block; flex-shrink: 0; }
        .brand-divider { width: 1px; height: 26px; background: #D9E0E8; flex-shrink: 0; }
        .brand-name { font-weight: 700; font-size: 1rem; letter-spacing: 0.4px; line-height: 1.15; }
        .brand-name small { display: block; font-size: 0.7rem; font-weight: 500; letter-spacing: 0.2px; color: #64748B; }
        .topbar .user-profile { display: flex; align-items: center; gap: 12px; font-size: 0.85rem; color: #475569; }
        .topbar .theme-toggle { background: #FFFFFF; border: 1px solid #E2E8F0; color: #1A3A5C; }
        .topbar .theme-toggle:hover { background: #F1F5F9; }
        .avatar { background: #0D7377; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 600; color: white; }

        .layout-wrapper { display: flex; margin-top: 60px; min-height: calc(100vh - 60px); position: relative; z-index: 100; }
        .sidebar { width: 240px; background: #F8FAFC; border-right: 1px solid #E5E9F0; flex-shrink: 0; position: fixed; height: calc(100vh - 60px); overflow-y: auto; display: flex; flex-direction: column; justify-content: space-between; z-index: 1020; }
        .sidebar-nav { padding-top: 16px; }
        .nav-item { padding: 12px 24px 12px 21px; color: #475569; border-left: 3px solid transparent; display: flex; align-items: center; gap: 12px; text-decoration: none; font-size: 0.9rem; font-weight: 500; transition: background 0.15s, color 0.15s; }
        .nav-item .bi { color: #64748B; transition: color 0.15s; }
        .nav-item:hover { color: #0D7377; background: #EEF6F6; }
        .nav-item:hover .bi { color: #0D7377; }
        .nav-item.active { color: #0D7377; background: #E7F3F3; border-left-color: #0D7377; font-weight: 600; }
        .nav-item.active .bi { color: #0D7377; }
        .nav-item-logout, .nav-item-logout .bi { color: #B42318; }
        .nav-item-logout:hover { color: #B42318; background: #FDF0EE; }
        .nav-item-logout:hover .bi { color: #B42318; }
        .active-semester { padding: 18px 20px; background: #FFFFFF; text-align: center; border-top: 1px solid #E5E9F0; }
        .active-semester p { margin: 0; font-size: 0.72rem; color: #64748B; text-transform: uppercase; letter-spacing: 0.6px; }
        .active-semester h6 { margin: 4px 0 0; color: #1A3A5C; font-weight: 700; font-size: 0.9rem; }

        .main-content { margin-left: 240px; flex-grow: 1; padding: 32px; }
        .page-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px; gap: 16px; }
        .page-header h2 { font-weight: 700; color: #1A3A5C; margin: 0 0 4px; font-size: 1.5rem; }
        .page-header p { color: #64748B; margin: 0; font-size: 0.9rem; }

        .dashboard-card { background: #fff; border: 1px solid var(--spts-card-border); border-radius: 16px; padding: 28px; box-shadow: var(--spts-card-shadow); margin-bottom: 24px; }
        .card-header-title { font-size: 1.05rem; font-weight: 700; color: #1A3A5C; margin-bottom: 20px; display: flex; align-items: center; gap: 8px; }
        .form-label { font-size: 0.85rem; font-weight: 600; color: #1A3A5C; }
        .btn-primary-custom { background: #0D7377; border: 2px solid #0D7377; color: #fff; font-weight: 600; }
        .btn-primary-custom:hover { background: #0a5e62; border-color: #0a5e62; color: #fff; }

        .table-custom-header th { background: #1A3A5C !important; color: #ffffff !important; font-weight: 600; font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: none; padding: 14px 12px; vertical-align: middle; }
        .table > tbody > tr > td { vertical-align: middle; font-size: 0.88rem; color: #334155; padding: 14px 12px; border-color: #F8FAFC; }

        .alert-ok  { background:#F0FDF4; border:1px solid #BBF7D0; color:#166534; font-size:0.88rem; border-radius:8px; }
        .alert-bad { background:#FEF2F2; border:1px solid #FECACA; color:#991B1B; font-size:0.88rem; border-radius:8px; }

        .empty-state { text-align: center; padding: 30px; color: #64748B; }
        .empty-state i { font-size: 2rem; display: block; margin-bottom: 8px; opacity: 0.4; }
        <?php echo $page_styles ?? ''; ?>
    </style>
</head>
<body>

<div class="bg-logo"></div>

<header class="topbar">
    <a class="brand" href="<?php echo h(role_dashboard_path($_SESSION['role'])); ?>" aria-label="SPTS home — Nilai University">
        <img src="../assets/images/nilai_logo_nav.png" class="brand-logo" alt="Nilai University" width="75" height="34">
        <span class="brand-divider" aria-hidden="true"></span>
        <span class="brand-name">SPTS<small>Student Performance Tracking</small></span>
    </a>
    <div class="user-profile">
        <button type="button" class="theme-toggle" onclick="toggleTheme()" title="Toggle dark mode">
            <i class="bi bi-moon-stars-fill theme-toggle-icon"></i>
        </button>
        <span><?php echo h($topbar_label ?? 'Welcome, ' . $first_name); ?></span>
        <div class="avatar"><?php echo h($initials); ?></div>
    </div>
</header>

<div class="layout-wrapper">
    <aside class="sidebar">
        <?php require __DIR__ . '/sidebar.php'; ?>
        <div class="active-semester">
            <p>Active Semester</p>
            <h6><?php echo $active_semester; ?></h6>
        </div>
    </aside>

    <main class="main-content">
        <?php if (empty($page_own_alerts)): ?>
        <?php if (!empty($success)): ?>
            <div class="alert alert-ok py-2 mb-4"><i class="bi bi-check-circle-fill me-2"></i><?php echo h($success); ?></div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="alert alert-bad py-2 mb-4"><i class="bi bi-exclamation-circle-fill me-2"></i><?php echo h($error); ?></div>
        <?php endif; ?>
        <?php endif; ?>
