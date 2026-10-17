# SPTS — System Functionalities (Detailed)

**System:** Student Performance Tracking System (SPTS), Nilai University
**Stack:** PHP 8 (procedural, mysqli) · MySQL · Bootstrap 5.3 · Bootstrap Icons · Chart.js (all served locally from `assets/vendor/`) · XAMPP/Apache
**Roles:** Administrator, Lecturer, Student (plus unauthenticated visitors). A student can also be a **peer mentor**.
**Updated:** September 2026, after the fault-fix round (see section 10).

---

## 1. System Overview

SPTS records four assessment components for each student, in each subject, in each **semester**. It then computes a **weighted total** and classifies the student's standing:

```
Total Score = Assignment × 20% + Midterm × 30% + Final Exam × 40% + Attendance × 10%
  ≥ 80 Excellent · ≥ 60 Pass · ≥ 50 At Risk · < 50 Fail
```

- **Progressive marks.** A blank mark means "not assessed yet" and is stored as NULL, never as 0. The total and status appear only when all four marks exist; until then the subject shows *Pending*.
- **Semesters.** Exactly one semester is active. Enrolments, marks and results all belong to a semester, so the transcript can show per-semester GPA and cumulative CGPA.
- **Enrolment.** Lecturers only see, and can only grade, students enrolled in their subject for the active semester.
- **Shared rules.** The formula lives in [includes/grading.php](includes/grading.php). Mark saving lives in [includes/marks.php](includes/marks.php), which the grid and the CSV upload both use. Mentorship rules live in [includes/mentorship.php](includes/mentorship.php).

---

## 2. Database Entities

There are 16 tables and 2 views:
- `users`
- `students`
- `semesters`
- `subjects`
- `lecturer_subjects`
- `student_enrollments`
- `performance`
- `audit_logs`
- `mentorship_sessions`
- `password_resets`
- `login_attempts`
- `account_requests`
- `plos` — Programme Learning Outcomes, with the score needed to attain one and the class KPI
- `subject_clos` — CLO1–CLO4 per subject: description, Bloom's level, linked PLO
- `clo_assessments` — which CLO each mark component (assignment, midterm, final exam, attendance) assesses
- `cqi_actions` — a lecturer's Continuous Quality Improvement action for a CLO that missed its KPI
- `predictions` (a **view**: each result is calculated from the marks in `performance` when read, so it is never stored)
- `early_risk_predictions` (a **view**: the weighted average of the marks entered so far, the final-exam score needed to pass, and the attendance bar — available before the final exam is marked)

The full attributes, keys, relationships, delete rules and a ready-to-render ERD are in [SYSTEM_WORKFLOW_AND_ERD.md](SYSTEM_WORKFLOW_AND_ERD.md). The importable schema is [database/schema.sql](database/schema.sql).

---

## 3. Public / Unauthenticated Functions

### 3.1 Landing Page — [index.php](index.php)
- Signed-in users are redirected to their dashboard.
- The page has a hero section, "How It Works", "Three roles", links to the Student and Staff login pages and to Register, and a dark-mode toggle.

### 3.2 Custom 404 Page — [404.php](404.php)
- Set through `.htaccess`. It works at any URL depth because its links and styles use absolute paths.
- Supports dark mode.
- Signed-in users get a "Back to Dashboard" button.

---

## 4. Authentication Module (`auth/`)

### 4.1 Login — [auth/login.php](auth/login.php)
- **Student and Staff portals.** They differ only in branding; the role comes from the database.
- **Security:**
  - bcrypt `password_verify`
  - CSRF token
  - rate limit of 5 failed attempts per 15 minutes for each email + IP
  - generic error message
  - session ID regenerated on login
- A user given a **temporary password** is sent to Change Password before anything else.
- If a session expired from inactivity, the page explains why the user is back at login.

### 4.1a Student Account Request — [auth/request_student_account.php](auth/request_student_account.php)
- **For newly admitted students**, linked from the student sign-in page ("New student? Request an account") while self-registration is off.
- **Fields:** full name, email (also the sign-in email; details are sent there), Student ID, programme, year (1–3), optional message.
- **Validation:** the same rules as admin-added students (Student ID format, programme must match the level the ID implies, year 1–3); refused if the email or Student ID already has an account, or already has a pending request (unique in the database).
- **Rate limit:** 5 requests per 15 minutes per network.
- **Review:** the admin sees it under **Account Requests** tagged *Student*, with the Student ID, detected level, programme and year, and checks it against admission records. Approving re-checks everything, then creates the login and the student profile in one transaction and emails a temporary password (shown to the admin once if email isn't set up). The student must choose their own password at first sign-in.

### 4.2 Student Self-Registration — [auth/register.php](auth/register.php)
- **Off by default.** Only available when `ALLOW_SELF_REGISTRATION` is `true` in `config/env.php`; otherwise the page answers "Registration is closed" and the Register links are hidden. The form can't verify that someone owns the email or Student ID they enter, so in production the admin office creates student accounts.
- **Fields:** full name, email, password + confirmation, Student ID, programme (grouped by level), year (1–3).
- **Server-side validation:**
  - email format
  - Student ID must be `n` + 7–10 digits, stored in lowercase
  - the programme must belong to the academic level detected from the ID (`n0002…` Diploma, `n0003…` Degree, `n0004…` Masters, otherwise PhD)
  - password: 8+ characters with a letter and a number
  - email and Student ID must be unique
- The `users` and `students` inserts run in one **transaction**. Database error text is logged, never shown to the user.

### 4.3 Forgot Password (admin-reviewed) — [auth/forgot_password.php](auth/forgot_password.php)
- The user enters email + full name. The page **never changes a password**; it only files a request for an administrator.
- The reply is identical whether or not the details match, and requests are rate-limited.
- An admin verifies the person, issues a one-time temporary password (**Password Requests**, 5.8), and the user must replace it at next login.

### 4.4 Change Password — [auth/change_password.php](auth/change_password.php)
- Available to every role from the sidebar.
- Requires the current password, a new one (8+ characters with a letter and a number, different from the old), and a confirmation. The session ID is regenerated afterwards.
- It is also the forced landing page for temporary passwords. From there the user can only change the password or log out.

### 4.5 Logout — [auth/logout.php](auth/logout.php)
- Logout is a **POST with a CSRF token**, done through the sidebar button. It clears the session and its cookie.
- Opening the URL directly shows a confirmation button instead of logging out silently.

### 4.6 Sessions — [includes/session.php](includes/session.php), [includes/session_check.php](includes/session_check.php)
- Session cookies are HttpOnly and SameSite=Lax, with strict mode on.
- Users are signed out after **30 minutes idle**.
- **Access is decided by folder:**
  - `admin/` → admin only
  - `lecturer/` and `actions/` → lecturer only
  - `student/` → student only
  
  Anyone else is redirected to their own dashboard.
- A student account with no student profile sees a "profile incomplete" message instead of broken pages.

---

## 5. Administrator Portal (`admin/`)

Sidebar (shared, [includes/sidebar.php](includes/sidebar.php)):
- Dashboard
- Subjects
- Enrolments
- Students
- Staff
- Semesters
- Password Requests (with a badge for open requests)
- Audit Log
- Change Password
- Logout

### 5.1 Dashboard — [admin_dashboard.php](admin/admin_dashboard.php)
- **KPI cards:** students, active subjects, assessments recorded this semester, number failing.
- **Charts:**
  - status-distribution doughnut for the active semester
  - average score for each active subject, with each bar coloured by status
  - **PLO attainment**: share of student CLO results this semester that met each PLO's target, against the class KPI
- **CLOs below target (CQI):** every CLO under its class KPI this semester, and whether the lecturer has logged an action.
- **Student table:** subjects enrolled and average score this semester. It has live search and a link to each profile.

### 5.2 Semesters — [manage_semesters.php](admin/manage_semesters.php)
- Add a semester (unique name, valid start and end dates).
- **Set Active**: exactly one semester is active at a time.
- Delete a semester only if it has no enrolments or marks.
- Each semester shows its enrolment and mark-record counts.

### 5.3 Subjects — [manage_subjects.php](admin/manage_subjects.php)
- **Add:**
  - Code must be 3–20 letters or digits and is stored in upper case; the name is required.
  - A lecturer is optional.
  - An existing code is rejected with a clear message: use Edit, or Restore if it is archived.
- **Credit hours** (1–6, steps of 0.5) on Add and Edit; the transcript's GPA uses them.
- **Edit** (modal): rename the subject, change its credit hours, and change or remove its lecturer (one lecturer per subject).
- **Outcomes** ([subject_outcomes.php](admin/subject_outcomes.php)): word CLO1–CLO4, tag each with a Bloom's level and a PLO, and choose which CLO each mark component assesses. Shows this semester's attainment per CLO. New subjects start with default outcomes.
- **Programme outcomes** (same page, shared by every subject): edit each PLO's title, description, target score and class KPI.

### 5.3a Early-Warning Accuracy — [model_evaluation.php](admin/model_evaluation.php)
- Replays every finished result as if the later marks weren't in yet (before the final exam, and before the midterm) and runs the same warning rules the dashboards use.
- Shows how many students who ended below 60% would have been flagged (recall), how many flagged students did end below 60% (precision), accuracy, a flagged/not-flagged table, and the average projection error with and without blending in earlier results.
- Class averages come from the replayed marks only, so nothing from the final exam leaks in. Warns when there are fewer than 30 finished results.
- **Unassign** lecturer, **Archive** (soft delete), and **Restore** from the Archived Subjects list.
- Archived subjects are hidden from lecturers, students, the CSV upload and the dashboard chart; their history is kept.

### 5.4 Enrolments — [manage_enrollments.php](admin/manage_enrollments.php)
- Pick an active subject and tick the students who take it in the **active semester**.
- Search and a level filter help find students; "Select shown" and "Clear shown" act on the visible rows.
- Students who already have marks this semester are locked in, so a lecturer's work can't be hidden by accident.

### 5.5 Students — [manage_students.php](admin/manage_students.php)
- **Add student:** same validation as self-registration. The admin-set password is temporary and must be changed at first login.
- **List:** Student ID, name and email, programme and level, year, subjects enrolled and average this semester, and peer-mentor status. Live search.
- **Row actions:**
  - **View**
  - **Edit** programme and year (the programme must match the ID's level)
  - **Reset** password (a one-time temporary password is shown to the admin)
  - **Peer Mentor** assign or revoke
  - **Delete** (cascades to the student's records, with confirmation)

### 5.6 View Student — [view_student.php](admin/view_student.php)
- Profile, plus results for **every semester**: semester, subject, four marks ("—" if not assessed), total, and status.

### 5.7 Staff — [manage_lecturers.php](admin/manage_lecturers.php)
- **Add staff account:** lecturer or **administrator**. Email is validated, and the temporary password must be changed at first login.
- **List:** lecturers and admins, with an Administrator badge, assigned subjects and creation date.
- **Reset** a lecturer's password (becomes temporary).
- **Delete** a lecturer. Their audit history and past sessions are **kept**, and their upcoming sessions are declined.

### 5.8 Password Requests — [password_requests.php](admin/password_requests.php)
- Shows open requests (user, role, Student ID, requested and expiry dates).
- **Issue Temporary Password** (shown once) or **Reject**.
- Also lists recently resolved requests and which admin handled each.

### 5.9 Audit Log — [audit_logs.php](admin/audit_logs.php)
- Every mark change by every lecturer, including lecturers since deleted.
- Each entry records old → new values.
- Filter by lecturer, action and text (for example a Student ID or subject code). Shows the latest 500 entries.

---

## 6. Lecturer Portal (`lecturer/` + `actions/`)

Sidebar: Dashboard · Enter Marks · CSV Upload · Audit Trail · Change Password · Logout.

### 6.1 Dashboard — [lecturer_dashboard.php](lecturer/lecturer_dashboard.php)
- **Stats** for the lecturer's active subjects and enrolled students this semester: total, passing, at risk, failing.
- **Mid-semester early warnings:** students whose final exam isn't marked yet and whose marks so far raise a flag, most serious first, with the projected total, the final-exam score needed to pass, and the reasons in plain words (attendance below 80% / BE, a final-exam target above 75%, a projection below 60%, assignment or midterm 1+ standard deviations below the class average).
- **Learning outcome attainment & CQI:** for each subject, the share of assessed students attaining each CLO against the KPI. A CLO below the KPI is marked "CQI required"; the lecturer records a root cause and remedial plan (one per CLO per semester, editable, audited).
- **Mentorship:**
  - Pending requests: **Approve**, **Reschedule**, **Decline**.
  - Upcoming approved sessions: **Reschedule**, **Cancel**, and **Complete** once the session time has passed.
- Rules enforced on the server:
  - valid status transitions only
  - future dates only
  - no two approved sessions within 60 minutes of each other

### 6.2 Enter Marks — [enter_marks.php](lecturer/enter_marks.php)
- A **subject picker** for lecturers with several subjects.
- Only the lecturer's own active subjects; any other `subject_id` is refused.
- The grid lists the **students enrolled** this semester, with filter tabs by academic level.
- Four mark inputs per student. **Blank = not assessed yet.** The total and status pill appear once all four marks are entered.
- Clear notices when no semester is active or no students are enrolled.

### 6.3 Save Marks — [actions/save_marks.php](actions/save_marks.php)
- Checks the CSRF token, subject ownership, that the subject isn't archived, and that a semester is active.
- Only enrolled students are processed. Each mark must be 0–100 or blank; a row with an invalid value is skipped and counted.
- Unchanged rows are skipped. Clearing a row that had marks is allowed and audited.
- The save is an **atomic upsert** keyed on (student, subject, semester). The total and status are not stored: the `predictions` view calculates them once all four marks are complete.
- The audit entry records old → new values.
- Everything runs in one transaction.

### 6.4 CSV Upload — [csv_upload.php](lecturer/csv_upload.php)
- The **template is pre-filled** with the lecturer's enrolled roster and existing marks.
- Columns are matched by **header name**:
  - column order doesn't matter, and extra columns are ignored
  - Excel's BOM is stripped
  - `.CSV` in any case is accepted
  - blank lines are skipped
- **Row checks:**
  - student ID and subject code must be present
  - marks must be 0–100 or blank (text is rejected, never turned into 0)
  - the subject must be one of the lecturer's active subjects
  - the student must exist and be enrolled this semester
- Saved through the same routine as the grid, in **one transaction**.
- **Report:** saved, already up to date, and rejected rows with reasons.

### 6.5 Audit Trail — [audit_trail.php](lecturer/audit_trail.php)
- The lecturer's own 200 most recent audit entries.

---

## 7. Student Portal (`student/`)

Sidebar: Dashboard · What-If Calculator · Mentorship · My Transcript · Change Password · Logout.

### 7.1 Dashboard — [student_dashboard.php](student/student_dashboard.php)
- **Profile card** and **stat tiles:** subjects, average, best and lowest score. Only graded subjects count; a real 0 counts; "—" if none are graded.
- **Results for the active semester:** every enrolled subject, marks so far ("—" if not assessed), an animated total bar, and the status (Pending until complete, with the projected total underneath). Each subject shows its CLO pills: attained ✓, not attained ✗, or not assessed yet.
- **Programme Learning Outcomes:** radar chart of the student's cumulative score on each PLO against its target.
- **Mid-semester outlook:** for each subject without a final-exam mark, the projected total, the final-exam score needed to pass, and the reasons for any warning, with a link to book mentorship.

### 7.2 What-If Calculator — [predict.php](student/predict.php)
- Lists only the student's own **enrolled, active** subjects this semester.
- The official assignment, midterm and attendance marks for this semester load and are locked.
- Try final-exam values to see:
  - the projected total, bar, status, advice and weighted breakdown
  - the **minimum final-exam score needed to pass**
  - a warning pop-up if the projection is At Risk or Fail
  - **learning outcome impact**: each CLO's score and whether it is attained, updating as the final-exam value changes
  - a **BE warning** when attendance is below 80%
- Nothing is saved.

### 7.3 Mentorship — [mentorship.php](student/mentorship.php)
- At-Risk / Fail alerts for this semester, and **early warnings** for subjects whose final exam isn't marked yet, with the reasons.
- **Find a mentor** for an enrolled subject. The list shows:
  - the subject's lecturer
  - **peer mentors**: students flagged by the admin who scored Excellent in that subject
- **Book** (checked on the server):
  - the mentor must be valid for that subject
  - the date must be in the future and at most 180 days ahead
  - notes up to 500 characters
  - no duplicate open request
  - the mentor must not be busy within 60 minutes of the time
- **My Sessions:** status list (Pending, Approved, Completed, Declined, Cancelled). **Cancel** is available while Pending or Approved.
- **Peer mentors** get a "Requests to Me" inbox (approve, decline, complete).

### 7.4 Transcript — [transcript.php](student/transcript.php)
- Printable (Print / Save as PDF). Header shows student details, programme, **intake** (first semester) and **number of semesters**, all taken from the data.
- **One block per semester:** code, course, credits (from the subject), grade, GP, Pass/Fail and Remarks.
- **Remarks:** "BE" (Barred from Examination) when attendance is below 80%; otherwise "At Risk" for passing scores of 50–59. "Pending", shown with the grade **GP**, for incomplete marks, which are excluded from the GPA.
- **Summary for each semester:**
  - total weightage and grade points
  - GPA = Σ(GP × credits) ÷ Σ(credits)
  - cumulative weightage, grade points and **CGPA**
- **PLO attainment (cumulative):** score, target and Attained / Not Attained for each PLO.
- Grade scale: A+ ≥90, A ≥80, A- ≥75, B+ ≥70, B ≥65, B- ≥60, C+ ≥55, C ≥50, D+ ≥45, D ≥40, F <40.

---

## 8. Shared Modules (`includes/`, `assets/`)

| Module | Purpose |
|---|---|
| `config/config.php` + `env.php` | Bootstrap: loads secrets from `env.php`, sets one timezone for PHP and MySQL, hides errors in production, shows a friendly 500 page for anything unexpected, connects as the least-privilege `spts_app` user. `h()` escaping helper. |
| `session.php` / `session_check.php` | Hardened session start, idle timeout, folder-based role gate, forced password change |
| `sidebar.php`, `page_start.php` / `page_end.php` | Shared navigation, and the page frame used by the newer admin pages |
| `csrf.php` | One token per session, timing-safe check |
| `grading.php` | Formula, component weights, status thresholds, colours |
| `early_warning.php` | Mid-semester projection (coursework blended with the student's earlier average, or the class average), attendance bar, class comparison, plain-language risk reasons |
| `obe.php` | Default CLOs, CLO and PLO attainment for a student, a class and the institution, CQI actions |
| `marks.php` | Mark parsing and validation, enrolment check, the shared save routine with audit |
| `mentorship.php` | Mentor eligibility, date and clash checks, status transitions |
| `academic_level.php` | Student ID format, level detection, programme catalogue, profile validation |
| `passwords.php` | Password policy and temporary passwords |
| `login_throttle.php` | Rate limiting for login and for reset requests |
| `semester.php` | Active semester lookup |
| `no_student_profile.php` | Message for student accounts with no profile |
| `assets/js/theme-toggle.js` | Dark/light mode, remembered in `localStorage` |

`config/`, `includes/` and `database/` are blocked from direct web access by `.htaccess`.

### One-time setup scripts (`tools/`)
`create_admin.php`, `create_lecturer.php` and `add_subject_data.php` each lock themselves once data exists. The default accounts they create must change their password at first login.

---

## 9. Security Features Summary

1. Prepared statements for all input-driven queries
2. bcrypt password hashes, a shared password policy, and temporary passwords that must be replaced
3. CSRF tokens on every state-changing form, including logout
4. Output escaping with `h()` everywhere, including CSV error messages
5. Folder-based role access control, plus object-level checks:
   - lecturer ↔ subject ownership for the grid and CSV
   - enrolment checks
   - mentor ↔ subject eligibility
   - session ownership
6. Session hardening: regeneration on login and password change, HttpOnly/SameSite cookies, 30-minute idle timeout
7. Rate limiting on login and password-reset requests
8. Admin-verified password resets that never confirm whether an account exists
9. Transactions for registration, mark saves, CSV uploads, enrolments and subject changes
10. Least-privilege database account; internal folders blocked from the web
11. Soft delete for subjects; history kept when lecturers are deleted (SET NULL plus stored names)
12. Audit trail with old → new values, visible to admins across all lecturers

---

## 10. Fault Fixes Applied (September 2026)

| # | Fault | Fix |
|---|---|---|
| 1 | Anyone could reset any password with email + name | Reset now files a request; an admin issues a temporary password; rate-limited; generic reply |
| 2 | CSV let any lecturer grade any subject | Ownership, archive and enrolment checks on every row |
| 3 | No way to change a password; default `admin123` | Change Password for all roles; temporary passwords forced to change; admins can create admins; the existing default admin was flagged to change at next login |
| 4 | Grid couldn't save partial marks | Blank = NULL; the result is computed once all 4 marks exist |
| 5 | CSV turned blanks and text into 0 | Blank = not assessed; text rejected with a reason |
| 6 | CSV crashes (extra columns, BOM, `.CSV`, empty file), no transaction | Header-based mapping, BOM strip, case-insensitive extension, empty-file message, one transaction |
| 7 | Transcript maths wrong / hard-coded | Real per-semester GPA and cumulative CGPA; GP for pending; At Risk in Remarks; real intake and semester count |
| 8 | Archive / duplicate-code crash, no restore or rename | Clear duplicate messages, Restore, Edit modal |
| 9 | Archived subjects still offered | Hidden from the grid, CSV, What-If, Mentorship and dashboard chart |
| 10 | Deleting a lecturer erased audit and session history | SET NULL foreign keys and stored names; upcoming sessions declined |
| 11 | No enrolment; every student on every grid | Enrolments page; grid, CSV, stats and student pages use enrolments |
| 12 | Semesters not linked to data | `semester_id` on marks and results; Semesters page; transcript by semester |
| 13 | Weak mentorship validation, no cancel or reschedule | Full server checks, clash detection, student Cancel, lecturer Reschedule and Cancel |
| 14 | Registration accepted bad email, ID and programme; leaked DB errors | Server-side validation; generic error text |
| 15 | Lecturers could open student pages | Folder-based role gate |
| 16 | Weak audit trail, no admin view | Only real changes logged, with old → new values; admin Audit Log page |
| 17 | Duplicate mark rows possible | UNIQUE (student, subject, semester) and atomic upserts |
| 18 | Peer-mentor flag did nothing | Peer mentors appear in search and get a request inbox |
| 19 | "Lowest score" wrong with zero scores | Stats use graded subjects only; a 0 counts |
| 20 | "Remember me" did nothing | Removed |
| 21 | CSV template IDs in the wrong format | Template pre-filled with the real roster |
| 22 | 404 page broke in subfolders | Absolute paths, dark mode, dashboard link |
| 23 | GET logout, no session timeout | POST + CSRF logout, 30-minute idle timeout, hardened cookies |
| 24 | Root DB user; CDN-only styling | `spts_app` least-privilege user; Bootstrap, icons and Chart.js served locally; internal folders blocked |
| 25 | Unused `password_resets` table; missing `uploads/` in README | The table now powers reset requests; README rewritten |

Also fixed along the way:
- **Subject picker on Enter Marks.** Lecturers with several subjects previously couldn't reach any subject but the first.
- **Admin's own name overwritten** in the top bar after adding a lecturer.
- **Inflated counts** from double LEFT JOINs on the admin student lists.
- **Student account with no profile** now shows a clear message instead of broken pages.

### Remaining limitations
- There is no email service, so password resets need an admin (by design, for this XAMPP deployment).
- `year_of_study` is limited to 1–3 for every level.
- Google Fonts (Inter) still loads from the internet. Offline, pages fall back to the system font.
- The `.htaccess` 404 path contains the folder name `student_prediction`.
