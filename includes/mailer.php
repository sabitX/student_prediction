<?php
// ── Outgoing email over SMTP ────────────────────────────────────────────
// A small SMTP client so the system can email people without Composer or
// a server mail setup (XAMPP's mail() doesn't work out of the box).
// Settings come from config/env.php (see env.example.php): MAIL_HOST,
// MAIL_PORT, MAIL_ENCRYPTION ('tls' = STARTTLS on 587, 'ssl' = 465),
// MAIL_USERNAME, MAIL_PASSWORD, MAIL_FROM, MAIL_FROM_NAME.
//
// send_mail() returns true when the server accepted the message. It never
// throws: callers fall back to showing the information to the admin.

// Full link to a page of the app for emails, e.g. app_url('/auth/login.php'):
// APP_URL from env.php if set, otherwise worked out from this request.
function app_url($path) {
    if (setting('APP_URL') !== null) {
        return rtrim(setting('APP_URL'), '/') . $path;
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . app_base_path() . $path;
}

function mail_is_configured() {
    return setting('MAIL_HOST') !== null && setting('MAIL_FROM') !== null;
}

function send_mail($to, $to_name, $subject, $body_text) {
    if (!mail_is_configured() || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $host       = setting('MAIL_HOST');
    $port       = (int)setting('MAIL_PORT', 587);
    $encryption = strtolower(setting('MAIL_ENCRYPTION', 'tls'));
    $user       = setting('MAIL_USERNAME', '');
    $pass       = setting('MAIL_PASSWORD', '');
    $from       = setting('MAIL_FROM');
    $from_name  = setting('MAIL_FROM_NAME', 'SPTS Nilai University');

    $remote = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $socket = @stream_socket_client($remote, $errno, $errstr, 15);
    if (!$socket) {
        error_log("SPTS mail: cannot connect to $remote ($errstr)");
        return false;
    }
    stream_set_timeout($socket, 15);

    // Reads one (possibly multi-line) reply; true if its code is expected.
    $expect = function ($codes) use ($socket) {
        $reply = '';
        while (($line = fgets($socket, 515)) !== false) {
            $reply .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        $ok = in_array((int)substr($reply, 0, 3), (array)$codes, true);
        if (!$ok) error_log('SPTS mail: unexpected reply: ' . trim($reply));
        return $ok;
    };
    $send = function ($cmd, $codes) use ($socket, $expect) {
        fwrite($socket, $cmd . "\r\n");
        return $expect($codes);
    };

    $hostname = gethostname() ?: 'localhost';
    $ok = $expect(220) && $send("EHLO $hostname", 250);

    if ($ok && $encryption === 'tls') {
        $ok = $send('STARTTLS', 220)
           && stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)
           && $send("EHLO $hostname", 250);
    }
    if ($ok && $user !== '') {
        $ok = $send('AUTH LOGIN', 334)
           && $send(base64_encode($user), 334)
           && $send(base64_encode($pass), 235);
    }

    $ok = $ok
       && $send("MAIL FROM:<$from>", 250)
       && $send("RCPT TO:<$to>", [250, 251])
       && $send('DATA', 354);

    if ($ok) {
        $encode_name = fn($name) => '=?UTF-8?B?' . base64_encode($name) . '?=';
        $headers = [
            'Date: ' . date('r'),
            'From: ' . $encode_name($from_name) . " <$from>",
            'To: ' . ($to_name !== '' ? $encode_name($to_name) . ' ' : '') . "<$to>",
            'Subject: ' . $encode_name($subject),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . substr(strrchr($from, '@'), 1) . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        $message = implode("\r\n", $headers) . "\r\n\r\n"
                 . rtrim(chunk_split(base64_encode($body_text), 76, "\r\n"));
        $ok = $send($message . "\r\n.", 250);
    }

    @fwrite($socket, "QUIT\r\n");
    fclose($socket);
    return $ok;
}
?>
