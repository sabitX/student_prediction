<?php
require '../includes/session_check.php';
require '../config/config.php';
require '../includes/csrf.php';
require '../includes/semester.php';
require '../includes/academic_level.php';
require '../includes/audit.php';

$error   = "";
require_once '../includes/flash.php';
$flash   = flash_take();
$success = $flash['success'] ?? "";

$semester_id = get_active_semester_id($conn);
$subjects    = mysqli_fetch_all(mysqli_query($conn, "SELECT id, subject_code, subject_name FROM subjects WHERE is_archived = 0 ORDER BY subject_code"), MYSQLI_ASSOC);
$subject_id  = (int)($_GET['subject_id'] ?? $_POST['subject_id'] ?? ($subjects[0]['id'] ?? 0));

$valid_subject = in_array($subject_id, array_map(fn($s) => (int)$s['id'], $subjects), true);
if (!$valid_subject) {
    $subject_id = 0;
}

// ── Save the enrolment list for one subject in the active semester ─────
// Students with marks already recorded this semester can't be removed,
// so a lecturer's work is never hidden by accident.
if (isset($_POST['save_enrolments'])) {
    if (!csrf_verify()) {
        $error = "Your session expired or the request could not be verified. Please try again.";
    } elseif (!$semester_id) {
        $error = "Activate a semester first (Semesters page).";
    } elseif (!$subject_id) {
        $error = "Choose a valid subject.";
    } else {
        $wanted = array_map('intval', (array)($_POST['students'] ?? []));

        $stmt = mysqli_prepare($conn, "SELECT student_id FROM student_enrollments WHERE subject_id = ? AND semester_id = ?");
        mysqli_stmt_bind_param($stmt, "ii", $subject_id, $semester_id);
        mysqli_stmt_execute($stmt);
        $current = array_map(fn($r) => (int)$r['student_id'], mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC));
        mysqli_stmt_close($stmt);

        $to_add    = array_diff($wanted, $current);
        $to_remove = array_diff($current, $wanted);
        $kept      = 0;

        mysqli_begin_transaction($conn);
        // INSERT ... SELECT only enrols ids that really are students.
        $stmt_add = mysqli_prepare($conn, "INSERT IGNORE INTO student_enrollments (student_id, subject_id, semester_id)
                                           SELECT id, ?, ? FROM students WHERE id = ?");
        foreach ($to_add as $sid) {
            mysqli_stmt_bind_param($stmt_add, "iii", $subject_id, $semester_id, $sid);
            mysqli_stmt_execute($stmt_add);
        }
        mysqli_stmt_close($stmt_add);

        $stmt_has = mysqli_prepare($conn, "SELECT 1 FROM performance WHERE student_id = ? AND subject_id = ? AND semester_id = ?");
        $stmt_del = mysqli_prepare($conn, "DELETE FROM student_enrollments WHERE student_id = ? AND subject_id = ? AND semester_id = ?");
        foreach ($to_remove as $sid) {
            mysqli_stmt_bind_param($stmt_has, "iii", $sid, $subject_id, $semester_id);
            mysqli_stmt_execute($stmt_has);
            if (mysqli_num_rows(mysqli_stmt_get_result($stmt_has)) > 0) {
                $kept++;
                continue;
            }
            mysqli_stmt_bind_param($stmt_del, "iii", $sid, $subject_id, $semester_id);
            mysqli_stmt_execute($stmt_del);
        }
        mysqli_stmt_close($stmt_has);
        mysqli_stmt_close($stmt_del);
        $removed = count($to_remove) - $kept;
        if ($to_add || $removed) {
            $code = '';
            foreach ($subjects as $s) { if ((int)$s['id'] === $subject_id) $code = $s['subject_code']; }
            audit_log($conn, 'Enrolments changed', "Subject: $code | " . count($to_add) . " enrolled, $removed removed");
        }
        mysqli_commit($conn);

        $success = count($to_add) . " enrolled, " . (count($to_remove) - $kept) . " removed.";
        if ($kept > 0) {
            $error = "$kept student(s) were kept because they already have marks this semester.";
        }
    }
}

redirect_after_success($success, $error, [], 'manage_enrollments.php?subject_id=' . $subject_id);

$students = [];
if ($subject_id && $semester_id) {
    $stmt = mysqli_prepare($conn, "SELECT s.id, s.student_id, s.academic_level, s.course, u.full_name,
                                          (e.id IS NOT NULL) AS enrolled,
                                          (p.id IS NOT NULL) AS has_marks
                                   FROM students s
                                   JOIN users u ON u.id = s.user_id
                                   LEFT JOIN student_enrollments e ON e.student_id = s.id AND e.subject_id = ? AND e.semester_id = ?
                                   LEFT JOIN performance p ON p.student_id = s.id AND p.subject_id = ? AND p.semester_id = ?
                                   ORDER BY u.full_name");
    mysqli_stmt_bind_param($stmt, "iiii", $subject_id, $semester_id, $subject_id, $semester_id);
    mysqli_stmt_execute($stmt);
    $students = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
}
$enrolled_count = count(array_filter($students, fn($s) => $s['enrolled']));

$page_title = 'Enrolments';
$page_scripts = <<<'JS'
<script>
// Search + level filter only hide rows; hidden checkboxes keep their state.
(function () {
    const search = document.getElementById('enrolSearch');
    const level  = document.getElementById('enrolLevel');
    if (!search) return;
    const rows = () => document.querySelectorAll('#enrolTable tbody tr');
    function apply() {
        const q = search.value.toLowerCase(), lv = level.value;
        rows().forEach(r => {
            r.style.display = (r.textContent.toLowerCase().includes(q) && (!lv || r.dataset.level === lv)) ? '' : 'none';
        });
    }
    search.addEventListener('input', apply);
    level.addEventListener('change', apply);
    document.getElementById('selectVisible').addEventListener('click', () => {
        rows().forEach(r => { if (r.style.display !== 'none') r.querySelector('input').checked = true; });
    });
    document.getElementById('clearVisible').addEventListener('click', () => {
        rows().forEach(r => { const c = r.querySelector('input'); if (r.style.display !== 'none' && !c.disabled) c.checked = false; });
    });
})();
</script>
JS;
require '../includes/page_start.php';
?>
        <div class="page-header">
            <div>
                <h2><i class="bi bi-person-check me-2"></i>Subject Enrolments</h2>
                <p>Choose which students take each subject in <strong><?php echo $active_semester; ?></strong>. Lecturers only see enrolled students.</p>
            </div>
        </div>

        <?php if (!$semester_id): ?>
            <div class="alert alert-warning">No semester is active. <a href="manage_semesters.php">Activate a semester</a> first.</div>
        <?php elseif (!$subjects): ?>
            <div class="alert alert-info">There are no active subjects yet. <a href="manage_subjects.php">Add a subject</a> first.</div>
        <?php else: ?>
        <div class="dashboard-card">
            <form method="GET" class="row g-2 align-items-end mb-4">
                <div class="col-md-6">
                    <label class="form-label" for="subject_id">Subject</label>
                    <select name="subject_id" id="subject_id" class="form-select" onchange="this.form.submit()">
                        <?php foreach ($subjects as $sub): ?>
                            <option value="<?php echo (int)$sub['id']; ?>" <?php echo (int)$sub['id'] === $subject_id ? 'selected' : ''; ?>>
                                <?php echo h($sub['subject_code'] . ' — ' . $sub['subject_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 text-md-end">
                    <span class="badge bg-primary fs-6"><?php echo $enrolled_count; ?> of <?php echo count($students); ?> students enrolled</span>
                </div>
            </form>

            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="subject_id" value="<?php echo $subject_id; ?>">
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <input type="text" id="enrolSearch" class="form-control form-control-sm" style="max-width:240px;" placeholder="Search name or ID...">
                    <select id="enrolLevel" class="form-select form-select-sm" style="max-width:200px;">
                        <option value="">All levels</option>
                        <?php foreach (['Diploma', 'Degree', 'Masters', 'PhD'] as $lv): ?>
                            <option value="<?php echo $lv; ?>"><?php echo h(academic_level_label($lv)); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" id="selectVisible" class="btn btn-sm btn-outline-secondary">Select shown</button>
                    <button type="button" id="clearVisible" class="btn btn-sm btn-outline-secondary">Clear shown</button>
                    <button type="submit" name="save_enrolments" class="btn btn-sm btn-primary-custom ms-auto">Save Enrolments</button>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="enrolTable">
                        <thead class="table-custom-header">
                            <tr><th style="width:50px;">Enrol</th><th>Student ID</th><th>Name</th><th>Level / Programme</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($students as $st): ?>
                            <tr data-level="<?php echo h($st['academic_level']); ?>">
                                <td>
                                    <?php if ($st['enrolled'] && $st['has_marks']): ?>
                                        <!-- Locked: has marks this semester, so it can't be un-enrolled. -->
                                        <input type="checkbox" class="form-check-input" checked disabled title="Has marks this semester — can't be removed" aria-label="<?php echo h($st['full_name']); ?> is enrolled (locked)">
                                        <input type="hidden" name="students[]" value="<?php echo (int)$st['id']; ?>">
                                    <?php else: ?>
                                        <input type="checkbox" class="form-check-input" name="students[]" value="<?php echo (int)$st['id']; ?>"
                                               aria-label="Enrol <?php echo h($st['full_name']); ?>" <?php echo $st['enrolled'] ? 'checked' : ''; ?>>
                                    <?php endif; ?>
                                </td>
                                <td class="fw-bold"><?php echo h($st['student_id']); ?></td>
                                <td><?php echo h($st['full_name']); ?><?php if ($st['has_marks']): ?> <span class="badge bg-light text-dark border">has marks</span><?php endif; ?></td>
                                <td class="small"><?php echo h(academic_level_label($st['academic_level'])); ?> · <?php echo h($st['course']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </form>
        </div>
        <?php endif; ?>
<?php require '../includes/page_end.php'; ?>
