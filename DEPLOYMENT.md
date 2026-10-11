# SPTS — Production Deployment Checklist

Use this list when moving SPTS from a development XAMPP install to a server that real students and staff will use. Work through it top to bottom; each item says **why** it matters.

---

## 1. Server & PHP

- [ ] **PHP 8.1 or newer** with `mysqli` and `mbstring`.
- [ ] **HTTPS only.** Install a TLS certificate (e.g. Let's Encrypt) and redirect HTTP to HTTPS. When the site is served over HTTPS, SPTS automatically marks the session cookie `Secure`, and `.htaccess` sends HSTS.
- [ ] Apache with **`AllowOverride All`** for the project folder, plus the **`mod_headers`** module. Without them, `.htaccess` security headers and folder blocking are ignored.
- [ ] In Apache's main config (`httpd.conf`), hide version numbers. Out of the box, every response says exactly which Apache, OpenSSL and PHP versions are running:

  ```apache
  ServerTokens Prod
  ServerSignature Off
  ```
- [ ] In `php.ini`:

  | Setting | Value | Why |
  |---|---|---|
  | `display_errors` | `Off` | Never show paths or SQL to visitors (SPTS also enforces this when `APP_ENV` is `production`) |
  | `log_errors` | `On` | Errors go to the log instead |
  | `expose_php` | `Off` | Don't advertise the PHP version |
  | `session.use_strict_mode` | `1` | Also set by SPTS; belt and braces |
  | `upload_max_filesize` / `post_max_size` | `4M` / `8M` | The CSV upload accepts up to 2 MB |
  | `date.timezone` | `Asia/Kuala_Lumpur` | Matches SPTS's `TIMEZONE` setting |

## 2. Configuration

- [ ] Copy `config/env.example.php` to **`config/env.php`** and fill it in:
  - `APP_ENV` = `production`
  - `TIMEZONE` = `Asia/Kuala_Lumpur`
  - database host, name, user and password
  - `APP_URL`: the site's public address, e.g. `https://spts.nilai.edu.my`. It's used for the sign-in link in emails.
  - `ALLOW_SELF_REGISTRATION`: leave `false`. Students are then added by the admin office; an open Register page would let anyone claim any Student ID.
  - **Email (`MAIL_*`)**, so new lecturers get their sign-in details automatically. With Gmail: turn on 2-Step Verification for the sending account, create an **App Password** (Google Account → Security → App passwords), then set `MAIL_HOST` `smtp.gmail.com`, `MAIL_PORT` `587`, `MAIL_ENCRYPTION` `tls`, the Gmail address as `MAIL_USERNAME` and `MAIL_FROM`, and the app password as `MAIL_PASSWORD`. Approve one test account request to confirm the email arrives. Without email settings, everything still works: the admin sees the temporary password on screen instead.
- [ ] `config/env.php` must **not** go into version control; `.gitignore` already excludes it. Restrict its file permissions so only the web server user can read it.
- [ ] If the folder is not served at `/student_prediction/`, update the `ErrorDocument` path in `.htaccess`.
- [ ] **Deploying with git?** Keep the repository data out of the web folder, so `/.git/` can never be downloaded: `git clone --separate-git-dir ~/spts.git <repo> ~/www/student_prediction` (or, for an existing checkout, `git init --separate-git-dir ~/spts.git` inside it). `.htaccess` also answers 404 for `/.git`, as a second line of defence.

## 3. Database

- [ ] **New install:** create the database with full Unicode, then import **`database/schema.sql`** (it already includes every migration):

  ```sql
  CREATE DATABASE student_prediction CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  ```

- [ ] **Upgrading an existing install:** run `database/migrations/001_fault_fixes.sql` and then `002_onboarding.sql`, `003_account_requests.sql`, `004_schema_hardening.sql`, `005_integrity_checks.sql`, `006_predictions_view.sql`, `007_predictions_view_collation.sql` and `008_student_account_requests.sql`, each once, in that order, after a backup. Run them (and `schema.sql`) as MySQL `root`, not `spts_app`: they create tables and triggers. If binary logging is on, set `log_bin_trust_function_creators = 1` first, or the triggers are refused.
- [ ] Create the **least-privilege application user**. Use a long random password, and never let the app connect as `root`:

  ```sql
  CREATE USER 'spts_app'@'localhost' IDENTIFIED BY '<long random password>';
  GRANT SELECT, INSERT, UPDATE, DELETE ON student_prediction.* TO 'spts_app'@'localhost';
  ```

- [ ] Give MySQL's own `root` account a strong password, and don't expose port 3306 to the internet.
- [ ] **Remove or lock down phpMyAdmin** on the production server. It is a common attack target.

## 4. First run

- [ ] Open `/tools/create_admin.php` **from the server itself**; `tools/` only answers requests from localhost. It shows a random temporary password **once**, so copy it. Sign in, and the system makes you choose your own password.
- [ ] As admin, in this order:
  1. Semesters (add one and *Set Active*)
  2. Staff
  3. Subjects (assign lecturers)
  4. Enrolments
- [ ] Optionally delete the `tools/` folder once setup is complete. The scripts already disable themselves.

## 5. Backups

- [ ] Schedule a **daily database dump**, kept for at least 30 days and copied off the server. Windows Task Scheduler example:

  ```bat
  "C:\xampp\mysql\bin\mysqldump.exe" -u root -p<root-password> --single-transaction student_prediction > "D:\backups\spts_%DATE:~-4%%DATE:~3,2%%DATE:~0,2%.sql"
  ```

- [ ] **Test a restore** into a scratch database at least once each semester. A backup nobody has restored is not a backup.
- [ ] Back up `config/env.php` separately and securely, because it holds the database password.

## 6. Monitoring & maintenance

- [ ] Check the Apache/PHP **error log** regularly. Lines starting `SPTS uncaught` are unexpected errors; each one shows the file and line.
- [ ] Rotate logs so they don't fill the disk.
- [ ] Old login attempts are deleted automatically after a day. Clear old, finished password-reset requests occasionally:

  ```sql
  DELETE FROM password_resets WHERE expires_at < NOW() - INTERVAL 90 DAY;
  ```

- [ ] At the start of each semester: add the semester, *Set Active*, then set up enrolments.
- [ ] Keep PHP, Apache and MySQL updated with security patches.

## 7. What SPTS already does for you

- Security headers and a content security policy, set in `.htaccess`
- No directory listings; `config/`, `includes/`, `database/`, `*.md` and `*.sql` are blocked from the web
- Friendly 500 and 404 pages; error details logged, never shown
- CSRF protection on every form
- Session hardening and a 30-minute idle timeout
- Rate limiting on login, password-reset requests and registration
- Password policy: 8+ characters with a letter and a number
- Temporary passwords are forced to change at next login
- Transactions and unique keys protect marks from partial saves and duplicates
- Database rules (foreign keys, checks, triggers) and strict SQL mode reject invalid data, whatever the source
- Signed-in sessions end at once if the account is deleted, its role changes, or its password is reset
- An audit log of every mark change and every administrator action
