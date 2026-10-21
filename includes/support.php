<?php
// ── Contact Admin support requests ──────────────────────────────────────
// Shared by the public form (auth/contact_admin.php, for people who can't
// sign in), the signed-in form (includes/support_page.php) and the admin
// page (admin/support_requests.php).
//
// Security rules:
//   - A request never changes an account. Recovery is done by an admin in
//     admin/support_requests.php: they verify the person, then issue a
//     one-time temporary password that must be replaced at next sign-in.
//   - Passwords are never asked for, shown or emailed back.
//   - The public form answers the same way whether or not the details
//     match an account; only admins see the account check.
require_once __DIR__ . '/academic_level.php';
require_once __DIR__ . '/login_throttle.php';
require_once __DIR__ . '/mailer.php';

const SUPPORT_CATEGORIES = [
    'forgot_password' => 'Forgot password',
    'cannot_login'    => 'Cannot log in',
    'other'           => 'Other',
];
const SUPPORT_STATUSES = ['Open', 'In Progress', 'Resolved'];
const SUPPORT_MESSAGE_MAX = 1000;

// Spam limits for the public form: per email + network, and per network.
const SUPPORT_MAX_PER_EMAIL   = 3;
const SUPPORT_MAX_PER_NETWORK = 10;

function support_category_label($category) {
    return SUPPORT_CATEGORIES[$category] ?? 'Other';
}

function support_status_class($status) {
    return match ($status) {
        'Open'        => 'bg-warning text-dark',
        'In Progress' => 'bg-info text-dark',
        default       => 'bg-success',
    };
}

function support_id_label($role) {
    return $role === 'student' ? 'Student ID' : 'Staff ID';
}

// Reads and trims the form fields. $account (signed-in user) fills in the
// identity fields from the database instead of the form.
function support_read_form(array $post, ?array $account = null) {
    $get = fn($key) => is_string($post[$key] ?? null) ? trim($post[$key]) : '';
    $form = [
        'role'      => $get('role'),
        'full_name' => $get('full_name'),
        'id_number' => $get('id_number'),
        'email'     => $get('email'),
        'category'  => $get('category'),
        'message'   => str_replace("\r\n", "\n", $get('message')),
    ];
    if ($account) {
        $form['role']      = $account['role'];
        $form['full_name'] = $account['full_name'];
        $form['id_number'] = $account['student_id'] ?? '';
        $form['email']     = $account['email'];
    }
    if ($form['role'] === 'student') {
        $form['id_number'] = normalize_student_id($form['id_number']);
    }
    return $form;
}

// Field => message for everything that is wrong; empty when valid.
function support_validate(array $form, $signed_in = false) {
    $errors = [];
    if (!in_array($form['role'], ['student', 'lecturer'], true)) {
        $errors['role'] = 'Choose whether you are a student or a lecturer.';
    }
    if (!$signed_in) {
        if ($form['full_name'] === '') {
            $errors['full_name'] = 'Enter your full name.';
        } elseif (mb_strlen($form['full_name']) > 100) {
            $errors['full_name'] = 'Your name can be at most 100 characters.';
        }

        if ($form['id_number'] === '') {
            $errors['id_number'] = 'Enter your ' . support_id_label($form['role']) . '.';
        } elseif ($form['role'] === 'student' && !valid_student_id_format($form['id_number'])) {
            $errors['id_number'] = 'Student ID must be the letter n followed by 7–10 digits (e.g. n00021960).';
        } elseif ($form['role'] !== 'student' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9\/-]{1,29}$/', $form['id_number'])) {
            $errors['id_number'] = 'Staff ID can only contain letters, numbers, - and / (2–30 characters).';
        }

        if ($form['email'] === '') {
            $errors['email'] = 'Enter the email address registered on your account.';
        } elseif (!filter_var($form['email'], FILTER_VALIDATE_EMAIL) || strlen($form['email']) > 100) {
            $errors['email'] = 'Enter a valid email address.';
        }
    }

    if (!isset(SUPPORT_CATEGORIES[$form['category']])) {
        $errors['category'] = 'Choose what you need help with.';
    }
    // A forgotten password needs no explanation; anything else does.
    $message_len = mb_strlen($form['message']);
    if ($form['category'] !== 'forgot_password' && $message_len < 10) {
        $errors['message'] = 'Describe the problem in a few words (at least 10 characters).';
    } elseif ($message_len > SUPPORT_MESSAGE_MAX) {
        $errors['message'] = 'The message can be at most ' . SUPPORT_MESSAGE_MAX . ' characters.';
    }
    return $errors;
}

// Random reference such as SR-7KQ2M9XD. Random rather than a running
// number, so it reveals nothing about other requests.
function support_new_reference() {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $ref = '';
    for ($i = 0; $i < 8; $i++) {
        $ref .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return 'SR-' . $ref;
}

// Saves the request and returns its reference number. Throws on a
// database error, so the caller never reports a request that wasn't saved.
function support_save($conn, array $form, $user_id = null) {
    $id_number = $form['id_number'] !== '' ? $form['id_number'] : null;
    $message   = $form['message'] !== '' ? $form['message'] : null;
    for ($attempt = 0; ; $attempt++) {
        $ref  = support_new_reference();
        $stmt = mysqli_prepare($conn, "INSERT INTO support_requests (reference_no, role, full_name, id_number, email, category, message, user_id)
                                       VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, "sssssssi", $ref, $form['role'], $form['full_name'], $id_number, $form['email'], $form['category'], $message, $user_id);
        try {
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            return $ref;
        } catch (mysqli_sql_exception $e) {
            mysqli_stmt_close($stmt);
            // A clash on the random reference: try another one.
            if ($e->getCode() !== 1062 || $attempt >= 4) throw $e;
        }
    }
}

// Tells the admins by email when email is set up. The request also shows
// as a badge on Support Requests in the admin menu, so this is best effort.
function support_notify_admins($conn, $reference, array $form) {
    if (!mail_is_configured()) {
        return;
    }
    $admins = mysqli_query($conn, "SELECT full_name, email FROM users WHERE role = 'admin'");
    while ($admin = mysqli_fetch_assoc($admins)) {
        send_mail($admin['email'], $admin['full_name'], "Support request $reference: " . support_category_label($form['category']),
            "Hello {$admin['full_name']},\n\n"
          . "A " . $form['role'] . " has asked the administrator for help on SPTS.\n\n"
          . "Reference: $reference\n"
          . "Category: " . support_category_label($form['category']) . "\n"
          . "Name: {$form['full_name']}\n\n"
          . "Open Support Requests in the admin panel to see the details. "
          . "Verify the person's identity before resetting any account.\n\n"
          . "— SPTS, Nilai University");
    }
}

// Spam protection for the public form. Returns an error message, or ''.
function support_throttle_error($conn, $email) {
    if (login_is_throttled($conn, '*', 'support', SUPPORT_MAX_PER_NETWORK)
        || login_is_throttled($conn, $email, 'support_email', SUPPORT_MAX_PER_EMAIL)) {
        return "Too many requests have been sent from here. Please wait " . LOGIN_WINDOW_MINUTES . " minutes and try again.";
    }
    return '';
}

function support_record_submission($conn, $email) {
    login_record_failed_attempt($conn, '*', 'support');
    login_record_failed_attempt($conn, $email, 'support_email');
}

// ── Admin only: does the request match an account? ──────────────────────
// Compares what was typed with the records, so the admin can see what
// still needs checking. Never shown to the requester.
function support_account_check($conn, array $req) {
    $sql = "SELECT u.id, u.full_name, u.email, u.role, s.student_id
            FROM users u LEFT JOIN students s ON s.user_id = u.id WHERE ";
    if ($req['user_id']) {
        $stmt = mysqli_prepare($conn, $sql . "u.id = ?");
        mysqli_stmt_bind_param($stmt, "i", $req['user_id']);
    } else {
        $stmt = mysqli_prepare($conn, $sql . "u.email = ?");
        mysqli_stmt_bind_param($stmt, "s", $req['email']);
    }
    mysqli_stmt_execute($stmt);
    $account = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
    mysqli_stmt_close($stmt);

    $check = ['account' => $account, 'signed_in' => (bool)$req['user_id'],
              'role_ok' => null, 'name_ok' => null, 'id_ok' => null, 'id_owner' => null];

    if ($account) {
        $check['role_ok'] = $account['role'] === $req['role'];
        $check['name_ok'] = strcasecmp(preg_replace('/\s+/', ' ', trim($account['full_name'])), preg_replace('/\s+/', ' ', trim($req['full_name']))) === 0;
        // Staff IDs aren't stored in SPTS, so only a Student ID can be checked.
        if ($req['role'] === 'student' && $account['role'] === 'student' && $req['id_number'] !== null) {
            $check['id_ok'] = strcasecmp((string)$account['student_id'], $req['id_number']) === 0;
        }
    }
    // A Student ID that belongs to a different account is worth knowing.
    if ($req['role'] === 'student' && $req['id_number'] && !$check['id_ok']) {
        $stmt = mysqli_prepare($conn, "SELECT u.full_name, u.email FROM students s JOIN users u ON u.id = s.user_id WHERE s.student_id = ?");
        mysqli_stmt_bind_param($stmt, "s", $req['id_number']);
        mysqli_stmt_execute($stmt);
        $check['id_owner'] = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
        mysqli_stmt_close($stmt);
    }
    return $check;
}

// Whether an admin may reset the matched account from this request: an
// open sign-in problem whose email belongs to a student/lecturer account
// of the role the requester gave. Administrator accounts are never reset
// from a support request.
function support_can_reset(array $req, array $check) {
    return $req['status'] !== 'Resolved'
        && in_array($req['category'], ['forgot_password', 'cannot_login'], true)
        && $check['account'] !== null
        && in_array($check['account']['role'], ['student', 'lecturer'], true)
        && $check['role_ok'];
}
?>
