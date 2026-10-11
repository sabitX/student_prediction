<?php
require '../includes/session_check.php';
require '../config/config.php';
require '../includes/semester.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'lecturer') {
    header("Location: ../auth/login.php");
    exit();
}

$user_id    = $_SESSION['user_id'];
$full_name  = $_SESSION['full_name'];
$first_name = explode(' ', trim($full_name))[0];
$initials   = strtoupper(substr($first_name, 0, 1) . substr(explode(' ', trim($full_name))[1] ?? '', 0, 1));
$active_semester = h(get_active_semester_name($conn));

// ── SECURE QUERY: AUDIT LOGS ─────────────────────────────────────────────
$stmt_logs = mysqli_prepare($conn, "SELECT action, details, created_at FROM audit_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 200");
mysqli_stmt_bind_param($stmt_logs, "i", $user_id);
mysqli_stmt_execute($stmt_logs);
$logs = mysqli_stmt_get_result($stmt_logs);
$log_count = mysqli_num_rows($logs);
mysqli_stmt_close($stmt_logs);
?>
<?php
// Shared head, topbar and sidebar (includes/page_start.php); only this
// page's own CSS is here.
$page_title      = 'Audit Trail';
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

        /* Dashboard Cards */
        .dashboard-card { background: white; border-radius: 16px; padding: 28px; box-shadow: 0 8px 32px rgba(26,58,92,0.05); margin-bottom: 24px; }

        .action-badge { padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; display: inline-block; background: #E0F2FE; color: #0369A1; }
        /* Shared rules this page does not use (keeps its original look). */
        .page-header { gap: normal; }
        .table > tbody > tr > td { vertical-align: inherit; font-size: inherit; color: var(--bs-table-color-state, var(--bs-table-color-type, var(--bs-table-color))); padding: .5rem .5rem; border-color: inherit; }
CSS;
require '../includes/page_start.php';
?>

        <div class="page-header">
            <div>
                <h2>Audit Trail</h2>
                <p>A record of your recent mark entries and data changes.</p>
            </div>
            <div class="badge-semester">
                <i class="bi bi-calendar3"></i> <?php echo $active_semester; ?>
            </div>
        </div>

        <div class="dashboard-card">
            <div class="card-header-title">
                <i class="bi bi-clock-history text-primary"></i>Recent Activity
                <?php if ($log_count > 0): ?>
                    <span class="badge bg-secondary ms-2" style="font-size:0.75rem;"><?php echo $log_count; ?> entries</span>
                <?php endif; ?>
            </div>

            <?php if ($log_count > 0): ?>
            <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size:0.88rem;">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Action</th>
                        <th>Details</th>
                        <th class="pe-3">Date & Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($log = mysqli_fetch_assoc($logs)): ?>
                    <tr>
                        <td class="ps-3"><span class="action-badge"><?php echo h($log['action']); ?></span></td>
                        <td style="max-width:480px; color:#334155;"><?php echo h($log['details']); ?></td>
                        <td class="pe-3 text-muted"><i class="bi bi-clock me-1"></i><?php echo date('d M Y, h:i A', strtotime($log['created_at'])); ?></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <i class="bi bi-clock-history"></i>
                <p class="mb-0">No activity recorded yet.</p>
            </div>
            <?php endif; ?>
        </div>

<?php require '../includes/page_end.php'; ?>
