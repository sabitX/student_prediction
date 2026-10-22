<?php
// ── Contact Admin page for signed-in students and lecturers ─────────────
// student/contact_admin.php and lecturer/contact_admin.php both include
// this, so the folder still decides who may open it (session_check.php).
// The requester's name, email and Student ID come from their account; they
// only choose a category and write a message. Below the form they can
// follow the status of what they have sent.
require_once __DIR__ . '/session_check.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/semester.php';
require_once __DIR__ . '/flash.php';
require_once __DIR__ . '/support.php';

$user_id = (int)$_SESSION['user_id'];
$stmt = mysqli_prepare($conn, "SELECT u.full_name, u.email, u.role, s.student_id
                               FROM users u LEFT JOIN students s ON s.user_id = u.id WHERE u.id = ?");
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$account = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

$flash     = flash_take();
$success   = $flash['success'] ?? '';
$reference = $flash['reference'] ?? '';
$error     = '';
$errors    = [];
$form      = ['category' => 'other', 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form   = support_read_form($_POST, $account);
    $errors = support_validate($form, true);

    if (!csrf_verify()) {
        $error = "Your session expired. Please send the form again.";
    } elseif ($errors) {
        $error = "Please correct the highlighted fields.";
    } elseif (login_is_throttled($conn, $account['email'], 'support_email', SUPPORT_MAX_PER_EMAIL)) {
        $error = "You've sent several requests in the last " . LOGIN_WINDOW_MINUTES . " minutes. Please wait before sending another.";
    } else {
        login_record_failed_attempt($conn, $account['email'], 'support_email');
        try {
            $ref = support_save($conn, $form, $user_id);
            support_notify_admins($conn, $ref, $form);
            redirect_after_success("Your request has been sent to the administrator. Reference: $ref.", '', ['reference' => $ref]);
        } catch (mysqli_sql_exception $e) {
            error_log('SPTS support request: ' . $e->getMessage());
            $error = "Your request could not be saved. Please try again in a few minutes.";
        }
    }
}

$stmt = mysqli_prepare($conn, "SELECT reference_no, category, message, status, admin_note, created_at, updated_at
                               FROM support_requests WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 20");
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$my_requests = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

$field_class = fn($field) => isset($errors[$field]) ? ' is-invalid' : '';

$page_title = 'Contact Admin';
$page_styles = <<<'CSS'
        .support-identity { background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 10px; padding: 12px 16px; font-size: 0.85rem; }
        .support-identity dt { font-weight: 600; color: #64748B; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.4px; }
        .support-identity dd { margin-bottom: 0; color: #1A3A5C; font-weight: 500; word-break: break-word; }
        [data-bs-theme="dark"] .support-identity { background: rgba(255,255,255,0.03); border-color: rgba(255,255,255,0.1); }
        [data-bs-theme="dark"] .support-identity dd { color: #E2E8F0; }
CSS;
require __DIR__ . '/page_start.php';
?>
        <div class="page-header">
            <div>
                <h2><i class="bi bi-headset me-2"></i>Contact Admin</h2>
                <p>Ask the administrator for help with your account or the system. You can follow your requests below.</p>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-xl-6">
                <div class="dashboard-card">
                    <div class="card-header-title"><i class="bi bi-send"></i>New Request</div>

                    <?php if ($reference): ?>
                        <div class="alert alert-ok py-2"><i class="bi bi-hash me-1"></i>Keep your reference number <strong class="user-select-all"><?php echo h($reference); ?></strong>.</div>
                    <?php endif; ?>

                    <dl class="support-identity row g-2 mb-3">
                        <div class="col-sm-6"><dt>Name</dt><dd><?php echo h($account['full_name']); ?></dd></div>
                        <div class="col-sm-6"><dt>Role</dt><dd><?php echo h(ucfirst($account['role'])); ?></dd></div>
                        <div class="col-sm-6"><dt>Email</dt><dd><?php echo h($account['email']); ?></dd></div>
                        <?php if ($account['student_id']): ?>
                            <div class="col-sm-6"><dt>Student ID</dt><dd><?php echo h($account['student_id']); ?></dd></div>
                        <?php endif; ?>
                    </dl>
                    <p class="text-muted small mb-3">These come from your account. If any of them is wrong, say so in your message. See the <a href="../auth/privacy.php">privacy notice</a> for what SPTS keeps about you.</p>

                    <form method="POST" id="supportForm" novalidate>
                        <?php echo csrf_field(); ?>
                        <div class="mb-3">
                            <label class="form-label" for="category">Category</label>
                            <select name="category" id="category" class="form-select<?php echo $field_class('category'); ?>" required>
                                <?php foreach (SUPPORT_CATEGORIES as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" <?php echo $form['category'] === $value ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback"><?php echo h($errors['category'] ?? 'Choose a category.'); ?></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="message">Message <span class="text-muted fw-normal" id="messageOptional">(optional)</span></label>
                            <textarea name="message" id="message" class="form-control<?php echo $field_class('message'); ?>" rows="5" maxlength="<?php echo SUPPORT_MESSAGE_MAX; ?>"
                                      placeholder="How can the administrator help? Never include your password."><?php echo h($form['message']); ?></textarea>
                            <div class="invalid-feedback"><?php echo h($errors['message'] ?? 'Describe the problem in a few words (at least 10 characters).'); ?></div>
                            <div class="form-text text-end"><span id="messageCount">0</span> / <?php echo SUPPORT_MESSAGE_MAX; ?></div>
                        </div>
                        <button type="submit" class="btn btn-primary-custom" id="submitBtn">
                            <span class="btn-label"><i class="bi bi-send me-2"></i>Send to Administrator</span>
                            <span class="btn-loading d-none"><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Sending…</span>
                        </button>
                    </form>
                </div>
            </div>

            <div class="col-xl-6">
                <div class="dashboard-card">
                    <div class="card-header-title"><i class="bi bi-list-check"></i>My Requests</div>
                    <?php if ($my_requests): ?>
                        <div class="table-responsive">
                            <table class="table mb-0">
                                <thead class="table-custom-header"><tr><th>Reference</th><th>Category</th><th>Status</th><th>Sent</th></tr></thead>
                                <tbody>
                                <?php foreach ($my_requests as $r): ?>
                                    <tr>
                                        <td class="text-nowrap"><strong><?php echo h($r['reference_no']); ?></strong></td>
                                        <td>
                                            <?php echo h(support_category_label($r['category'])); ?>
                                            <?php if ($r['admin_note']): ?>
                                                <div class="small text-secondary mt-1"><i class="bi bi-reply me-1"></i><?php echo h($r['admin_note']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="badge <?php echo support_status_class($r['status']); ?>"><?php echo h($r['status']); ?></span></td>
                                        <td class="text-nowrap small" title="<?php echo date('d M Y, h:i A', strtotime($r['created_at'])); ?>"><?php echo date('d M Y', strtotime($r['created_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state"><i class="bi bi-inbox"></i>You haven't sent any requests yet.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
<?php
$page_scripts = <<<'JS'
<script>
(function () {
    const form = document.getElementById('supportForm');
    const category = document.getElementById('category');
    const message = document.getElementById('message');
    const count = document.getElementById('messageCount');
    function applyCategory() {
        const optional = category.value === 'forgot_password';
        document.getElementById('messageOptional').classList.toggle('d-none', !optional);
    }
    function updateCount() { count.textContent = message.value.length; }
    category.addEventListener('change', applyCategory);
    message.addEventListener('input', () => { updateCount(); message.classList.remove('is-invalid'); });
    applyCategory(); updateCount();
    form.addEventListener('submit', function (e) {
        if (category.value !== 'forgot_password' && message.value.trim().length < 10) {
            message.classList.add('is-invalid');
            message.focus();
            e.preventDefault();
            return;
        }
        const btn = document.getElementById('submitBtn');
        btn.disabled = true;
        btn.querySelector('.btn-label').classList.add('d-none');
        btn.querySelector('.btn-loading').classList.remove('d-none');
    });
})();
</script>
JS;
require __DIR__ . '/page_end.php';
?>
