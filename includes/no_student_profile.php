<?php
// Shown instead of a student page when the signed-in student account has
// no row in `students` (e.g. an account created before registration used
// a transaction). Without a profile there is no Student ID, programme or
// marks to show, so the pages can't render meaningfully.
require_once __DIR__ . '/csrf.php';
http_response_code(200);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile Incomplete | SPTS Nilai University</title>
    <link href="../assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/dark-mode.css?v=4" rel="stylesheet">
    <link href="../assets/css/app.css?v=4" rel="stylesheet">
    <link rel="icon" href="../assets/images/favicon-32.png" type="image/png" sizes="32x32">
    <link rel="icon" href="../assets/images/favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="../assets/images/apple-touch-icon.png">
</head>
<body class="d-flex align-items-center justify-content-center min-vh-100 bg-light">
    <div class="text-center" style="max-width:460px;">
        <h4 class="mb-3">Your student profile is incomplete</h4>
        <p class="text-muted">This account has no Student ID or programme on record, so results can't be shown. Please contact the admin office to have your profile completed.</p>
        <form method="POST" action="../auth/logout.php">
            <?php echo csrf_field(); ?>
            <button type="submit" class="btn btn-outline-secondary">Log Out</button>
        </form>
    </div>
</body>
</html>
