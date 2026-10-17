<?php
require '../includes/session.php';
require '../config/config.php';
require '../includes/csrf.php';
require '../includes/login_throttle.php';
require '../includes/mailer.php';

// ── Account request for new lecturers ───────────────────────────────────
// Staff accounts are never created here: this page only files a request.
// An administrator reviews it in admin/account_requests.php; approving
// creates the lecturer account and emails the sign-in details to the
// personal email given below. Submissions are rate-limited per email + IP.

$success = "";
$error   = "";
$form    = ['full_name' => '', 'email' => '', 'personal_email' => '', 'staff_id' => '', 'department' => '', 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    foreach ($form as $key => $_) {
        $form[$key] = is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : '';
    }

    if (!csrf_verify()) {
        $error = "Your session expired. Please try again.";
    } elseif ($form['full_name'] === '' || $form['email'] === '' || $form['personal_email'] === '' || $form['department'] === '') {
        $error = "Please fill in all the required fields.";
    } elseif (!filter_var($form['email'], FILTER_VALIDATE_EMAIL) || !filter_var($form['personal_email'], FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter valid email addresses.";
    } elseif (mb_strlen($form['full_name']) > 100 || mb_strlen($form['department']) > 100 || mb_strlen($form['staff_id']) > 30 || mb_strlen($form['message']) > 500) {
        $error = "One of the fields is too long.";
    // Limited per network (the throttle key always includes the IP), not per
    // typed email: each request emails an address the sender chooses, so a
    // per-email limit could be dodged by changing that field.
    } elseif (login_is_throttled($conn, '*', 'account_request')) {
        $error = "Too many requests. Please wait " . LOGIN_WINDOW_MINUTES . " minutes and try again.";
    } else {
        login_record_failed_attempt($conn, '*', 'account_request');

        $stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ?");
        mysqli_stmt_bind_param($stmt, "s", $form['email']);
        mysqli_stmt_execute($stmt);
        $has_account = mysqli_num_rows(mysqli_stmt_get_result($stmt)) > 0;
        mysqli_stmt_close($stmt);

        $stmt = mysqli_prepare($conn, "SELECT id FROM account_requests WHERE email = ? AND status = 'Pending'");
        mysqli_stmt_bind_param($stmt, "s", $form['email']);
        mysqli_stmt_execute($stmt);
        $has_pending = mysqli_num_rows(mysqli_stmt_get_result($stmt)) > 0;
        mysqli_stmt_close($stmt);

        if ($has_account) {
            $error = "An account with this university email already exists. If you can't sign in, use Forgot Password on the staff sign-in page.";
        } elseif ($has_pending) {
            $error = "A request for this email is already waiting for the administrator. You'll get an email at your personal address once it's reviewed.";
        } else {
            $staff_id = $form['staff_id'] !== '' ? $form['staff_id'] : null;
            $message  = $form['message'] !== '' ? $form['message'] : null;
            $stmt = mysqli_prepare($conn, "INSERT INTO account_requests (full_name, email, personal_email, staff_id, department, message) VALUES (?, ?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, "ssssss", $form['full_name'], $form['email'], $form['personal_email'], $staff_id, $form['department'], $message);
            try {
                mysqli_stmt_execute($stmt);
                $saved = true;
            } catch (mysqli_sql_exception $e) {
                // uniq_pending_email: an identical request arrived at the same
                // moment (e.g. a double-click) and was saved first.
                if ($e->getCode() !== 1062) throw $e;
                $saved = false;
                $error = "A request for this email is already waiting for the administrator. You'll get an email at your personal address once it's reviewed.";
            }
            mysqli_stmt_close($stmt);
        }

        if ($error === '' && !empty($saved)) {
            // Best effort: let the admins know, and confirm to the requester.
            // The request is saved either way, and shows in the admin panel.
            if (mail_is_configured()) {
                $admins = mysqli_query($conn, "SELECT full_name, email FROM users WHERE role = 'admin'");
                while ($admin = mysqli_fetch_assoc($admins)) {
                    send_mail($admin['email'], $admin['full_name'], 'New lecturer account request',
                        "Hello {$admin['full_name']},\n\n"
                      . "{$form['full_name']} ({$form['department']}) has asked for a lecturer account on SPTS.\n\n"
                      . "University email: {$form['email']}\n"
                      . "Staff ID: " . ($staff_id ?? '—') . "\n\n"
                      . "Please review it under Account Requests in the admin panel.\n\n"
                      . "— SPTS, Nilai University");
                }
                send_mail($form['personal_email'], $form['full_name'], 'We received your SPTS account request',
                    "Hello {$form['full_name']},\n\n"
                  . "Thank you for your request for a lecturer account on the Student Performance Tracking System.\n\n"
                  . "An administrator will review it. Once it is approved, we'll email your sign-in details to this address.\n\n"
                  . "— SPTS, Nilai University");
            }

            $success = "Your request has been sent to the administrator. Once it's approved, your sign-in details will be emailed to "
                     . $form['personal_email'] . ".";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request an Account | SPTS Nilai University</title>
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
            max-width: 520px;
            background: #fff;
            border: 1px solid #E2E8F0;
            border-radius: 14px;
            padding: 40px;
            box-shadow: 0 10px 36px rgba(26,58,92,0.08);
        }

        .brand-icon { font-size: 2.5rem; color: #1A3A5C; margin-bottom: 10px; }
        .login-title { font-size: 1.25rem; font-weight: 700; color: #1A3A5C; margin-bottom: 4px; text-align: center; }
        .login-subtitle { font-size: 0.85rem; color: #6C757D; text-align: center; margin-bottom: 24px; }
        .form-label { font-size: 0.85rem; font-weight: 600; color: #1A3A5C; }
        .form-label .optional { font-weight: 400; color: #94A3B8; }
        .form-text { font-size: 0.75rem; }

        .input-group-text { background: #F8FAFC; border-right: none; color: #6C757D; }
        .form-control { border-left: none; background: #F8FAFC; font-size: 0.9rem; }
        textarea.form-control { border-left: 1px solid var(--bs-border-color); }
        .form-control:focus { box-shadow: none; border-color: #0D7377; background: #fff; }
        .input-group:focus-within .input-group-text { border-color: #0D7377; background: #fff; }

        .btn-primary-custom {
            background: #0D7377; border: 2px solid #0D7377; color: #fff;
            font-weight: 600; padding: 10px; border-radius: 8px; transition: all 0.2s;
        }
        .btn-primary-custom:hover {
            background: #0a5e62; border-color: #0a5e62; color: #fff;
            transform: translateY(-1px); box-shadow: 0 4px 12px rgba(13,115,119,0.2);
        }

        .link-custom { color: #0D7377; text-decoration: none; font-weight: 600; }
        .link-custom:hover { text-decoration: underline; color: #0a5e62; }

        .how-it-works {
            background: #F0F9F9; border: 1px solid #CCE7E7; border-radius: 10px;
            padding: 12px 14px; font-size: 0.8rem; color: #334155; margin-bottom: 22px;
        }
        .how-it-works ol { margin: 6px 0 0; padding-left: 18px; }
        [data-bs-theme="dark"] .how-it-works { background: rgba(13,115,119,0.12); border-color: rgba(13,115,119,0.35); color: #CBD5E1; }
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
            <i class="bi bi-person-plus-fill brand-icon"></i>
            <h1 class="login-title">Request a Lecturer Account</h1>
            <p class="login-subtitle">Student Performance Tracking System — Nilai University</p>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success d-flex align-items-start gap-2 py-2" style="font-size: 0.85rem;">
                <i class="bi bi-check-circle-fill mt-1"></i>
                <div><?php echo h($success); ?></div>
            </div>
            <a href="login.php?portal=staff" class="btn btn-primary-custom w-100 mt-2">
                <i class="bi bi-box-arrow-in-right me-2"></i>Go to Staff Sign-in
            </a>
        <?php else: ?>

            <div class="how-it-works">
                <strong><i class="bi bi-info-circle me-1"></i>How it works</strong>
                <ol>
                    <li>Send this request to the administrator.</li>
                    <li>Once approved, your sign-in details and a temporary password are emailed to your personal email.</li>
                    <li>Sign in with your university email, then set your own password.</li>
                </ol>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger d-flex align-items-start gap-2 py-2" style="font-size:0.85rem;">
                    <i class="bi bi-exclamation-circle-fill mt-1"></i>
                    <div><?php echo h($error); ?></div>
                </div>
            <?php endif; ?>

            <form method="POST">
                <?php echo csrf_field(); ?>

                <div class="mb-3">
                    <label class="form-label" for="full_name">Full Name</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-person-fill"></i></span>
                        <input type="text" name="full_name" id="full_name" class="form-control" placeholder="e.g. Dr. Aisha Rahman" required autocomplete="name" maxlength="100" value="<?php echo h($form['full_name']); ?>">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="email">University Email</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-building"></i></span>
                        <input type="email" name="email" id="email" class="form-control" placeholder="your.name@nilai.edu.my" required autocomplete="work email" maxlength="100" value="<?php echo h($form['email']); ?>">
                    </div>
                    <div class="form-text">You'll sign in with this email.</div>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="personal_email">Personal Email</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-envelope-fill"></i></span>
                        <input type="email" name="personal_email" id="personal_email" class="form-control" placeholder="e.g. name@gmail.com" required autocomplete="email" maxlength="100" value="<?php echo h($form['personal_email']); ?>">
                    </div>
                    <div class="form-text">Your sign-in details will be sent here.</div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-sm-6">
                        <label class="form-label" for="department">Department / Faculty</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-diagram-3"></i></span>
                            <input type="text" name="department" id="department" class="form-control" placeholder="e.g. Computing" required maxlength="100" value="<?php echo h($form['department']); ?>">
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label" for="staff_id">Staff ID <span class="optional">(if you have one)</span></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-person-vcard"></i></span>
                            <input type="text" name="staff_id" id="staff_id" class="form-control" placeholder="e.g. S12345" maxlength="30" value="<?php echo h($form['staff_id']); ?>">
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="message">Message to the administrator <span class="optional">(optional)</span></label>
                    <textarea name="message" id="message" class="form-control" rows="3" maxlength="500" placeholder="e.g. the subjects you'll be teaching this semester"><?php echo h($form['message']); ?></textarea>
                </div>

                <button type="submit" class="btn btn-primary-custom w-100 mt-2">
                    <i class="bi bi-send me-2"></i>Send Request
                </button>
            </form>

            <div class="text-center mt-4">
                <a href="login.php?portal=staff" class="link-custom" style="font-size:0.85rem;">
                    <i class="bi bi-arrow-left me-1"></i>Back to Staff Sign-in
                </a>
            </div>

        <?php endif; ?>
    </div>
</div>

<script src="../assets/js/theme-toggle.js"></script>
<script src="../assets/js/app.js?v=4"></script>
</body>
</html>
