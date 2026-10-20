<?php
require '../includes/session_check.php';
require '../config/config.php';
require '../includes/semester.php';
require '../includes/grading.php';
require '../includes/early_warning.php';
require '../includes/obe.php';

$user_id =$_SESSION['user_id'];

$full_name  = $_SESSION['full_name'];
$first_name = explode(' ', trim($full_name))[0];
$initials   = strtoupper(substr($first_name, 0, 1) . substr(explode(' ', trim($full_name))[1] ?? '', 0, 1));
$active_semester = h(get_active_semester_name($conn));

// ── SECURE QUERY: Fetch Student ID & Name ──────────────────────────────
$stmt_student = mysqli_prepare($conn, "SELECT s.id, u.full_name FROM students s JOIN users u ON s.user_id = u.id WHERE s.user_id = ?");
mysqli_stmt_bind_param($stmt_student, "i", $user_id);
mysqli_stmt_execute($stmt_student);
$student = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_student));
if (!$student) {
    require '../includes/no_student_profile.php';
    exit();
}
$student_db_id =$student['id'];
$student_name  =$student['full_name'];
mysqli_stmt_close($stmt_student);

// Only the student's own active (non-archived) subjects this semester.
$semester_id = get_active_semester_id($conn);
$stmt_subs = mysqli_prepare($conn, "SELECT sub.id, sub.subject_code, sub.subject_name
                                    FROM student_enrollments e JOIN subjects sub ON sub.id = e.subject_id
                                    WHERE e.student_id = ? AND e.semester_id = ? AND sub.is_archived = 0
                                    ORDER BY sub.subject_name");
mysqli_stmt_bind_param($stmt_subs, "ii", $student_db_id, $semester_id);
mysqli_stmt_execute($stmt_subs);
$subjects = mysqli_stmt_get_result($stmt_subs);
mysqli_stmt_close($stmt_subs);

// ── SECURE AJAX: Fetch existing marks for selected subject ─────────────
// Final exam is left out on purpose: it's the value being "what-if"-ed.
if (isset($_GET['get_marks']) && isset($_GET['subject_id'])) {
    $subject_id = (int)$_GET['subject_id'];

    $stmt_ajax = mysqli_prepare($conn, "SELECT p.assignment, p.midterm, p.attendance FROM performance p JOIN student_enrollments e ON e.id = p.enrollment_id
                                         WHERE e.student_id = ? AND e.subject_id = ? AND e.semester_id = ?");
    mysqli_stmt_bind_param($stmt_ajax, "iii", $student_db_id, $subject_id, $semester_id);
    mysqli_stmt_execute($stmt_ajax);
    $marks = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_ajax));
    mysqli_stmt_close($stmt_ajax);

    // The subject's learning outcomes, so the page can show how a
    // hypothetical final exam score moves each one (includes/obe.php).
    $clos = [];
    foreach (obe_subject_outcomes($conn, [$subject_id])[$subject_id] ?? [] as $clo) {
        $clos[] = [
            'code'       => $clo['clo_code'],
            'label'      => obe_clo_label($clo),
            'plo'        => $clo['plo_code'] . ' ' . $clo['plo_title'],
            'components' => $clo['components'],
            'threshold'  => $clo['threshold'],
        ];
    }

    header('Content-Type: application/json');
    echo json_encode(($marks ?: ['assignment' => '', 'midterm' => '', 'attendance' => '']) + ['clos' => $clos]);
    exit();
}
?>
<?php
// Shared head, topbar and sidebar (includes/page_start.php); only this
// page's own CSS is here.
$page_title      = 'What-If Calculator';
$page_own_alerts = true;   // this page places its own messages
$page_styles     = <<<'CSS'
        .container { position: relative; z-index: 100; }

        /* ── Dashboard Cards & Tables ───────────────────────────────────── */
        .dashboard-card {
            background: #fff;
            border-radius: 12px;
            padding: 24px;
            box-shadow: var(--spts-card-shadow); border: 1px solid var(--spts-card-border);
            /* Removed height: 100% to fix the stretching bug */
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

        /* ── Forms & Calculator ─────────────────────────────────────────── */
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

        .input-target {
            border: 2px solid #0D7377 !important;
            background: #fff !important;
        }

        .locked-field {
            background: #F0FDF4 !important;
            border-color: #059669 !important;
            color: #065F46 !important;
            font-weight: 600;
        }
        .locked-label { color: #059669; font-size: 0.75rem; font-weight: 600; }
        /* Shared rules this page does not use (keeps its original look). */
        .dashboard-card { margin-bottom: 0; }
        .form-label { font-weight: 400; }
        .table > tbody > tr > td { vertical-align: inherit; font-size: inherit; color: var(--bs-table-color-state, var(--bs-table-color-type, var(--bs-table-color))); padding: .5rem .5rem; border-color: inherit; }
CSS;
require '../includes/page_start.php';
?>
    <div class="container mb-5" style="max-width:100%; margin-left:0;">
<div class="row g-4">

    <!-- Left: Calculator Form -->
    <div class="col-md-7">
    <div class="dashboard-card">
        <div class="card-header-title">
            <i class="bi bi-calculator text-primary"></i>What-If Score Calculator
        </div>
        <p class="text-muted mb-4" style="font-size:0.88rem;">
            Select a subject and the system will pre-fill your existing marks.
            Enter a hypothetical Final Exam score to see your projected result.
            <strong style="color:#1A3A5C;">Nothing is saved.</strong>
        </p>

        <div class="mb-4">
            <label class="form-label fw-semibold">Subject</label>
            <select id="subject_select" class="form-select" onchange="loadExistingMarks(this.value)">
                <option value="">-- Select Subject --</option>
                <?php while ($sub = mysqli_fetch_assoc($subjects)): ?>
                    <option value="<?php echo $sub['id']; ?>">
                        <?php echo h($sub['subject_code']) . ' — ' . h($sub['subject_name']); ?>
                    </option>
                <?php endwhile; ?>
            </select>
            <small class="text-muted mt-1 d-block" style="font-size: 0.78rem;">
                <i class="bi bi-lightning-fill text-warning"></i>
                Selecting a subject auto-fills your existing marks from the database.
            </small>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-6">
                <label class="form-label fw-semibold">
                    Assignment <span style="color:#7DD3D6; font-size:0.75rem;">(×20%)</span>
                    <span class="locked-label ms-1" id="label_assignment" style="display:none;">
                        <i class="bi bi-lock-fill"></i> Database
                    </span>
                </label>
                <input type="number" id="c_assignment" class="form-control"
                       min="0" max="100" step="0.01"
                       placeholder="0 – 100" oninput="calcWhatIf()">
            </div>
            <div class="col-6">
                <label class="form-label fw-semibold">
                    Midterm <span style="color:#7DD3D6; font-size:0.75rem;">(×30%)</span>
                    <span class="locked-label ms-1" id="label_midterm" style="display:none;">
                        <i class="bi bi-lock-fill"></i> Database
                    </span>
                </label>
                <input type="number" id="c_midterm" class="form-control"
                       min="0" max="100" step="0.01"
                       placeholder="0 – 100" oninput="calcWhatIf()">
            </div>
            <div class="col-6">
                <label class="form-label fw-semibold" style="color:#0D7377;">
                    Final Exam <span style="color:#7DD3D6; font-size:0.75rem;">(×40%)</span>
                </label>
                <input type="number" id="c_final" class="form-control input-target"
                       min="0" max="100" step="0.01"
                       placeholder="Hypothetical score" oninput="calcWhatIf()">
                <small style="color:#0D7377; font-size:0.75rem; font-weight:600;" class="mt-1 d-block">
                    <i class="bi bi-pencil-fill me-1"></i>Type target score here
                </small>
            </div>
            <div class="col-6">
                <label class="form-label fw-semibold">
                    Attendance <span style="color:#7DD3D6; font-size:0.75rem;">(×10%)</span>
                    <span class="locked-label ms-1" id="label_attendance" style="display:none;">
                        <i class="bi bi-lock-fill"></i> Database
                    </span>
                </label>
                <input type="number" id="c_attendance" class="form-control"
                       min="0" max="100" step="0.01"
                       placeholder="0 – 100" oninput="calcWhatIf()">
            </div>
        </div>

        <div id="be-warning" class="alert py-2 mb-4" style="display:none; font-size:0.88rem; background:#F9E5E5; border:1px solid #EECACA; color:#9B2C2C; border-radius:8px;">
            <i class="bi bi-exclamation-octagon-fill me-1"></i>
            Attendance is below <?php echo ATTENDANCE_BAR; ?>%. You may be <strong>barred from the final exam (BE)</strong>,
            whatever your other marks — speak to your lecturer now.
        </div>

        <div class="card border-0 p-3 mb-4" style="background:#F8FAFC; border-radius:10px;">
            <h6 class="fw-bold mb-1" style="color:#1A3A5C;">
                <i class="bi bi-bullseye me-1 text-primary"></i>
                What Final Exam score do I need to Pass?
            </h6>
            <p class="text-muted mb-2" style="font-size:0.83rem;">
                Fill Assignment, Midterm and Attendance — leave Final Exam blank.
            </p>
            <div id="min-final-box" style="display:none;">
                <div class="alert py-2 mb-0" id="min-final-alert" style="font-size:0.88rem;">
                    <span id="min-final-text"></span>
                </div>
            </div>
        </div>

        <div class="p-3 rounded" style="background:#F1F5F9;">
            <small class="text-muted d-block" style="line-height: 1.6;">
                <strong style="color:#1A3A5C;">Scoring Formula:</strong><br>
                Assignment×20% + Midterm×30% + Final Exam×40% + Attendance×10%<br>
                <span style="color:#198754;">≥80 Excellent</span> &nbsp;|&nbsp;
                <span style="color:#2563EB;">≥60 Pass</span> &nbsp;|&nbsp;
                <span style="color:#F59E0B;">≥50 At Risk</span> &nbsp;|&nbsp;
                <span style="color:#DC2626;">&lt;50 Fail</span>
            </small>
        </div>
    </div>
    </div>

    <!-- Right: Live Result Card -->
    <div class="col-md-5">
        <div class="dashboard-card d-flex flex-column mb-4">
            <div class="card-header-title justify-content-center mb-4">
                <i class="bi bi-graph-up text-primary"></i>Projected Result
            </div>

            <div id="result-empty" class="text-center py-5 text-muted my-auto">
                <i class="bi bi-calculator" style="font-size:3.5rem; opacity:0.2;"></i>
                <p class="mt-3 mb-0">Enter marks on the left to see<br>your projected result here.</p>
            </div>

            <div id="result-box" style="display:none;">
                <div class="text-center mb-4">
                    <p class="text-muted mb-1 fw-semibold" style="font-size:0.85rem;">Projected Total Score</p>
                    <h1 id="result-score" class="fw-bold display-3 mb-0" style="color:#1A3A5C;">0%</h1>
                </div>

                <div class="progress mb-4" style="height:14px; border-radius:10px; background:#F1F5F9;">
                    <div id="result-bar" class="progress-bar fw-bold"
                         style="width:0%; font-size:0.75rem; transition: width 0.5s ease, background 0.4s ease; border-radius:10px;">
                    </div>
                </div>

                <div class="text-center mb-4">
                    <span id="result-badge" class="badge px-4 py-2"
                          style="border-radius:30px; font-size:1.1rem; font-weight:600;">—</span>
                </div>

                <div id="result-advice-box" class="alert py-2 text-center mb-4"
                     style="font-size:0.88rem; border-radius:8px;">
                    <span id="result-advice"></span>
                </div>

                <div class="card border-0 p-3 mt-auto" style="background:#F8FAFC; border-radius:10px;">
                    <small class="fw-bold mb-2 d-block" style="color:#1A3A5C;">Score Breakdown</small>
                    <div class="row text-center g-2" style="font-size:0.82rem;">
                        <div class="col-3 border-end">
                            <div class="text-muted mb-1">Assign.</div>
                            <div class="fw-bold" style="color:#1A3A5C;" id="b_assignment">—</div>
                        </div>
                        <div class="col-3 border-end">
                            <div class="text-muted mb-1">Midterm</div>
                            <div class="fw-bold" style="color:#1A3A5C;" id="b_midterm">—</div>
                        </div>
                        <div class="col-3 border-end">
                            <div class="text-muted mb-1">Final</div>
                            <div class="fw-bold" style="color:#1A3A5C;" id="b_final">—</div>
                        </div>
                        <div class="col-3">
                            <div class="text-muted mb-1">Attend.</div>
                            <div class="fw-bold" style="color:#1A3A5C;" id="b_attendance">—</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Learning outcome impact -->
        <div class="dashboard-card mb-4" id="clo-card" style="display:none;">
            <div class="card-header-title mb-2">
                <i class="bi bi-diagram-3 text-primary"></i>Learning Outcome Impact
            </div>
            <p class="text-muted mb-3" style="font-size:0.8rem;">
                How these marks score on each course learning outcome (CLO). A CLO is attained at its target score.
            </p>
            <div id="clo-list"></div>
        </div>

        <!-- Official Results -->
        <div class="dashboard-card p-0 overflow-hidden">
            <div class="p-4 border-bottom">
                <div class="card-header-title mb-1">
                    <i class="bi bi-clipboard-check text-primary"></i>My Official Results
                </div>
                <p class="text-muted mb-0" style="font-size:0.82rem;">
                    Entered by your lecturer. Read-only.
                </p>
            </div>

            <?php
            // ── SECURE QUERY: Fetch Official Results ───────────────────────
            $stmt_off = mysqli_prepare($conn,
                "SELECT p.*, sub.subject_name, sub.subject_code, pr.total_score, pr.predicted_status
                 FROM performance p
                 JOIN student_enrollments e ON e.id = p.enrollment_id
                 JOIN subjects sub ON e.subject_id = sub.id
                 LEFT JOIN predictions pr ON pr.student_id = e.student_id AND pr.subject_id = e.subject_id AND pr.semester_id = e.semester_id
                 WHERE e.student_id = ? AND e.semester_id = ?");
            mysqli_stmt_bind_param($stmt_off, "ii", $student_db_id, $semester_id);
            mysqli_stmt_execute($stmt_off);
            $official = mysqli_stmt_get_result($stmt_off);

            if (mysqli_num_rows($official) > 0): ?>
            <div class="table-responsive">
            <table class="table mb-0 align-middle" style="font-size:0.85rem;">
                <thead class="table-custom-header">
                    <tr>
                        <th class="ps-4">Subject</th>
                        <th class="text-center">Total</th>
                        <th class="text-center pe-4">Result</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = mysqli_fetch_assoc($official)):
                        $status =$row['predicted_status'] ?? 'Pending';
                        $badge = status_badge_class($status);
                    ?>
                    <tr>
                        <td class="ps-4 py-3 border-bottom-0">
                            <strong style="color:#1A3A5C;"><?php echo h($row['subject_code']); ?></strong>
                        </td>
                        <td class="text-center fw-bold border-bottom-0" style="color:#1A3A5C;">
                            <?php echo $row['total_score'] !== null ? number_format($row['total_score'],1).'%' : '—'; ?>
                        </td>
                        <td class="text-center pe-4 border-bottom-0">
                            <span class="<?php echo $badge; ?>">
                                <?php echo h($status); ?>
                            </span>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
            </div>
            <?php else: ?>
                <div class="p-4 text-center text-muted" style="font-size:0.85rem;">
                    No official marks recorded yet.
                </div>
            <?php endif;
            mysqli_stmt_close($stmt_off);
            ?>
        </div>
    </div>

</div>
</div>

    </div>
<?php
// Modals and page scripts: page_end.php prints them after Bootstrap.
ob_start();
?>
<!-- Warning Modal -->
<div class="modal fade" id="alertModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0" style="border-radius:12px; overflow:hidden;">
            <div class="modal-header border-0" id="modal-header">
                <h5 class="modal-title fw-bold">⚠️ Warning</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center p-4">
                <div id="modal-icon" style="font-size:3.5rem; line-height:1;" class="mb-3"></div>
                <p id="modal-message" class="fs-6 fw-semibold text-dark mb-0"></p>
            </div>
            <div class="modal-footer border-0 justify-content-center pb-4">
                <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal" style="border-radius:8px;">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
function loadExistingMarks(subjectId) {
    const aField = document.getElementById('c_assignment');
    const mField = document.getElementById('c_midterm');
    const tField = document.getElementById('c_attendance');
    const fField = document.getElementById('c_final');

    [aField, mField, tField].forEach(f => {
        f.value = '';
        f.classList.remove('locked-field');
        f.readOnly = false;
    });
    ['label_assignment','label_midterm','label_attendance'].forEach(id => {
        document.getElementById(id).style.display = 'none';
    });
    fField.value = '';
    subjectClos = [];
    calcWhatIf();

    if (!subjectId) return;

    fetch(`predict.php?get_marks=1&subject_id=${subjectId}`)
        .then(r => r.json())
        .then(data => {
            subjectClos = data.clos || [];
            if (data.assignment !== '' && data.assignment !== null) {
                aField.value = data.assignment;
                aField.classList.add('locked-field');
                aField.readOnly = true;
                document.getElementById('label_assignment').style.display = 'inline';
            }
            if (data.midterm !== '' && data.midterm !== null) {
                mField.value = data.midterm;
                mField.classList.add('locked-field');
                mField.readOnly = true;
                document.getElementById('label_midterm').style.display = 'inline';
            }
            if (data.attendance !== '' && data.attendance !== null) {
                tField.value = data.attendance;
                tField.classList.add('locked-field');
                tField.readOnly = true;
                document.getElementById('label_attendance').style.display = 'inline';
            }
            calcWhatIf();
        });
}

// The selected subject's CLOs (from includes/obe.php via get_marks).
let subjectClos = [];
const MARK_WEIGHTS   = <?php echo json_encode(MARK_WEIGHTS); ?>;
const ATTENDANCE_BAR = <?php echo (int)ATTENDANCE_BAR; ?>;

// Each CLO's score is the weighted average of the components mapped to it;
// a CLO is shown as "not assessed" until all of them have a value.
function renderClos(values) {
    const card = document.getElementById('clo-card');
    const list = document.getElementById('clo-list');
    list.replaceChildren();
    card.style.display = subjectClos.length ? 'block' : 'none';
    const dark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
    subjectClos.forEach(clo => {
        let sum = 0, weight = 0, complete = clo.components.length > 0;
        clo.components.forEach(c => {
            if (values[c] === '') { complete = false; return; }
            sum += parseFloat(values[c]) * MARK_WEIGHTS[c];
            weight += MARK_WEIGHTS[c];
        });
        const score = complete ? sum / weight : null;
        const attained = score !== null && score >= clo.threshold;

        const row = document.createElement('div');
        row.className = 'mb-3';
        row.title = clo.label;
        const head = document.createElement('div');
        head.className = 'd-flex justify-content-between';
        head.style.fontSize = '0.82rem';
        const name = document.createElement('span');
        name.innerHTML = '<strong></strong> <span class="text-muted"></span>';
        name.children[0].textContent = clo.code;
        name.children[1].textContent = '· ' + clo.plo;
        const state = document.createElement('span');
        state.className = 'badge-status clo-pill badge-status-' + (score === null ? 'pending' : attained ? 'excellent' : 'risk');
        state.textContent = score === null ? 'Not assessed' : `${score.toFixed(1)}% ${attained ? '✓' : '✗'}`;
        head.append(name, state);

        const bar = document.createElement('div');
        bar.className = 'progress mt-1';
        bar.style.cssText = 'height:6px; position:relative;';
        const fill = document.createElement('div');
        fill.className = 'progress-bar';
        fill.style.width = (score === null ? 0 : Math.min(score, 100)) + '%';
        fill.style.background = attained ? (dark ? '#4FA67E' : '#2F7D5B') : (dark ? '#D2A24E' : '#B8862E');
        const mark = document.createElement('div');
        mark.style.cssText = `position:absolute; top:0; bottom:0; width:2px; left:${clo.threshold}%; background:${dark ? '#E2E8F0' : '#1A3A5C'};`;
        bar.append(fill, mark);
        row.append(head, bar);
        list.append(row);
    });
}

function calcWhatIf() {
    const aVal = document.getElementById('c_assignment').value;
    const mVal = document.getElementById('c_midterm').value;
    const fVal = document.getElementById('c_final').value;
    const tVal = document.getElementById('c_attendance').value;

    renderClos({ assignment: aVal, midterm: mVal, final_exam: fVal, attendance: tVal });
    document.getElementById('be-warning').style.display =
        (tVal !== '' && parseFloat(tVal) < ATTENDANCE_BAR) ? 'block' : 'none';

    const a = parseFloat(aVal) || 0;
    const m = parseFloat(mVal) || 0;
    const f = parseFloat(fVal) || 0;
    const t = parseFloat(tVal) || 0;

    const anyFilled = aVal !== '' || mVal !== '' || fVal !== '' || tVal !== '';
    const resultBox  = document.getElementById('result-box');
    const emptyBox   = document.getElementById('result-empty');

    if (!anyFilled) {
        resultBox.style.display = 'none';
        emptyBox.style.display  = 'block';
        return;
    }

    resultBox.style.display = 'block';
    emptyBox.style.display  = 'none';

    const total = (a * 0.20) + (m * 0.30) + (f * 0.40) + (t * 0.10);

    let status = '—', color = '#64748B', advice = '',
        adviceClass = 'alert-secondary', badgeText = 'text-white';

    if (total >= 80) {
        status = 'Excellent'; color = '#198754';
        advice = '🌟 Outstanding! You are on track for distinction.';
        adviceClass = 'alert-success border-success';
    } else if (total >= 60) {
        status = 'Pass'; color = '#2563EB';
        advice = '✅ You are on track to pass this subject.';
        adviceClass = 'alert-primary border-primary';
    } else if (total >= 50) {
        status = 'At Risk'; color = '#F59E0B';
        advice = '⚠️ You are At Risk. Focus on your Final Exam — it carries 40%.';
        adviceClass = 'alert-warning border-warning'; badgeText = 'text-dark';
    } else if (total > 0) {
        status = 'Fail'; color = '#DC2626';
        advice = '❌ Projected Fail. Speak to your lecturer for support.';
        adviceClass = 'alert-danger border-danger text-danger';
    }

    document.getElementById('result-score').textContent    = total.toFixed(2) + '%';
    document.getElementById('result-bar').style.width      = Math.min(total, 100) + '%';
    document.getElementById('result-bar').style.background = color;
    document.getElementById('result-bar').textContent      = total.toFixed(1) + '%';

    const badge = document.getElementById('result-badge');
    badge.textContent      = status;
    badge.className        = `badge fs-5 px-4 py-2 ${badgeText}`;
    badge.style.background = color;

    const advBox = document.getElementById('result-advice-box');
    advBox.className = `alert py-2 text-center mb-3 ${adviceClass}`;
    document.getElementById('result-advice').textContent = advice;

    document.getElementById('b_assignment').textContent = a > 0 ? (a*0.20).toFixed(1) : '—';
    document.getElementById('b_midterm').textContent    = m > 0 ? (m*0.30).toFixed(1) : '—';
    document.getElementById('b_final').textContent      = f > 0 ? (f*0.40).toFixed(1) : '—';
    document.getElementById('b_attendance').textContent = t > 0 ? (t*0.10).toFixed(1) : '—';

    const minBox   = document.getElementById('min-final-box');
    const minText  = document.getElementById('min-final-text');
    const minAlert = document.getElementById('min-final-alert');

    if (fVal === '' && (aVal !== '' || mVal !== '' || tVal !== '')) {
        const withoutFinal = (a*0.20) + (m*0.30) + (t*0.10);
        const needed = (60 - withoutFinal) / 0.40;
        if (needed <= 0) {
            minText.innerHTML  = '✅ You will <strong>Pass</strong> regardless of your Final Exam score.';
            minAlert.className = 'alert alert-success py-2 mb-0 border-success';
        } else if (needed <= 100) {
            minText.innerHTML  = `To <strong>Pass</strong>, you need at least <strong>${needed.toFixed(1)}%</strong> in your Final Exam.`;
            minAlert.className = needed > 80
                ? 'alert alert-danger py-2 mb-0 border-danger text-danger'
                : 'alert alert-warning py-2 mb-0 border-warning text-dark';
        } else {
            minText.innerHTML  = '❌ Even with 100% in Final Exam, you <strong>cannot Pass</strong>.';
            minAlert.className = 'alert alert-danger py-2 mb-0 border-danger text-danger';
        }
        minBox.style.display = 'block';
    } else {
        minBox.style.display = 'none';
    }

    const allFilled = aVal !== '' && mVal !== '' && fVal !== '' && tVal !== '';
    if (allFilled && total < 60) {
        const isAtRisk = total >= 50;
        const header   = document.getElementById('modal-header');
        const icon     = document.getElementById('modal-icon');
        const message  = document.getElementById('modal-message');

        header.className    = isAtRisk ? 'modal-header bg-warning border-0' : 'modal-header bg-danger text-white border-0';
        icon.textContent    = isAtRisk ? '⚠️' : '❌';
        message.textContent = isAtRisk
            ? 'You are At Risk! Focus on your Final Exam — it carries 40% of your grade.'
            : 'You are projected to Fail. Speak to your lecturer for academic support.';

        if (!window.lastStatus || window.lastStatus !== status) {
            new bootstrap.Modal(document.getElementById('alertModal')).show();
            window.lastStatus = status;
        }
    } else {
        window.lastStatus = status;
    }
}
</script>
<?php $page_scripts = ob_get_clean(); require '../includes/page_end.php'; ?>
