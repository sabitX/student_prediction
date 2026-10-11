<?php
require __DIR__ . '/includes/session.php';

// Apache serves this page for missing URLs at any depth (see .htaccess),
// so every link and asset must be absolute from the app's base path —
// relative paths break when the missing URL was inside a subfolder.
$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');

$home = $base . '/index.php';
if (isset($_SESSION['role'])) {
    $home = $base . match ($_SESSION['role']) {
        'admin'    => '/admin/admin_dashboard.php',
        'lecturer' => '/lecturer/lecturer_dashboard.php',
        default    => '/student/student_dashboard.php',
    };
}
http_response_code(404);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Page Not Found | SPTS Nilai University</title>
    <script>(function(){try{if(localStorage.getItem('spts-theme')==='dark')document.documentElement.setAttribute('data-bs-theme','dark');}catch(e){}})();</script>
    <link href="<?php echo htmlspecialchars($base); ?>/assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="<?php echo htmlspecialchars($base); ?>/assets/css/dark-mode.css?v=4" rel="stylesheet">
    <link rel="icon" href="<?php echo htmlspecialchars($base); ?>/assets/images/favicon-32.png" type="image/png" sizes="32x32">
    <link rel="icon" href="<?php echo htmlspecialchars($base); ?>/assets/images/favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="<?php echo htmlspecialchars($base); ?>/assets/images/apple-touch-icon.png">
</head>
<body class="d-flex align-items-center justify-content-center min-vh-100 bg-light">
    <div class="text-center">
        <h1 class="display-1 fw-bold text-primary">404</h1>
        <h4 class="mb-3">Page Not Found</h4>
        <p class="text-muted">The page you're looking for doesn't exist.</p>
        <a href="<?php echo htmlspecialchars($home); ?>" class="btn btn-primary mt-2">
            <?php echo isset($_SESSION['role']) ? 'Back to Dashboard' : 'Go Back Home'; ?>
        </a>
    </div>
</body>
</html>
