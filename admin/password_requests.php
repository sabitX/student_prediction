<?php
// Password reset requests are now support requests (Forgot password);
// this address stays so old links and bookmarks keep working.
require '../includes/session_check.php';
header('Location: support_requests.php?category=forgot_password', true, 301);
exit();
?>
