<?php
require '../includes/session_check.php';
require '../config/config.php';
require '../includes/csrf.php';
require '../includes/passwords.php';

$user_id = (int)$_SESSION['user_id'];
$forced  = !empty($_SESSION['must_change_password']);
$error   = "";
require_once '../includes/flash.php';
$flash   = flash_take();
$success = $flash['success'] ?? "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = "Your session expired. Please try again.";
    } else {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        $stmt = mysqli_prepare($conn, "SELECT password FROM users WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if (!$row || !password_verify($current, $row['password'])) {
            $error = "Your current password is incorrect.";
        } elseif ($new !== $confirm) {
            $error = "New passwords do not match.";
        } elseif (($policy = password_policy_error($new)) !== '') {
            $error = $policy;
        } elseif (password_verify($new, $row['password'])) {
            $error = "Your new password must be different from the current one.";
        } else {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $stmt = mysqli_prepare($conn, "UPDATE users SET password = ?, must_change_password = 0 WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "si", $hash, $user_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            // New session ID after a credential change. The new fingerprint
            // keeps this session valid; any other session for this account
            // ends on its next request (see config.php).
            session_regenerate_id(true);
            $_SESSION['pw_fp'] = hash('sha256', $hash);
            unset($_SESSION['must_change_password']);
            $forced  = false;
            $success = "Your password has been changed.";
        }
    }
}

redirect_after_success($success, $error);

$dashboard = role_dashboard_path($_SESSION['role']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password | SPTS Nilai University</title>
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
        body { font-family: 'Inter', system-ui, sans-serif; background: #F0F4F8; color: #1A1A2E; min-height: 100vh; display: flex; align-items: center; justify-content: center; margin: 0; padding: 40px 20px; }
        .reset-card { width: 100%; max-width: 440px; background: #fff; border: 1px solid #E2E8F0; border-radius: 14px; padding: 40px; box-shadow: 0 10px 36px rgba(26,58,92,0.08); }
        .brand-icon { font-size: 2.5rem; color: #1A3A5C; }
        .login-title { font-size: 1.25rem; font-weight: 700; color: #1A3A5C; margin: 8px 0 24px; }
        .form-label { font-size: 0.85rem; font-weight: 600; color: #1A3A5C; }
        .form-control { background: #F8FAFC; font-size: 0.9rem; }
        .btn-primary-custom { background: #0D7377; border: 2px solid #0D7377; color: #fff; font-weight: 600; padding: 10px; border-radius: 8px; }
        .btn-primary-custom:hover { background: #0a5e62; border-color: #0a5e62; color: #fff; }
        .link-custom { color: #0D7377; text-decoration: none; font-weight: 600; }
    </style>
</head>
<body>

<button type="button" class="theme-toggle theme-toggle-floating" onclick="toggleTheme()" title="Toggle dark mode">
    <i class="bi bi-moon-stars-fill theme-toggle-icon"></i>
</button>

<div class="reset-card">
    <div class="text-center">
        <i class="bi bi-shield-lock-fill brand-icon"></i>
        <h1 class="login-title">Change Password</h1>
    </div>

    <?php if ($forced): ?>
        <div class="alert alert-warning py-2" style="font-size:0.85rem;">
            <i class="bi bi-exclamation-triangle-fill me-1"></i>
            You signed in with a temporary password. Please set your own password to continue.
        </div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success py-2" style="font-size:0.85rem;"><i class="bi bi-check-circle-fill me-1"></i><?php echo h($success); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger py-2" style="font-size:0.85rem;"><i class="bi bi-exclamation-circle-fill me-1"></i><?php echo h($error); ?></div>
    <?php endif; ?>

    <form method="POST">
        <?php echo csrf_field(); ?>
        <div class="mb-3">
            <label class="form-label" for="current_password">Current Password</label>
            <input type="password" name="current_password" id="current_password" class="form-control" required autocomplete="current-password">
        </div>
        <div class="mb-3">
            <label class="form-label" for="new_password">New Password</label>
            <input type="password" name="new_password" id="new_password" class="form-control" required minlength="<?php echo PASSWORD_MIN_LENGTH; ?>" autocomplete="new-password" placeholder="Minimum <?php echo PASSWORD_MIN_LENGTH; ?> characters" aria-describedby="pwRule">
            <div id="pwRule" class="form-text"><?php echo h(PASSWORD_RULE_TEXT); ?></div>
        </div>
        <div class="mb-4">
            <label class="form-label" for="confirm_password">Confirm New Password</label>
            <input type="password" name="confirm_password" id="confirm_password" class="form-control" required autocomplete="new-password">
        </div>
        <button type="submit" class="btn btn-primary-custom w-100"><i class="bi bi-check2 me-1"></i>Update Password</button>
    </form>

    <div class="text-center mt-4">
        <?php if (!$forced): ?>
            <a href="<?php echo h($dashboard); ?>" class="link-custom" style="font-size:0.85rem;"><i class="bi bi-arrow-left me-1"></i>Back to Dashboard</a>
        <?php else: ?>
            <form method="POST" action="logout.php" class="d-inline">
                <?php echo csrf_field(); ?>
                <button type="submit" class="btn btn-link link-custom p-0" style="font-size:0.85rem;"><i class="bi bi-box-arrow-right me-1"></i>Log out instead</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<script src="../assets/js/theme-toggle.js"></script>
<script src="../assets/js/app.js?v=4"></script>
</body>
</html>
