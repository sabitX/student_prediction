<?php
require '../includes/session_check.php';
require '../config/config.php';
require '../includes/csrf.php';
require '../includes/semester.php';
require '../includes/passwords.php';
require '../includes/mailer.php';
require '../includes/audit.php';
require '../includes/academic_level.php';

$error   = "";
require_once '../includes/flash.php';
$flash   = flash_take();
$success = $flash['success'] ?? "";
$issued  = $flash['issued'] ?? null;   // shown once when the email could not be sent

// Sign-in link for the emails: APP_URL from env.php if set, otherwise
// worked out from this request. Staff and students have separate portals.
function login_url_for($role) {
    $path = '/auth/login.php' . ($role === 'student' ? '' : '?portal=staff');
    if (setting('APP_URL') !== null) {
        return rtrim(setting('APP_URL'), '/') . $path;
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $base   = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['PHP_SELF']))), '/');
    return $scheme . '://' . $_SERVER['HTTP_HOST'] . $base . $path;
}


// ── Approve / reject a request ──────────────────────────────────────────
// Lecturer requests come from the staff sign-in page, student requests
// from the student one. The admin is expected to confirm the person (with
// HR / the department, or against admission records) before approving.
if (isset($_POST['request_id'])) {
    if (!csrf_verify()) {
        $error = "Your session expired or the request could not be verified. Please try again.";
    } else {
        $request_id = (int)$_POST['request_id'];
        $admin_id   = (int)$_SESSION['user_id'];

        $stmt = mysqli_prepare($conn, "SELECT * FROM account_requests WHERE id = ? AND status = 'Pending'");
        mysqli_stmt_bind_param($stmt, "i", $request_id);
        mysqli_stmt_execute($stmt);
        $req = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if (!$req) {
            $error = "That request is no longer pending.";
        } elseif (isset($_POST['approve'])) {
            // Only ever 'lecturer' or 'student': administrator accounts are
            // never created from a request.
            $role       = $req['role'] === 'student' ? 'student' : 'lecturer';
            $is_student = $role === 'student';

            $stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ?");
            mysqli_stmt_bind_param($stmt, "s", $req['email']);
            mysqli_stmt_execute($stmt);
            $taken = mysqli_num_rows(mysqli_stmt_get_result($stmt)) > 0;
            mysqli_stmt_close($stmt);

            // Students: the Student ID must still be free and the programme
            // still valid for its level (checked again now, not just when
            // the request was sent).
            $id_taken = false;
            $profile_error = '';
            if ($is_student) {
                $stmt = mysqli_prepare($conn, "SELECT id FROM students WHERE student_id = ?");
                mysqli_stmt_bind_param($stmt, "s", $req['student_id']);
                mysqli_stmt_execute($stmt);
                $id_taken = mysqli_num_rows(mysqli_stmt_get_result($stmt)) > 0;
                mysqli_stmt_close($stmt);
                $profile_error = student_profile_error($req['student_id'], $req['course'], (int)$req['year_of_study']);
            }

            if ($taken) {
                $error = "An account with {$req['email']} already exists. Reject this request, or reset that account's password from " . ($is_student ? 'Students' : 'Staff') . ".";
            } elseif ($id_taken) {
                $error = "Student ID {$req['student_id']} already has an account. Reject this request, or reset that student's password from Students.";
            } elseif ($profile_error !== '') {
                $error = "This request can't be approved as it stands: $profile_error Reject it and ask the student to send a corrected request.";
            } else {
                $temp = generate_temp_password();
                $hash = password_hash($temp, PASSWORD_DEFAULT);

                mysqli_begin_transaction($conn);
                try {
                    $stmt = mysqli_prepare($conn, "INSERT INTO users (full_name, email, password, role, must_change_password) VALUES (?, ?, ?, ?, 1)");
                    mysqli_stmt_bind_param($stmt, "ssss", $req['full_name'], $req['email'], $hash, $role);
                    mysqli_stmt_execute($stmt);
                    $new_user_id = mysqli_insert_id($conn);
                    mysqli_stmt_close($stmt);

                    if ($is_student) {
                        // Academic level comes from the Student ID, as everywhere else.
                        $level = detect_academic_level($req['student_id']);
                        $year  = (int)$req['year_of_study'];
                        $stmt = mysqli_prepare($conn, "INSERT INTO students (user_id, student_id, course, academic_level, year_of_study) VALUES (?, ?, ?, ?, ?)");
                        mysqli_stmt_bind_param($stmt, "isssi", $new_user_id, $req['student_id'], $req['course'], $level, $year);
                        mysqli_stmt_execute($stmt);
                        mysqli_stmt_close($stmt);
                    }

                    // Still pending? Another admin may have handled it since
                    // this page loaded; then nothing is created.
                    $stmt = mysqli_prepare($conn, "UPDATE account_requests SET status = 'Approved', user_id = ?, resolved_by = ?, resolved_at = NOW() WHERE id = ? AND status = 'Pending'");
                    mysqli_stmt_bind_param($stmt, "iii", $new_user_id, $admin_id, $request_id);
                    mysqli_stmt_execute($stmt);
                    $claimed = mysqli_stmt_affected_rows($stmt) === 1;
                    mysqli_stmt_close($stmt);
                    if (!$claimed) {
                        throw new RuntimeException('request already resolved');
                    }

                    audit_log($conn, 'Account request approved', ucfirst($role) . " account created for {$req['full_name']} ({$req['email']}"
                        . ($is_student ? ", {$req['student_id']}" : '') . ")");
                    mysqli_commit($conn);
                } catch (RuntimeException $e) {   // includes mysqli_sql_exception
                    mysqli_rollback($conn);
                    if ($e->getMessage() === 'request already resolved') {
                        $error = "That request was just handled by another administrator.";
                    } else {
                        error_log('SPTS account request approve: ' . $e->getMessage());
                        $error = "The account could not be created. Please try again.";
                    }
                }

                if ($error === '') {
                    $sent = send_mail($req['personal_email'], $req['full_name'], "Your SPTS $role account is ready",
                        "Hello {$req['full_name']},\n\n"
                      . "Your $role account on the Student Performance Tracking System (Nilai University) has been approved.\n\n"
                      . "Sign in here: " . login_url_for($role) . "\n"
                      . "Email: {$req['email']}\n"
                      . "Temporary password: $temp\n\n"
                      . "You'll be asked to choose your own password the first time you sign in. "
                      . "You can change it again at any time from Change Password in the menu.\n\n"
                      . "If you didn't ask for this account, please contact the administrator.\n\n"
                      . "— SPTS, Nilai University");

                    if ($sent) {
                        $success = "Account created for {$req['full_name']}. Their sign-in details were emailed to {$req['personal_email']}.";
                    } else {
                        $issued  = ['name' => $req['full_name'], 'email' => $req['email'], 'personal_email' => $req['personal_email'], 'password' => $temp];
                        $success = "Account created for {$req['full_name']}, but the email could not be sent. Give them the temporary password below.";
                    }
                }
            }
        } else {
            $reason = is_string($_POST['reject_reason'] ?? null) ? trim($_POST['reject_reason']) : '';
            $reason = $reason !== '' ? mb_substr($reason, 0, 255) : null;
            // Only a request that is still pending: if another admin approved
            // it meanwhile, it must not flip to Rejected (or email the person).
            $stmt = mysqli_prepare($conn, "UPDATE account_requests SET status = 'Rejected', reject_reason = ?, resolved_by = ?, resolved_at = NOW() WHERE id = ? AND status = 'Pending'");
            mysqli_stmt_bind_param($stmt, "sii", $reason, $admin_id, $request_id);
            mysqli_stmt_execute($stmt);
            $rejected = mysqli_stmt_affected_rows($stmt) === 1;
            mysqli_stmt_close($stmt);

            if (!$rejected) {
                $error = "That request was just handled by another administrator.";
            } else {
                audit_log($conn, 'Account request rejected', "Request from {$req['full_name']} ({$req['email']})" . ($reason ? " | Reason: $reason" : ''));

                send_mail($req['personal_email'], $req['full_name'], 'Your SPTS account request',
                    "Hello {$req['full_name']},\n\n"
                  . "Your request for a " . ($req['role'] === 'student' ? 'student' : 'lecturer') . " account on the Student Performance Tracking System was not approved."
                  . ($reason ? "\n\nReason: $reason" : '')
                  . "\n\nIf you think this is a mistake, please contact the administrator.\n\n"
                  . "— SPTS, Nilai University");
                $success = "Request from {$req['full_name']} rejected.";
            }
        }
    }
}

redirect_after_success($success, $error, ['issued' => $issued]);

$pending = mysqli_query($conn, "SELECT * FROM account_requests WHERE status = 'Pending' ORDER BY created_at");

$history = mysqli_query($conn, "SELECT r.*, a.full_name AS admin_name
                                FROM account_requests r
                                LEFT JOIN users a ON a.id = r.resolved_by
                                WHERE r.status <> 'Pending'
                                ORDER BY r.resolved_at DESC LIMIT 20");

$page_title = 'Account Requests';
require '../includes/page_start.php';
?>
        <div class="page-header">
            <div>
                <h2><i class="bi bi-person-plus me-2"></i>Account Requests</h2>
                <p>New lecturers ask from the staff sign-in page, newly admitted students from the student sign-in page. Check lecturers with HR or the department, and students against admission records, before approving.</p>
            </div>
        </div>

        <?php if (!mail_is_configured()): ?>
            <div class="alert alert-warning mb-4" style="border-radius:10px; font-size:0.88rem;">
                <i class="bi bi-envelope-exclamation me-1"></i>
                <strong>Email isn't set up yet.</strong> Approving still creates the account, and the temporary password is shown here for you to pass on.
                To email it automatically, add the <code>MAIL_*</code> settings to <code>config/env.php</code> (see <code>env.example.php</code>).
            </div>
        <?php endif; ?>

        <?php if ($issued): ?>
            <div class="alert alert-warning mb-4" style="border-radius:10px;">
                <strong>Temporary password for <?php echo h($issued['name']); ?></strong>
                (signs in as <?php echo h($issued['email']); ?>):
                <code class="fs-5 ms-2 user-select-all"><?php echo h($issued['password']); ?></code>
                <div class="small mt-1">This is shown only once. Send it to <?php echo h($issued['personal_email']); ?> or give it to them in person. They must change it at first sign-in.</div>
            </div>
        <?php endif; ?>

        <div class="dashboard-card">
            <div class="card-header-title"><i class="bi bi-inbox"></i>Pending Requests</div>
            <?php if (mysqli_num_rows($pending) > 0): ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-custom-header">
                        <tr><th>Name</th><th>Type</th><th>Details</th><th>Sign-in Details Go To</th><th>Requested</th><th class="text-end">Action</th></tr>
                    </thead>
                    <tbody>
                    <?php while ($r = mysqli_fetch_assoc($pending)):
                        $is_student = $r['role'] === 'student'; ?>
                        <tr>
                            <td>
                                <strong><?php echo h($r['full_name']); ?></strong><br>
                                <span class="text-muted small"><?php echo h($r['email']); ?><?php echo $r['staff_id'] ? ' · ' . h($r['staff_id']) : ''; ?></span>
                                <?php if ($r['message']): ?>
                                    <div class="small mt-1 text-secondary"><i class="bi bi-chat-left-text me-1"></i><?php echo h($r['message']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge-status <?php echo $is_student ? 'badge-status-pass' : 'badge-status-pending'; ?>">
                                    <i class="bi <?php echo $is_student ? 'bi-mortarboard' : 'bi-person-badge'; ?> me-1"></i><?php echo $is_student ? 'Student' : 'Lecturer'; ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($is_student): ?>
                                    <strong><?php echo h($r['student_id']); ?></strong>
                                    <span class="text-muted small">(<?php echo h(academic_level_label(detect_academic_level($r['student_id']))); ?>)</span><br>
                                    <span class="small"><?php echo h($r['course']); ?> · Year <?php echo (int)$r['year_of_study']; ?></span>
                                <?php else: ?>
                                    <?php echo h($r['department']); ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo h($r['personal_email']); ?></td>
                            <td class="text-nowrap"><?php echo date('d M Y, h:i A', strtotime($r['created_at'])); ?></td>
                            <td class="text-end text-nowrap">
                                <form method="POST" class="d-inline" onsubmit="return confirm(<?php echo h(json_encode('Create a ' . ($is_student ? 'student' : 'lecturer') . ' account for ' . $r['full_name'] . ' and send the sign-in details to ' . $r['personal_email'] . '?')); ?>);">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>">
                                    <button type="submit" name="approve" class="btn btn-sm btn-primary-custom"><i class="bi bi-check-lg me-1"></i>Approve</button>
                                </form>
                                <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectModal"
                                        data-id="<?php echo (int)$r['id']; ?>" data-name="<?php echo h($r['full_name']); ?>">Reject</button>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <div class="empty-state"><i class="bi bi-check2-circle"></i>No pending account requests.</div>
            <?php endif; ?>
        </div>

        <div class="dashboard-card">
            <div class="card-header-title"><i class="bi bi-clock-history"></i>Recently Reviewed</div>
            <?php if (mysqli_num_rows($history) > 0): ?>
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead class="table-custom-header"><tr><th>Name</th><th>Type</th><th>Status</th><th>Requested</th><th>Reviewed</th><th>By</th></tr></thead>
                    <tbody>
                    <?php while ($r = mysqli_fetch_assoc($history)): ?>
                        <tr>
                            <td><?php echo h($r['full_name']); ?> <span class="text-muted small">(<?php echo h($r['email']); ?><?php echo $r['student_id'] ? ', ' . h($r['student_id']) : ''; ?>)</span></td>
                            <td><?php echo $r['role'] === 'student' ? 'Student' : 'Lecturer'; ?></td>
                            <td>
                                <span class="<?php echo $r['status'] === 'Approved' ? 'badge-status badge-status-excellent' : 'badge-status badge-status-fail'; ?>"><?php echo h($r['status']); ?></span>
                                <?php if ($r['reject_reason']): ?><div class="small text-muted mt-1"><?php echo h($r['reject_reason']); ?></div><?php endif; ?>
                            </td>
                            <td><?php echo date('d M Y', strtotime($r['created_at'])); ?></td>
                            <td><?php echo $r['resolved_at'] ? date('d M Y, h:i A', strtotime($r['resolved_at'])) : '—'; ?></td>
                            <td><?php echo h($r['admin_name'] ?? '—'); ?></td>
                        </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <div class="empty-state"><i class="bi bi-inbox"></i>Nothing reviewed yet.</div>
            <?php endif; ?>
        </div>

        <div class="modal fade" id="rejectModal" tabindex="-1" aria-labelledby="rejectTitle" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <form method="POST" class="modal-content">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="request_id" id="reject_id">
                    <div class="modal-header">
                        <h5 class="modal-title" id="rejectTitle">Reject request from <span id="reject_name"></span></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <label class="form-label" for="reject_reason">Reason <span class="text-muted fw-normal">(optional, included in the email to them)</span></label>
                        <input type="text" name="reject_reason" id="reject_reason" class="form-control" maxlength="255" placeholder="e.g. We couldn't match this Student ID to an admission record">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="reject" class="btn btn-danger">Reject Request</button>
                    </div>
                </form>
            </div>
        </div>
<?php
$page_scripts = <<<'JS'
<script>
document.getElementById('rejectModal').addEventListener('show.bs.modal', function (e) {
    document.getElementById('reject_id').value = e.relatedTarget.dataset.id;
    document.getElementById('reject_name').textContent = e.relatedTarget.dataset.name;
});
</script>
JS;
require '../includes/page_end.php';
?>
