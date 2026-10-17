<?php
// ── Shared sidebar navigation ───────────────────────────────────────────
// One menu definition per role, rendered by every signed-in page so a new
// link only has to be added here. Pages include this inside <aside
// class="sidebar"> and must already have $conn and a started session.
require_once __DIR__ . '/csrf.php';

$sidebar_menus = [
    'admin' => [
        ['admin_dashboard.php',    'bi-grid-1x2',       'Dashboard'],
        ['manage_subjects.php',    'bi-journal-code',   'Subjects'],
        ['manage_enrollments.php', 'bi-person-check',   'Enrolments'],
        ['manage_students.php',    'bi-people',         'Students'],
        ['manage_lecturers.php',   'bi-person-badge',   'Staff'],
        ['manage_semesters.php',   'bi-calendar3',      'Semesters'],
        ['model_evaluation.php',   'bi-clipboard-data', 'Warning Accuracy'],
        ['account_requests.php',   'bi-person-plus',    'Account Requests'],
        ['password_requests.php',  'bi-key',            'Password Requests'],
        ['audit_logs.php',         'bi-clock-history',  'Audit Log'],
    ],
    'lecturer' => [
        ['lecturer_dashboard.php', 'bi-grid-1x2',       'Dashboard'],
        ['enter_marks.php',        'bi-pencil-square',  'Enter Marks'],
        ['csv_upload.php',         'bi-cloud-arrow-up', 'CSV Upload'],
        ['audit_trail.php',        'bi-clock-history',  'Audit Trail'],
    ],
    'student' => [
        ['student_dashboard.php',  'bi-grid-1x2',       'Dashboard'],
        ['predict.php',            'bi-calculator',     'What-If Calculator'],
        ['mentorship.php',         'bi-people',         'Mentorship'],
        ['transcript.php',         'bi-file-earmark-text', 'My Transcript'],
    ],
];

// Detail pages highlight the list page they belong to.
$sidebar_aliases = ['view_student.php' => 'manage_students.php', 'subject_outcomes.php' => 'manage_subjects.php'];

$sidebar_role   = $_SESSION['role'] ?? 'student';
$sidebar_active = basename($_SERVER['PHP_SELF']);
$sidebar_active = $sidebar_aliases[$sidebar_active] ?? $sidebar_active;

// Pending password-reset requests are shown as a badge for admins.
$sidebar_badges = [];
if ($sidebar_role === 'admin') {
    $sidebar_badge_q = mysqli_query($conn, "SELECT COUNT(*) AS c FROM password_resets WHERE used = 0 AND expires_at > NOW()");
    $sidebar_badges['password_requests.php'] = (int)mysqli_fetch_assoc($sidebar_badge_q)['c'];
    try {
        $sidebar_badge_q = mysqli_query($conn, "SELECT COUNT(*) AS c FROM account_requests WHERE status = 'Pending'");
        $sidebar_badges['account_requests.php'] = (int)mysqli_fetch_assoc($sidebar_badge_q)['c'];
    } catch (mysqli_sql_exception $e) {
        // Table missing until database/migrations/003_account_requests.sql is run.
    }
}
?>
<nav class="sidebar-nav">
    <?php foreach ($sidebar_menus[$sidebar_role] ?? [] as [$sidebar_href, $sidebar_icon, $sidebar_label]): ?>
        <a href="<?php echo $sidebar_href; ?>" class="nav-item<?php echo $sidebar_href === $sidebar_active ? ' active' : ''; ?>">
            <i class="bi <?php echo $sidebar_icon; ?>"></i> <?php echo $sidebar_label; ?>
            <?php if (!empty($sidebar_badges[$sidebar_href])): ?>
                <span class="badge bg-warning text-dark ms-auto"><?php echo $sidebar_badges[$sidebar_href]; ?></span>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
    <?php if (in_array($sidebar_role, ['lecturer', 'student'], true)): ?>
        <!-- Reopens the first-login guide (includes/onboarding.php). -->
        <a href="../<?php echo $sidebar_role; ?>/<?php echo $sidebar_role; ?>_dashboard.php?guide=1" class="nav-item mt-4">
            <i class="bi bi-question-circle"></i> Getting Started
        </a>
    <?php endif; ?>
    <a href="../auth/change_password.php" class="nav-item<?php echo in_array($sidebar_role, ['lecturer', 'student'], true) ? '' : ' mt-4'; ?>">
        <i class="bi bi-shield-lock"></i> Change Password
    </a>
    <!-- Logout is a CSRF-protected POST so another site can't sign users out. -->
    <form method="POST" action="../auth/logout.php" class="m-0">
        <?php echo csrf_field(); ?>
        <button type="submit" class="nav-item nav-item-button nav-item-logout">
            <i class="bi bi-box-arrow-right"></i> Logout
        </button>
    </form>
</nav>
