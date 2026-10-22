<?php
require '../includes/session_check.php';
require '../config/config.php';
require '../includes/audit.php';
require '../includes/csrf.php';
require '../includes/semester.php';

$error   = "";
require_once '../includes/flash.php';
$flash   = flash_take();
$success = $flash['success'] ?? "";

// Accepts a YYYY-MM-DD string from an <input type="date">.
function valid_date($d) {
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt && $dt->format('Y-m-d') === $d;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = "Your session expired or the request could not be verified. Please try again.";

    // ── Add a semester ─────────────────────────────────────────────────
    } elseif (isset($_POST['add_semester'])) {
        $name  = trim($_POST['semester_name'] ?? '');
        $start = $_POST['start_date'] ?? '';
        $end   = $_POST['end_date'] ?? '';

        if ($name === '' || !valid_date($start) || !valid_date($end)) {
            $error = "Semester name, start date and end date are required.";
        } elseif (mb_strlen($name) > 50) {
            $error = "Semester name is too long (max 50 characters).";
        } elseif ($end <= $start) {
            $error = "The end date must be after the start date.";
        } else {
            $stmt = mysqli_prepare($conn, "SELECT id FROM semesters WHERE semester_name = ?");
            mysqli_stmt_bind_param($stmt, "s", $name);
            mysqli_stmt_execute($stmt);
            $dupe = mysqli_num_rows(mysqli_stmt_get_result($stmt)) > 0;
            mysqli_stmt_close($stmt);

            if ($dupe) {
                $error = "A semester with that name already exists.";
            } else {
                $stmt = mysqli_prepare($conn, "INSERT INTO semesters (semester_name, start_date, end_date, is_active) VALUES (?, ?, ?, 0)");
                mysqli_stmt_bind_param($stmt, "sss", $name, $start, $end);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                audit_log($conn, 'Semester added', "Semester: $name ($start to $end)");
                $success = "Semester \"$name\" added. Activate it when it starts.";
            }
        }

    // ── Make a semester the active one (exactly one is active) ──────────
    } elseif (isset($_POST['activate_id'])) {
        $id = (int)$_POST['activate_id'];
        mysqli_begin_transaction($conn);
        $prev_id = get_active_semester_id($conn);
        mysqli_query($conn, "UPDATE semesters SET is_active = 0");
        $stmt = mysqli_prepare($conn, "UPDATE semesters SET is_active = 1 WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);
        $found = mysqli_stmt_affected_rows($stmt) > 0;
        mysqli_stmt_close($stmt);
        if ($found) {
            $sem_name = mysqli_fetch_assoc(mysqli_query($conn, "SELECT semester_name FROM semesters WHERE id = " . (int)$id))['semester_name'] ?? "id $id";
            // Lecturers are assigned per semester. A semester with no
            // assignments yet starts with the previous semester's, so
            // lecturers keep their subjects until an admin changes them.
            $copied = 0;
            $has_any = mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM lecturer_subjects WHERE semester_id = " . (int)$id))[0] > 0;
            if (!$has_any && $prev_id > 0 && $prev_id !== $id) {
                $stmt = mysqli_prepare($conn, "INSERT INTO lecturer_subjects (user_id, subject_id, semester_id)
                                               SELECT user_id, subject_id, ? FROM lecturer_subjects WHERE semester_id = ?");
                mysqli_stmt_bind_param($stmt, "ii", $id, $prev_id);
                mysqli_stmt_execute($stmt);
                $copied = mysqli_stmt_affected_rows($stmt);
                mysqli_stmt_close($stmt);
            }
            // Assessment weights carry over the same way, but only to subjects
            // with no weights and no marks yet in this semester, so going
            // back to an earlier semester never changes its results.
            $weights_copied = 0;
            if ($prev_id > 0 && $prev_id !== $id) {
                $stmt = mysqli_prepare($conn, "INSERT IGNORE INTO subject_weights (subject_id, semester_id, weight_assignment, weight_midterm, weight_final_exam, weight_attendance)
                                               SELECT w.subject_id, ?, w.weight_assignment, w.weight_midterm, w.weight_final_exam, w.weight_attendance
                                               FROM subject_weights w
                                               WHERE w.semester_id = ?
                                                 AND NOT EXISTS (SELECT 1 FROM performance p JOIN student_enrollments e ON e.id = p.enrollment_id
                                                                 WHERE e.subject_id = w.subject_id AND e.semester_id = ?)");
                mysqli_stmt_bind_param($stmt, "iii", $id, $prev_id, $id);
                mysqli_stmt_execute($stmt);
                $weights_copied = mysqli_stmt_affected_rows($stmt);
                mysqli_stmt_close($stmt);
            }
            audit_log($conn, 'Active semester changed', "Semester: $sem_name" . ($copied ? " | Lecturer assignments carried over: $copied" : '')
                . ($weights_copied ? " | Assessment weights carried over: $weights_copied" : ''));
            mysqli_commit($conn);
            $success = "Active semester changed. Marks, enrolments and dashboards now use it; earlier semesters stay on students' transcripts."
                     . ($copied ? " $copied lecturer assignment" . ($copied == 1 ? '' : 's') . " carried over from the previous semester — change them under Subjects." : '');
        } else {
            mysqli_rollback($conn);
            $error = "Semester not found.";
        }

    // ── Delete a semester that has no academic data ─────────────────────
    } elseif (isset($_POST['delete_id'])) {
        $id = (int)$_POST['delete_id'];
        $stmt = mysqli_prepare($conn, "SELECT is_active,
                    (SELECT COUNT(*) FROM performance p JOIN student_enrollments e ON e.id = p.enrollment_id WHERE e.semester_id = s.id) +
                    (SELECT COUNT(*) FROM student_enrollments WHERE semester_id = s.id) AS used
                FROM semesters s WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);
        $sem = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if (!$sem) {
            $error = "Semester not found.";
        } elseif ($sem['is_active']) {
            $error = "The active semester can't be deleted.";
        } elseif ($sem['used'] > 0) {
            $error = "This semester has enrolments or marks, so it can't be deleted.";
        } else {
            $sem_name = mysqli_fetch_assoc(mysqli_query($conn, "SELECT semester_name FROM semesters WHERE id = " . (int)$id))['semester_name'] ?? "id $id";
            $stmt = mysqli_prepare($conn, "DELETE FROM semesters WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "i", $id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            audit_log($conn, 'Semester deleted', "Semester: $sem_name");
            $success = "Semester deleted.";
        }
    }
}

redirect_after_success($success, $error);

$semesters = mysqli_query($conn, "SELECT s.*,
        (SELECT COUNT(*) FROM student_enrollments e WHERE e.semester_id = s.id) AS enrolments,
        (SELECT COUNT(*) FROM performance p JOIN student_enrollments pe ON pe.id = p.enrollment_id WHERE pe.semester_id = s.id) AS mark_rows
    FROM semesters s ORDER BY s.start_date DESC");

$page_title = 'Semesters';
require '../includes/page_start.php';
?>
        <div class="page-header">
            <div>
                <h2><i class="bi bi-calendar3 me-2"></i>Semesters</h2>
                <p>Marks, results and enrolments are recorded against the active semester.</p>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-4">
                <div class="dashboard-card">
                    <div class="card-header-title"><i class="bi bi-plus-circle"></i>Add Semester</div>
                    <form method="POST">
                        <?php echo csrf_field(); ?>
                        <div class="mb-3">
                            <label class="form-label" for="semester_name">Semester Name</label>
                            <input type="text" name="semester_name" id="semester_name" class="form-control" placeholder="e.g. January 2027" maxlength="50" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="start_date">Start Date</label>
                            <input type="date" name="start_date" id="start_date" class="form-control" required>
                        </div>
                        <div class="mb-4">
                            <label class="form-label" for="end_date">End Date</label>
                            <input type="date" name="end_date" id="end_date" class="form-control" required>
                        </div>
                        <button type="submit" name="add_semester" class="btn btn-primary-custom w-100">Add Semester</button>
                    </form>
                </div>
            </div>

            <div class="col-lg-8">
                <div class="dashboard-card">
                    <div class="card-header-title"><i class="bi bi-list-ul"></i>All Semesters</div>
                    <div class="table-responsive">
                        <table class="table mb-0">
                            <thead class="table-custom-header">
                                <tr><th>Semester</th><th>Dates</th><th class="text-center">Enrolments</th><th class="text-center">Mark Records</th><th class="text-end">Action</th></tr>
                            </thead>
                            <tbody>
                            <?php while ($s = mysqli_fetch_assoc($semesters)): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo h($s['semester_name']); ?></strong>
                                        <?php if ($s['is_active']): ?><span class="badge bg-success ms-1">Active</span><?php endif; ?>
                                    </td>
                                    <td class="small"><?php echo date('d M Y', strtotime($s['start_date'])); ?> – <?php echo date('d M Y', strtotime($s['end_date'])); ?></td>
                                    <td class="text-center"><?php echo (int)$s['enrolments']; ?></td>
                                    <td class="text-center"><?php echo (int)$s['mark_rows']; ?></td>
                                    <td class="text-end text-nowrap">
                                        <?php if ($s['enrolments'] > 0): ?>
                                            <div class="btn-group btn-group-sm me-1" role="group" aria-label="Downloads for <?php echo h($s['semester_name']); ?>">
                                                <a href="export.php?type=results&amp;semester_id=<?php echo (int)$s['id']; ?>" class="btn btn-outline-secondary" title="Every enrolment's marks, total and status"><i class="bi bi-download me-1"></i>Results</a>
                                                <a href="export.php?type=at_risk&amp;semester_id=<?php echo (int)$s['id']; ?>" class="btn btn-outline-secondary" title="At Risk / Fail results and early warnings">At-risk</a>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!$s['is_active']): ?>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Make this the active semester? New marks and enrolments will go into it.');">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="activate_id" value="<?php echo (int)$s['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-success">Set Active</button>
                                            </form>
                                            <?php if ($s['enrolments'] + $s['mark_rows'] == 0): ?>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Delete this semester?');">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="delete_id" value="<?php echo (int)$s['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                            </form>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted small">Current</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
<?php require '../includes/page_end.php'; ?>
