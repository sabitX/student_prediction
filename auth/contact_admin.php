<?php
require '../includes/session.php';
require '../config/config.php';
require '../includes/csrf.php';
require '../includes/flash.php';
require '../includes/support.php';

// ── Contact Admin (no sign-in needed) ───────────────────────────────────
// For students and lecturers who can't sign in, e.g. a forgotten password.
// The request goes to the administrator (admin/support_requests.php), who
// verifies the person and, for a forgotten password, issues a one-time
// temporary password. Nothing here changes an account, and the answer is
// the same whether or not the details match one, so the form can't be used
// to find out which accounts exist.

// Signed-in users have their own Contact Admin page with their details filled in.
if (isset($_SESSION['user_id'])) {
    $role = $_SESSION['role'] ?? '';
    header('Location: ' . (in_array($role, ['student', 'lecturer'], true) ? "../$role/contact_admin.php" : '../admin/admin_dashboard.php'));
    exit();
}

$portal    = (($_GET['portal'] ?? $_POST['portal'] ?? '') === 'staff') ? 'staff' : 'student';
$login_url = 'login.php' . ($portal === 'staff' ? '?portal=staff' : '');

$flash     = flash_take();
$reference = $flash['reference'] ?? '';
$success   = $flash['success'] ?? '';
$error     = '';
$errors    = [];

$topic = $_GET['topic'] ?? '';
$form  = [
    'role'      => $portal === 'staff' ? 'lecturer' : 'student',
    'full_name' => '', 'id_number' => '', 'email' => '',
    'category'  => isset(SUPPORT_CATEGORIES[$topic]) ? $topic : 'forgot_password',
    'message'   => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form   = support_read_form($_POST);
    $errors = support_validate($form);

    if (!csrf_verify()) {
        $error = "Your session expired. Please send the form again.";
    } elseif (($_POST['leave_blank'] ?? '') !== '') {
        // Hidden field that only bots fill in.
        $error = "Your request could not be sent. Please try again.";
    } elseif ($errors) {
        $error = "Please correct the highlighted fields.";
    } elseif (($throttle = support_throttle_error($conn, $form['email'])) !== '') {
        $error = $throttle;
    } else {
        support_record_submission($conn, $form['email']);
        try {
            $ref = support_save($conn, $form);
        } catch (mysqli_sql_exception $e) {
            error_log('SPTS support request: ' . $e->getMessage());
            $ref = '';
            $error = "Your request could not be saved. Please try again in a few minutes.";
        }
        if ($ref !== '') {
            support_notify_admins($conn, $ref, $form);
            redirect_after_success('sent', '', ['reference' => $ref],
                'contact_admin.php?sent=1' . ($portal === 'staff' ? '&portal=staff' : ''));
        }
    }
}

$field_class = fn($field) => isset($errors[$field]) ? ' is-invalid' : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact Admin | SPTS Nilai University</title>
    <script>(function(){try{if(localStorage.getItem('spts-theme')==='dark')document.documentElement.setAttribute('data-bs-theme','dark');}catch(e){}})();</script>
    <link href="../assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="../assets/css/dark-mode.css?v=4" rel="stylesheet">
    <link href="../assets/css/app.css?v=6" rel="stylesheet">
    <link rel="icon" href="../assets/images/favicon-32.png" type="image/png" sizes="32x32">
    <link rel="icon" href="../assets/images/favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="../assets/images/apple-touch-icon.png">
    <style>
        *, *::before, *::after { box-sizing: border-box; }

        body {
            font-family: 'Inter', system-ui, sans-serif;
            background: #F0F4F8;
            color: #1A1A2E;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            padding: 40px 20px;
        }

        .bg-logo {
            position: fixed;
            top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            width: 80vw; height: 80vh;
            background-image: url('../assets/images/nilai_logo.png');
            background-size: contain;
            background-repeat: no-repeat;
            background-position: center;
            opacity: 0.08;
            pointer-events: none;
            z-index: 99;
            filter: grayscale(100%);
        }

        .container { position: relative; z-index: 100; }

        .request-card {
            width: 100%;
            max-width: 540px;
            background: #fff;
            border: 1px solid #E2E8F0;
            border-radius: 14px;
            padding: 40px;
            box-shadow: 0 10px 36px rgba(26,58,92,0.08);
        }
        @media (max-width: 575.98px) { .request-card { padding: 28px 22px; } }

        .brand-icon { font-size: 2.5rem; color: #1A3A5C; margin-bottom: 10px; }
        .login-title { font-size: 1.25rem; font-weight: 700; color: #1A3A5C; margin-bottom: 4px; text-align: center; }
        .login-subtitle { font-size: 0.85rem; color: #6C757D; text-align: center; margin-bottom: 24px; }
        .form-label { font-size: 0.85rem; font-weight: 600; color: #1A3A5C; }
        .form-label .optional { font-weight: 400; color: #94A3B8; }
        .form-text { font-size: 0.75rem; }

        .input-group-text { background: #F8FAFC; border-right: none; color: #6C757D; }
        .form-control, .form-select { border-left: none; background-color: #F8FAFC; font-size: 0.9rem; }
        textarea.form-control { border-left: 1px solid var(--bs-border-color); }
        .form-control:focus, .form-select:focus { box-shadow: none; border-color: #0D7377; background-color: #fff; }
        .input-group:focus-within .input-group-text { border-color: #0D7377; background: #fff; }
        .form-control.is-invalid, .form-select.is-invalid { border-color: var(--bs-form-invalid-border-color); }
        .input-group:has(.is-invalid) .input-group-text { border-color: var(--bs-form-invalid-border-color); }

        .role-choice .btn { font-size: 0.85rem; font-weight: 600; }
        .role-choice .btn-check:checked + .btn { background: #0D7377; border-color: #0D7377; color: #fff; }
        .role-choice .btn-outline-secondary { color: #475569; border-color: #CBD5E1; }

        .btn-primary-custom {
            background: #0D7377; border: 2px solid #0D7377; color: #fff;
            font-weight: 600; padding: 10px; border-radius: 8px; transition: background 0.2s;
        }
        .btn-primary-custom:hover { background: #0a5e62; border-color: #0a5e62; color: #fff; }

        .link-custom { color: #0D7377; text-decoration: none; font-weight: 600; }
        .link-custom:hover { text-decoration: underline; color: #0a5e62; }

        .how-it-works {
            background: #F0F9F9; border: 1px solid #CCE7E7; border-radius: 10px;
            padding: 12px 14px; font-size: 0.8rem; color: #334155; margin-bottom: 22px;
        }
        .how-it-works ol { margin: 6px 0 0; padding-left: 18px; }
        [data-bs-theme="dark"] .how-it-works { background: rgba(13,115,119,0.12); border-color: rgba(13,115,119,0.35); color: #CBD5E1; }

        .reference-box {
            border: 2px dashed #0D7377; border-radius: 10px; padding: 14px; text-align: center; margin: 16px 0;
        }
        .reference-box code { font-size: 1.35rem; font-weight: 700; color: #0D7377; letter-spacing: 1px; }
        .hp-field { position: absolute; left: -10000px; width: 1px; height: 1px; overflow: hidden; }
    </style>
</head>
<body>

<div class="bg-logo"></div>

<button type="button" class="theme-toggle theme-toggle-floating" onclick="toggleTheme()" title="Toggle dark mode">
    <i class="bi bi-moon-stars-fill theme-toggle-icon"></i>
</button>

<div class="container d-flex justify-content-center">
    <div class="request-card">
        <div class="text-center">
            <i class="bi bi-headset brand-icon"></i>
            <h1 class="login-title">Contact the Administrator</h1>
            <p class="login-subtitle">Help with signing in to SPTS</p>
        </div>

        <?php if ($success && $reference): ?>
            <div class="alert alert-success d-flex align-items-start gap-2 py-2 mb-0" style="font-size: 0.85rem;" role="status">
                <i class="bi bi-check-circle-fill mt-1"></i>
                <div>Your request has been sent to the administrator.</div>
            </div>
            <div class="reference-box">
                <div class="text-muted small mb-1">Your reference number</div>
                <code class="user-select-all"><?php echo h($reference); ?></code>
                <div class="text-muted small mt-1">Keep it and quote it when you speak to the admin office.</div>
            </div>
            <div class="how-it-works mb-3">
                <strong><i class="bi bi-info-circle me-1"></i>What happens next</strong>
                <ol>
                    <li>The administrator checks your details against university records.</li>
                    <li>Before any account is reset, the administrator must confirm who you are, for example with your student or staff card.</li>
                    <li>If your password is reset, you get a one-time temporary password and must choose a new password when you next sign in.</li>
                </ol>
                <div class="mt-2">For your security, nobody at the university will ever ask you for your password.</div>
            </div>
            <a href="<?php echo h($login_url); ?>" class="btn btn-primary-custom w-100">
                <i class="bi bi-box-arrow-in-right me-2"></i>Back to Sign-in
            </a>
        <?php else: ?>

            <div class="how-it-works">
                <strong><i class="bi bi-info-circle me-1"></i>Forgot your password, or can't sign in?</strong>
                <ol>
                    <li>Send this form with the details on your account. You'll get a reference number.</li>
                    <li>The administrator confirms who you are, for example with your student or staff card.</li>
                    <li>They then give you a one-time temporary password. You choose your own password the next time you sign in.</li>
                </ol>
                <div class="mt-2"><i class="bi bi-shield-lock me-1"></i>Never type your password into this form. The administrator will never ask for it.</div>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger d-flex align-items-start gap-2 py-2" style="font-size:0.85rem;" role="alert">
                    <i class="bi bi-exclamation-circle-fill mt-1"></i>
                    <div><?php echo h($error); ?></div>
                </div>
            <?php endif; ?>

            <form method="POST" id="supportForm" novalidate>
                <?php echo csrf_field(); ?>
                <input type="hidden" name="portal" value="<?php echo h($portal); ?>">
                <div class="hp-field" aria-hidden="true">
                    <label for="leave_blank">Leave this empty</label>
                    <input type="text" name="leave_blank" id="leave_blank" tabindex="-1" autocomplete="off">
                </div>

                <div class="mb-3">
                    <span class="form-label d-block mb-1" id="roleLabel">I am a</span>
                    <div class="btn-group w-100 role-choice<?php echo $field_class('role'); ?>" role="group" aria-labelledby="roleLabel">
                        <input type="radio" class="btn-check" name="role" id="roleStudent" value="student" autocomplete="off" <?php echo $form['role'] === 'student' ? 'checked' : ''; ?>>
                        <label class="btn btn-outline-secondary" for="roleStudent"><i class="bi bi-mortarboard me-1"></i>Student</label>
                        <input type="radio" class="btn-check" name="role" id="roleLecturer" value="lecturer" autocomplete="off" <?php echo $form['role'] === 'lecturer' ? 'checked' : ''; ?>>
                        <label class="btn btn-outline-secondary" for="roleLecturer"><i class="bi bi-person-badge me-1"></i>Lecturer</label>
                    </div>
                    <?php if (isset($errors['role'])): ?><div class="invalid-feedback d-block"><?php echo h($errors['role']); ?></div><?php endif; ?>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="full_name">Full Name</label>
                    <div class="input-group has-validation">
                        <span class="input-group-text"><i class="bi bi-person-fill"></i></span>
                        <input type="text" name="full_name" id="full_name" class="form-control<?php echo $field_class('full_name'); ?>" placeholder="As registered with the university" required autocomplete="name" maxlength="100" value="<?php echo h($form['full_name']); ?>">
                        <div class="invalid-feedback"><?php echo h($errors['full_name'] ?? 'Enter your full name.'); ?></div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="id_number" id="idLabel"><?php echo h(support_id_label($form['role'])); ?></label>
                    <div class="input-group has-validation">
                        <span class="input-group-text"><i class="bi bi-credit-card-fill"></i></span>
                        <input type="text" name="id_number" id="id_number" class="form-control<?php echo $field_class('id_number'); ?>" required maxlength="30" autocomplete="off" value="<?php echo h($form['id_number']); ?>">
                        <div class="invalid-feedback" id="idFeedback"><?php echo h($errors['id_number'] ?? ''); ?></div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="email">Registered Email Address</label>
                    <div class="input-group has-validation">
                        <span class="input-group-text"><i class="bi bi-envelope-fill"></i></span>
                        <input type="email" name="email" id="email" class="form-control<?php echo $field_class('email'); ?>" placeholder="The email you sign in with" required autocomplete="email" maxlength="100" value="<?php echo h($form['email']); ?>">
                        <div class="invalid-feedback"><?php echo h($errors['email'] ?? 'Enter a valid email address.'); ?></div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="category">What do you need help with?</label>
                    <div class="input-group has-validation">
                        <span class="input-group-text"><i class="bi bi-tag-fill"></i></span>
                        <select name="category" id="category" class="form-select<?php echo $field_class('category'); ?>" required>
                            <?php foreach (SUPPORT_CATEGORIES as $value => $label): ?>
                                <option value="<?php echo $value; ?>" <?php echo $form['category'] === $value ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="invalid-feedback"><?php echo h($errors['category'] ?? 'Choose what you need help with.'); ?></div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="message">Message <span class="optional" id="messageOptional">(optional)</span></label>
                    <textarea name="message" id="message" class="form-control<?php echo $field_class('message'); ?>" rows="4" maxlength="<?php echo SUPPORT_MESSAGE_MAX; ?>"
                              placeholder="What happens when you try to sign in? Don't include your password."><?php echo h($form['message']); ?></textarea>
                    <div class="invalid-feedback"><?php echo h($errors['message'] ?? 'Describe the problem in a few words (at least 10 characters).'); ?></div>
                    <div class="form-text text-end"><span id="messageCount">0</span> / <?php echo SUPPORT_MESSAGE_MAX; ?></div>
                </div>

                <p class="text-muted text-center mb-2" style="font-size:0.75rem;"><i class="bi bi-shield-check me-1"></i>How we use these details: <a href="privacy.php" class="link-custom">Privacy notice</a></p>
                <button type="submit" class="btn btn-primary-custom w-100 mt-1" id="submitBtn">
                    <span class="btn-label"><i class="bi bi-send me-2"></i>Send to Administrator</span>
                    <span class="btn-loading d-none"><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Sending…</span>
                </button>
            </form>

            <div class="text-center mt-4">
                <a href="<?php echo h($login_url); ?>" class="link-custom" style="font-size:0.85rem;">
                    <i class="bi bi-arrow-left me-1"></i>Back to Sign-in
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Client-side checks mirror includes/support.php; the server decides.
(function () {
    const form = document.getElementById('supportForm');
    if (!form) return;
    const id = document.getElementById('id_number');
    const idLabel = document.getElementById('idLabel');
    const idFeedback = document.getElementById('idFeedback');
    const category = document.getElementById('category');
    const message = document.getElementById('message');
    const count = document.getElementById('messageCount');
    const serverIdError = idFeedback.textContent.trim();

    function role() { return form.querySelector('input[name="role"]:checked')?.value || 'student'; }

    function applyRole() {
        const student = role() === 'student';
        idLabel.textContent = student ? 'Student ID' : 'Staff ID';
        id.placeholder = student ? 'e.g. n00021960' : 'Your university staff ID';
        id.pattern = student ? '[nN][0-9]{7,10}' : '[A-Za-z0-9][A-Za-z0-9\\/\\-]{1,29}';
        idFeedback.textContent = serverIdError && id.classList.contains('is-invalid') ? serverIdError
            : (student ? 'Student ID must be the letter n followed by 7–10 digits (e.g. n00021960).'
                       : 'Enter your staff ID (letters, numbers, - and /).');
    }
    function applyCategory() {
        const optional = category.value === 'forgot_password';
        document.getElementById('messageOptional').classList.toggle('d-none', !optional);
        message.required = !optional;
        message.minLength = optional ? 0 : 10;
    }
    function updateCount() { count.textContent = message.value.length; }

    form.querySelectorAll('input[name="role"]').forEach(r => r.addEventListener('change', () => { id.classList.remove('is-invalid'); applyRole(); }));
    category.addEventListener('change', applyCategory);
    message.addEventListener('input', updateCount);
    form.querySelectorAll('.form-control, .form-select').forEach(el => el.addEventListener('input', () => el.classList.remove('is-invalid')));
    applyRole(); applyCategory(); updateCount();

    form.addEventListener('submit', function (e) {
        let ok = true;
        form.querySelectorAll('.form-control, .form-select').forEach(function (el) {
            if (el.name === 'leave_blank') return;
            const tooShort = el === message && message.required && message.value.trim().length < 10;
            const bad = !el.checkValidity() || tooShort || (el.required && el.value.trim() === '');
            el.classList.toggle('is-invalid', bad);
            if (bad && ok) { el.focus(); ok = false; }
        });
        if (!ok) { e.preventDefault(); return; }
        // Loading state; also stops a double submit.
        const btn = document.getElementById('submitBtn');
        btn.disabled = true;
        btn.querySelector('.btn-label').classList.add('d-none');
        btn.querySelector('.btn-loading').classList.remove('d-none');
    });
})();
</script>
<script src="../assets/js/theme-toggle.js"></script>
<script src="../assets/js/app.js?v=4"></script>
</body>
</html>
