# Prompt: Write my FYP draft report and final report (SPTS)

> **How to use (on claude.ai)**
>
> 1. Create a **Project** called "SPTS FYP Report". Upload these as project knowledge:
>    - from this folder: `SYSTEM_FUNCTIONALITIES.md`, `SYSTEM_WORKFLOW_AND_ERD.md`, `README.md`, `DEPLOYMENT.md`, `COLOR_PALETTE.md`, `database/schema.sql`, `tests/README.md`
>    - from Downloads: `SPTS_Normalization_0NF_to_3NF_v4.docx` (the approved normalization) and `SPTS_ERD_3NF.png`
>    - your university's **report template / guidelines / marking rubric** for the draft and final report, if you have them
>    - screenshots of the system (one per main page, light mode)
> 2. Fill in every `[bracket]` in section 1 below.
> 3. Paste everything below the line into the Project's instructions (or as the first message of a chat in that Project).
> 4. In each new chat, say what you want, e.g. *"Mode: DRAFT. Write the outline."* or *"Mode: FINAL. Revise Chapter 4 using this supervisor feedback: …"*.

---

You are helping me write the **draft report** and then the **final report** for my Final Year Project. I designed and built the system myself; the attached files describe it accurately and are the source of truth. Write as me, the student, in formal academic English suitable for a Malaysian private university diploma programme.

## 1. Project and student details

- **Project title:** Student Performance Tracking Web System Using a Weighted Scoring Model (SPTS)
- **Student:** Jayed Al Sabit · Student ID n00021960
- **Programme:** Diploma in Information Technology, Nilai University
- **Course:** EC2320 Summative (Computing Project), 2026
- **Department:** Department of Computing and Data Sciences
- **Supervisor:** [SUPERVISOR NAME]
- **Draft report due:** [DATE] · **Final report due:** [DATE]
- **Length and format:** [e.g. 10,000–15,000 words, Times New Roman 12, 1.5 spacing, or "follow the attached template"]
- **Referencing style:** [APA 7th edition, unless the template says otherwise]
- **Live system:** https://stps.alwaysdata.net/student_prediction/ (HTTPS, hosted on alwaysdata)

## 2. What the system is

SPTS is a role-based web application for tracking student performance. Lecturers record assessment marks; the system calculates each student's weighted total and academic standing, warns about students at risk **before the final exam**, tracks course and programme learning outcomes, and supports mentoring. The full detail is in the attached files; this is the summary.

**Weighted Scoring Model**
- Total = Assignment × A% + Midterm × M% + Final Exam × F% + Attendance × T%. Each subject has its own weights **for each semester** (table ASSESSMENT_WEIGHTS; default 20/30/40/10; each 5–80%, together 100%). Earlier semesters keep their weights, so published results never change.
- Standing (PredictedStatus): **Excellent** ≥ 80, **Pass** 60 to below 80, **At Risk** 50 to below 60, **Fail** below 50.
- Marks are entered progressively. A blank mark means "not assessed yet" (NULL, never 0). TotalScore and PredictedStatus are **calculated when read** by a database view (`predictions`), never stored; until all four marks exist the subject shows *Pending*.
- Remarks: **BE** (Barred from Examination) when attendance is below 80%; it is a remark only, it does not change the grade. [TO CONFIRM: whether Nilai's policy makes BE an automatic F, and whether 80% is the correct attendance bar]

**Early warning (prediction before the final exam)**
- A second view (`early_risk_predictions`) works from the marks entered so far: CurrentScore (weighted average of marks entered, rescaled to 0–100), the final-exam score needed to reach 60, and the attendance bar.
- The projected total blends the current score with the student's average in earlier semesters (or the class average for a new student): projected = α × current + (1 − α) × prior, where α = coursework weight marked so far ÷ total coursework weight. It is a simple, explainable shrinkage blend, **not a trained machine-learning model**.
- A student is flagged, with plain-language reasons, when: attendance is below 80% (BE risk); the final-exam score needed is above 75% (or above 100%, i.e. can no longer pass); the projection is below 60%; or the assignment or midterm is 1+ standard deviations below the class average (classes of 5 or more).
- Lecturers can **email** the warning to the student (records who was told and when; never repeats unless the risk becomes critical).
- **Warning Accuracy page (admin):** a backtest that replays every finished result as if later marks were missing (before the final exam, and before the midterm) and reports precision, recall, accuracy, a confusion table and the mean projection error with and without the prior. This is the project's evaluation of the early-warning method.

**Outcome-Based Education (OBE)**
- Programme Learning Outcomes (PLOs, with a target score and a class KPI) and CLO1–CLO4 per subject (description, Bloom's level, linked PLO). Each mark component assesses one CLO.
- CLO attainment per student and per class; PLO attainment per student (radar chart), per transcript and institution-wide.
- When a class misses a CLO's KPI, the lecturer records a **CQI action** (root cause and remedial plan).

**Roles and features**
- **Administrator:** dashboard (status doughnut, subject averages, PLO attainment, CLOs below target, support requests, results and at-risk CSV downloads); semesters (one active, carry-over of lecturers and weights); subjects (credit hours, archive/restore, weights and outcomes, PLOs); enrolments; students (add, edit, reset password, peer-mentor flag); staff; account requests from new students and lecturers; Support Requests (Contact Admin, identity-verified password reset); Warning Accuracy; audit log of every mark change (old → new).
- **Lecturer:** dashboard for their subjects (stats, early warnings with email, CLO attainment and CQI, mentorship requests); mark-entry grid; CSV upload (pre-filled template, row-level validation report); results CSV; audit trail; Contact Admin.
- **Student:** dashboard (results with CLO pills, progress across semesters with GPA/CGPA trend, PLO radar, mid-semester outlook); What-If Calculator (uses the subject's real weights, shows the final-exam score needed); mentorship booking with lecturers and qualified peer mentors (IsMentor and Excellent in that subject); printable transcript (A+ to F, credit-weighted GPA and CGPA, BE/At Risk remarks, PLO attainment); Contact Admin.
- **Public:** landing page, sign-in (student and staff portals), account request forms, Contact Admin (forgot password / cannot log in, with a reference number), privacy notice.

**Technology**
- PHP 8 (procedural, mysqli prepared statements), MariaDB/MySQL, Bootstrap 5.3, Bootstrap Icons, Chart.js (served locally), vanilla JavaScript.
- Developed on XAMPP (Windows); deployed on alwaysdata (Apache, PHP 8.4, MariaDB 11.8, HTTPS) by git, with database migrations 001–015.
- Responsive layout, dark mode, accessibility work (labels, focus, skip link).

**Security**
- Prepared statements; bcrypt password hashes; password policy; forced change of temporary passwords.
- CSRF tokens on every state-changing form (including logout); output escaping.
- Session hardening (regeneration, HttpOnly/SameSite cookies, 30-minute idle timeout).
- Rate limiting on login and public forms, honeypot field; admin-verified password resets that never reveal whether an account exists.
- Folder-based role access plus object-level checks (lecturer ↔ subject, enrolment, mentor eligibility).
- Least-privilege database user; security headers and Content Security Policy; HTTPS with HSTS; internal folders blocked from the web.
- Audit trail; CSV exports protected against formula injection; privacy notice.

## 3. Database design

- **19 tables and 2 views** (list, attributes, keys and Mermaid ERD in `SYSTEM_WORKFLOW_AND_ERD.md`; DDL in `schema.sql`).
- The **normalization (0NF → 1NF → 2NF → 3NF)** in `SPTS_Normalization_0NF_to_3NF_v4.docx` was reviewed by my supervisor over three rounds and is the version to use. Key decisions to explain:
  - PERFORMANCE's PK is **EnrollmentID** only (also the FK to STUDENT_ENROLLMENTS; one-to-zero-or-one); there is no PerformanceID.
  - COURSES (CourseID → CourseName, AcademicLevel) removes a transitive dependency from STUDENTS.
  - LECTURER_SUBJECTS has PK (SubjectID, SemesterID): one lecturer per subject per semester; LecturerID became UserID (a lecturer is a user).
  - ASSESSMENT_WEIGHTS has PK (SubjectID, SemesterID), kept separate from LECTURER_SUBJECTS because each is optional on its own.
  - MENTORSHIP_SESSIONS records who mentors whom: MenteeID → STUDENTS, MentorID → USERS (a lecturer or a peer-mentor student).
  - TotalScore and PredictedStatus are derived, so they are not stored (views).
- Use `SPTS_ERD_3NF.png` as the ERD of the core tables, and the full Mermaid ERD for the complete schema.

## 4. Development history (Methodology chapter)

Iterative, prototyping-style development, with a supervisor review loop on the data design:

1. **Core build** (to early October 2026): authentication, three portals, mark-entry grid, CSV upload, weighted scoring model, dashboards, What-If Calculator, mentorship, transcript.
2. **Structured review and fault fixing:** a full review found 25 faults (security to UI) and all were fixed and re-tested; the list is in section 10 of `SYSTEM_FUNCTIONALITIES.md`.
3. **Production hardening and deployment (8–12 October 2026):** environment-file secrets, time-zone alignment, friendly error page, security headers/CSP, Post/Redirect/Get, responsive and accessibility fixes, deployment to alwaysdata over HTTPS, a security audit, light academic theme, account requests for new students.
4. **Research upgrade (17–18 October):** mid-semester early warning, CLO/PLO outcomes with CQI, credit hours, the BE remark, and the Warning Accuracy backtest.
5. **Normalization review (20–26 October):** three supervisor review rounds led to the COURSES table, marks keyed by enrolment, lecturers per semester, `password_hash`, ASSESSMENT_WEIGHTS and mentorship in the normalization.
6. **Feature completion (22 October):** Contact Admin with identity-verified recovery, per-subject weights, progress trends, early-warning emails, CSV exports, privacy notice, automated tests.

Every database change was a numbered, re-runnable migration, applied to the live site after a backup, with results compared before and after (identical each time).

## 5. Verified test evidence (Testing chapter)

Use only these as **executed and passed**:

- **Automated end-to-end tests** (`tests/`, Python, run against a local copy): **157 checks in 5 suites, all passing**:
  - page crawl as every role, no PHP errors, custom 404 (5 checks)
  - Contact Admin and account recovery: validation, honeypot, rate limits, identical replies for unknown accounts, admin filters and statuses, access control, identity-verified reset, sign-in with the temporary password and the forced change (82)
  - per-subject weights: validation, recalculated totals, untouched history, carry-over, What-If and dashboards, admin-only editing, database CHECK constraints (32)
  - early-warning emails through a local fake mail server: content, "email all" never repeats, roster-only targets, CSRF, mail server down (19)
  - CSV exports: content matches the database, UTF-8 BOM, role checks, formula-injection protection, audit log (19)
- **Manual scenarios from the fault-fix round**, all passed:
  - login redirects to the right portal; cross-role pages refused; 6th failed login in 15 minutes blocked; idle session ends after 30 minutes
  - registration/account-request validation (email, Student ID format, programme vs level, weak password, duplicate ID in another case)
  - mark grid: partial marks save as Pending; 70/65/80/90 → 74.50 Pass (default weights); unchanged re-save creates no audit entry; "abc" and 190 rejected; another lecturer's subject refused; audit stores old → new
  - CSV: BOM, `.CSV`, extra columns, blank lines handled; text marks, other lecturers' subjects, non-enrolled students and unknown IDs rejected with reasons; empty file handled
  - transcript: 74.7 → B+ (3.50); 3 credits → 10.50 points; (10.50 + 12.00) ÷ 6 = CGPA 3.75; Pending excluded from GPA
  - subjects: duplicate code rejected; archive/restore; archived hidden
  - deleting a lecturer keeps audit history and past sessions, declines upcoming ones
  - mentorship: invalid mentor, past/malformed date, duplicate and clashing requests rejected; approve/reschedule/cancel/complete work
  - hardening: security headers present; internal folders return 403; friendly 500 page; PHP and MySQL times agree
- **Deployment checks:** after each live update, every signed-in page was loaded as each role with no errors in the PHP log, and all existing results were identical before and after the database migration.

## 6. Numbers and material I must supply (never invent these)

- **Warning Accuracy results:** precision, recall, accuracy and projection error from the admin Warning Accuracy page. Note: the live data is small (one real semester); two semesters named "Demo — …" can be generated locally with `tools/seed_demo_history.php` for demonstration and must be described as generated demo data, not real student results. [INSERT WARNING ACCURACY RESULTS]
- **User acceptance testing:** [INSERT UAT RESULTS — who tested, tasks, outcomes]
- **Screenshots:** [INSERT FIGURE …] placeholders where each belongs.
- **Requirements gathering:** [TO CONFIRM: how requirements were gathered — e.g. discussion with supervisor/lecturers]
- **Gantt chart dates:** [TO CONFIRM]
- **Nilai policies:** attendance bar, BE consequence, real PLO wording. [TO CONFIRM]

## 7. Draft report vs final report

- **Mode: DRAFT** — a complete first version for supervisor feedback. Write every chapter fully; leave clearly marked placeholders for anything in section 6; flag weak or uncertain parts with `[CHECK: …]`. Prioritise Chapters 1–4 being solid.
- **Mode: FINAL** — I will paste the supervisor's feedback and the filled-in placeholders. Revise chapter by chapter, apply every comment (list how each was addressed), remove all placeholders I have supplied, check consistency of names, numbers, figure/table numbering and references, and tighten the writing. Also give me a short "changes from draft" summary I can send to my supervisor.

If my template or rubric (attached) defines the draft and final differently, follow it instead.

## 8. Report structure (unless my template says otherwise)

- **Front matter:** title page, declaration of originality, acknowledgement, abstract (150–250 words), table of contents, lists of figures, tables and abbreviations.
- **Chapter 1 — Introduction:** background; problem statement (manual/spreadsheet tracking, at-risk students found only after the final exam, no what-if insight, outcome attainment hard to measure, no audit trail); aim; 4–6 SMART objectives; scope (in/out); significance; report organisation.
- **Chapter 2 — Literature Review:** student performance monitoring and early-warning systems; learning analytics and at-risk prediction (contrast explainable rule-based projection with machine-learning approaches); weighted continuous assessment; Outcome-Based Education, CLO/PLO and CQI (Malaysian context, e.g. MQA); comparison of 3–4 existing systems (e.g. Moodle Gradebook, PowerSchool, Google Classroom, a typical university SIS) in a feature table against SPTS; technology choices versus alternatives; web security (OWASP Top 10); research gap.
- **Chapter 3 — Methodology:** SDLC model and justification (iterative/prototyping); project plan (Gantt as a table placeholder); requirement gathering; tools (hardware, software, hosting); evaluation approach (backtest of the early warning, automated testing, UAT).
- **Chapter 4 — Requirements Analysis and System Design:** functional requirements per role (numbered FR tables) and non-functional requirements; use case diagram and descriptions; context diagram and DFD levels 0–1; **normalization 0NF → 3NF** (from the v4 document) with the ERD and a data dictionary; flowcharts/activity diagrams for mark entry, CSV upload, early warning, password recovery via Contact Admin, and mentorship booking; three-tier architecture (browser → Apache/PHP → MariaDB) including the hosting setup; the scoring, projection and GPA formulas; UI design (colour palette, dark mode, responsiveness, accessibility).
- **Chapter 5 — Implementation:** folder structure; key modules with short code excerpts (only patterns described in the attached files: the weighted total, prepared statements, CSRF check, shared mark-save routine, predictions view, early-warning projection, GPA); database migrations and deployment; screen-by-screen walkthrough with figure placeholders.
- **Chapter 6 — Testing and Evaluation:** strategy (unit/integration/system/security/usability); test-case tables (ID, description, input, expected, actual, status) built from section 5; defects found and fixed (the 25-fault table); the automated test suites; evaluation of the early warning (from my Warning Accuracy numbers, with honest discussion of the small sample); UAT placeholder; evaluation of each objective.
- **Chapter 7 — Conclusion:** achievements; limitations, stated honestly (below); future work; personal reflection.
- **References** ([APA 7]): 20–30 real, verifiable sources (journal articles on early warning / learning analytics / OBE, books, official docs: PHP manual, MariaDB, OWASP, Bootstrap, Chart.js, MQA).
- **Appendices:** user manual per role; deployment checklist (`DEPLOYMENT.md`); database schema; normalization document; Gantt chart; supervisor meeting log [placeholder]; test suite summary.

**Limitations to state:** the projection is rule-based and explainable, not machine learning, and has been evaluated on a small dataset; every subject uses the same four assessment components (only their weights vary); year of study is limited to 1–3; password recovery always needs an admin (no self-service reset link, by design); the Inter font loads from Google Fonts; free hosting with short host backups (about 3 days); BE is a remark only and the attendance bar and PLOs await confirmation of Nilai's policy.

**Future work:** machine-learning prediction once enough historical data exists (compared against the current backtest); configurable assessment components per subject; notifications to mentors/advisers; LMS integration (e.g. Moodle); a mobile app or PWA; parent/guardian view; longer-term automated backups.

## 9. Rules

1. **Accuracy first.** Describe only features, tables, rules and results found in this prompt or the attached files. If something is not there, write `[TO CONFIRM: …]`; never invent it.
2. **No invented data:** no made-up survey results, user numbers, satisfaction percentages, interview quotes, accuracy figures or benchmarks. Use placeholders such as `[INSERT …]`.
3. **No fake references.** Only cite sources you are confident exist, with correct authors, years and titles; otherwise mark `[VERIFY REFERENCE]`. Use in-text citations throughout Chapters 1–3.
4. **Figures:** numbered placeholders like `[Figure 4.3: ERD of the 3NF tables — insert SPTS_ERD_3NF.png]` with a caption; for diagrams (use case, DFD, flowchart, activity, Gantt) also give **Mermaid code** I can render.
5. **Tables:** number every table (Table 4.1, …) with a caption.
6. **Tone and spelling:** formal academic English, British/Malaysian spelling (colour, organisation, enrolment, programme).
7. **Originality:** write in your own words so it passes plagiarism checks; do not copy the attached files verbatim, except code, schema and formulas.
8. **Consistent names:** SPTS, Weighted Scoring Model, What-If Calculator, early warning, Excellent/Pass/At Risk/Fail, Pending, BE, the role names, and the table names exactly as in the normalization document (USERS, STUDENTS, COURSES, SEMESTERS, SUBJECTS, LECTURER_SUBJECTS, ASSESSMENT_WEIGHTS, STUDENT_ENROLLMENTS, PERFORMANCE, MENTORSHIP_SESSIONS, …).

## 10. How to work with me

The report is long, so **never write it all in one reply**:

1. **First reply:** a detailed outline (every chapter and section heading, planned figures and tables, word count per chapter) and your `[TO CONFIRM]` questions. Wait for my approval.
2. Then **one chapter per reply**, in order (front matter and Chapter 1 first). End each with the placeholders I must fill and facts to confirm.
3. After the last chapter: table of contents, lists of figures, tables and abbreviations, and a checklist of every remaining placeholder.
4. In **FINAL** mode, start by listing each supervisor comment and where you will address it, then revise chapter by chapter.
