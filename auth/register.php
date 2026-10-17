<?php
require '../includes/session.php';
require '../config/config.php';
require '../includes/csrf.php';
require '../includes/academic_level.php';
require '../includes/passwords.php';
require '../includes/login_throttle.php';

if (!registration_open()) {
    render_error_page(404, 'Registration is closed', 'Student accounts are created by the admin office. Please contact them to get access.');
    exit();
}

$error   = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!csrf_verify()) {
        $error = "Your session expired. Please try again.";
    } else {
    $full_name  = trim($_POST['full_name'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $password   = $_POST['password'] ?? '';
    $confirm    = $_POST['confirm_password'] ?? '';
    $student_id = normalize_student_id($_POST['student_id'] ?? '');
    $course     = trim($_POST['course'] ?? '');
    $year       = (int)($_POST['year_of_study'] ?? 0);

    // Validation
    if (empty($full_name) || empty($email) || empty($password) || empty($student_id) || empty($course)) {
        $error = "All fields are required.";
    } elseif (mb_strlen($full_name) > 100) {
        $error = "Full name is too long.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
        $error = "Please enter a valid email address.";
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match.";
    } elseif (($policy = password_policy_error($password)) !== '') {
        $error = $policy;
    } elseif (($profile_error = student_profile_error($student_id, $course, $year)) !== '') {
        $error = $profile_error;
    } elseif (login_is_throttled($conn, 'accounts', 'register')) {
        // Counts successful sign-ups per IP, so typos never lock anyone out.
        $error = "Too many accounts have been created from this network recently. Please try again later.";
    } else {
        // BR-42: Secure Duplicate Checks using Prepared Statements
        $stmt_email = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ?");
        mysqli_stmt_bind_param($stmt_email, "s", $email);
        mysqli_stmt_execute($stmt_email);
        $res_email = mysqli_stmt_get_result($stmt_email);

        $stmt_sid = mysqli_prepare($conn, "SELECT id FROM students WHERE student_id = ?");
        mysqli_stmt_bind_param($stmt_sid, "s", $student_id);
        mysqli_stmt_execute($stmt_sid);
        $res_sid = mysqli_stmt_get_result($stmt_sid);

        if (mysqli_num_rows($res_email) > 0) {
            $error = "An account with this email address already exists.";
        } elseif (mysqli_num_rows($res_sid) > 0) {
            $error = "This Student ID is already registered.";
        } else {
            // ── SECURE DATABASE TRANSACTION ─────────────────────────────
            mysqli_begin_transaction($conn);
            try {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $role = 'student';

                // Step 1 — Insert into users table securely
                $stmt_user = mysqli_prepare($conn, "INSERT INTO users (full_name, email, password, role) VALUES (?, ?, ?, ?)");
                mysqli_stmt_bind_param($stmt_user, "ssss", $full_name, $email, $hashed, $role);

                if (!mysqli_stmt_execute($stmt_user)) {
                    throw new Exception("Failed to create user account.");
                }
                $user_id = mysqli_insert_id($conn);

                // Step 2 — Insert into students table securely
                // Academic level is never taken from user input — it is derived
                // server-side from the Student ID prefix (see academic_level.php).
                $academic_level = detect_academic_level($student_id);
                $stmt_student = mysqli_prepare($conn, "INSERT INTO students (user_id, student_id, course, academic_level, year_of_study) VALUES (?, ?, ?, ?, ?)");
                mysqli_stmt_bind_param($stmt_student, "isssi", $user_id, $student_id, $course, $academic_level, $year);

                if (!mysqli_stmt_execute($stmt_student)) {
                    throw new Exception("Failed to create student profile.");
                }

                mysqli_commit($conn);
                login_record_failed_attempt($conn, 'accounts', 'register');
                // Post/Redirect/Get: go straight to login with a confirmation,
                // so refreshing can't resubmit the registration.
                $_SESSION['flash'] = ['success' => "Account created. Sign in with your email and password."];
                header("Location: login.php", true, 303);
                exit();
            } catch (Exception $e) {
                // Log the real cause; never show database error text to visitors.
                mysqli_rollback($conn);
                error_log('Registration failed: ' . $e->getMessage());
                $error = "Registration failed. Please try again.";
            }
            // ── END TRANSACTION ─────────────────────────────────────────
        }
        mysqli_stmt_close($stmt_email);
        mysqli_stmt_close($stmt_sid);
    }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register | SPTS Nilai University</title>
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

        /* Institutional Watermark */
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

        .container {
            position: relative;
            z-index: 100;
        }

        .register-card {
            width: 100%;
            max-width: 520px;
            background: #fff;
            border: 1px solid #E2E8F0;
            border-radius: 14px;
            padding: 40px;
            box-shadow: 0 10px 36px rgba(26,58,92,0.08);
        }

        .brand-icon {
            font-size: 2.5rem;
            color: #1A3A5C;
            margin-bottom: 10px;
        }

        .login-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: #1A3A5C;
            margin-bottom: 4px;
            text-align: center;
        }

        .login-subtitle {
            font-size: 0.85rem;
            color: #6C757D;
            text-align: center;
            margin-bottom: 30px;
        }

        .section-label {
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #0D7377;
            margin-bottom: 12px;
            margin-top: 20px;
        }

        .form-label {
            font-size: 0.85rem;
            font-weight: 600;
            color: #1A3A5C;
        }

        .input-group-text {
            background: #F8FAFC;
            border-right: none;
            color: #6C757D;
        }

        .form-control, .form-select {
            border-left: none;
            background: #F8FAFC;
            font-size: 0.9rem;
        }

        .form-control:focus, .form-select:focus {
            box-shadow: none;
            border-color: #0D7377;
            background: #fff;
        }

        .form-control:focus + .input-group-text,
        .input-group:focus-within .input-group-text {
            border-color: #0D7377;
            background: #fff;
        }

        .toggle-pw {
            cursor: pointer;
            background: #F8FAFC;
            border-left: none;
            color: #6C757D;
        }

        .toggle-pw:hover { color: #0D7377; }

        .btn-primary-custom {
            background: #0D7377;
            border: 2px solid #0D7377;
            color: #fff;
            font-weight: 600;
            padding: 10px;
            border-radius: 8px;
            transition: all 0.2s;
        }

        .btn-primary-custom:hover {
            background: #0a5e62;
            border-color: #0a5e62;
            color: #fff;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(13,115,119,0.2);
        }

        .link-custom {
            color: #0D7377;
            text-decoration: none;
            font-weight: 600;
        }

        .link-custom:hover {
            text-decoration: underline;
            color: #0a5e62;
        }

        .divider {
            display: flex;
            align-items: center;
            text-align: center;
            margin: 20px 0;
            color: #CBD5E1;
            font-size: 0.8rem;
        }

        .divider::before, .divider::after {
            content: '';
            flex: 1;
            border-bottom: 1px solid #E2E8F0;
        }

        .divider:not(:empty)::before { margin-right: .25em; }
        .divider:not(:empty)::after { margin-left: .25em; }
    </style>
</head>
<body>

<div class="bg-logo"></div>

<button type="button" class="theme-toggle theme-toggle-floating" onclick="toggleTheme()" title="Toggle dark mode">
    <i class="bi bi-moon-stars-fill theme-toggle-icon"></i>
</button>

<div class="container d-flex justify-content-center">
    <div class="register-card">

        <div class="text-center">
            <i class="bi bi-mortarboard-fill brand-icon"></i>
            <h1 class="login-title">Student Performance<br>Tracking System</h1>
            <p class="login-subtitle">Nilai University — Create your account</p>
        </div>


        <?php if ($error): ?>
            <div class="alert alert-danger py-2 d-flex align-items-center gap-2" style="font-size: 0.85rem;">
                <i class="bi bi-exclamation-circle-fill"></i>
                <?php echo h($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <?php echo csrf_field(); ?>

            <div class="section-label">
                <i class="bi bi-person me-1"></i>Personal Details
            </div>

            <div class="mb-3">
                <label class="form-label" for="full_name">Full Name</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-person-fill"></i></span>
                    <input type="text" name="full_name" id="full_name" class="form-control" placeholder="Enter your full name" autocomplete="name" maxlength="100"
                           value="<?php echo isset($_POST['full_name']) ? h($_POST['full_name']) : ''; ?>" required>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label" for="email">Email Address</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-envelope-fill"></i></span>
                    <input type="email" name="email" id="email" class="form-control" placeholder="Enter your email address" autocomplete="email" maxlength="100"
                           value="<?php echo isset($_POST['email']) ? h($_POST['email']) : ''; ?>" required>
                </div>
            </div>

            <div class="row g-2">
                <div class="col-md-6 mb-3">
                    <label class="form-label" for="passwordField">Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                        <input type="password" name="password" id="passwordField" class="form-control" placeholder="Min <?php echo PASSWORD_MIN_LENGTH; ?> chars" required minlength="<?php echo PASSWORD_MIN_LENGTH; ?>" autocomplete="new-password" aria-describedby="pwHint">
                        <button type="button" class="input-group-text toggle-pw" onclick="togglePassword('passwordField','eyeIcon1', this)" aria-label="Show password" aria-controls="passwordField">
                            <i class="bi bi-eye" id="eyeIcon1"></i>
                        </button>
                    </div>
                    <div id="pwHint" class="form-text" style="font-size:0.72rem;"><?php echo h(PASSWORD_RULE_TEXT); ?></div>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label" for="confirmField">Confirm Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                        <input type="password" name="confirm_password" id="confirmField" class="form-control" placeholder="Re-enter" required autocomplete="new-password">
                        <button type="button" class="input-group-text toggle-pw" onclick="togglePassword('confirmField','eyeIcon2', this)" aria-label="Show password" aria-controls="confirmField">
                            <i class="bi bi-eye" id="eyeIcon2"></i>
                        </button>
                    </div>
                </div>
            </div>

            <div class="section-label">
                <i class="bi bi-journal-bookmark me-1"></i>Academic Details
            </div>

            <div class="mb-3">
                <label class="form-label" for="studentIdField">Student ID</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-credit-card-fill"></i></span>
                    <input type="text" name="student_id" id="studentIdField" class="form-control" placeholder="e.g. n00021960" pattern="[nN][0-9]{7,10}" title="The letter n followed by 7–10 digits"
                           value="<?php echo isset($_POST['student_id']) ? h($_POST['student_id']) : ''; ?>" required>
                </div>
                <small class="text-muted d-block mt-1" style="font-size:0.78rem;">
                    Academic level is detected automatically from your Student ID — no need to select it.
                    <span id="levelPreview" class="fw-semibold" style="color:#0D7377;"></span>
                </small>
            </div>

            <div class="mb-3">
                <label class="form-label" for="course">Programme</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-building"></i></span>
                    <select name="course" id="course" class="form-select" required>
                        <option value="">-- Select your programme --</option>
                        <?php render_programme_options($_POST['course'] ?? ''); ?>
                    </select>
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label" for="year_of_study">Year of Study</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-calendar3"></i></span>
                    <select name="year_of_study" id="year_of_study" class="form-select" required>
                        <option value="">-- Select year --</option>
                        <option value="1" <?php echo (isset($_POST['year_of_study']) && $_POST['year_of_study'] == '1') ? 'selected' : ''; ?>>Year 1</option>
                        <option value="2" <?php echo (isset($_POST['year_of_study']) && $_POST['year_of_study'] == '2') ? 'selected' : ''; ?>>Year 2</option>
                        <option value="3" <?php echo (isset($_POST['year_of_study']) && $_POST['year_of_study'] == '3') ? 'selected' : ''; ?>>Year 3</option>
                    </select>
                </div>
            </div>

            <button type="submit" class="btn btn-primary-custom w-100">
                <i class="bi bi-person-plus me-2"></i>Create Account
            </button>
        </form>

        <div class="divider">or</div>

        <div class="text-center" style="font-size: 0.85rem;">
            <span class="text-muted">Already have an account?</span>
            <a href="login.php" class="link-custom">Sign in here</a>
        </div>

        <div class="text-center mt-4">
            <a href="../index.php" class="link-custom" style="font-size:0.85rem;">
                <i class="bi bi-arrow-left me-1"></i>Back to Home
            </a>
        </div>

        <div class="text-center mt-3">
            <small class="text-muted" style="font-size: 0.7rem;">
                &copy; 2026 Student Performance Tracking System<br>Nilai University
            </small>
        </div>

    </div>
</div>

<script>
function togglePassword(fieldId, iconId, btn) {
    const field   = document.getElementById(fieldId);
    const icon    = document.getElementById(iconId);
    const showing = field.type === 'text';
    field.type    = showing ? 'password' : 'text';
    icon.className = showing ? 'bi bi-eye' : 'bi bi-eye-slash';
    btn.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
}

// Cosmetic-only preview of the detected academic level as the user types
// their Student ID. This never determines what gets saved — the server
// (academic_level.php) is the single source of truth for that.
function previewAcademicLevel() {
    const idField = document.getElementById('studentIdField');
    const preview = document.getElementById('levelPreview');
    const val = idField.value.trim().toLowerCase();
    let level = '';
    if (val.startsWith('n0002')) level = 'Diploma';
    else if (val.startsWith('n0003')) level = "Bachelor's Degree";
    else if (val.startsWith('n0004')) level = "Master's";
    else if (val.length > 0) level = 'PhD';
    preview.textContent = level ? ('Detected level: ' + level) : '';
}
document.getElementById('studentIdField').addEventListener('input', previewAcademicLevel);
</script>
<script src="../assets/js/theme-toggle.js"></script>
<script src="../assets/js/app.js?v=4"></script>
</body>
</html>