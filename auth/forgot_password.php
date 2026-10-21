<?php
// Forgot Password is now part of Contact Admin (auth/contact_admin.php);
// this address stays so old links and bookmarks keep working.
$query = ['topic' => 'forgot_password'];
if (($_GET['portal'] ?? '') === 'staff') {
    $query['portal'] = 'staff';
}
header('Location: contact_admin.php?' . http_build_query($query), true, 301);
exit();
?>
