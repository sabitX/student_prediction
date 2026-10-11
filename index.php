<?php
require 'includes/session.php';
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] == 'admin')        header("Location: admin/admin_dashboard.php");
    elseif ($_SESSION['role'] == 'lecturer') header("Location: lecturer/lecturer_dashboard.php");
    else                                      header("Location: student/student_dashboard.php");
    exit();
}
?>

<?php
// index.php — SPTS Public Landing Page
// Nilai University — EC2320 Summative Project

// Show "Register" only when self-registration is on (see registration_open()
// in config/config.php). Read straight from env.php so this public page
// doesn't need a database connection.
$env_settings      = is_file(__DIR__ . '/config/env.php') ? require __DIR__ . '/config/env.php' : [];
$registration_open = !empty($env_settings['ALLOW_SELF_REGISTRATION']);
unset($env_settings);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SPTS — Student Performance Tracking System | Nilai University</title>
    <script>(function(){try{if(localStorage.getItem('spts-theme')==='dark')document.documentElement.setAttribute('data-bs-theme','dark');}catch(e){}})();</script>
    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="assets/css/dark-mode.css?v=4" rel="stylesheet">
    <link href="assets/css/app.css?v=4" rel="stylesheet">
    <link rel="icon" href="assets/images/favicon-32.png" type="image/png" sizes="32x32">
    <link rel="icon" href="assets/images/favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="assets/images/apple-touch-icon.png">
<style>
    /* ── Base ───────────────────────────────────────────────────────── */
    *, *::before, *::after { box-sizing: border-box; }

    body {
        font-family: 'Inter', system-ui, sans-serif;
        background: #F0F4F8;
        color: #1A1A2E;
        margin: 0;
    }

    /* ── Background Logo Watermark ──────────────────────────────────── */
    .bg-logo {
           position: fixed;
           top: 50%; left: 50%;
           transform: translate(-50%, -50%);
           width: 80vw; height: 80vh;
           background-image: url('assets/images/nilai_logo.png');
           background-size: contain;
           background-repeat: no-repeat;
           background-position: center;
           opacity: 0.1;               /* Bumped up slightly to see it */
           pointer-events: none;       /* Ensures you can still click buttons through it */
           z-index: 99;                /* Pulls it IN FRONT of the solid backgrounds */
           filter: grayscale(100%);
}

    /* Ensure main sections sit properly above the watermark */
    nav, section, footer, .top-nav {
        position: relative;
        z-index: 1;
    }

    /* ── Navbar ─────────────────────────────────────────────────────── */
    .top-nav {
        background: #1A3A5C;
        padding: 0 28px;
        height: 56px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        position: sticky;
        top: 0;
        z-index: 100;
        border-bottom: 3px solid #0D7377;
    }

    .top-nav .brand {
        font-size: 0.95rem;
        font-weight: 700;
        color: #fff;
        letter-spacing: 0.3px;
    }

    .top-nav .brand span span {
        color: #D4A017;
    }

    .top-nav .nav-links {
        display: flex;
        gap: 10px;
    }

    .top-nav .nav-links a {
        font-size: 0.82rem;
        font-weight: 500;
        padding: 6px 16px;
        border-radius: 6px;
        text-decoration: none;
        transition: all 0.18s;
    }

    .btn-nav-primary {
        background: #0D7377;
        color: #fff;
        border: 1px solid transparent;
    }
    .btn-nav-primary:hover { background: #0a5e62; color: #fff; }

    .btn-nav-secondary {
        background: transparent;
        color: rgba(255,255,255,0.8);
        border: 1px solid rgba(255,255,255,0.3);
    }
    .btn-nav-secondary:hover { background: rgba(255,255,255,0.1); color: #fff; border-color: rgba(255,255,255,0.6); }

    /* ── Hero ───────────────────────────────────────────────────────── */
    .hero {
        background: linear-gradient(135deg, #1A3A5C 0%, #14526E 60%, #0D7377 100%);
        padding: 80px 0 60px;
        overflow: hidden;
        position: relative;
    }

    .hero::before {
        content: '';
        position: absolute;
        inset: 0;
        background:
            radial-gradient(ellipse at 70% 50%, rgba(13,115,119,0.25) 0%, transparent 60%),
            radial-gradient(ellipse at 10% 80%, rgba(212,160,23,0.12) 0%, transparent 50%);
    }

    .hero-eyebrow {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: rgba(13,115,119,0.35);
        border: 1px solid rgba(13,115,119,0.6);
        color: #7DD3D6;
        font-size: 0.78rem;
        font-weight: 600;
        letter-spacing: 0.8px;
        text-transform: uppercase;
        padding: 6px 14px;
        border-radius: 20px;
        margin-bottom: 22px;
    }

    .hero h1 {
        font-size: clamp(1.8rem, 4vw, 2.9rem);
        font-weight: 800;
        color: #fff;
        line-height: 1.15;
        margin-bottom: 8px;
    }

    .hero h1 .spts-abbr {
        color: #D4A017;
        font-size: 0.65em;
        font-weight: 700;
        background: rgba(212,160,23,0.15);
        border: 1px solid rgba(212,160,23,0.35);
        border-radius: 6px;
        padding: 2px 10px;
        vertical-align: middle;
        margin-left: 8px;
        letter-spacing: 1px;
    }

    .hero .subtitle {
        font-size: 1rem;
        color: rgba(255,255,255,0.72);
        line-height: 1.6;
        margin-bottom: 36px;
        max-width: 480px;
    }

    .hero-cta {
        display: flex;
        gap: 14px;
        flex-wrap: wrap;
    }

    .btn-hero-primary {
        background: #0D7377;
        color: #fff;
        border: 2px solid #0D7377;
        font-weight: 700;
        font-size: 0.92rem;
        padding: 13px 28px;
        border-radius: 8px;
        text-decoration: none;
        transition: all 0.18s;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }
    .btn-hero-primary:hover {
        background: #0a5e62;
        border-color: #0a5e62;
        color: #fff;
        transform: translateY(-1px);
        box-shadow: 0 6px 20px rgba(13,115,119,0.4);
    }

    .btn-hero-secondary {
        background: transparent;
        color: rgba(255,255,255,0.88);
        border: 2px solid rgba(255,255,255,0.35);
        font-weight: 600;
        font-size: 0.92rem;
        padding: 13px 28px;
        border-radius: 8px;
        text-decoration: none;
        transition: all 0.18s;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }
    .btn-hero-secondary:hover {
        background: rgba(255,255,255,0.1);
        border-color: rgba(255,255,255,0.7);
        color: #fff;
    }

    /* ── Mockup Image ────────────────────────────────────────────────── */
    .hero-mockup-wrapper {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        position: relative;
        padding-left: 20px;
    }

    .hero-mockup-img {
        width: 100%;
        max-width: 580px;
        border-radius: 12px;
        box-shadow:
            0 0 0 1px rgba(255,255,255,0.12),
            0 24px 80px rgba(0,0,0,0.55),
            0 8px 24px rgba(0,0,0,0.35);
        transform: perspective(1000px) rotateY(-6deg) rotateX(3deg);
        transition: transform 0.4s ease;
    }

    .hero-mockup-img:hover {
        transform: perspective(1000px) rotateY(-2deg) rotateX(1deg);
    }

    /* Glow behind mockup */
    .hero-mockup-wrapper::before {
        content: '';
        position: absolute;
        width: 70%;
        height: 70%;
        background: radial-gradient(ellipse, rgba(13,115,119,0.45) 0%, transparent 70%);
        border-radius: 50%;
        z-index: 0;
        right: 10%;
        top: 15%;
        filter: blur(30px);
    }

    .hero-mockup-img {
        position: relative;
        z-index: 1;
    }

    /* ── How It Works ────────────────────────────────────────────────── */
    .section-how {
        background: #fff;
        padding: 72px 0;
    }

    .section-label {
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 1.5px;
        text-transform: uppercase;
        color: #0D7377;
        margin-bottom: 8px;
    }

    .section-title {
        font-size: 1.75rem;
        font-weight: 800;
        color: #1A3A5C;
        margin-bottom: 8px;
    }

    .section-sub {
        color: #6C757D;
        font-size: 0.95rem;
        margin-bottom: 0;
    }

    .feature-card {
        background: #fff;
        border: 1px solid #E2E8F0;
        border-radius: 12px;
        padding: 28px 28px 24px;
        height: 100%;
        transition: all 0.22s;
        text-align: left;
    }

    .feature-card:hover {
        border-color: #0D7377;
        box-shadow: 0 8px 32px rgba(13,115,119,0.12);
        transform: translateY(-3px);
    }

    .feature-icon-wrap {
        width: 52px;
        height: 52px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 18px;
        font-size: 1.4rem;
    }

    .feature-card h5 {
        font-size: 1rem;
        font-weight: 700;
        color: #1A3A5C;
        margin-bottom: 10px;
    }

    .feature-card p {
        font-size: 0.88rem;
        color: #5A6A7A;
        line-height: 1.6;
        margin: 0;
    }

    /* ── Role Cards ──────────────────────────────────────────────────── */
    .section-roles {
        background: #F0F4F8;
        padding: 72px 0;
    }

    .role-card {
        background: #fff;
        border: 1px solid #E2E8F0;
        border-radius: 14px;
        padding: 30px 26px;
        height: 100%;
        transition: all 0.22s;
    }

    .role-card:hover {
        box-shadow: 0 10px 36px rgba(26,58,92,0.12);
        transform: translateY(-3px);
    }

    .role-icon {
        width: 54px;
        height: 54px;
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
        margin-bottom: 16px;
    }

    .role-card h5 {
        font-size: 1.05rem;
        font-weight: 700;
        color: #1A3A5C;
        margin-bottom: 8px;
    }

    .role-card p {
        font-size: 0.87rem;
        color: #6C757D;
        line-height: 1.6;
        margin-bottom: 18px;
    }

    /* ── Footer ──────────────────────────────────────────────────────── */
    footer {
        background: #122840;
        color: rgba(255,255,255,0.55);
        padding: 28px 0;
        text-align: center;
        border-top: 3px solid #0D7377;
    }

    footer .footer-brand {
        font-size: 0.88rem;
        color: rgba(255,255,255,0.8);
        font-weight: 600;
        margin-bottom: 4px;
    }

    footer .footer-copy {
        font-size: 0.76rem;
    }

    /* ── Responsive ──────────────────────────────────────────────────── */
    @media (max-width: 767px) {
        .hero { padding: 50px 0 40px; }
        .hero-mockup-wrapper { margin-top: 36px; justify-content: center; }
        .hero-mockup-img { transform: none; max-width: 100%; }
        .hero-cta { flex-direction: column; }
        .hero-cta a { text-align: center; justify-content: center; }
        .top-nav { padding: 0 14px; }
        .top-nav .brand { white-space: nowrap; }
        .top-nav .nav-links { gap: 8px; align-items: center; }
        .top-nav .nav-links a { padding: 6px 12px; white-space: nowrap; }
    }
    @media (max-width: 479.98px) {
        .top-nav .brand-long { display: none; }
    }
</style>
</head>
<body>

<!-- Background Watermark -->
<div class="bg-logo"></div>


<!-- ── Navbar ────────────────────────────────────────────────────────────── -->
<nav class="top-nav">
    <div class="brand">
        <i class="bi bi-mortarboard-fill me-2"></i>
        SPTS<span class="brand-long"> — <span>Nilai University</span></span>
    </div>
    <div class="nav-links">
        <button type="button" class="theme-toggle" onclick="toggleTheme()" title="Toggle dark mode">
            <i class="bi bi-moon-stars-fill theme-toggle-icon"></i>
        </button>
        <a href="auth/login.php" class="btn-nav-primary">
            <i class="bi bi-person me-1"></i>Login
        </a>
        <?php if ($registration_open): ?>
        <a href="auth/register.php" class="btn-nav-secondary">
            <i class="bi bi-person-plus me-1"></i>Register
        </a>
        <?php endif; ?>
    </div>
</nav>


<!-- ── Hero ──────────────────────────────────────────────────────────────── -->
<section class="hero">
    <div class="container position-relative" style="z-index:1;">
        <div class="row align-items-center g-5">

            <!-- Left: Text + CTAs -->
            <div class="col-lg-5">
                <div class="hero-eyebrow">
                    <i class="bi bi-mortarboard"></i>
                    Department of Computing & Data Sciences
                </div>

                <h1>
                    Student Performance<br>Tracking System
                    <span class="spts-abbr">SPTS</span>
                </h1>

                <p class="subtitle">
                    Centralised assessment tracking and weighted scoring for the
                    Department of Computing &amp; Data Sciences.
                </p>

                <div class="hero-cta">
                    <a href="auth/login.php" class="btn-hero-primary">
                        <i class="bi bi-person-fill"></i>
                        Student Login
                    </a>
                    <a href="auth/login.php?portal=staff" class="btn-hero-secondary">
                        <i class="bi bi-shield-lock-fill"></i>
                        Staff &amp; Admin Login
                    </a>
                </div>
            </div>

            <!-- Right: Dashboard Mockup -->
            <div class="col-lg-7">
                <div class="hero-mockup-wrapper">
                    <img
                        src="assets/images/mockup.png"
                        alt="SPTS Lecturer Dashboard — Mark Entry Grid"
                        class="hero-mockup-img"
                    >
                </div>
            </div>

        </div>
    </div>
</section>


<!-- ── How It Works ──────────────────────────────────────────────────────── -->
<section class="section-how">
    <div class="container">
        <div class="mb-5">
            <div class="section-label">How It Works</div>
            <h2 class="section-title">Three steps. One system.</h2>
            <p class="section-sub">From mark entry to academic insight — all in one place.</p>
        </div>

        <div class="row g-4">

            <!-- Step 1: Enter Marks -->
            <div class="col-md-4">
                <div class="feature-card">
                    <div class="feature-icon-wrap" style="background:#EAF4FB;">
                        <i class="bi bi-grid-3x3-gap-fill" style="color:#1A3A5C; font-size:1.5rem;"></i>
                    </div>
                    <h5>Enter Marks via Grid</h5>
                    <p>
                        Lecturers fill in the inline mark-entry grid — assignment, midterm,
                        final exam and attendance — and save all rows in a single click.
                        Bulk CSV upload is also available.
                    </p>
                </div>
            </div>

            <!-- Step 2: Automated Scoring -->
            <div class="col-md-4">
                <div class="feature-card">
                    <div class="feature-icon-wrap" style="background:#E0F4F4;">
                        <i class="bi bi-cpu-fill" style="color:#0D7377; font-size:1.5rem;"></i>
                    </div>
                    <h5>Weighted Score Computed</h5>
                    <p>
                        The system instantly applies the Weighted Scoring Model —
                        Assignment 20%, Midterm 30%, Final 40%, Attendance 10% —
                        and classifies each student as Excellent, Pass, At Risk, or Fail.
                    </p>
                </div>
            </div>

            <!-- Step 3: View Results -->
            <div class="col-md-4">
                <div class="feature-card">
                    <div class="feature-icon-wrap" style="background:#FFF8E1;">
                        <i class="bi bi-calculator-fill" style="color:#D4A017; font-size:1.5rem;"></i>
                    </div>
                    <h5>View Results &amp; What-If</h5>
                    <p>
                        Students view their live grade summary, download a formatted
                        academic transcript, and use the What-If Calculator to simulate
                        different final exam scores before the semester ends.
                    </p>
                </div>
            </div>

        </div>
    </div>
</section>


<!-- ── Role Cards ────────────────────────────────────────────────────────── -->
<section class="section-roles">
    <div class="container">
        <div class="mb-5">
            <div class="section-label">Who Uses SPTS</div>
            <h2 class="section-title">Three roles. One platform.</h2>
            <p class="section-sub">Each user sees only what is relevant to their role.</p>
        </div>

        <div class="row g-4">

            <!-- Student -->
            <div class="col-md-4">
                <div class="role-card">
                    <div class="role-icon" style="background:#EAF0F8;">
                        <i class="bi bi-mortarboard" style="font-size:1.8rem; color:#2563eb;"></i>
                    </div>
                    <h5>Students</h5>
                    <p>
                        View your official marks, track your academic status across all subjects,
                        download a formatted transcript, and simulate exam scenarios with
                        the What-If Calculator.
                    </p>
                </div>
            </div>

            <!-- Lecturer -->
            <div class="col-md-4">
                <div class="role-card">
                    <div class="role-icon" style="background:#E0F4F4;">
                        <i class="bi bi-person-badge" style="color:#0D7377;"></i>
                    </div>
                    <h5>Lecturers</h5>
                    <p>
                        Enter and update marks using the inline grid or bulk CSV upload.
                        Monitor class-level performance summaries and review the full
                        audit trail of every mark change.
                    </p>
                </div>
            </div>

            <!-- Admin -->
            <div class="col-md-4">
                <div class="role-card">
                    <div class="role-icon" style="background:#F5F5F5;">
                        <i class="bi bi-shield-lock" style="color:#333;"></i>
                    </div>
                    <h5>Administrators</h5>
                    <p>
                        Manage subjects, student accounts and lecturer assignments.
                        View the analytics dashboard with live performance distribution
                        charts across all enrolled subjects.
                    </p>
                </div>
            </div>

        </div>
    </div>
</section>


<!-- ── Footer ────────────────────────────────────────────────────────────── -->
<footer>
    <div class="container">
        <p class="footer-brand mb-1">
            <i class="bi bi-mortarboard-fill me-1"></i>
            Student Performance Tracking Web System Using Weighted Scoring Model
        </p>
        <p class="footer-copy mb-0">
            © 2026 Nilai University — Department of Computing and Data Sciences — EC2320 Summative Project
        </p>
    </div>
</footer>

<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/js/theme-toggle.js"></script>
<script src="assets/js/app.js?v=4"></script>
</body>
</html>