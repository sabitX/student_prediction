<?php
// ── Flash messages + Post/Redirect/Get ──────────────────────────────────
// After a form succeeds, the page redirects to itself (GET) and shows the
// message from the session. Refreshing then can't repeat the action, and
// the browser doesn't warn about resending the form. Failed submissions
// are NOT redirected, so the user's input and the error stay on screen.
//
// Usage in a page:
//   $flash   = flash_take();                 // before handling the POST
//   $success = $flash['success'] ?? '';
//   ... handle POST, setting $success / $error ...
//   redirect_after_success($success, $error, ['issued' => $issued]);

function flash_take() {
    $flash = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flash;
}

// Redirects (and exits) only for a POST that succeeded without errors.
// $extra holds anything else to show once, e.g. a temporary password.
function redirect_after_success($success, $error, array $extra = [], $to = null) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $success === '' || $error !== '') {
        return;
    }
    $_SESSION['flash'] = ['success' => $success] + $extra;
    header('Location: ' . ($to ?? $_SERVER['REQUEST_URI']), true, 303);
    exit();
}
?>
