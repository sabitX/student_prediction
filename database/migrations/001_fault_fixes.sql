-- ─────────────────────────────────────────────────────────────────────────
-- 001_fault_fixes.sql — schema changes for the 2026-09 fault-fix round.
-- Run ONCE against an existing `student_prediction` database created from
-- the original report SQL. Fresh installs should import database/schema.sql
-- instead (it already contains everything below).
-- ─────────────────────────────────────────────────────────────────────────

-- Make sure at least one semester exists so existing marks can be attached.
INSERT INTO semesters (semester_name, start_date, end_date, is_active)
SELECT 'Default Semester', CURDATE(), CURDATE() + INTERVAL 3 MONTH, 1
WHERE NOT EXISTS (SELECT 1 FROM semesters);

-- ── #12 Marks and results belong to a semester ───────────────────────────
ALTER TABLE performance ADD COLUMN semester_id INT NULL AFTER subject_id;
ALTER TABLE predictions ADD COLUMN semester_id INT NULL AFTER subject_id;

UPDATE performance SET semester_id = (SELECT id FROM semesters ORDER BY is_active DESC, id DESC LIMIT 1) WHERE semester_id IS NULL;
UPDATE predictions SET semester_id = (SELECT id FROM semesters ORDER BY is_active DESC, id DESC LIMIT 1) WHERE semester_id IS NULL;

ALTER TABLE performance
    MODIFY semester_id INT NOT NULL,
    ADD CONSTRAINT performance_ibfk_3 FOREIGN KEY (semester_id) REFERENCES semesters (id);
ALTER TABLE predictions
    MODIFY semester_id INT NOT NULL,
    ADD CONSTRAINT predictions_ibfk_3 FOREIGN KEY (semester_id) REFERENCES semesters (id);

-- ── #4 Partial marks: a component not yet assessed is NULL, not 0 ─────────
ALTER TABLE performance
    MODIFY assignment DECIMAL(5,2) NULL DEFAULT NULL,
    MODIFY midterm    DECIMAL(5,2) NULL DEFAULT NULL,
    MODIFY final_exam DECIMAL(5,2) NULL DEFAULT NULL,
    MODIFY attendance DECIMAL(5,2) NULL DEFAULT NULL;

-- ── #17 One marks row / one result row per student, subject and semester ──
ALTER TABLE performance ADD UNIQUE KEY uniq_performance (student_id, subject_id, semester_id);
ALTER TABLE predictions ADD UNIQUE KEY uniq_prediction (student_id, subject_id, semester_id);

-- One student profile per user account, one lecturer per subject.
ALTER TABLE students ADD UNIQUE KEY uniq_student_user (user_id);
ALTER TABLE lecturer_subjects ADD UNIQUE KEY uniq_subject_lecturer (subject_id);

-- ── #11 Enrolment: carry existing marks over as enrolments ────────────────
INSERT IGNORE INTO student_enrollments (student_id, subject_id, semester_id)
SELECT student_id, subject_id, semester_id FROM performance;

-- ── #10 Deleting a lecturer must not erase the audit trail ───────────────
ALTER TABLE audit_logs DROP FOREIGN KEY audit_logs_ibfk_1;
ALTER TABLE audit_logs
    MODIFY user_id INT NULL,
    ADD COLUMN actor_name VARCHAR(100) NULL AFTER user_id,
    ADD CONSTRAINT audit_logs_ibfk_1 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL;
UPDATE audit_logs a JOIN users u ON u.id = a.user_id SET a.actor_name = u.full_name WHERE a.actor_name IS NULL;

-- ── #10 / #13 Mentorship history survives mentor deletion; students can cancel
ALTER TABLE mentorship_sessions DROP FOREIGN KEY mentorship_sessions_ibfk_2;
ALTER TABLE mentorship_sessions
    MODIFY mentor_id INT NULL,
    ADD COLUMN mentor_name VARCHAR(100) NULL AFTER mentor_id,
    MODIFY status ENUM('Pending','Approved','Completed','Declined','Cancelled') DEFAULT 'Pending',
    ADD CONSTRAINT mentorship_sessions_ibfk_2 FOREIGN KEY (mentor_id) REFERENCES users (id) ON DELETE SET NULL;
UPDATE mentorship_sessions m JOIN users u ON u.id = m.mentor_id SET m.mentor_name = u.full_name WHERE m.mentor_name IS NULL;

-- ── #1 Password reset requests are reviewed by an administrator ──────────
ALTER TABLE password_resets
    ADD COLUMN user_id INT NULL AFTER id,
    ADD COLUMN resolved_by INT NULL AFTER used,
    ADD COLUMN resolved_at DATETIME NULL AFTER resolved_by,
    ADD KEY idx_reset_user (user_id),
    ADD CONSTRAINT password_resets_ibfk_1 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE;

-- login_attempts used to be auto-created by includes/login_throttle.php;
-- it is declared here now because the application DB user has no CREATE rights.
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(191) NOT NULL,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_identifier_time (identifier, attempted_at)
);

-- ── #1 / #3 Temporary passwords must be replaced at next login ───────────
ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER role;
