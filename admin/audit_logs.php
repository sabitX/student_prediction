<?php
require '../includes/session_check.php';
require '../config/config.php';
require '../includes/csrf.php';
require '../includes/semester.php';

// ── System-wide audit log (read-only) ───────────────────────────────────
// Every mark change by every lecturer and every administrator action
// (accounts, subjects, enrolments, semesters, requests), including people
// whose accounts were later deleted (actor_name is kept on the log row).

$filter_actor  = (int)($_GET['actor'] ?? 0);
// Filter choices are the action types actually present in the log.
$action_types  = array_column(mysqli_fetch_all(mysqli_query($conn, "SELECT DISTINCT action FROM audit_logs ORDER BY action"), MYSQLI_ASSOC), 'action');
$filter_action = in_array($_GET['action'] ?? '', $action_types, true) ? $_GET['action'] : '';
$search        = trim($_GET['q'] ?? '');

$where  = [];
$types  = '';
$params = [];
if ($filter_actor) {
    $where[] = 'a.user_id = ?';
    $types  .= 'i';
    $params[] = $filter_actor;
}
if ($filter_action !== '') {
    $where[] = 'a.action = ?';
    $types  .= 's';
    $params[] = $filter_action;
}
if ($search !== '') {
    $where[] = 'a.details LIKE ?';
    $types  .= 's';
    $params[] = '%' . $search . '%';
}
$sql = "SELECT a.action, a.details, a.created_at, COALESCE(u.full_name, a.actor_name, 'Unknown') AS actor, (u.id IS NULL) AS actor_deleted
        FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id"
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . " ORDER BY a.created_at DESC, a.id DESC LIMIT 500";
$stmt = mysqli_prepare($conn, $sql);
if ($params) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}
mysqli_stmt_execute($stmt);
$logs = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

$actors = mysqli_query($conn, "SELECT DISTINCT u.id, u.full_name FROM audit_logs a JOIN users u ON u.id = a.user_id ORDER BY u.full_name");

$page_title = 'Audit Log';
require '../includes/page_start.php';
?>
        <div class="page-header">
            <div>
                <h2><i class="bi bi-clock-history me-2"></i>Audit Log</h2>
                <p>Every mark change (with old → new values) and every administrator action. Showing the latest <?php echo count($logs); ?> matching entries (max 500).</p>
            </div>
        </div>

        <div class="dashboard-card">
            <form method="GET" class="row g-2 align-items-end mb-4">
                <div class="col-md-3">
                    <label class="form-label" for="actor">Person</label>
                    <select name="actor" id="actor" class="form-select form-select-sm">
                        <option value="0">All</option>
                        <?php while ($a = mysqli_fetch_assoc($actors)): ?>
                            <option value="<?php echo (int)$a['id']; ?>" <?php echo $filter_actor === (int)$a['id'] ? 'selected' : ''; ?>><?php echo h($a['full_name']); ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="action">Action</label>
                    <select name="action" id="action" class="form-select form-select-sm">
                        <option value="">All</option>
                        <?php foreach ($action_types as $act): ?>
                            <option value="<?php echo h($act); ?>" <?php echo $filter_action === $act ? 'selected' : ''; ?>><?php echo h($act); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="q">Search details</label>
                    <input type="text" name="q" id="q" class="form-control form-control-sm" value="<?php echo h($search); ?>" placeholder="Student ID or subject code">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-sm btn-primary-custom w-100">Filter</button>
                </div>
            </form>

            <?php if ($logs): ?>
            <div class="table-responsive">
                <table class="table mb-0" style="font-size:0.85rem;">
                    <thead class="table-custom-header"><tr><th>When</th><th>Lecturer</th><th>Action</th><th>Details</th></tr></thead>
                    <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td class="text-nowrap text-muted"><?php echo date('d M Y, h:i A', strtotime($log['created_at'])); ?></td>
                            <td><?php echo h($log['actor']); ?><?php if ($log['actor_deleted']): ?> <span class="badge bg-light text-muted border">deleted</span><?php endif; ?></td>
                            <td><span class="badge bg-secondary"><?php echo h($log['action']); ?></span></td>
                            <td><?php echo h($log['details']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <div class="empty-state"><i class="bi bi-journal-x"></i>No audit entries match.</div>
            <?php endif; ?>
        </div>
<?php require '../includes/page_end.php'; ?>
