<?php
require '../includes/session_check.php';
require '../config/config.php';
require '../includes/csrf.php';
require '../includes/semester.php';
require '../includes/passwords.php';
require '../includes/audit.php';

$error   = "";
require_once '../includes/flash.php';
$flash   = flash_take();
$success = $flash['success'] ?? "";
$issued  = $flash['issued'] ?? null;   // temporary password to show the admin exactly once

// ── Issue a temporary password / reject a request ──────────────────────
// The admin is expected to confirm the person's identity (e.g. staff or
// student card at the admin office) before issuing a password.
if (isset($_POST['request_id'])) {
    if (!csrf_verify()) {
        $error = "Your session expired or the request could not be verified. Please try again.";
    } else {
        $request_id = (int)$_POST['request_id'];
        $admin_id   = (int)$_SESSION['user_id'];

        $stmt_req = mysqli_prepare($conn, "SELECT pr.id, pr.user_id, u.full_name, u.email
                                           FROM password_resets pr JOIN users u ON u.id = pr.user_id
                                           WHERE pr.id = ? AND pr.used = 0 AND pr.expires_at > NOW()");
        mysqli_stmt_bind_param($stmt_req, "i", $request_id);
        mysqli_stmt_execute($stmt_req);
        $req = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_req));
        mysqli_stmt_close($stmt_req);

        if (!$req) {
            $error = "That request is no longer open.";
        } else {
            if (isset($_POST['issue'])) {
                $temp = generate_temp_password();
                set_temporary_password($conn, $req['user_id'], $temp);
                audit_log($conn, 'Password reset by admin', "Reset request from {$req['full_name']} ({$req['email']})");
                $issued  = ['name' => $req['full_name'], 'email' => $req['email'], 'password' => $temp];
                $success = "Temporary password issued. The user must change it at their next login.";
            } else {
                audit_log($conn, 'Password reset request rejected', "Request from {$req['full_name']} ({$req['email']})");
                $success = "Request rejected.";
            }
            $stmt_done = mysqli_prepare($conn, "UPDATE password_resets SET used = 1, resolved_by = ?, resolved_at = NOW() WHERE id = ?");
            mysqli_stmt_bind_param($stmt_done, "ii", $admin_id, $request_id);
            mysqli_stmt_execute($stmt_done);
            mysqli_stmt_close($stmt_done);
        }
    }
}

redirect_after_success($success, $error, ['issued' => $issued]);

$open = mysqli_query($conn, "SELECT pr.id, pr.created_at, pr.expires_at, u.full_name, u.email, u.role, s.student_id
                             FROM password_resets pr
                             JOIN users u ON u.id = pr.user_id
                             LEFT JOIN students s ON s.user_id = u.id
                             WHERE pr.used = 0 AND pr.expires_at > NOW()
                             ORDER BY pr.created_at");

$history = mysqli_query($conn, "SELECT pr.created_at, pr.resolved_at, u.full_name, u.email, a.full_name AS admin_name
                                FROM password_resets pr
                                JOIN users u ON u.id = pr.user_id
                                LEFT JOIN users a ON a.id = pr.resolved_by
                                WHERE pr.used = 1
                                ORDER BY pr.resolved_at DESC LIMIT 20");

$page_title = 'Password Requests';
require '../includes/page_start.php';
?>
        <div class="page-header">
            <div>
                <h2><i class="bi bi-key me-2"></i>Password Reset Requests</h2>
                <p>Verify the person's identity in person before issuing a temporary password. They must change it at their next login.</p>
            </div>
        </div>

        <?php if ($issued): ?>
            <div class="alert alert-warning mb-4" style="border-radius:10px;">
                <strong>Temporary password for <?php echo h($issued['name']); ?> (<?php echo h($issued['email']); ?>):</strong>
                <code class="fs-5 ms-2 user-select-all"><?php echo h($issued['password']); ?></code>
                <div class="small mt-1">This is shown only once. Give it to the user directly — do not send it to an unverified contact.</div>
            </div>
        <?php endif; ?>

        <div class="dashboard-card">
            <div class="card-header-title"><i class="bi bi-inbox"></i>Open Requests</div>
            <?php if (mysqli_num_rows($open) > 0): ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-custom-header">
                        <tr><th>User</th><th>Role</th><th>Requested</th><th>Expires</th><th class="text-end">Action</th></tr>
                    </thead>
                    <tbody>
                    <?php while ($r = mysqli_fetch_assoc($open)): ?>
                        <tr>
                            <td>
                                <strong><?php echo h($r['full_name']); ?></strong><br>
                                <span class="text-muted small"><?php echo h($r['email']); ?><?php echo $r['student_id'] ? ' · ' . h($r['student_id']) : ''; ?></span>
                            </td>
                            <td><span class="badge bg-secondary"><?php echo h(ucfirst($r['role'])); ?></span></td>
                            <td><?php echo date('d M Y, h:i A', strtotime($r['created_at'])); ?></td>
                            <td><?php echo date('d M Y', strtotime($r['expires_at'])); ?></td>
                            <td class="text-end text-nowrap">
                                <form method="POST" class="d-inline" onsubmit="return confirm('Have you verified this person\'s identity? A new temporary password will replace their current one.');">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>">
                                    <button type="submit" name="issue" class="btn btn-sm btn-primary-custom">Issue Temporary Password</button>
                                </form>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Reject this request?');">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>">
                                    <button type="submit" name="reject" class="btn btn-sm btn-outline-danger">Reject</button>
                                </form>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <div class="empty-state"><i class="bi bi-check2-circle"></i>No open password reset requests.</div>
            <?php endif; ?>
        </div>

        <div class="dashboard-card">
            <div class="card-header-title"><i class="bi bi-clock-history"></i>Recently Resolved</div>
            <?php if (mysqli_num_rows($history) > 0): ?>
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead class="table-custom-header"><tr><th>User</th><th>Requested</th><th>Resolved</th><th>By</th></tr></thead>
                    <tbody>
                    <?php while ($r = mysqli_fetch_assoc($history)): ?>
                        <tr>
                            <td><?php echo h($r['full_name']); ?> <span class="text-muted small">(<?php echo h($r['email']); ?>)</span></td>
                            <td><?php echo date('d M Y, h:i A', strtotime($r['created_at'])); ?></td>
                            <td><?php echo $r['resolved_at'] ? date('d M Y, h:i A', strtotime($r['resolved_at'])) : '—'; ?></td>
                            <td><?php echo h($r['admin_name'] ?? '—'); ?></td>
                        </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <div class="empty-state"><i class="bi bi-inbox"></i>Nothing resolved yet.</div>
            <?php endif; ?>
        </div>
<?php require '../includes/page_end.php'; ?>
