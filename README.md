# STUDENT PERFORMANCE TRACKING SYSTEM

Developed by: Jayed Al Sabit
Student ID : 00021960
Course : Diploma in Information Technology
University : Nilai University
Subject : EC2320 Summative (Computing Project)
Year : 2026

---

## SETUP INSTRUCTIONS (fresh install)

1. Install XAMPP and start Apache + MySQL.
2. Copy the `student_prediction` folder to `C:\xampp\htdocs\`.
3. Open phpMyAdmin at http://localhost/phpmyadmin and create a database named `student_prediction`.
4. Import `database/schema.sql` into it.
5. Create the application's database user (it only gets read/write rights on this database, never root). In phpMyAdmin → SQL, run, choosing your own password:

   ```sql
   CREATE USER 'spts_app'@'localhost' IDENTIFIED BY 'choose-a-strong-password';
   GRANT SELECT, INSERT, UPDATE, DELETE ON student_prediction.* TO 'spts_app'@'localhost';
   ```

6. Copy `config/env.example.php` to `config/env.php` and put that password in `DB_PASSWORD` (also set `APP_ENV` and `TIMEZONE`).

Going live on a real server? Follow **DEPLOYMENT.md** (HTTPS, php.ini, backups, monitoring).

### Upgrading an existing install

Back up the database, then run `database/migrations/001_fault_fixes.sql` and then `002_onboarding.sql`, `003_account_requests.sql`, `004_schema_hardening.sql`, `005_integrity_checks.sql`, `006_predictions_view.sql`, `007_predictions_view_collation.sql`, `008_student_account_requests.sql`, `009_obe_and_early_warning.sql`, `010_normalization_review.sql` and `011_support_requests.sql`, each once, in that order, as MySQL `root` (004–010 create triggers, views and constraints). After that, do steps 5–6 above.

---

## FIRST TIME SETUP (run once in browser)

1. Create the admin account: http://localhost/student_prediction/tools/create_admin.php
   (email `admin@student.com`; the page shows a random temporary password once, so copy it — you must set your own password at first login).
2. Log in as admin, then:
   - **Semesters**: add the current semester and click *Set Active*.
   - **Staff**: add lecturer accounts.
   - **Subjects**: add subjects and assign a lecturer to each.
   - **Enrolments**: choose which students take each subject this semester.
3. The admin adds students under **Students**, or newly admitted students ask for an account from the student sign-in page ("New student? Request an account") and the admin approves it under **Account Requests** after checking it against admission records. (The public Register page is off unless `ALLOW_SELF_REGISTRATION` is set to `true` in `config/env.php`; it can't verify who owns an email or Student ID.)

The `tools/` scripts disable themselves once an admin, lecturer or subject exists.

---

## ACCOUNTS & PASSWORDS

- Every user can change their password from **Change Password** in the sidebar.
- Accounts created by an admin, and the default setup accounts, must set a new password at first login.
- **Forgot password?** on the sign-in pages opens **Contact Admin**, which sends a support request with a reference number to the administrator (**Support Requests**). The admin verifies the person and issues a one-time temporary password. The user then sets their own password at next login. Signed-in students and lecturers have **Contact Admin** in their menu for anything else.
- Sessions end after 30 minutes of inactivity. Login and Contact Admin requests are rate-limited.

---

## PREDICTION FORMULA

Total Score = (Assignment x 20%) + (Midterm x 30%) + (Final Exam x 40%) + (Attendance x 10%)

- \>= 80 : Excellent
- \>= 60 : Pass
- \>= 50 : At Risk
- \< 50 : Fail

Marks can be entered progressively; a blank mark means "not assessed yet". The total and status are calculated once all four marks are entered. Until then the subject shows *Pending*. All marks, results and enrolments belong to a semester.

---

## TECH STACK

- Frontend : HTML, CSS, Bootstrap 5 (served locally from `assets/vendor/`)
- Backend : PHP 8
- Database : MySQL (via phpMyAdmin)
- Server : XAMPP (Apache)

---

## PROJECT STRUCTURE

```
student_prediction/
├── assets/            CSS, JS, images, vendor/ (Bootstrap, Bootstrap Icons, Chart.js)
├── config/            config.php (bootstrap), env.php (secrets, not in git), env.example.php (not web-accessible)
├── database/          schema.sql, migrations/ (not web-accessible)
├── includes/          shared logic (not web-accessible): session.php, session_check.php,
│                      sidebar.php, page_start.php/page_end.php, csrf.php, semester.php,
│                      academic_level.php, grading.php, marks.php, mentorship.php,
│                      passwords.php, login_throttle.php, flash.php, no_student_profile.php,
│                      support.php, support_page.php
├── auth/              login, logout, register, contact_admin, change_password
├── admin/             admin_dashboard, manage_subjects, manage_enrollments, manage_students,
│                      manage_lecturers (staff), manage_semesters, support_requests,
│                      audit_logs, view_student
├── lecturer/          lecturer_dashboard, enter_marks, csv_upload, audit_trail, contact_admin
├── student/           student_dashboard, predict, mentorship, transcript, contact_admin
├── actions/           save_marks.php
├── tools/             one-time setup scripts (answer only from localhost)
├── index.php
├── 404.php
└── .htaccess          404 page, security headers, blocked files (update the 404 path if the folder is renamed)
```

CSV uploads are parsed in memory and never stored on the server.
