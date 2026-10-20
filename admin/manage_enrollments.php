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
    } elseif (!isset($_POST['shown'])) {
        // Pages from before the list was paged don't say which students they
        // showed; saving one could un-enrol everyone else. Reload instead.
        $error = "This page was out of date. Please make your changes again.";
    } else {
        // The list is paged and searchable, so only the students that were on
        // screen ("shown") are changed. A student on another page is neither
        // enrolled nor removed just because their box wasn't in this form.
        $shown  = array_map('intval', (array)$_POST['shown']);
        $wanted = array_intersect(array_map('intval', (array)($_POST['students'] ?? [])), $shown);

        $stmt = mysqli_prepare($conn, "SELECT student_id FROM student_enrollments WHERE subject_id = ? AND semester_id = ?");
        mysqli_stmt_bind_param($stmt, "ii", $subject_id, $semester_id);
        mysqli_stmt_execute($stmt);
        $current = array_map(fn($r) => (int)$r['student_id'], mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC));
        mysqli_stmt_close($stmt);

        $to_add    = array_diff($wanted, $current);
        $to_remove = array_diff(array_intersect($current, $shown), $wanted);
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

        $stmt_has = mysqli_prepare($conn, "SELECT 1 FROM performance p JOIN student_enrollments e ON e.id = p.enrollment_id WHERE e.student_id = ? AND e.subject_id = ? AND e.semester_id = ?");
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

// ── One page of students, optionally searched / filtered by level ──────
require '../includes/pagination.php';
$list  = list_params();
$level = in_array($_GET['level'] ?? '', ['Diploma', 'Degree', 'Masters', 'PhD'], true) ? $_GET['level'] : '';

// Back to the same subject, search and page after saving.
$self_url = 'manage_enrollments.php?' . http_build_query(array_filter(
    ['subject_id' => $subject_id, 'q' => $list['q'], 'level' => $level, 'page' => $list['page'] > 1 ? $list['page'] : null],
    fn($v) => $v !== null && $v !== ''
));
redirect_after_success($success, $error, [], $self_url);

$students       = [];
$students_total = 0;
$enrolled_count = 0;
$all_students   = 0;
if ($subject_id && $semester_id) {
    // Whole-subject figures for the badge, whatever page is showing.
    $stmt = mysqli_prepare($conn, "SELECT (SELECT COUNT(*) FROM students) AS all_students,
                                          (SELECT COUNT(*) FROM student_enrollments WHERE subject_id = ? AND semester_id = ?) AS enrolled");
    mysqli_stmt_bind_param($stmt, "ii", $subject_id, $semester_id);
    mysqli_stmt_execute($stmt);
    $counts = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    $all_students   = (int)$counts['all_students'];
    $enrolled_count = (int)$counts['enrolled'];

    // Conditions added only when used (comparing a bound value with '' in
    // SQL can fail with "Illegal mix of collations" on MariaDB).
    $conds  = [];
    $types  = '';
    $params = [];
    if ($list['q'] !== '') {
        $conds[] = "(u.full_name LIKE ? OR s.student_id LIKE ?)";
        $types  .= 'ss';
        array_push($params, like_pattern($list['q']), like_pattern($list['q']));
    }
    if ($level !== '') {
        $conds[] = "c.academic_level = ?";
        $types  .= 's';
        $params[] = $level;
    }
    $filter = "FROM students s JOIN users u ON u.id = s.user_id JOIN courses c ON c.id = s.course_id" . ($conds ? ' WHERE ' . implode(' AND ', $conds) : '');

    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS n $filter");
    if ($params) mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $students_total = (int)mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['n'];
    mysqli_stmt_close($stmt);
    $list = list_clamp($list, $students_total);

    $stmt = mysqli_prepare($conn, "SELECT s.id, s.student_id, c.academic_level, c.course_name AS course, u.full_name,
                                          EXISTS(SELECT 1 FROM student_enrollments e WHERE e.student_id = s.id AND e.subject_id = ? AND e.semester_id = ?) AS enrolled,
                                          EXISTS(SELECT 1 FROM performance p JOIN student_enrollments pe ON pe.id = p.enrollment_id WHERE pe.student_id = s.id AND pe.subject_id = ? AND pe.semester_id = ?) AS has_marks
                                   $filter
                                   ORDER BY u.full_name
                                   LIMIT ? OFFSET ?");
    mysqli_stmt_bind_param($stmt, "iiii{$types}ii", $subject_id, $semester_id, $subject_id, $semester_id,
                           ...[...$params, $list['limit'], $list['offset']]);
    mysqli_stmt_execute($stmt);
    $students = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
}

$page_title = 'Enrolments';
$page_scripts = <<<'JS'
<script>
// Typing filters the rows on this page (hidden checkboxes keep their state);
// Enter, or changing the level, reloads with a search of every student.
(function () {
    const search = document.getElementById('enrolSearch');
    if (!search) return;
    const rows = () => document.querySelectorAll('#enrolTable tbody tr');
    search.addEventListener('input', function () {
        const q = search.value.toLowerCase();
        rows().forEach(r => { r.style.display = r.textContent.toLowerCase().includes(q) ? '' : 'none'; });
    });
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
                    <span class="badge bg-primary fs-6"><?php echo $enrolled_count; ?> of <?php echo $all_students; ?> students enrolled</span>
                </div>
                <!-- Enter in the search box, or a level change, searches every student. -->
                <div class="col-12 d-flex flex-wrap gap-2 mt-3">
                    <input type="search" name="q" id="enrolSearch" value="<?php echo h($list['q']); ?>" aria-label="Search students" class="form-control form-control-sm" style="max-width:240px;" placeholder="Search name or ID...">
                    <select name="level" id="enrolLevel" aria-label="Academic level" class="form-select form-select-sm" style="max-width:200px;" onchange="this.form.submit()">
                        <option value="">All levels</option>
                        <?php foreach (['Diploma', 'Degree', 'Masters', 'PhD'] as $lv): ?>
                            <option value="<?php echo $lv; ?>" <?php echo $level === $lv ? 'selected' : ''; ?>><?php echo h(academic_level_label($lv)); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($list['q'] !== '' || $level !== ''): ?>
                        <a href="manage_enrollments.php?subject_id=<?php echo $subject_id; ?>" class="btn btn-sm btn-link">Clear search</a>
                    <?php endif; ?>
                </div>
            </form>

            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="subject_id" value="<?php echo $subject_id; ?>">
                <div class="d-flex flex-wrap gap-2 mb-3">
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
                                    <!-- Saving only changes the students listed here. -->
                                    <input type="hidden" name="shown[]" value="<?php echo (int)$st['id']; ?>">
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
                <?php if (!$students): ?>
                    <div class="empty-state">No students match this search.</div>
                <?php endif; ?>
            </form>
            <?php render_pagination($list, $students_total); ?>
        </div>
        <?php endif; ?>
<?php require '../includes/page_end.php'; ?>
