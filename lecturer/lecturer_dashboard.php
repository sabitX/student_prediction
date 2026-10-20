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
require '../includes/marks.php';
require '../includes/early_warning.php';
require '../includes/obe.php';

$semester_id = get_active_semester_id($conn);

// ── SECURE POST: CQI ACTION for a CLO that missed its target ───────────────
// obe_save_cqi() checks the CLO belongs to one of this lecturer's subjects
// and recalculates the attainment itself.
if (isset($_POST['log_cqi']) && csrf_verify()) {
    [$ok, $msg] = obe_save_cqi($conn, (int)$user_id, (int)($_POST['clo_id'] ?? 0), $semester_id,
                               is_string($_POST['root_cause'] ?? null) ? $_POST['root_cause'] : '',
                               is_string($_POST['remedial_plan'] ?? null) ? $_POST['remedial_plan'] : '');
    $_SESSION['dashboard_flash'] = ['ok' => $ok, 'msg' => $msg];
    header("Location: lecturer_dashboard.php#outcomes");
    exit();
}

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
     JOIN student_enrollments e ON e.subject_id = ls.subject_id AND e.semester_id = ls.semester_id AND e.semester_id = ?
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

// ── Mid-semester early-warning roster: my students whose final exam isn't
// marked yet and whose marks so far raise a flag, most serious first.
$roster = array_values(array_filter(early_warnings($conn, $semester_id, 'lecturer', $user_id),
    fn($ew) => !$ew['analysis']['final_marked'] && $ew['analysis']['level'] !== 'ok'));
usort($roster, fn($x, $y) => [$x['analysis']['level'] !== 'critical', $x['analysis']['projected']]
                         <=> [$y['analysis']['level'] !== 'critical', $y['analysis']['projected']]);

// ── CLO attainment per subject this semester, with CQI flags ───────────────
$stmt_my_subs = mysqli_prepare($conn, "SELECT sub.id, sub.subject_code, sub.subject_name
                                       FROM lecturer_subjects ls JOIN subjects sub ON sub.id = ls.subject_id AND sub.is_archived = 0
                                       WHERE ls.user_id = ? AND ls.semester_id = (SELECT id FROM semesters WHERE is_active = 1) ORDER BY sub.subject_code");
mysqli_stmt_bind_param($stmt_my_subs, "i", $user_id);
mysqli_stmt_execute($stmt_my_subs);
$my_subjects = mysqli_fetch_all(mysqli_stmt_get_result($stmt_my_subs), MYSQLI_ASSOC);
mysqli_stmt_close($stmt_my_subs);
foreach ($my_subjects as &$sub) {
    $sub['clos'] = obe_cohort_clo($conn, (int)$sub['id'], $semester_id);
}
unset($sub);
$cqi_open = 0;
foreach ($my_subjects as $sub) {
    foreach ($sub['clos'] as $clo) {
        if ($clo['cqi_required'] && !$clo['cqi']) $cqi_open++;
    }
}
?>
<?php
// Shared head, topbar and sidebar (includes/page_start.php); only this
// page's own CSS is here.
$page_title      = 'Lecturer Dashboard';
$page_own_alerts = true;   // this page places its own messages
$page_styles     = <<<'CSS'
        body {
            font-family: 'Inter', system-ui, sans-serif;
            background: #F4F7F9;
            color: #1A1A2E;
            margin: 0;
            padding: 0;
            overflow-x: hidden;
        }

        .container {
            position: relative;
            z-index: 100; /* Ensure form sits above watermark */
        }

        /* Page Header */
        .page-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px; }

        .badge-semester { background: #0D7377; color: white; padding: 8px 16px; border-radius: 6px; font-weight: 600; font-size: 0.85rem; display: flex; align-items: center; gap: 8px; }

        /* Stat Cards */
        .stat-card { background: white; border-radius: 12px; padding: 20px; box-shadow: var(--spts-card-shadow); border: 1px solid var(--spts-card-border); display: flex; flex-direction: column; justify-content: center; height: 100%; position: relative; overflow: hidden; }
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
        .dashboard-card { background: white; border-radius: 16px; padding: 28px; box-shadow: var(--spts-card-shadow); border: 1px solid var(--spts-card-border); margin-bottom: 24px; }

        /* Action Buttons */
        .btn-action { font-size: 0.75rem; font-weight: 600; padding: 4px 12px; border-radius: 6px; border: none; transition: all 0.2s; }
        .btn-approve { background: #ECFDF5; color: #059669; border: 1px solid #A7F3D0; }
        .btn-approve:hover { background: #D1FAE5; }
        .btn-complete { background: #E0F2FE; color: #0369A1; border: 1px solid #BAE6FD; }
        .btn-complete:hover { background: #BAE6FD; }
        .btn-decline { background: #FEF2F2; color: #DC2626; border: 1px solid #FECACA; }
        .btn-decline:hover { background: #FEE2E2; }
        /* Shared rules this page does not use (keeps its original look). */
        .page-header { gap: normal; }
        .table > tbody > tr > td { vertical-align: inherit; font-size: inherit; color: var(--bs-table-color-state, var(--bs-table-color-type, var(--bs-table-color))); padding: .5rem .5rem; border-color: inherit; }
        .form-label { font-size: inherit; font-weight: inherit; color: inherit; }
CSS;
require '../includes/page_start.php';
?>

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
                    <small>needs attention<?php echo $roster ? ' · + ' . count($roster) . ' early warning' . (count($roster) == 1 ? '' : 's') : ''; ?></small>
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

        <!-- Mid-Semester Early-Warning Roster -->
        <div class="dashboard-card">
            <div class="card-header-title">
                <i class="bi bi-binoculars text-primary"></i>Mid-Semester Early Warnings
                <?php if ($roster): ?>
                    <span class="badge bg-warning text-dark ms-2" style="font-size:0.75rem;"><?php echo count($roster); ?> flagged</span>
                <?php endif; ?>
            </div>
            <p class="text-muted" style="font-size:0.82rem; margin-top:-8px;">
                Students whose final exam isn't marked yet and whose marks so far point to trouble.
                Projections blend the coursework marked so far with the student's earlier results
                (or the class average for new students).
            </p>
            <?php if ($roster): ?>
            <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size:0.86rem;">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Student</th>
                        <th>Subject</th>
                        <th class="text-center">Asg / Mid / Att</th>
                        <th class="text-center">Projected</th>
                        <th class="text-center">Final needed</th>
                        <th>Why</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($roster as $ew): $a = $ew['analysis']; ?>
                    <tr>
                        <td class="ps-3">
                            <strong style="color:#1A3A5C;"><?php echo h($ew['full_name']); ?></strong><br>
                            <span class="text-muted" style="font-size:0.75rem;"><?php echo h($ew['student_code']); ?></span>
                        </td>
                        <td><strong><?php echo h($ew['subject_code']); ?></strong></td>
                        <td class="text-center text-nowrap">
                            <?php echo implode(' / ', array_map(fn($k) => $ew[$k] === null ? '—' : h(ew_pct($ew[$k])), ['assignment', 'midterm', 'attendance'])); ?>
                        </td>
                        <td class="text-center">
                            <strong><?php echo ew_pct($a['projected']); ?></strong><br>
                            <span class="<?php echo risk_badge_class($a['level']); ?>"><?php echo risk_label($a['level']); ?></span>
                        </td>
                        <td class="text-center"><?php echo $a['min_final'] === null ? '—' : ($a['min_final'] > 100 ? 'over 100%' : ew_pct($a['min_final'])); ?></td>
                        <td style="min-width:260px;"><?php echo render_risk_reasons($a['reasons']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <i class="bi bi-binoculars"></i>
                <p class="mb-0">No early warnings — every student with marks so far is on track, or their final exam is already marked.</p>
            </div>
            <?php endif; ?>
        </div>

        <!-- CLO Attainment & CQI -->
        <div class="dashboard-card" id="outcomes">
            <div class="card-header-title">
                <i class="bi bi-diagram-3 text-primary"></i>Learning Outcome Attainment &amp; CQI
                <?php if ($cqi_open > 0): ?>
                    <span class="badge bg-danger ms-2" style="font-size:0.75rem;"><?php echo $cqi_open; ?> need a CQI action</span>
                <?php endif; ?>
            </div>
            <p class="text-muted" style="font-size:0.82rem; margin-top:-8px;">
                Share of assessed students who reached each CLO's target this semester. Below the class target (KPI),
                the CLO needs a Continuous Quality Improvement action.
            </p>
            <?php if (!$my_subjects): ?>
            <div class="empty-state"><i class="bi bi-journal"></i><p class="mb-0">No subjects are assigned to you.</p></div>
            <?php endif; ?>
            <?php foreach ($my_subjects as $sub): ?>
            <h6 class="fw-bold mt-3 mb-2" style="color:#1A3A5C;"><?php echo h($sub['subject_code']); ?> <span class="text-muted fw-normal" style="font-size:0.85rem;"><?php echo h($sub['subject_name']); ?></span></h6>
            <div class="table-responsive mb-2">
            <table class="table align-middle mb-0" style="font-size:0.85rem;">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">CLO</th>
                        <th>Assessed by</th>
                        <th class="text-center">Attained</th>
                        <th class="text-center">KPI</th>
                        <th class="text-center">Status</th>
                        <th class="text-center pe-3">CQI</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sub['clos'] as $clo): ?>
                    <tr>
                        <td class="ps-3" title="<?php echo h(obe_clo_label($clo)); ?>">
                            <strong><?php echo h($clo['clo_code']); ?></strong>
                            <span class="text-muted" style="font-size:0.75rem;"><?php echo h($clo['bloom_level'] . ' · ' . $clo['plo_code']); ?></span><br>
                            <span class="text-muted" style="font-size:0.78rem;"><?php echo h($clo['clo_description']); ?></span>
                        </td>
                        <td style="font-size:0.8rem;"><?php echo $clo['components'] ? h(implode(', ', array_map(fn($c) => MARK_FIELDS[$c], $clo['components']))) : '<span class="text-muted">Nothing</span>'; ?></td>
                        <td class="text-center text-nowrap">
                            <?php if ($clo['pct'] === null): ?>—<?php else: ?>
                                <strong><?php echo number_format($clo['pct'], 0); ?>%</strong>
                                <span class="text-muted" style="font-size:0.75rem;">(<?php echo $clo['attained']; ?>/<?php echo $clo['assessed']; ?>)</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center"><?php echo number_format($clo['kpi'], 0); ?>%</td>
                        <td class="text-center">
                            <?php if ($clo['pct'] === null): ?>
                                <span class="<?php echo status_badge_class(null); ?>">Not assessed</span>
                            <?php elseif ($clo['cqi_required']): ?>
                                <span class="<?php echo status_badge_class('Fail'); ?>">CQI required</span>
                            <?php else: ?>
                                <span class="<?php echo status_badge_class('Excellent'); ?>">Meets KPI</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center pe-3">
                            <?php if ($clo['cqi']): ?>
                                <span class="d-block text-muted" style="font-size:0.72rem;" title="<?php echo h('Root cause: ' . $clo['cqi']['root_cause'] . "\nPlan: " . $clo['cqi']['remedial_plan']); ?>">
                                    <i class="bi bi-check2-circle"></i> Logged <?php echo date('d M', strtotime($clo['cqi']['updated_at'])); ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($clo['pct'] !== null && ($clo['cqi_required'] || $clo['cqi'])): ?>
                            <button type="button" class="btn btn-action btn-complete" data-bs-toggle="modal" data-bs-target="#cqiModal"
                                    data-clo="<?php echo (int)$clo['id']; ?>"
                                    data-title="<?php echo h($sub['subject_code'] . ' ' . $clo['clo_code'] . ' — ' . number_format($clo['pct'], 0) . '% attained (KPI ' . number_format($clo['kpi'], 0) . '%)'); ?>"
                                    data-cause="<?php echo h($clo['cqi']['root_cause'] ?? ''); ?>"
                                    data-plan="<?php echo h($clo['cqi']['remedial_plan'] ?? ''); ?>">
                                <i class="bi bi-pencil-square me-1"></i><?php echo $clo['cqi'] ? 'Edit' : 'Log action'; ?>
                            </button>
                            <?php elseif ($clo['pct'] !== null): ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endforeach; ?>
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

<?php
// Modals and page scripts: page_end.php prints them after Bootstrap.
ob_start();
?>
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

<!-- CQI Action Modal -->
<div class="modal fade" id="cqiModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:12px; border:none;">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" style="color:#1A3A5C;"><i class="bi bi-arrow-repeat me-2"></i>CQI Action</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="clo_id" id="cqi_clo">
                <div class="modal-body">
                    <p class="small text-muted" id="cqi_title"></p>
                    <label class="form-label fw-semibold" for="cqi_cause">Root cause</label>
                    <textarea name="root_cause" id="cqi_cause" class="form-control mb-3" rows="3" required maxlength="<?php echo CQI_TEXT_MAX; ?>"
                              placeholder="e.g. Midterm questions on normalisation were answered poorly; topic was covered in one lecture."></textarea>
                    <label class="form-label fw-semibold" for="cqi_plan">Remedial plan</label>
                    <textarea name="remedial_plan" id="cqi_plan" class="form-control" rows="3" required maxlength="<?php echo CQI_TEXT_MAX; ?>"
                              placeholder="e.g. Extra tutorial in week 9 and a practice quiz before the final exam."></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="log_cqi" class="btn btn-primary">Save Action</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require '../includes/onboarding.php'; ?>
<script>
document.getElementById('rescheduleModal').addEventListener('show.bs.modal', function (e) {
    document.getElementById('reschedule_sid').value = e.relatedTarget.dataset.sid;
    document.getElementById('reschedule_student').textContent = e.relatedTarget.dataset.student;
});
document.getElementById('cqiModal').addEventListener('show.bs.modal', function (e) {
    const d = e.relatedTarget.dataset;
    document.getElementById('cqi_clo').value         = d.clo;
    document.getElementById('cqi_title').textContent = d.title;
    document.getElementById('cqi_cause').value       = d.cause;
    document.getElementById('cqi_plan').value        = d.plan;
});
</script>
<?php $page_scripts = ob_get_clean(); require '../includes/page_end.php'; ?>
