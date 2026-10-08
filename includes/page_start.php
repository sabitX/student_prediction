<?php
// ── Shared page frame (head, topbar, sidebar) for signed-in pages ───────
// Used by the newer admin pages so they don't each carry a copy of the
// layout CSS. Set $page_title before including; optional $page_styles is
// extra CSS for that page. Close the page with includes/page_end.php.
// Requires session_check.php, config.php and semester.php to be loaded.

$full_name       = $_SESSION['full_name'];
$first_name      = explode(' ', trim($full_name))[0];
$initials        = strtoupper(substr($first_name, 0, 1) . substr(explode(' ', trim($full_name))[1] ?? '', 0, 1));
$active_semester = h(get_active_semester_name($conn));
$brand_icon      = ($_SESSION['role'] === 'admin') ? 'bi-shield-lock-fill' : 'bi-mortarboard-fill';
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
    <link href="../assets/css/dark-mode.css" rel="stylesheet">
    <link href="../assets/css/app.css" rel="stylesheet">
    <link rel="icon" href="../assets/images/favicon-32.png" type="image/png" sizes="32x32">
    <link rel="icon" href="../assets/images/favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="../assets/images/apple-touch-icon.png">
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { font-family: 'Inter', system-ui, sans-serif; background: #F0F4F8; color: #1A1A2E; margin: 0; padding: 0; overflow-x: hidden; }
        .bg-logo { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 80vw; height: 80vh; background-image: url('../assets/images/nilai_logo.png'); background-size: contain; background-repeat: no-repeat; background-position: center; opacity: 0.08; pointer-events: none; z-index: 99; filter: grayscale(100%); }

        .topbar { background: #1A3A5C; height: 60px; display: flex; align-items: center; justify-content: space-between; padding: 0 24px; color: white; position: fixed; width: 100%; top: 0; z-index: 1030; border-bottom: 3px solid #0D7377; }
        .topbar .brand { font-weight: 700; font-size: 1.1rem; letter-spacing: 0.5px; display: flex; align-items: center; gap: 8px; }
        .topbar .user-profile { display: flex; align-items: center; gap: 12px; font-size: 0.85rem; color: #CBD5E1; }
        .avatar { background: #0D7377; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 600; color: white; }

        .layout-wrapper { display: flex; margin-top: 60px; min-height: calc(100vh - 60px); position: relative; z-index: 100; }
        .sidebar { width: 240px; background: #1E293B; flex-shrink: 0; position: fixed; height: calc(100vh - 60px); overflow-y: auto; display: flex; flex-direction: column; justify-content: space-between; z-index: 1020; }
        .sidebar-nav { padding-top: 20px; }
        .nav-item { padding: 14px 24px; color: #94A3B8; display: flex; align-items: center; gap: 12px; text-decoration: none; font-size: 0.9rem; font-weight: 500; transition: all 0.2s; }
        .nav-item:hover { color: white; background: rgba(255,255,255,0.05); }
        .nav-item.active { background: #0D7377; color: white; border-left: 4px solid white; }
        .active-semester { padding: 20px; background: rgba(0,0,0,0.15); text-align: center; border-top: 1px solid rgba(255,255,255,0.05); }
        .active-semester p { margin: 0; font-size: 0.75rem; color: #64748B; text-transform: uppercase; letter-spacing: 0.5px; }
        .active-semester h6 { margin: 4px 0 0; color: #F59E0B; font-weight: 700; font-size: 0.9rem; }

        .main-content { margin-left: 240px; flex-grow: 1; padding: 32px; }
        .page-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px; gap: 16px; }
        .page-header h2 { font-weight: 700; color: #1A3A5C; margin: 0 0 4px; font-size: 1.5rem; }
        .page-header p { color: #64748B; margin: 0; font-size: 0.9rem; }

        .dashboard-card { background: #fff; border: none; border-radius: 16px; padding: 28px; box-shadow: 0 8px 32px rgba(26,58,92,0.05), 0 2px 8px rgba(26,58,92,0.02); margin-bottom: 24px; }
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
    <div class="brand">
        <i class="bi <?php echo $brand_icon; ?> me-2"></i>SPTS — Nilai University
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
    <aside class="sidebar">
        <?php require __DIR__ . '/sidebar.php'; ?>
        <div class="active-semester">
            <p>Active Semester</p>
            <h6><?php echo $active_semester; ?></h6>
        </div>
    </aside>

    <main class="main-content">
        <?php if (!empty($success)): ?>
            <div class="alert alert-ok py-2 mb-4"><i class="bi bi-check-circle-fill me-2"></i><?php echo h($success); ?></div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="alert alert-bad py-2 mb-4"><i class="bi bi-exclamation-circle-fill me-2"></i><?php echo h($error); ?></div>
        <?php endif; ?>
