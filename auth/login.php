<?php
require '../includes/session.php';
require '../config/config.php';
require '../includes/csrf.php';
require '../includes/login_throttle.php';

$error = '';

// Confirmation carried over from another page (e.g. after registering).
require '../includes/flash.php';
$notice = flash_take()['success'] ?? '';

// session.php sets this flag when an idle session is ended.
if (!empty($_SESSION['timed_out'])) {
    $notice = "You were signed out after " . SESSION_IDLE_MINUTES . " minutes of inactivity. Please sign in again.";
    unset($_SESSION['timed_out']);
}

// config.php sets this when the signed-in account was deleted, its role
// changed, or its password was changed elsewhere (e.g. reset by an admin).
if (!empty($_SESSION['account_changed'])) {
    $notice = "You were signed out because your account details changed. Please sign in again.";
    unset($_SESSION['account_changed']);
}

// Which portal is this? Both share the users table, but each one only
// signs in its own roles: students here, admins/lecturers on ?portal=staff.
$portal = (($_GET['portal'] ?? $_POST['portal'] ?? '') === 'staff') ? 'staff' : 'student';
$portal_roles = $portal === 'staff' ? ['admin', 'lecturer'] : ['student'];

// Set when the password is right but the account belongs to the other
// portal, so the page can link there.
$wrong_portal = false;

// If already logged in, redirect to appropriate dashboard
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'admin') header("Location: ../admin/admin_dashboard.php");
    elseif ($_SESSION['role'] === 'lecturer') header("Location: ../lecturer/lecturer_dashboard.php");
    else header("Location: ../student/student_dashboard.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!csrf_verify()) {
        $error = "Your session expired. Please try again.";
    } else {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    if (empty($email) || empty($password)) {
        $error = "Please enter both email and password.";
    } elseif (login_is_throttled($conn, $email)) {
        $error = "Too many failed attempts. Please wait " . LOGIN_WINDOW_MINUTES . " minutes and try again.";
    } else {
        // BR-42: Using Prepared Statements to prevent SQL Injection
        $stmt = mysqli_prepare($conn, "SELECT id, full_name, password, role, must_change_password FROM users WHERE email = ?");
        mysqli_stmt_bind_param($stmt, "s", $email);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        if ($row = mysqli_fetch_assoc($result)) {
            $password_ok = password_verify($password, $row['password']);
            if ($password_ok && !in_array($row['role'], $portal_roles, true)) {
                // Correct password, wrong door: don't sign in here.
                $wrong_portal = true;
                $error = $portal === 'staff'
                    ? "This sign-in is for staff and administrators only. Students, please use the student login."
                    : "This sign-in is for students only. Staff and administrators, please use the staff sign-in.";
            } elseif ($password_ok) {
                login_clear_attempts($conn, $email);

                // Issue a fresh session ID on login so a pre-login session
                // (e.g. one an attacker could have fixed) is never reused
                // for an authenticated session.
                session_regenerate_id(true);
                unset($_SESSION['csrf_token']);   // fresh token for the signed-in session
                $_SESSION['pw_fp'] = hash('sha256', $row['password']);
                $_SESSION['user_id'] = $row['id'];
                $_SESSION['full_name'] = $row['full_name'];
                $_SESSION['role'] = $row['role'];
                $_SESSION['last_activity'] = time();

                // Admin-issued temporary password: must be replaced first.
                if ($row['must_change_password']) {
                    $_SESSION['must_change_password'] = true;
                    header("Location: change_password.php");
                    exit();
                }

                if ($row['role'] === 'admin') header("Location: ../admin/admin_dashboard.php");
                elseif ($row['role'] === 'lecturer') header("Location: ../lecturer/lecturer_dashboard.php");
                else header("Location: ../student/student_dashboard.php");
                exit();
            } else {
                login_record_failed_attempt($conn, $email);
                $error = "Invalid email or password.";
            }
        } else {
            login_record_failed_attempt($conn, $email);
            $error = "Invalid email or password.";
        }
        mysqli_stmt_close($stmt);
    }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $portal === 'staff' ? 'Staff Login' : 'Login'; ?> | SPTS Nilai University</title>
    <script>(function(){try{if(localStorage.getItem('spts-theme')==='dark')document.documentElement.setAttribute('data-bs-theme','dark');}catch(e){}})();</script>
    <link href="../assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="../assets/css/dark-mode.css" rel="stylesheet">
    <link href="../assets/css/app.css" rel="stylesheet">
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
            z-index: 100; /* Ensure form sits above watermark */
        }

        .login-card {
            width: 100%;
            max-width: 420px;
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

        .form-control {
            border-left: none;
            background: #F8FAFC;
            font-size: 0.9rem;
        }

        .form-control:focus {
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

<div class="container d-flex justify-content-center px-3">
    <div class="login-card">

        <div class="text-center">
            <i class="bi bi-<?php echo $portal === 'staff' ? 'shield-lock-fill' : 'mortarboard-fill'; ?> brand-icon"></i>
            <h1 class="login-title">Student Performance<br>Tracking System</h1>
            <p class="login-subtitle">
                <?php echo $portal === 'staff' ? 'Nilai University — Staff & Admin sign in' : 'Nilai University — Sign in to your account'; ?>
            </p>
        </div>

        <?php if ($notice): ?>
            <div class="alert alert-info py-2 d-flex align-items-center gap-2" style="font-size: 0.85rem;">
                <i class="bi bi-info-circle-fill"></i>
                <?php echo h($notice); ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger py-2 d-flex align-items-center gap-2" style="font-size: 0.85rem;">
                <i class="bi bi-exclamation-circle-fill"></i>
                <div>
                    <?php echo h($error); ?>
                    <?php if ($wrong_portal): ?>
                        <a href="<?php echo $portal === 'staff' ? 'login.php' : 'login.php?portal=staff'; ?>" class="alert-link d-block mt-1">
                            <?php echo $portal === 'staff' ? 'Go to student login' : 'Go to staff sign-in'; ?> <i class="bi bi-arrow-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php<?php echo $portal === 'staff' ? '?portal=staff' : ''; ?>">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="portal" value="<?php echo h($portal); ?>">

            <div class="mb-3">
                <label class="form-label" for="email">Email Address</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                    <input type="email" name="email" id="email" class="form-control" placeholder="Enter your email address" required autofocus autocomplete="username" maxlength="100"
                           value="<?php echo h($_POST['email'] ?? ''); ?>">
                </div>
            </div>

            <div class="mb-4">
                <div class="d-flex justify-content-between align-items-center">
                    <label class="form-label mb-0" for="passwordField">Password</label>
                    <a href="forgot_password.php<?php echo $portal === 'staff' ? '?portal=staff' : ''; ?>" class="link-custom" style="font-size: 0.75rem; font-weight: 500;">
                        <i class="bi bi-question-circle me-1"></i>Forgot Password?
                    </a>
                </div>
                <div class="input-group mt-1">
                    <span class="input-group-text"><i class="bi bi-lock"></i></span>
                    <input type="password" name="password" id="passwordField" class="form-control" placeholder="Enter your password" required autocomplete="current-password">
                    <button type="button" class="input-group-text toggle-pw" onclick="togglePassword(this)" aria-label="Show password" aria-controls="passwordField">
                        <i class="bi bi-eye" id="eyeIcon"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn btn-primary-custom w-100">
                <i class="bi bi-box-arrow-in-right me-2"></i>Sign In
            </button>
        </form>

        <div class="divider">or</div>

        <?php if ($portal === 'staff'): ?>
        <div class="text-center" style="font-size: 0.8rem;">
            <span class="text-muted">New lecturer?</span>
            <a href="request_account.php" class="link-custom">Request an account</a>
        </div>
        <div class="text-center mt-2" style="font-size: 0.85rem;">
            <a href="login.php" class="link-custom"><i class="bi bi-arrow-left me-1"></i>Student? Sign in here</a>
        </div>
        <?php else: ?>
        <div class="text-center" style="font-size: 0.85rem;">
            <span class="text-muted">Don't have an account?</span>
            <a href="register.php" class="link-custom">Register here</a>
        </div>
        <div class="text-center mt-2" style="font-size: 0.8rem;">
            <a href="login.php?portal=staff" class="link-custom"><i class="bi bi-shield-lock me-1"></i>Staff & Admin sign in</a>
        </div>
        <?php endif; ?>

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
function togglePassword(btn) {
    const passField = document.getElementById('passwordField');
    const eyeIcon = document.getElementById('eyeIcon');
    const show = passField.type === 'password';
    passField.type = show ? 'text' : 'password';
    eyeIcon.classList.replace(show ? 'bi-eye' : 'bi-eye-slash', show ? 'bi-eye-slash' : 'bi-eye');
    btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
}
</script>
<script src="../assets/js/theme-toggle.js"></script>
<script src="../assets/js/app.js"></script>
</body>
</html>