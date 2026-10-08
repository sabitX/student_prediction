<?php
// ── Environment settings template ───────────────────────────────────────
// Copy this file to env.php (same folder) and fill in real values.
// env.php holds secrets: keep it out of version control and backups
// that are shared. The config/ folder is not web-accessible (.htaccess).
return [
    // 'production' hides all error details from visitors;
    // 'development' shows PHP errors in the page while you work.
    'APP_ENV'     => 'production',

    // Used for all dates and times shown and compared by the system.
    'TIMEZONE'    => 'Asia/Kuala_Lumpur',

    'DB_HOST'     => 'localhost',
    'DB_NAME'     => 'student_prediction',
    'DB_USER'     => 'spts_app',
    'DB_PASSWORD' => 'change-me',

    // Public address of the site, used for links in emails. Leave blank to
    // work it out from each request (fine for localhost).
    'APP_URL'     => '',   // e.g. 'https://spts.nilai.edu.my'

    // Outgoing email (SMTP). Used to send new lecturers their sign-in
    // details. Leave MAIL_HOST blank to turn email off: the admin then sees
    // the temporary password on screen instead.
    // Gmail: MAIL_HOST 'smtp.gmail.com', port 587, 'tls', and an App
    // Password (Google Account → Security → App passwords), not the
    // normal Gmail password.
    'MAIL_HOST'       => '',
    'MAIL_PORT'       => 587,
    'MAIL_ENCRYPTION' => 'tls',       // 'tls' (port 587) or 'ssl' (port 465)
    'MAIL_USERNAME'   => '',
    'MAIL_PASSWORD'   => '',
    'MAIL_FROM'       => '',          // usually the same as MAIL_USERNAME
    'MAIL_FROM_NAME'  => 'SPTS Nilai University',
];
