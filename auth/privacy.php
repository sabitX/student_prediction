<?php
require '../includes/session.php';
require '../config/config.php';

// ── Privacy notice (public) ─────────────────────────────────────────────
// What personal data SPTS keeps, why, who can see it and for how long,
// written from what the code actually does. Linked from the landing page,
// the sign-in pages and every form that collects personal data. Requests to
// see or correct data go through Contact Admin (no other contact details
// are given here, because none are configured in the system).

$back = isset($_SESSION['user_id']) ? '../index.php' : 'login.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Privacy Notice | SPTS Nilai University</title>
    <script>(function(){try{if(localStorage.getItem('spts-theme')==='dark')document.documentElement.setAttribute('data-bs-theme','dark');}catch(e){}})();</script>
    <link href="../assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="../assets/css/dark-mode.css?v=4" rel="stylesheet">
    <link href="../assets/css/app.css?v=6" rel="stylesheet">
    <link rel="icon" href="../assets/images/favicon-32.png" type="image/png" sizes="32x32">
    <link rel="icon" href="../assets/images/favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="../assets/images/apple-touch-icon.png">
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { font-family: 'Inter', system-ui, sans-serif; background: #F0F4F8; color: #1A1A2E; margin: 0; padding: 40px 20px; }
        .notice-card { max-width: 780px; margin: 0 auto; background: #fff; border: 1px solid #E2E8F0; border-radius: 14px; padding: 40px; box-shadow: 0 10px 36px rgba(26,58,92,0.08); }
        @media (max-width: 575.98px) { .notice-card { padding: 26px 20px; } }
        h1 { font-size: 1.5rem; font-weight: 700; color: #1A3A5C; }
        h2 { font-size: 1.05rem; font-weight: 700; color: #1A3A5C; margin-top: 28px; }
        p, li, td, th { font-size: 0.9rem; line-height: 1.6; color: #334155; }
        .table th { background: #F8FAFC; }
        .link-custom { color: #0D7377; text-decoration: none; font-weight: 600; }
        .link-custom:hover { text-decoration: underline; color: #0a5e62; }
        [data-bs-theme="dark"] h1, [data-bs-theme="dark"] h2 { color: #E2E8F0; }
        [data-bs-theme="dark"] p, [data-bs-theme="dark"] li, [data-bs-theme="dark"] td, [data-bs-theme="dark"] th { color: #CBD5E1; }
        [data-bs-theme="dark"] .table th { background: rgba(255,255,255,0.04); }
    </style>
</head>
<body>

<button type="button" class="theme-toggle theme-toggle-floating" onclick="toggleTheme()" title="Toggle dark mode">
    <i class="bi bi-moon-stars-fill theme-toggle-icon"></i>
</button>

<main class="notice-card">
    <h1><i class="bi bi-shield-check me-2"></i>Privacy Notice</h1>
    <p class="text-muted mb-0">Student Performance Tracking System (SPTS), Nilai University</p>

    <h2>What this covers</h2>
    <p>This notice explains what personal data SPTS keeps about students and staff, why, who can see it and how long it is kept.
       It covers SPTS only.</p>

    <h2>What we keep</h2>
    <div class="table-responsive">
    <table class="table table-sm align-top">
        <thead><tr><th>Data</th><th>Why</th></tr></thead>
        <tbody>
            <tr><td>Name, email address and role (student, lecturer or administrator)</td><td>To give you an account and show your name to the people you work with.</td></tr>
            <tr><td>Your password, stored only as a one-way hash</td><td>To check it when you sign in. Nobody, including administrators, can read your password.</td></tr>
            <tr><td>Student ID, programme and year of study</td><td>To identify you and place you in the right programme.</td></tr>
            <tr><td>Marks (assignment, midterm, final exam, attendance), totals, statuses and grades</td><td>To track your performance, calculate results and your transcript, and give early warnings.</td></tr>
            <tr><td>Early warnings and whether you were emailed about one</td><td>To help you act before the final exam.</td></tr>
            <tr><td>Learning outcome attainment</td><td>To report how well subjects meet their learning outcomes.</td></tr>
            <tr><td>Mentoring bookings and session notes</td><td>To arrange and record peer and lecturer mentoring.</td></tr>
            <tr><td>Account requests and support requests (Contact Admin), including the details you give</td><td>To create accounts and answer requests for help.</td></tr>
            <tr><td>An audit log of changes, such as who changed which mark and when</td><td>To keep marks trustworthy and changes accountable.</td></tr>
            <tr><td>Sign-in attempts, stored as a scrambled code made from your email and network address</td><td>To slow down password guessing. Kept for one day.</td></tr>
        </tbody>
    </table>
    </div>

    <h2>Who can see it</h2>
    <ul>
        <li><strong>You</strong> see your own profile, marks, results, transcript, mentoring sessions and requests.</li>
        <li><strong>Lecturers</strong> see the students enrolled in the subjects they teach, and the mentoring sessions booked with them.</li>
        <li><strong>Peer mentors</strong> see the name and Student ID of a student who books a session with them, and the subject.</li>
        <li><strong>Administrators</strong> can see all records, to run the system.</li>
    </ul>
    <p>SPTS does not sell your data or share it for advertising. It is stored by the server that hosts SPTS, and emails
       (for example, early warnings or a new account's sign-in details) are sent through the configured mail server.</p>

    <h2>Cookies and your browser</h2>
    <ul>
        <li>A <strong>session cookie</strong> keeps you signed in. It ends when you sign out, close the browser, or after 30 minutes without activity.</li>
        <li>A short-lived cookie carries a one-time message to the next page (for example, "Access denied"). It expires after a minute.</li>
        <li>Your <strong>light/dark theme</strong> choice is saved in your browser only.</li>
        <li>The page font is loaded from <strong>Google Fonts</strong>, so your browser contacts Google's servers to fetch it.</li>
    </ul>
    <p>SPTS uses no analytics, tracking or advertising cookies.</p>

    <h2>How long it is kept</h2>
    <p>Academic records stay on your transcript for as long as the university keeps them in SPTS. Sign-in attempts are deleted after one day.
       Administrators can delete accounts and clear resolved requests; deleting an account keeps the audit history of changes it made, under the name recorded at the time.</p>

    <h2>Your choices</h2>
    <p>You can ask to see the personal data SPTS holds about you, or to correct anything that is wrong, through
       <a class="link-custom" href="contact_admin.php">Contact Admin</a> (or Contact Admin in your menu once signed in).
       Marks can only be changed by your lecturer or an administrator, and every change is recorded.</p>

    <div class="text-center mt-4">
        <a href="<?php echo h($back); ?>" class="link-custom"><i class="bi bi-arrow-left me-1"></i><?php echo isset($_SESSION['user_id']) ? 'Back to Home' : 'Back to Sign-in'; ?></a>
    </div>
</main>

<script src="../assets/js/theme-toggle.js"></script>
<script src="../assets/js/app.js?v=4"></script>
</body>
</html>
