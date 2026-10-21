# Prompt: Write my Final Year Project (FYP) report

> **How to use:** Paste everything below the line into a new Claude conversation. Attach these files from the project folder: `SYSTEM_FUNCTIONALITIES.md`, `SYSTEM_WORKFLOW_AND_ERD.md`, `COLOR_PALETTE.md`, `DEPLOYMENT.md`, `README.md` and `database/schema.sql`. Also attach any screenshots of the system and your university's report template or guidelines if you have them. Fill in the `[brackets]` in section 1 first.

---

You are helping me write the **Final Year Project report** for my Diploma computing project. I built the system myself; the attached files document it accurately. Write the report as me, the student, in formal academic English suitable for a Malaysian private university diploma programme.

## 1. Project and student details

- **Project title:** Student Performance Tracking Web System Using a Weighted Scoring Model (SPTS)
- **Student:** Jayed Al Sabit · Student ID 00021960
- **Programme:** Diploma in Information Technology, Nilai University
- **Course:** EC2320 Summative (Computing Project), 2026
- **Department:** Department of Computing & Data Sciences
- **Supervisor:** [SUPERVISOR NAME]
- **Submission date:** [DATE]
- **Required length / format:** [e.g. 8,000–12,000 words, Times New Roman 12, 1.5 spacing — or "follow the attached template"]
- **Referencing style:** [APA 7th edition, unless the template says otherwise]

## 2. What the system is (summary; full detail is in the attached files)

SPTS is a role-based web application. It lets lecturers record student assessment marks, computes a weighted total score for each student, and classifies each student's academic standing, so that at-risk students can be identified and supported early.

- **Weighted Scoring Model:**
  - Total = Assignment × 20% + Midterm × 30% + Final Exam × 40% + Attendance × 10%
  - Standing: ≥ 80 **Excellent**, ≥ 60 **Pass**, ≥ 50 **At Risk**, < 50 **Fail**
- **Marks can be entered progressively.** A blank component means "not assessed yet" (stored as NULL). The standing is computed only when all four components exist; until then it shows *Pending*.
- **Three roles:** Administrator, Lecturer, Student. A student can also be made a **peer mentor**.
- **Administrator features:**
  - analytics dashboard (Chart.js doughnut and bar charts)
  - management of semesters, subjects (with archive/restore), subject enrolments, students and staff
  - password-reset request handling
  - system-wide audit log
- **Lecturer features:**
  - dashboard scoped to their subjects
  - inline mark-entry grid
  - bulk CSV upload (template pre-filled with the enrolled roster, row-level validation report)
  - personal audit trail
  - handling of mentorship requests (approve, reschedule, decline, complete)
- **Student features:**
  - results dashboard
  - **What-If Calculator:** projects the final result from official marks and shows the minimum final-exam score needed to pass
  - mentorship booking with lecturers and qualified peer mentors
  - printable multi-semester transcript: letter grades A+ to F, credit-weighted GPA per semester and cumulative CGPA (business rule BR-30)
- **Technology:**
  - PHP 8 (procedural, mysqli prepared statements), MySQL/MariaDB, Bootstrap 5.3, Bootstrap Icons, Chart.js
  - XAMPP (Apache) for development
  - dark mode and a responsive, mobile-friendly layout
- **Database:** 12 tables. Full attributes, keys, relationships, delete rules and a ready-made Mermaid ERD are in `SYSTEM_WORKFLOW_AND_ERD.md`; the exact DDL is in `schema.sql`.
- **Security:**
  - prepared statements (business rule BR-42)
  - bcrypt password hashing and a password policy (8+ characters with a letter and a number)
  - CSRF tokens on all forms, including logout
  - session hardening: regeneration, HttpOnly/SameSite cookies, 30-minute idle timeout
  - rate limiting on login, reset requests and registration
  - admin-verified password resets with forced change of temporary passwords
  - role-based access control by folder, plus object-level checks (lecturer ↔ subject, enrolment, mentor eligibility)
  - least-privilege database user
  - security headers and a Content Security Policy
  - output escaping (XSS)
  - transactions and unique constraints for data integrity
  - audit trail recording old → new values

## 3. Development history (use this for the Methodology and Testing chapters)

The project followed an **iterative (prototyping/Agile-style) approach**:

1. **Iteration 1 — core build.**
   - Authentication, the three role portals, mark entry grid, CSV upload, the weighted scoring model, dashboards, the What-If Calculator, mentorship and the transcript.
2. **Iteration 2 — structured review and fault fixing.** A systematic review of the whole system found 25 faults, from critical security issues to UI issues. All were fixed and re-tested. The full list with fixes is in section 10 of `SYSTEM_FUNCTIONALITIES.md`. Key examples:
   - The password reset could be abused to take over any account; it was redesigned as an admin-verified request.
   - The CSV upload let a lecturer write marks to any subject, and silently turned blanks and text into 0.
   - Transcript GPA calculations were wrong and used hard-coded values.
   - There were no enrolment or semester structures. These were added, and semester-based CGPA followed from them.
   - Deleting a lecturer erased the audit history. Foreign keys were changed to SET NULL, and the names are kept.
3. **Iteration 3 — production hardening and polish.** This round:
   - moved secrets into an environment file
   - aligned the PHP and MySQL time zones (a 6-hour mismatch was found)
   - added a friendly error page, security headers and a CSP
   - used the Post/Redirect/Get pattern and prevented double submits
   - made the sidebar responsive for mobile
   - added accessibility fixes: labels, keyboard focus, skip link
   - fixed dark-mode contrast problems
   - added a new favicon
   - wrote a deployment checklist

**Real test scenarios that were executed and passed.** Use these in the Testing chapter as actual results; do not invent others as "passed":

- **Login and accounts:**
  - Login redirects to the correct portal for each role.
  - A lecturer opening a student page is redirected to their own dashboard.
  - The 6th failed attempt within 15 minutes is blocked.
  - An idle session ends after 30 minutes with an explanatory message.
  - Contact Admin (Forgot password) gives an identical response, with a reference number, for real and fake accounts; the request appears under Support Requests; the admin can reset only after confirming identity; the temporary password forces a password change before any page can be used.
- **Registration:** rejects an invalid email, a malformed Student ID, a programme from the wrong level, and a weak password. An uppercase Student ID is stored in lowercase and treated as a duplicate.
- **Mark entry grid:**
  - Partial marks save with status Pending.
  - Complete marks compute correctly (e.g. 70/65/80/90 → 74.50, Pass).
  - Re-saving unchanged marks creates no audit entry.
  - Invalid marks ("abc", 190) skip the row.
  - Saving to another lecturer's subject is refused.
  - The audit log stores old → new values.
- **CSV upload:**
  - Handles a UTF-8 BOM, an uppercase `.CSV` extension, extra columns, trailing commas and blank lines.
  - Rejects text marks, other lecturers' subjects, non-enrolled students and unknown IDs, each with a clear reason.
  - An empty file gives a message instead of crashing.
- **Transcript:**
  - 74.7 → B+ (3.50), 3.50 × 3 credits = 10.50 grade points.
  - Across two semesters, (10.50 + 12.00) ÷ 6 credits = CGPA 3.75.
  - Pending subjects are excluded from the GPA.
- **Subjects:** duplicate codes are rejected with a clear message; archive/restore works; archived subjects are hidden from lecturers and students.
- **Deleting a lecturer:** their audit history and past mentorship sessions are kept, and upcoming sessions are declined.
- **Mentorship:**
  - Rejects an invalid mentor, a past date, a malformed date, a duplicate request and a clashing time.
  - Approve, reschedule, cancel and complete all work.
  - Peer mentors appear in search.
- **Hardening:**
  - Security headers are present.
  - `config/`, `includes/`, `database/`, `.md` and `.sql` files return 403.
  - The friendly 500 page is shown and the error is logged.
  - PHP and MySQL time agree.
  - Every page was loaded as every role with no PHP errors in the logs.

## 4. Report structure to produce

Unless my template says otherwise, use this structure:

- **Front matter:** Title page, Declaration of originality, Acknowledgement, Abstract (150–250 words), Table of Contents, List of Figures, List of Tables, List of Abbreviations
- **Chapter 1 — Introduction:**
  - background
  - problem statement (manual or spreadsheet tracking, late identification of at-risk students, no what-if insight, no audit trail)
  - aim and objectives (SMART, 4–6 objectives)
  - scope (in and out of scope)
  - significance
  - report organisation
- **Chapter 2 — Literature Review:**
  - student performance monitoring and early-warning systems
  - weighted/continuous assessment models
  - comparison of 3–4 existing systems (e.g. Moodle Gradebook, PowerSchool, Google Classroom, a typical university SIS) in a feature comparison table against SPTS
  - review of the chosen technologies versus alternatives
  - web security practices (OWASP Top 10)
  - summary of the research gap
- **Chapter 3 — Methodology:**
  - chosen SDLC model and justification
  - project plan (Gantt chart as a table placeholder)
  - requirement-gathering approach
  - hardware and software tools
- **Chapter 4 — Requirements Analysis & System Design:**
  - functional requirements per role (numbered FR tables) and non-functional requirements (security, usability, performance, accessibility, maintainability)
  - use case diagram and use case descriptions for the main use cases
  - context diagram / DFD level 0–1
  - ERD, using the attached Mermaid ERD, and a data dictionary table for each entity
  - activity diagrams or flowcharts for mark entry, CSV upload, password reset and mentorship booking
  - system architecture (three-tier: browser → Apache/PHP → MySQL)
  - UI design principles, including the colour palette from `COLOR_PALETTE.md`, dark mode and responsiveness
- **Chapter 5 — Implementation:**
  - folder structure
  - key modules with short, representative code excerpts. **Only use code patterns described in the attached files; don't fabricate large code blocks.** Good candidates: the scoring formula, the prepared-statement pattern, the CSRF check, the shared mark-save routine, the GPA calculation.
  - screen-by-screen description of the main interfaces, with figure placeholders
- **Chapter 6 — Testing & Evaluation:**
  - test strategy: unit, integration, system, security, usability
  - test case tables (ID, description, input, expected result, actual result, status), built from the **real scenarios in section 3**
  - a "Defects found and fixed" table summarising the 25-fault review
  - User Acceptance Testing section: leave it as a placeholder table for me to fill in, because I have not supplied UAT results
  - evaluation of each objective (achieved / partially achieved)
- **Chapter 7 — Conclusion:**
  - summary of achievements
  - limitations, stated honestly: no email service, so resets need an admin; year of study limited to 1–3; the Inter font loads online; the 404 path is tied to the folder name; tested on a local XAMPP server, not deployed publicly
  - future enhancements: email/OTP password reset, notifications, a mobile app or PWA, analytics/ML prediction, LMS integration, parent/guardian portal
  - personal reflection
- **References** in [APA 7] style: 15–25 real, verifiable sources (books, journal articles, official docs such as the PHP manual, OWASP, Bootstrap docs, MySQL docs).
- **Appendices:** user manual for each role, deployment checklist (from `DEPLOYMENT.md`), database schema (from `schema.sql`), Gantt chart, supervisor meeting logs (placeholder).

## 5. Rules

1. **Accuracy first.** Describe only features, tables, rules and test results found in this prompt or the attached files. If something isn't there, write `[TO CONFIRM: …]` instead of inventing it.
2. **No invented data.** Don't make up survey results, user numbers, percentages of satisfaction, interview quotes or performance benchmarks. Use clearly marked placeholders like `[INSERT UAT RESULTS]`.
3. **No fake references.** Only cite sources you are confident exist, with correct authors, years and titles. If unsure, mark it `[VERIFY REFERENCE]`. Use in-text citations throughout Chapters 1–3.
4. **Figures and diagrams:**
   - Where a screenshot or diagram belongs, insert a numbered placeholder such as `[Figure 4.3: Entity Relationship Diagram — insert image]` with a one-line caption.
   - For diagrams, also give me the **Mermaid code** (use case, DFD, flowchart, activity, Gantt) so I can render them.
5. **Tables:** number every table (Table 4.1, …) and give it a caption.
6. **Tone:** formal, first person plural or passive voice as is normal in academic reports; British/Malaysian English spelling (e.g. "colour", "organisation", "enrolment").
7. **Originality:** write in your own words, so the report passes plagiarism checks; don't copy the attached files verbatim, except code, schema and formulas.
8. **Consistency:** use the exact names from the system (SPTS, Weighted Scoring Model, What-If Calculator, Excellent/Pass/At Risk/Fail, the role names, the table names).

## 6. How to work with me

The report is long, so **don't write it all in one reply**:

1. First, reply with a **detailed outline**: every chapter and section heading, the planned figures and tables, and an estimated word count for each chapter. Also list any `[TO CONFIRM]` questions you have for me. Wait for my approval.
2. Then write **one chapter per reply**, in order, starting with the front matter and Chapter 1. At the end of each chapter, list the placeholders I need to fill and any facts to confirm.
3. After the last chapter, give me: the full Table of Contents, List of Figures, List of Tables, List of Abbreviations, and a final checklist of every placeholder left in the report.
