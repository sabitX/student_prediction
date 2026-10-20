<?php
// ── Shared password rules ───────────────────────────────────────────────
// One place for the password policy and for generating temporary
// passwords that an administrator hands to a user. Every form that sets
// a password (register, change password, admin add/reset) uses this.

define('PASSWORD_MIN_LENGTH', 8);
define('PASSWORD_MAX_LENGTH', 72);   // bcrypt ignores anything longer
define('PASSWORD_RULE_TEXT', 'At least ' . PASSWORD_MIN_LENGTH . ' characters, including a letter and a number.');

// Returns an error message, or '' when the password is acceptable.
function password_policy_error($password) {
    $password = (string)$password;
    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        return "Password must be at least " . PASSWORD_MIN_LENGTH . " characters.";
    }
    if (strlen($password) > PASSWORD_MAX_LENGTH) {
        return "Password must be at most " . PASSWORD_MAX_LENGTH . " characters.";
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        return "Password must include at least one letter and one number.";
    }
    return '';
}

// Readable random temporary password (no 0/O/1/l look-alikes). Always
// contains a digit and a letter, so it satisfies the policy above.
function generate_temp_password($length = 10) {
    $letters = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz';
    $digits  = '23456789';
    $all     = $letters . $digits;
    $chars   = [$letters[random_int(0, strlen($letters) - 1)], $digits[random_int(0, strlen($digits) - 1)]];
    for ($i = 2; $i < $length; $i++) {
        $chars[] = $all[random_int(0, strlen($all) - 1)];
    }
    // Fisher–Yates with a CSPRNG so the letter/digit aren't always first.
    for ($i = count($chars) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
    }
    return implode('', $chars);
}

// Sets a temporary password that the user must replace at next login.
function set_temporary_password($conn, $user_id, $temp_password) {
    $hash = password_hash($temp_password, PASSWORD_DEFAULT);
    $stmt = mysqli_prepare($conn, "UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "si", $hash, $user_id);
    mysqli_stmt_execute($stmt);
    $changed = mysqli_stmt_affected_rows($stmt) > 0;
    mysqli_stmt_close($stmt);
    return $changed;
}
?>
