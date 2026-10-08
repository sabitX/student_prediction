<?php
require '../includes/session.php';
require '../config/config.php';
require '../includes/csrf.php';

// Logout only happens through a CSRF-protected POST (the sidebar button),
// so another website can't silently sign users out with a link or <img>.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header("Location: login.php");
    exit();
}

// Not signed in — nothing to log out of.
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Opened directly (old bookmark, typed URL): ask for confirmation.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log Out | SPTS Nilai University</title>
    <script>(function(){try{if(localStorage.getItem('spts-theme')==='dark')document.documentElement.setAttribute('data-bs-theme','dark');}catch(e){}})();</script>
    <link href="../assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/dark-mode.css" rel="stylesheet">
    <link href="../assets/css/app.css" rel="stylesheet">
    <link rel="icon" href="../assets/images/favicon-32.png" type="image/png" sizes="32x32">
    <link rel="icon" href="../assets/images/favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="../assets/images/apple-touch-icon.png">
</head>
<body class="d-flex align-items-center justify-content-center min-vh-100 bg-light">
    <div class="text-center">
        <h4 class="mb-3">Log out of SPTS?</h4>
        <form method="POST">
            <?php echo csrf_field(); ?>
            <button type="submit" class="btn btn-danger px-4">Log Out</button>
            <a href="javascript:history.back()" class="btn btn-outline-secondary px-4 ms-2">Cancel</a>
        </form>
    </div>
</body>
</html>
