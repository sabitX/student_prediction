<?php
require '../includes/session.php';
require '../config/config.php';
require '../includes/csrf.php';
require '../includes/login_throttle.php';
require '../includes/academic_level.php';
require '../includes/mailer.php';

// ── Account request for newly admitted students ─────────────────────────
// Self-registration is off (it can't verify who owns a Student ID), so a
// new student files a request here instead. An administrator checks it
// against admission records in admin/account_requests.php; approving
// creates the student account and emails a temporary password to the
// address given below, which is also the sign-in email.
// Submissions are rate-limited per network (the throttle key includes the
// IP), since each one sends an email to an address the visitor chooses.

$success = "";
$error   = "";
$form    = ['full_name' => '', 'email' => '', 'student_id' => '', 'course' => '', 'year_of_study' => '', 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    foreach ($form as $key => $_) {
        $form[$key] = is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : '';
    }
    $student_id = normalize_student_id($form['student_id']);
    $year       = (int)$form['year_of_study'];

    if (!csrf_verify()) {
        $error = "Your session expired. Please try again.";
    } elseif ($form['full_name'] === '' || $form['email'] === '' || $student_id === '' || $form['course'] === '' || $year === 0) {
        $error = "Please fill in all the required fields.";
    } elseif (!filter_var($form['email'], FILTER_VALIDATE_EMAIL) || strlen($form['email']) > 100) {
        $error = "Please enter a valid email address.";
    } elseif (mb_strlen($form['full_name']) > 100 || mb_strlen($form['message']) > 500) {
        $error = "One of the fields is too long.";
    } elseif (($profile_error = student_profile_error($student_id, $form['course'], $year)) !== '') {
        $error = $profile_error;
    } elseif (login_is_throttled($conn, '*', 'student_request')) {
        $error = "Too many requests. Please wait " . LOGIN_WINDOW_MINUTES . " minutes and try again.";
    } else {
        login_record_failed_attempt($conn, '*', 'student_request');

        $stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ?");
        mysqli_stmt_bind_param($stmt, "s", $form['email']);
        mysqli_stmt_execute($stmt);
        $email_taken = mysqli_num_rows(mysqli_stmt_get_result($stmt)) > 0;
        mysqli_stmt_close($stmt);

        $stmt = mysqli_prepare($conn, "SELECT id FROM students WHERE student_id = ?");
        mysqli_stmt_bind_param($stmt, "s", $student_id);
        mysqli_stmt_execute($stmt);
        $id_taken = mysqli_num_rows(mysqli_stmt_get_result($stmt)) > 0;
        mysqli_stmt_close($stmt);

        $pending_msg = "A request with this email or Student ID is already waiting for the administrator. You'll get an email once it's reviewed.";

        if ($email_taken) {
            $error = "An account with this email already exists. Sign in, or use Forgot Password if you can't.";
        } elseif ($id_taken) {
            $error = "This Student ID already has an account. Sign in, or use Forgot Password if you can't. If it isn't yours, contact the admin office.";
        } else {
            $message = $form['message'] !== '' ? $form['message'] : null;
            $stmt = mysqli_prepare($conn, "INSERT INTO account_requests (role, full_name, email, personal_email, student_id, course, year_of_study, message)
                                           VALUES ('student', ?, ?, ?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, "sssssis", $form['full_name'], $form['email'], $form['email'], $student_id, $form['course'], $year, $message);
            try {
                mysqli_stmt_execute($stmt);
                $saved = true;
            } catch (mysqli_sql_exception $e) {
                // uniq_pending_email / uniq_pending_student_id: a request for
                // this email or Student ID is already pending.
                if ($e->getCode() !== 1062) throw $e;
                $saved = false;
                $error = $pending_msg;
            }
            mysqli_stmt_close($stmt);
        }

        if ($error === '' && !empty($saved)) {
            // Best effort: tell the admins and confirm to the student. The
            // request is saved either way and shows in the admin panel.
            if (mail_is_configured()) {
                $admins = mysqli_query($conn, "SELECT full_name, email FROM users WHERE role = 'admin'");
                while ($admin = mysqli_fetch_assoc($admins)) {
                    send_mail($admin['email'], $admin['full_name'], 'New student account request',
                        "Hello {$admin['full_name']},\n\n"
                      . "{$form['full_name']} has asked for a student account on SPTS.\n\n"
                      . "Student ID: $student_id\n"
                      . "Programme: {$form['course']} (Year $year)\n"
                      . "Email: {$form['email']}\n\n"
                      . "Please check these against the admission records, then review the request under Account Requests in the admin panel.\n\n"
                      . "— SPTS, Nilai University");
                }
                send_mail($form['email'], $form['full_name'], 'We received your SPTS account request',
                    "Hello {$form['full_name']},\n\n"
                  . "Thank you for your request for a student account on the Student Performance Tracking System.\n\n"
                  . "The admin office will check your details. Once it is approved, we'll email your sign-in details to this address.\n\n"
                  . "— SPTS, Nilai University");
            }

            $success = "Your request has been sent to the admin office. Once it's approved, your sign-in details will be emailed to "
                     . $form['email'] . ".";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request a Student Account | SPTS Nilai University</title>
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
            <i class="bi bi-mortarboard-fill brand-icon"></i>
            <h1 class="login-title">Request a Student Account</h1>
            <p class="login-subtitle">For newly admitted students of Nilai University</p>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success d-flex align-items-start gap-2 py-2" style="font-size: 0.85rem;">
                <i class="bi bi-check-circle-fill mt-1"></i>
                <div><?php echo h($success); ?></div>
            </div>
            <a href="login.php" class="btn btn-primary-custom w-100 mt-2">
                <i class="bi bi-box-arrow-in-right me-2"></i>Go to Sign-in
            </a>
        <?php else: ?>

            <div class="how-it-works">
                <strong><i class="bi bi-info-circle me-1"></i>How it works</strong>
                <ol>
                    <li>Send this request with the Student ID from your offer letter.</li>
                    <li>The admin office checks it against your admission record.</li>
                    <li>Once approved, your sign-in details and a temporary password are emailed to you. Sign in, then set your own password.</li>
                </ol>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger d-flex align-items-start gap-2 py-2" style="font-size:0.85rem;" role="alert">
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
                        <input type="text" name="full_name" id="full_name" class="form-control" placeholder="As on your offer letter" required autocomplete="name" maxlength="100" value="<?php echo h($form['full_name']); ?>">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="email">Email Address</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-envelope-fill"></i></span>
                        <input type="email" name="email" id="email" class="form-control" placeholder="e.g. name@gmail.com" required autocomplete="email" maxlength="100" value="<?php echo h($form['email']); ?>">
                    </div>
                    <div class="form-text">You'll sign in with this email, and your sign-in details are sent here.</div>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="student_id">Student ID</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-credit-card-fill"></i></span>
                        <input type="text" name="student_id" id="student_id" class="form-control" placeholder="e.g. n00021960" required maxlength="11"
                               pattern="[nN][0-9]{7,10}" title="The letter n followed by 7–10 digits" value="<?php echo h($form['student_id']); ?>">
                    </div>
                    <div class="form-text">
                        Your academic level is worked out from your Student ID.
                        <span id="levelPreview" class="fw-semibold" style="color:#0D7377;"></span>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-sm-8">
                        <label class="form-label" for="course">Programme</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-journal-bookmark"></i></span>
                            <select name="course" id="course" class="form-select" required>
                                <option value="">-- Select your programme --</option>
                                <?php render_programme_options($form['course']); ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label" for="year_of_study">Year</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-calendar3"></i></span>
                            <select name="year_of_study" id="year_of_study" class="form-select" required>
                                <option value="">--</option>
                                <?php foreach ([1, 2, 3] as $y): ?>
                                    <option value="<?php echo $y; ?>" <?php echo $form['year_of_study'] === (string)$y ? 'selected' : ''; ?>>Year <?php echo $y; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="message">Message to the admin office <span class="optional">(optional)</span></label>
                    <textarea name="message" id="message" class="form-control" rows="3" maxlength="500" placeholder="e.g. your intake (September 2026)"><?php echo h($form['message']); ?></textarea>
                </div>

                <button type="submit" class="btn btn-primary-custom w-100 mt-2">
                    <i class="bi bi-send me-2"></i>Send Request
                </button>
            </form>

            <div class="text-center mt-4" style="font-size:0.85rem;">
                <span class="text-muted">Already have an account?</span>
                <a href="login.php" class="link-custom">Sign in</a>
            </div>

        <?php endif; ?>

        <div class="text-center mt-4">
            <a href="../index.php" class="link-custom" style="font-size:0.85rem;">
                <i class="bi bi-arrow-left me-1"></i>Back to Home
            </a>
        </div>
    </div>
</div>

<script>
// Cosmetic preview of the academic level the Student ID implies; the
// server (includes/academic_level.php) decides what is stored.
document.getElementById('student_id')?.addEventListener('input', function () {
    const v = this.value.trim().toLowerCase();
    const level = !v ? '' : v.startsWith('n0002') ? 'Diploma' : v.startsWith('n0003') ? "Bachelor's Degree" : v.startsWith('n0004') ? "Master's" : 'PhD';
    document.getElementById('levelPreview').textContent = level ? 'Detected: ' + level : '';
});
</script>
<script src="../assets/js/theme-toggle.js"></script>
<script src="../assets/js/app.js?v=4"></script>
</body>
</html>
