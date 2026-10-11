<?php
require '../includes/session.php';
require '../config/config.php';
require '../includes/csrf.php';

require '../includes/login_throttle.php';

$success = "";
$error   = "";

// Which portal to return to — branding only, same shared flow.
$portal = (($_GET['portal'] ?? $_POST['portal'] ?? '') === 'staff') ? 'staff' : 'student';
$login_url = 'login.php' . ($portal === 'staff' ? '?portal=staff' : '');

// Requests stay open for an administrator to action for this long.
define('RESET_REQUEST_DAYS', 7);

// ── Reset REQUEST, not an instant reset ─────────────────────────────────
// Email + full name are not secret (names and staff emails are visible
// inside the system), so they can never be enough to change a password.
// This page only files a request; an administrator verifies the person
// and issues a temporary password from Password Requests, and the user
// then sets their own password via Change Password.
//
// The response is identical whether or not the details matched, so the
// form can't be used to discover which accounts exist, and submissions
// are rate-limited per email + IP.
$generic_success = "If those details match an account, a reset request has been sent to the administrator. "
                 . "Contact the admin office with your ID to receive a temporary password.";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!csrf_verify()) {
        $error = "Your session expired. Please try again.";
    } else {
        $email     = trim($_POST['email'] ?? '');
        $full_name = trim($_POST['full_name'] ?? '');

        if ($email === '' || $full_name === '') {
            $error = "All fields are required.";
        } elseif (login_is_throttled($conn, $email, 'reset')) {
            $error = "Too many requests. Please wait " . LOGIN_WINDOW_MINUTES . " minutes and try again.";
        } else {
            // Every submission counts towards the limit, matched or not.
            login_record_failed_attempt($conn, $email, 'reset');

            $stmt_check = mysqli_prepare($conn, "SELECT id, email, full_name FROM users WHERE email = ?");
            mysqli_stmt_bind_param($stmt_check, "s", $email);
            mysqli_stmt_execute($stmt_check);
            $user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_check));
            mysqli_stmt_close($stmt_check);

            if ($user && strcasecmp(trim($user['full_name']), $full_name) === 0) {
                // Only one open request per account at a time.
                $stmt_open = mysqli_prepare($conn, "SELECT id FROM password_resets WHERE user_id = ? AND used = 0 AND expires_at > NOW()");
                mysqli_stmt_bind_param($stmt_open, "i", $user['id']);
                mysqli_stmt_execute($stmt_open);
                $has_open = mysqli_num_rows(mysqli_stmt_get_result($stmt_open)) > 0;
                mysqli_stmt_close($stmt_open);

                if (!$has_open) {
                    $token = bin2hex(random_bytes(16));
                    $stmt_req = mysqli_prepare($conn, "INSERT INTO password_resets (user_id, email, token, expires_at) VALUES (?, ?, ?, NOW() + INTERVAL " . RESET_REQUEST_DAYS . " DAY)");
                    mysqli_stmt_bind_param($stmt_req, "iss", $user['id'], $user['email'], $token);
                    mysqli_stmt_execute($stmt_req);
                    mysqli_stmt_close($stmt_req);
                }
            }
            $success = $generic_success;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password | SPTS Nilai University</title>
    <script>(function(){try{if(localStorage.getItem('spts-theme')==='dark')document.documentElement.setAttribute('data-bs-theme','dark');}catch(e){}})();</script>
    <link href="../assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="../assets/css/dark-mode.css?v=4" rel="stylesheet">
    <link href="../assets/css/app.css?v=4" rel="stylesheet">
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

        .container {
            position: relative;
            z-index: 100;
        }

        .reset-card {
            width: 100%;
            max-width: 440px;
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
    </style>
</head>
<body>

<div class="bg-logo"></div>

<button type="button" class="theme-toggle theme-toggle-floating" onclick="toggleTheme()" title="Toggle dark mode">
    <i class="bi bi-moon-stars-fill theme-toggle-icon"></i>
</button>

<div class="container d-flex justify-content-center">
    <div class="reset-card">
        <div class="text-center">
            <i class="bi bi-shield-lock-fill brand-icon"></i>
            <h1 class="login-title">Reset Password</h1>
            <p class="login-subtitle">Student Performance Tracking System</p>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success d-flex align-items-center gap-2 py-2" style="font-size: 0.85rem;">
                <i class="bi bi-check-circle-fill"></i>
                <?php echo h($success); ?>
            </div>
            <div class="text-center mt-3">
                <a href="<?php echo h($login_url); ?>" class="btn btn-primary-custom w-100">
                    <i class="bi bi-box-arrow-in-right me-2"></i>Go to Login
                </a>
            </div>
        <?php else: ?>

            <?php if ($error): ?>
                <div class="alert alert-danger d-flex align-items-center gap-2 py-2" style="font-size:0.85rem;">
                    <i class="bi bi-exclamation-circle-fill"></i>
                    <?php echo h($error); ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="portal" value="<?php echo h($portal); ?>">
                <p class="text-muted text-center mb-3" style="font-size:0.8rem;">
                Enter your registered email and full name. An administrator will verify your identity and issue you a temporary password, which you can then change from <strong>Change Password</strong>.
            </p>

            <div class="mb-3">
                    <label class="form-label" for="email">Email Address</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-envelope-fill"></i></span>
                        <input type="email" name="email" id="email" class="form-control" placeholder="Enter your registered email" required autocomplete="email" maxlength="100">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="full_name">Full Name</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-person-fill"></i></span>
                        <input type="text" name="full_name" id="full_name" class="form-control" placeholder="Enter your full name as registered" required autocomplete="name" maxlength="100">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary-custom w-100 mt-2">
                    <i class="bi bi-send me-2"></i>Request Password Reset
                </button>
            </form>

            <div class="text-center mt-4">
                <a href="<?php echo h($login_url); ?>" class="link-custom" style="font-size:0.85rem;">
                    <i class="bi bi-arrow-left me-1"></i>Back to Login
                </a>
            </div>

        <?php endif; ?>
    </div>
</div>

<script src="../assets/js/theme-toggle.js"></script>
<script src="../assets/js/app.js?v=4"></script>
</body>
</html>