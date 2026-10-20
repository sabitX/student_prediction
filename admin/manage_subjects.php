<?php
require '../includes/session_check.php';
require '../config/config.php';
require '../includes/csrf.php';
require '../includes/semester.php';
require '../includes/audit.php';
require '../includes/obe.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: ../auth/login.php");
    exit();
}

$full_name  = $_SESSION['full_name'];
$first_name = explode(' ', trim($full_name))[0];
$initials   = strtoupper(substr($first_name, 0, 1) . substr(explode(' ', trim($full_name))[1] ?? '', 0, 1));
$active_semester = h(get_active_semester_name($conn));

$error   = "";
require_once '../includes/flash.php';
$flash   = flash_take();
$success = $flash['success'] ?? "";

// Assigns (or, with 0, removes) the one lecturer for a subject in the
// active semester — a lecturer teaches a subject in a given semester
// (lecturer_subjects.semester_id, migration 010).
// Returns an error message, or '' on success.
function set_subject_lecturer($conn, $subject_id, $lecturer_id) {
    $semester_id = get_active_semester_id($conn);
    if ($semester_id === 0) {
        return $lecturer_id > 0 ? "Set an active semester (Semesters) before assigning lecturers." : '';
    }
    if ($lecturer_id > 0) {
        $stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE id = ? AND role = 'lecturer'");
        mysqli_stmt_bind_param($stmt, "i", $lecturer_id);
        mysqli_stmt_execute($stmt);
        $is_lecturer = mysqli_num_rows(mysqli_stmt_get_result($stmt)) > 0;
        mysqli_stmt_close($stmt);
        if (!$is_lecturer) {
            return "Invalid lecturer selected.";
        }
    }
    $stmt = mysqli_prepare($conn, "DELETE FROM lecturer_subjects WHERE subject_id = ? AND semester_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $subject_id, $semester_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    if ($lecturer_id > 0) {
        $stmt = mysqli_prepare($conn, "INSERT INTO lecturer_subjects (user_id, subject_id, semester_id) VALUES (?, ?, ?)");
        mysqli_stmt_bind_param($stmt, "iii", $lecturer_id, $subject_id, $semester_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
    return '';
}

// Credit hours from a form field: 1.0–6.0 in steps of 0.5, else null.
function parse_credit_hours($raw) {
    $raw = trim((string)$raw);
    if (!is_numeric($raw)) return null;
    $v = (float)$raw;
    return ($v >= 1 && $v <= 6 && fmod($v * 2, 1) == 0) ? $v : null;
}

// The active (not archived) subject with this id, or null.
function active_subject($conn, $subject_id) {
    $stmt = mysqli_prepare($conn, "SELECT id, subject_code, subject_name FROM subjects WHERE id = ? AND is_archived = 0");
    mysqli_stmt_bind_param($stmt, "i", $subject_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $row;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    $error = "Your session expired or the request could not be verified. Please try again.";

// ── Unassign the lecturer from a subject ───────────────────────────────
} elseif (isset($_POST['unassign_lecturer'])) {
    $subject = active_subject($conn, (int)($_POST['unassign_subject_id'] ?? 0));
    if (!$subject) {
        $error = "Subject not found.";
    } else {
        set_subject_lecturer($conn, (int)$subject['id'], 0);
        audit_log($conn, 'Subject lecturer unassigned', "Subject: {$subject['subject_code']}");
        $success = "Lecturer successfully unassigned.";
    }

// ── Add a brand-new subject ────────────────────────────────────────────
// Subject codes are unique across active AND archived subjects, so an
// existing code is reported clearly instead of silently changing that
// subject or failing on the database's UNIQUE index.
} elseif (isset($_POST['add_subject'])) {
    $code        = strtoupper(trim($_POST['subject_code'] ?? ''));
    $name        = trim($_POST['subject_name'] ?? '');
    $credits     = parse_credit_hours($_POST['credit_hours'] ?? '');
    $lecturer_id = (int)($_POST['lecturer_id'] ?? 0);

    $stmt_check = mysqli_prepare($conn, "SELECT id, is_archived FROM subjects WHERE subject_code = ?");
    mysqli_stmt_bind_param($stmt_check, "s", $code);
    mysqli_stmt_execute($stmt_check);
    $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_check));
    mysqli_stmt_close($stmt_check);

    if (!preg_match('/^[A-Z0-9]{3,20}$/', $code) || $name === '') {
        $error = "Enter a subject code (3–20 letters/digits, e.g. EC2301) and a subject name.";
    } elseif (mb_strlen($name) > 100) {
        $error = "Subject name is too long (max 100 characters).";
    } elseif ($credits === null) {
        $error = "Credit hours must be from 1 to 6 (in steps of 0.5).";
    } elseif ($existing && $existing['is_archived']) {
        $error = "$code belongs to an archived subject. Restore it from the Archived Subjects list instead.";
    } elseif ($existing) {
        $error = "$code already exists. Use Edit on that subject to change its name or lecturer.";
    } else {
        mysqli_begin_transaction($conn);
        $stmt_insert = mysqli_prepare($conn, "INSERT INTO subjects (subject_code, subject_name, credit_hours) VALUES (?, ?, ?)");
        mysqli_stmt_bind_param($stmt_insert, "ssd", $code, $name, $credits);
        mysqli_stmt_execute($stmt_insert);
        $subject_id = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt_insert);
        // Default learning outcomes (CLO1–CLO4); reworded under Outcomes.
        obe_seed_subject($conn, $subject_id);

        $lec_error = set_subject_lecturer($conn, $subject_id, $lecturer_id);
        if ($lec_error) {
            mysqli_rollback($conn);
            $error = $lec_error;
        } else {
            audit_log($conn, 'Subject added', "Subject: $code ($name) | Credits: $credits" . ($lecturer_id ? " | Lecturer id: $lecturer_id" : ''));
            mysqli_commit($conn);
            $success = "Subject $code added. Remember to enrol students from Enrolments.";
        }
    }

// ── Edit an existing subject's name / lecturer ─────────────────────────
} elseif (isset($_POST['edit_subject'])) {
    $subject_id  = (int)($_POST['edit_id'] ?? 0);
    $name        = trim($_POST['edit_name'] ?? '');
    $credits     = parse_credit_hours($_POST['edit_credit_hours'] ?? '');
    $lecturer_id = (int)($_POST['edit_lecturer_id'] ?? 0);

    $subject = active_subject($conn, $subject_id);
    if (!$subject) {
        $error = "Subject not found, or it has been archived.";
    } elseif ($name === '' || mb_strlen($name) > 100) {
        $error = "Subject name is required (max 100 characters).";
    } elseif ($credits === null) {
        $error = "Credit hours must be from 1 to 6 (in steps of 0.5).";
    } else {
        mysqli_begin_transaction($conn);
        $stmt = mysqli_prepare($conn, "UPDATE subjects SET subject_name = ?, credit_hours = ? WHERE id = ? AND is_archived = 0");
        mysqli_stmt_bind_param($stmt, "sdi", $name, $credits, $subject_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $lec_error = set_subject_lecturer($conn, $subject_id, $lecturer_id);
        if ($lec_error) {
            mysqli_rollback($conn);
            $error = $lec_error;
        } else {
            audit_log($conn, 'Subject edited', "Subject: {$subject['subject_code']} | Name: $name | Credits: $credits | Lecturer id: " . ($lecturer_id ?: 'none'));
            mysqli_commit($conn);
            $success = "Subject updated.";
        }
    }

// ── Archive (soft delete) / restore ────────────────────────────────────
} elseif (isset($_POST['archive_subject']) || isset($_POST['restore_subject'])) {
    $archive = isset($_POST['archive_subject']) ? 1 : 0;
    $id      = (int)($_POST['archive_id'] ?? 0);
    $stmt_arch = mysqli_prepare($conn, "UPDATE subjects SET is_archived = ? WHERE id = ?");
    mysqli_stmt_bind_param($stmt_arch, "ii", $archive, $id);
    mysqli_stmt_execute($stmt_arch);
    $changed = mysqli_stmt_affected_rows($stmt_arch) > 0;
    mysqli_stmt_close($stmt_arch);
    if ($changed) {
        $code_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT subject_code FROM subjects WHERE id = " . (int)$id));
        audit_log($conn, $archive ? 'Subject archived' : 'Subject restored', "Subject: " . ($code_row['subject_code'] ?? "id $id"));
    }
    header("Location: manage_subjects.php?" . ($archive ? "archived=1" : "restored=1"));
    exit();
}

redirect_after_success($success, $error);

if (isset($_GET['archived'])) $success = "Subject archived. It is hidden from lecturers and students, and all historical records are preserved.";
if (isset($_GET['restored'])) $success = "Subject restored.";

// ── SECURE SELECT: active subjects, then archived ones ─────────────────
$subjects_query = "SELECT s.*, u.id as lecturer_id, u.full_name as lecturer_name
                   FROM subjects s
                   LEFT JOIN lecturer_subjects ls ON ls.subject_id = s.id AND ls.semester_id = (SELECT id FROM semesters WHERE is_active = 1)
                   LEFT JOIN users u ON u.id = ls.user_id
                   WHERE s.is_archived = 0
                   ORDER BY s.subject_code";
$subjects = mysqli_query($conn, $subjects_query);
$archived = mysqli_query($conn, "SELECT id, subject_code, subject_name FROM subjects WHERE is_archived = 1 ORDER BY subject_code");

$lecturers = mysqli_query($conn, "SELECT id, full_name FROM users WHERE role = 'lecturer' ORDER BY full_name");
$lec_list  = [];
while ($l = mysqli_fetch_assoc($lecturers)) { $lec_list[] = $l; }
?>
<?php
// Shared head, topbar and sidebar (includes/page_start.php); only this
// page's own CSS is here.
$page_title      = 'Manage Subjects';
$page_own_alerts = true;   // this page places its own messages
$page_styles     = <<<'CSS'
        .container {
            position: relative;
            z-index: 100; /* Ensure form sits above watermark */
        }

        /* ── Dashboard Cards & Forms (Softened Edges) ───────────────────── */
        .dashboard-card {
            background: #fff;
            border-radius: 16px;
            padding: 28px;
            box-shadow: var(--spts-card-shadow); border: 1px solid var(--spts-card-border);
            height: 100%;
        }

        .form-label { font-size: 0.88rem; color: #1A3A5C; font-weight: 600; }

        .form-control, .form-select {
            background: #F8FAFC;
            border: 1px solid #E2E8F0;
            font-size: 0.9rem;
            border-radius: 8px;
        }

        .form-control:focus, .form-select:focus {
            box-shadow: 0 0 0 3px rgba(13,115,119,0.15);
            border-color: #0D7377;
            background: #fff;
        }

        .btn-primary-custom {
            background: #0D7377;
            border: 2px solid #0D7377;
            color: #fff;
            font-weight: 600;
            transition: all 0.2s;
        }

        .btn-warning-custom {
            background: #FFFBEB;
            border: 1px solid #FCD34D;
            color: #92400E;
            font-weight: 600;
            transition: all 0.2s;
        }
        .btn-warning-custom:hover { background: #FEF3C7; border-color: #FBBF24; color: #92400E; }
        .table > tbody > tr > td {
            vertical-align: middle;
            font-size: 0.88rem;
            color: #334155;
            padding: 16px 12px;
            border-color: #F8FAFC;
        }
        /* Shared rules this page does not use (keeps its original look). */
        .dashboard-card { margin-bottom: 0; }
CSS;
require '../includes/page_start.php';
?>
    <div class="container mb-5" style="max-width:100%; margin-left:0;">
    <div class="row g-4">

        <!-- Add Subject Form -->
        <div class="col-md-4">
            <div class="dashboard-card">
                <div class="card-header-title" style="color: #0D7377;">
                    <i class="bi bi-plus-circle me-1"></i>Add New Subject
                </div>

                <?php if ($success): ?>
                    <div class="alert py-2" style="background:#F0FDF4; border:1px solid #BBF7D0; color:#166534; font-size:0.88rem; border-radius:8px;">
                        <i class="bi bi-check-circle-fill me-2 text-success"></i><?php echo h($success); ?>
                    </div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="alert py-2" style="background:#FEF2F2; border:1px solid #FECACA; color:#991B1B; font-size:0.88rem; border-radius:8px;">
                        <i class="bi bi-exclamation-circle-fill me-2 text-danger"></i><?php echo h($error); ?>
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <div class="mb-3">
                        <label class="form-label">Subject Code</label>
                        <input type="text" name="subject_code" class="form-control"
                               placeholder="e.g. EC2301" required pattern="[A-Za-z0-9]{3,20}" maxlength="20">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Subject Name</label>
                        <input type="text" name="subject_name" class="form-control"
                               placeholder="e.g. Database Development" required maxlength="100">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Credit Hours</label>
                        <input type="number" name="credit_hours" class="form-control" value="3" min="1" max="6" step="0.5" required>
                        <small class="text-muted mt-1 d-block" style="font-size:0.75rem;">Used to weight this subject in GPA and CGPA.</small>
                    </div>
                    <div class="mb-4">
                        <label class="form-label">Lecturer this semester</label>
                        <select name="lecturer_id" class="form-select">
                            <option value="0">-- No lecturer assigned yet --</option>
                            <?php foreach ($lec_list as $lec): ?>
                                <option value="<?php echo $lec['id']; ?>">
                                    <?php echo h($lec['full_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted mt-1 d-block" style="font-size:0.75rem;">Assigns this subject to a lecturer's dashboard.</small>
                    </div>
                    <button type="submit" name="add_subject" class="btn btn-primary-custom w-100 py-2 rounded-2">
                        <i class="bi bi-plus-lg me-1"></i>Add Subject
                    </button>
                </form>
            </div>
        </div>

        <!-- Subjects Table -->
        <div class="col-md-8">
            <div class="dashboard-card p-0 overflow-hidden">
                <div class="p-4 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div class="card-header-title mb-0">
                        <i class="bi bi-list-ul text-primary"></i>All Subjects
                    </div>
                    <div style="width: 240px;">
                        <div class="input-group">
                            <span class="input-group-text bg-light border-0" style="color:#94A3B8;"><i class="bi bi-search"></i></span>
                            <input type="text" id="subjectSearch" class="form-control bg-light border-0" placeholder="Search subjects..." style="font-size:0.88rem; box-shadow:none;">
                        </div>
                    </div>
                </div>

                <div class="table-responsive">
                <table class="table table-hover mb-0" id="subjectTable">
                    <thead class="table-custom-header">
                        <tr>
                            <th class="ps-4">#</th>
                            <th>Code</th>
                            <th>Subject Name</th>
                            <th class="text-center">Credits</th>
                            <th>Lecturer (this semester)</th>
                            <th class="text-center pe-4">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 1; while ($row = mysqli_fetch_assoc($subjects)): ?>
                        <tr>
                            <td class="ps-4 text-muted"><?php echo $i++; ?></td>
                            <td class="fw-bold" style="color:#1A3A5C;"><?php echo h($row['subject_code']); ?></td>
                            <td class="fw-semibold text-dark"><?php echo h($row['subject_name']); ?></td>
                            <td class="text-center"><?php echo h(number_format((float)$row['credit_hours'], 1)); ?></td>
                            <td>
                                <?php if ($row['lecturer_name']): ?>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="badge" style="background:#EAF4FB; color:#0369A1; font-weight:600; border:1px solid #BAE6FD;">
                                            <i class="bi bi-person-check me-1"></i><?php echo h($row['lecturer_name']); ?>
                                        </span>
                                        <!-- Inline Secure Unassign Button -->
                                        <form method="POST" class="m-0" onsubmit="return confirm('Unassign <?php echo h($row['lecturer_name']); ?> from this subject?');">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="unassign_subject_id" value="<?php echo $row['id']; ?>">
                                            <button type="submit" name="unassign_lecturer" class="btn btn-sm btn-outline-danger" style="padding: 2px 6px; font-size: 0.75rem;" title="Unassign Lecturer">
                                                <i class="bi bi-x-lg"></i>
                                            </button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted" style="font-size:0.8rem; font-style:italic;">Not assigned</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center pe-4 text-nowrap">
                                <button type="button" class="btn btn-sm btn-outline-primary px-3 py-1 rounded-2 me-1" style="font-size:0.75rem;"
                                        data-bs-toggle="modal" data-bs-target="#editSubjectModal"
                                        data-id="<?php echo (int)$row['id']; ?>"
                                        data-code="<?php echo h($row['subject_code']); ?>"
                                        data-name="<?php echo h($row['subject_name']); ?>"
                                        data-credits="<?php echo h($row['credit_hours']); ?>"
                                        data-lecturer="<?php echo (int)$row['lecturer_id']; ?>">
                                    <i class="bi bi-pencil-square me-1"></i>Edit
                                </button>
                                <a href="subject_outcomes.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-sm btn-outline-secondary px-3 py-1 rounded-2 me-1" style="font-size:0.75rem;">
                                    <i class="bi bi-diagram-3 me-1"></i>Outcomes
                                </a>
                                <form method="POST" class="d-inline" onsubmit="return confirm('⚠️ This subject will be archived. All historical student records are preserved. Continue?');">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="archive_id" value="<?php echo $row['id']; ?>">
                                    <button type="submit" name="archive_subject" class="btn btn-warning-custom btn-sm px-3 py-1 rounded-2" style="font-size:0.75rem;">
                                        <i class="bi bi-archive me-1"></i>Archive
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
                </div>
                <div class="p-3" style="background:#F8FAFC; border-top:1px solid #E2E8F0;">
                    <small class="text-muted d-block text-center" style="font-size:0.75rem;">
                        <i class="bi bi-info-circle me-1"></i>
                        Archiving a subject hides it from active use but preserves all student grades and records.
                    </small>
                </div>
            </div>

            <?php if (mysqli_num_rows($archived) > 0): ?>
            <div class="dashboard-card mt-4">
                <div class="card-header-title"><i class="bi bi-archive text-muted"></i>Archived Subjects</div>
                <table class="table mb-0">
                    <tbody>
                    <?php while ($arc = mysqli_fetch_assoc($archived)): ?>
                        <tr>
                            <td class="fw-bold" style="width:110px;"><?php echo h($arc['subject_code']); ?></td>
                            <td><?php echo h($arc['subject_name']); ?></td>
                            <td class="text-end">
                                <form method="POST" class="d-inline" onsubmit="return confirm('Restore this subject to active use?');">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="archive_id" value="<?php echo (int)$arc['id']; ?>">
                                    <button type="submit" name="restore_subject" class="btn btn-sm btn-outline-success"><i class="bi bi-arrow-counterclockwise me-1"></i>Restore</button>
                                </form>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

    </div>
    </div>
<?php
// Modals and page scripts: page_end.php prints them after Bootstrap.
ob_start();
?>
<!-- Edit Subject Modal -->
<div class="modal fade" id="editSubjectModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:12px; border:none;">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" style="color:#1A3A5C;"><i class="bi bi-pencil-square me-2"></i>Edit <span id="edit_code"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="edit_id" id="edit_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label" for="edit_name">Subject Name</label>
                        <input type="text" name="edit_name" id="edit_name" class="form-control" required maxlength="100">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="edit_credit_hours">Credit Hours</label>
                        <input type="number" name="edit_credit_hours" id="edit_credit_hours" class="form-control" min="1" max="6" step="0.5" required>
                    </div>
                    <div>
                        <label class="form-label" for="edit_lecturer_id">Lecturer</label>
                        <select name="edit_lecturer_id" id="edit_lecturer_id" class="form-select">
                            <option value="0">-- No lecturer --</option>
                            <?php foreach ($lec_list as $lec): ?>
                                <option value="<?php echo (int)$lec['id']; ?>"><?php echo h($lec['full_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="edit_subject" class="btn btn-primary-custom">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('editSubjectModal').addEventListener('show.bs.modal', function (e) {
    const d = e.relatedTarget.dataset;
    document.getElementById('edit_id').value          = d.id;
    document.getElementById('edit_code').textContent  = d.code;
    document.getElementById('edit_name').value        = d.name;
    document.getElementById('edit_credit_hours').value = d.credits;
    document.getElementById('edit_lecturer_id').value = d.lecturer;
});

document.getElementById('subjectSearch').addEventListener('input', function () {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#subjectTable tbody tr').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
});
</script>
<?php $page_scripts = ob_get_clean(); require '../includes/page_end.php'; ?>
