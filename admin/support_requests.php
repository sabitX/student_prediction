<?php
require '../includes/session_check.php';
require '../config/config.php';
require '../includes/csrf.php';
require '../includes/semester.php';
require '../includes/passwords.php';
require '../includes/audit.php';
require '../includes/pagination.php';
require '../includes/support.php';

// ── Support requests from students and lecturers ────────────────────────
// Requests come from Contact Admin (auth/contact_admin.php when someone
// can't sign in, and the Contact Admin page inside the system). The admin
// works through them Open → In Progress → Resolved.
//
// Account recovery: a request never changes an account by itself. For a
// sign-in problem whose email matches a student or lecturer account, the
// admin first confirms the person's identity (e.g. their card, in person),
// ticks that they did, and only then can issue a one-time temporary
// password. It is shown here once and must be replaced at the next sign-in.

$error   = "";
require_once '../includes/flash.php';
$flash   = flash_take();
$success = $flash['success'] ?? "";
$issued  = $flash['issued'] ?? null;   // temporary password, shown once

const SUPPORT_VERIFY_METHODS = [
    'in_person' => 'In person, with their university ID card',
    'video'     => 'Video call, with their university ID card',
    'records'   => 'Confirmed against university records by the registry / HR',
];

function support_load($conn, $id) {
    $stmt = mysqli_prepare($conn, "SELECT r.*, a.full_name AS admin_name FROM support_requests r
                                   LEFT JOIN users a ON a.id = r.handled_by WHERE r.id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $req = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
    mysqli_stmt_close($stmt);
    return $req;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_id'])) {
    $request_id = (int)$_POST['request_id'];
    $admin_id   = (int)$_SESSION['user_id'];
    $req        = support_load($conn, $request_id);

    if (!csrf_verify()) {
        $error = "Your session expired or the request could not be verified. Please try again.";
    } elseif (!$req) {
        $error = "That support request no longer exists.";
    } elseif (isset($_POST['update_status'])) {
        $status = $_POST['status'] ?? '';
        $note   = is_string($_POST['admin_note'] ?? null) ? trim($_POST['admin_note']) : '';
        if (!in_array($status, SUPPORT_STATUSES, true)) {
            $error = "Choose a valid status.";
        } elseif (mb_strlen($note) > 500) {
            $error = "The note can be at most 500 characters.";
        } else {
            $note     = $note !== '' ? $note : null;
            $resolved = $status === 'Resolved' ? 1 : 0;
            $stmt = mysqli_prepare($conn, "UPDATE support_requests
                                           SET status = ?, admin_note = ?, handled_by = ?, updated_at = NOW(),
                                               resolved_at = IF(?, COALESCE(resolved_at, NOW()), NULL)
                                           WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "ssiii", $status, $note, $admin_id, $resolved, $request_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            audit_log($conn, 'Support request updated', "{$req['reference_no']}: {$req['status']} → $status" . ($note ? " | Note: $note" : ''));
            $success = "{$req['reference_no']} is now $status.";
        }
    } elseif (isset($_POST['issue_password'])) {
        $check  = support_account_check($conn, $req);
        $method = $_POST['verify_method'] ?? '';
        if (!support_can_reset($req, $check)) {
            $error = "This request can't be used to reset an account. Only an open sign-in request whose email and role match a student or lecturer account can.";
        } elseif ((int)($_POST['account_id'] ?? 0) !== (int)$check['account']['id']) {
            $error = "The matching account changed while you were viewing this request. Please check it again.";
        } elseif (empty($_POST['identity_verified']) || !isset(SUPPORT_VERIFY_METHODS[$method])) {
            $error = "Confirm that you verified the person's identity, and how, before resetting the password.";
        } else {
            $account = $check['account'];
            $temp    = generate_temp_password();
            mysqli_begin_transaction($conn);
            try {
                // Only while the request is still unresolved: another admin
                // may have handled it since this page loaded.
                $note = "Identity verified and a temporary password issued. Sign in with it, then choose a new password.";
                $stmt = mysqli_prepare($conn, "UPDATE support_requests SET status = 'Resolved', admin_note = ?, handled_by = ?,
                                                      updated_at = NOW(), resolved_at = NOW()
                                               WHERE id = ? AND status <> 'Resolved'");
                mysqli_stmt_bind_param($stmt, "sii", $note, $admin_id, $request_id);
                mysqli_stmt_execute($stmt);
                $claimed = mysqli_stmt_affected_rows($stmt) === 1;
                mysqli_stmt_close($stmt);
                if (!$claimed) {
                    throw new RuntimeException('already resolved');
                }
                if (!set_temporary_password($conn, $account['id'], $temp)) {
                    throw new RuntimeException('account missing');
                }
                audit_log($conn, 'Password reset by admin', "Support request {$req['reference_no']} from {$account['full_name']} ({$account['email']})"
                    . " | Identity verified: " . SUPPORT_VERIFY_METHODS[$method]);
                mysqli_commit($conn);
                $issued  = ['name' => $account['full_name'], 'email' => $account['email'], 'password' => $temp];
                $success = "Temporary password issued and {$req['reference_no']} resolved. The user must change it at their next sign-in.";
            } catch (RuntimeException $e) {   // includes mysqli_sql_exception
                mysqli_rollback($conn);
                $error = match ($e->getMessage()) {
                    'already resolved' => "That request was just resolved by another administrator. No password was changed.",
                    'account missing'  => "That account no longer exists. No password was changed.",
                    default            => "The password could not be reset. Nothing was changed. Please try again.",
                };
                if (!in_array($e->getMessage(), ['already resolved', 'account missing'], true)) {
                    error_log('SPTS support reset: ' . $e->getMessage());
                }
            }
        }
    }
}

redirect_after_success($success, $error, ['issued' => $issued]);

// ── Filters ─────────────────────────────────────────────────────────────
$status_filter   = in_array($_GET['status'] ?? '', array_merge(SUPPORT_STATUSES, ['all']), true) ? $_GET['status'] : 'unresolved';
$role_filter     = in_array($_GET['role'] ?? '', ['student', 'lecturer'], true) ? $_GET['role'] : '';
$category_filter = isset(SUPPORT_CATEGORIES[$_GET['category'] ?? '']) ? $_GET['category'] : '';
$list            = list_params();

$where = [];
$types = '';
$params = [];
if ($status_filter === 'unresolved') {
    $where[] = "r.status <> 'Resolved'";
} elseif ($status_filter !== 'all') {
    $where[] = "r.status = ?"; $types .= 's'; $params[] = $status_filter;
}
if ($role_filter !== '') {
    $where[] = "r.role = ?"; $types .= 's'; $params[] = $role_filter;
}
if ($category_filter !== '') {
    $where[] = "r.category = ?"; $types .= 's'; $params[] = $category_filter;
}
if ($list['q'] !== '') {
    $where[] = "(r.reference_no LIKE ? OR r.full_name LIKE ? OR r.email LIKE ? OR r.id_number LIKE ?)";
    $like = like_pattern($list['q']);
    $types .= 'ssss'; array_push($params, $like, $like, $like, $like);
}
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS c FROM support_requests r $where_sql");
if ($params) mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$total = (int)mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['c'];
mysqli_stmt_close($stmt);
$list = list_clamp($list, $total);

$stmt = mysqli_prepare($conn, "SELECT r.id, r.reference_no, r.role, r.full_name, r.email, r.id_number, r.category, r.status,
                                      r.user_id, r.created_at, r.updated_at
                               FROM support_requests r $where_sql
                               ORDER BY FIELD(r.status, 'Open', 'In Progress', 'Resolved'), r.created_at DESC, r.id DESC
                               LIMIT ? OFFSET ?");
$list_params = array_merge($params, [$list['limit'], $list['offset']]);
mysqli_stmt_bind_param($stmt, $types . 'ii', ...$list_params);
mysqli_stmt_execute($stmt);
$rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

$counts = array_fill_keys(SUPPORT_STATUSES, 0);
foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT status, COUNT(*) AS c FROM support_requests GROUP BY status"), MYSQLI_ASSOC) as $c) {
    $counts[$c['status']] = (int)$c['c'];
}

// ── Detail view ─────────────────────────────────────────────────────────
$detail = isset($_GET['id']) ? support_load($conn, (int)$_GET['id']) : null;
$check  = $detail ? support_account_check($conn, $detail) : null;
$can_reset = $detail && support_can_reset($detail, $check);

// Keeps the filters when moving between the list and a request.
$filter_query = array_filter(['status' => $status_filter === 'unresolved' ? null : $status_filter, 'role' => $role_filter,
                              'category' => $category_filter, 'q' => $list['q'], 'page' => $list['page'] > 1 ? $list['page'] : null]);
$list_url = 'support_requests.php' . ($filter_query ? '?' . http_build_query($filter_query) : '');

function support_check_icon($ok) {
    if ($ok === null) return '<i class="bi bi-dash-circle text-secondary" title="Can\'t be checked"></i>';
    return $ok ? '<i class="bi bi-check-circle-fill text-success" title="Matches"></i>'
               : '<i class="bi bi-x-circle-fill text-danger" title="Does not match"></i>';
}

$page_title = 'Support Requests';
$page_styles = <<<'CSS'
        .support-meta dt { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.4px; color: #64748B; font-weight: 600; }
        .support-meta dd { font-size: 0.9rem; color: #1A3A5C; margin-bottom: 12px; word-break: break-word; }
        .support-message { white-space: pre-wrap; background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 10px; padding: 12px 14px; font-size: 0.88rem; }
        .check-list li { display: flex; gap: 8px; align-items: flex-start; font-size: 0.86rem; margin-bottom: 6px; }
        .stat-pill { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 999px; background: #F1F5F9; font-size: 0.82rem; font-weight: 600; color: #334155; text-decoration: none; }
        .stat-pill:hover { background: #E2E8F0; color: #1A3A5C; }
        tr.row-link { cursor: pointer; }
        tr.row-selected > td { background: #EEF6F6 !important; }
        [data-bs-theme="dark"] .support-message { background: rgba(255,255,255,0.03); border-color: rgba(255,255,255,0.1); }
        [data-bs-theme="dark"] .support-meta dd { color: #E2E8F0; }
        [data-bs-theme="dark"] .stat-pill { background: rgba(255,255,255,0.06); color: #CBD5E1; }
        [data-bs-theme="dark"] tr.row-selected > td { background: rgba(13,115,119,0.18) !important; }
CSS;
require '../includes/page_start.php';
?>
        <div class="page-header">
            <div>
                <h2><i class="bi bi-life-preserver me-2"></i>Support Requests</h2>
                <p>Requests from students and lecturers through Contact Admin. Verify a person's identity before resetting their password — a request alone never proves who sent it.</p>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2 mb-4">
            <a class="stat-pill" href="?status=Open"><span class="badge <?php echo support_status_class('Open'); ?>"><?php echo $counts['Open']; ?></span>Open</a>
            <a class="stat-pill" href="?status=In+Progress"><span class="badge <?php echo support_status_class('In Progress'); ?>"><?php echo $counts['In Progress']; ?></span>In Progress</a>
            <a class="stat-pill" href="?status=Resolved"><span class="badge <?php echo support_status_class('Resolved'); ?>"><?php echo $counts['Resolved']; ?></span>Resolved</a>
        </div>

        <?php if ($issued): ?>
            <div class="alert alert-warning mb-4" style="border-radius:10px;">
                <strong>Temporary password for <?php echo h($issued['name']); ?> (<?php echo h($issued['email']); ?>):</strong>
                <code class="fs-5 ms-2 user-select-all"><?php echo h($issued['password']); ?></code>
                <div class="small mt-1">This is shown only once. Give it to the person you verified, directly — do not send it to an unverified contact. They must choose a new password when they sign in.</div>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['id']) && !$detail): ?>
            <div class="alert alert-bad py-2 mb-4"><i class="bi bi-exclamation-circle-fill me-2"></i>That support request doesn't exist.</div>
        <?php endif; ?>

        <?php if ($detail): ?>
        <div class="row g-4 mb-4">
            <div class="col-xl-7">
                <div class="dashboard-card mb-0 h-100">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                        <div class="card-header-title mb-0"><i class="bi bi-ticket-detailed"></i><?php echo h($detail['reference_no']); ?></div>
                        <div class="d-flex gap-2 align-items-center">
                            <span class="badge <?php echo support_status_class($detail['status']); ?>"><?php echo h($detail['status']); ?></span>
                            <a href="<?php echo h($list_url); ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-lg"></i><span class="visually-hidden">Close</span></a>
                        </div>
                    </div>
                    <dl class="support-meta row g-0 mb-0">
                        <div class="col-sm-6"><dt>Category</dt><dd><?php echo h(support_category_label($detail['category'])); ?></dd></div>
                        <div class="col-sm-6"><dt>Sent</dt><dd><?php echo date('d M Y, h:i A', strtotime($detail['created_at'])); ?>
                            <?php echo $detail['user_id'] ? '<span class="badge bg-secondary ms-1">Signed in</span>' : '<span class="badge bg-light text-dark border ms-1">From sign-in page</span>'; ?></dd></div>
                        <div class="col-sm-6"><dt>Name given</dt><dd><?php echo h($detail['full_name']); ?></dd></div>
                        <div class="col-sm-6"><dt>Role given</dt><dd><?php echo h(ucfirst($detail['role'])); ?></dd></div>
                        <div class="col-sm-6"><dt><?php echo h(support_id_label($detail['role'])); ?> given</dt><dd><?php echo h($detail['id_number'] ?? '—'); ?></dd></div>
                        <div class="col-sm-6"><dt>Email given</dt><dd><?php echo h($detail['email']); ?></dd></div>
                    </dl>
                    <div class="form-label mb-1">Message</div>
                    <div class="support-message mb-3"><?php echo $detail['message'] !== null ? h($detail['message']) : '<span class="text-muted">No message.</span>'; ?></div>

                    <form method="POST" class="row g-2 align-items-end">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="request_id" value="<?php echo (int)$detail['id']; ?>">
                        <div class="col-sm-4">
                            <label class="form-label" for="status">Status</label>
                            <select name="status" id="status" class="form-select form-select-sm">
                                <?php foreach (SUPPORT_STATUSES as $s): ?>
                                    <option <?php echo $detail['status'] === $s ? 'selected' : ''; ?>><?php echo $s; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-sm-8">
                            <label class="form-label" for="admin_note">Note <span class="text-muted fw-normal">(signed-in users see it)</span></label>
                            <input type="text" name="admin_note" id="admin_note" class="form-control form-control-sm" maxlength="500" value="<?php echo h($detail['admin_note'] ?? ''); ?>">
                        </div>
                        <div class="col-12 d-flex justify-content-between align-items-center flex-wrap gap-2 mt-2">
                            <span class="text-muted small">
                                <?php if ($detail['updated_at']): ?>
                                    Last updated <?php echo date('d M Y, h:i A', strtotime($detail['updated_at'])); ?><?php echo $detail['admin_name'] ? ' by ' . h($detail['admin_name']) : ''; ?>
                                <?php endif; ?>
                            </span>
                            <button type="submit" name="update_status" class="btn btn-sm btn-primary-custom"><i class="bi bi-save me-1"></i>Save</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="col-xl-5">
                <div class="dashboard-card mb-0 h-100">
                    <div class="card-header-title"><i class="bi bi-person-check"></i>Account Check</div>
                    <?php if (!$check['account']): ?>
                        <p class="small mb-2"><i class="bi bi-x-circle-fill text-danger me-1"></i>No account uses <strong><?php echo h($detail['email']); ?></strong>.</p>
                        <?php if ($check['id_owner']): ?>
                            <p class="small mb-2"><i class="bi bi-exclamation-triangle-fill text-warning me-1"></i>Student ID <?php echo h($detail['id_number']); ?> belongs to
                                <strong><?php echo h($check['id_owner']['full_name']); ?></strong> (<?php echo h($check['id_owner']['email']); ?>). Confirm the person before helping them with that account.</p>
                        <?php endif; ?>
                        <p class="text-muted small mb-0">Nothing can be reset from this request. Contact the person through university records if needed, then resolve it.</p>
                    <?php else: $acc = $check['account']; ?>
                        <p class="small mb-2">
                            <?php echo $check['signed_in'] ? 'Sent while signed in as' : 'The email given belongs to'; ?>
                            <strong><?php echo h($acc['full_name']); ?></strong> (<?php echo h(ucfirst($acc['role'])); ?><?php echo $acc['student_id'] ? ', ' . h($acc['student_id']) : ''; ?>).
                        </p>
                        <?php if (!$check['signed_in']): ?>
                        <ul class="list-unstyled check-list mb-3">
                            <li><?php echo support_check_icon(true); ?><span>Email is registered</span></li>
                            <li><?php echo support_check_icon($check['role_ok']); ?><span>Role <?php echo $check['role_ok'] ? 'matches' : 'does not match (account is ' . h($acc['role']) . ')'; ?></span></li>
                            <li><?php echo support_check_icon($check['name_ok']); ?><span>Name <?php echo $check['name_ok'] ? 'matches' : 'differs from the account'; ?></span></li>
                            <li><?php echo support_check_icon($check['id_ok']); ?><span>
                                <?php if ($check['id_ok'] === null): ?>Staff ID can't be checked (not stored in SPTS)
                                <?php elseif ($check['id_ok']): ?>Student ID matches
                                <?php else: ?>Student ID does not match<?php echo $check['id_owner'] ? ' — it belongs to ' . h($check['id_owner']['full_name']) : ''; ?>
                                <?php endif; ?></span></li>
                        </ul>
                        <p class="text-muted small">Matching details are not proof of identity — anyone may know a name and email. Always verify the person.</p>
                        <?php endif; ?>

                        <?php if ($can_reset): ?>
                            <hr>
                            <div class="form-label mb-2"><i class="bi bi-key me-1"></i>Reset password</div>
                            <form method="POST" id="resetForm" onsubmit="return confirm(<?php echo h(json_encode('Replace the password of ' . $acc['full_name'] . ' (' . $acc['email'] . ') with a temporary one? Their current password will stop working.')); ?>);">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="request_id" value="<?php echo (int)$detail['id']; ?>">
                                <input type="hidden" name="account_id" value="<?php echo (int)$acc['id']; ?>">
                                <label class="form-label" for="verify_method">How did you verify their identity?</label>
                                <select name="verify_method" id="verify_method" class="form-select form-select-sm mb-2" required>
                                    <option value="">-- Choose --</option>
                                    <?php foreach (SUPPORT_VERIFY_METHODS as $value => $label): ?>
                                        <option value="<?php echo $value; ?>"><?php echo h($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="checkbox" name="identity_verified" value="1" id="identity_verified" required>
                                    <label class="form-check-label small" for="identity_verified">I have confirmed this person's identity and that this account is theirs.</label>
                                </div>
                                <button type="submit" name="issue_password" class="btn btn-sm btn-primary-custom w-100"><i class="bi bi-key me-1"></i>Issue Temporary Password</button>
                                <div class="text-muted small mt-2">The temporary password is shown to you once. The user must replace it at their next sign-in, and any of their open sessions are signed out.</div>
                            </form>
                        <?php elseif (in_array($detail['category'], ['forgot_password', 'cannot_login'], true) && $detail['status'] !== 'Resolved'): ?>
                            <p class="text-muted small mb-0"><i class="bi bi-info-circle me-1"></i>
                                <?php echo !$check['role_ok'] ? "The role given doesn't match the account, so it can't be reset from here." : "This account can't be reset from a support request."; ?>
                                Use <?php echo $acc['role'] === 'student' ? 'Students' : 'Staff'; ?> after verifying the person, if appropriate.</p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="dashboard-card p-0 overflow-hidden">
            <form method="GET" class="p-4 border-bottom row g-2 align-items-end">
                <div class="col-sm-6 col-lg-3">
                    <label class="form-label" for="f_status">Status</label>
                    <select name="status" id="f_status" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="unresolved" <?php echo $status_filter === 'unresolved' ? 'selected' : ''; ?>>Open + In Progress</option>
                        <?php foreach (SUPPORT_STATUSES as $s): ?>
                            <option <?php echo $status_filter === $s ? 'selected' : ''; ?>><?php echo $s; ?></option>
                        <?php endforeach; ?>
                        <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All</option>
                    </select>
                </div>
                <div class="col-sm-6 col-lg-2">
                    <label class="form-label" for="f_role">Role</label>
                    <select name="role" id="f_role" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All</option>
                        <option value="student" <?php echo $role_filter === 'student' ? 'selected' : ''; ?>>Students</option>
                        <option value="lecturer" <?php echo $role_filter === 'lecturer' ? 'selected' : ''; ?>>Lecturers</option>
                    </select>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <label class="form-label" for="f_category">Category</label>
                    <select name="category" id="f_category" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All</option>
                        <?php foreach (SUPPORT_CATEGORIES as $value => $label): ?>
                            <option value="<?php echo $value; ?>" <?php echo $category_filter === $value ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-6 col-lg-4">
                    <label class="form-label" for="f_q">Search</label>
                    <div class="input-group input-group-sm">
                        <input type="search" name="q" id="f_q" class="form-control" placeholder="Reference, name, email or ID" value="<?php echo h($list['q']); ?>">
                        <button class="btn btn-outline-secondary" type="submit" aria-label="Search"><i class="bi bi-search"></i></button>
                    </div>
                </div>
            </form>

            <?php if ($rows): ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-custom-header">
                        <tr><th>Reference</th><th>From</th><th>Role</th><th>Category</th><th>Status</th><th>Sent</th><th class="text-end"><span class="visually-hidden">Open</span></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r):
                        $url = 'support_requests.php?' . http_build_query(['id' => $r['id']] + $filter_query); ?>
                        <tr class="row-link<?php echo $detail && (int)$detail['id'] === (int)$r['id'] ? ' row-selected' : ''; ?>" data-href="<?php echo h($url); ?>">
                            <td class="text-nowrap"><strong><?php echo h($r['reference_no']); ?></strong></td>
                            <td>
                                <?php echo h($r['full_name']); ?><br>
                                <span class="text-muted small"><?php echo h($r['email']); ?><?php echo $r['id_number'] ? ' · ' . h($r['id_number']) : ''; ?></span>
                            </td>
                            <td><?php echo h(ucfirst($r['role'])); ?></td>
                            <td><?php echo h(support_category_label($r['category'])); ?></td>
                            <td><span class="badge <?php echo support_status_class($r['status']); ?>"><?php echo h($r['status']); ?></span></td>
                            <td class="text-nowrap small"><?php echo date('d M Y, h:i A', strtotime($r['created_at'])); ?></td>
                            <td class="text-end"><a href="<?php echo h($url); ?>" class="btn btn-sm btn-outline-secondary">View</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php render_pagination($list, $total); ?>
            <?php else: ?>
                <div class="empty-state"><i class="bi bi-inbox"></i>No support requests match these filters.</div>
            <?php endif; ?>
        </div>
<?php
$page_scripts = <<<'JS'
<script>
document.querySelectorAll('tr.row-link').forEach(function (tr) {
    tr.addEventListener('click', function (e) {
        if (!e.target.closest('a, button')) window.location = tr.dataset.href;
    });
});
</script>
JS;
require '../includes/page_end.php';
?>
