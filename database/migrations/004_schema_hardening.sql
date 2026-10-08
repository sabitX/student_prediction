-- ── 004 Schema hardening ────────────────────────────────────────────────
-- Run once, after 001–003, as a MySQL user that can ALTER tables and
-- CREATE TRIGGER (e.g. root) — not the app's least-privilege user.
-- Back up the database first. Safe to run again if it stops partway
-- (indexes use IF [NOT] EXISTS; foreign keys are dropped if present, then added).
--
-- What it does:
--   1. Repairs any data that would break the new rules (no data is lost).
--   2. Converts every table to utf8mb4 (full Unicode, incl. emoji).
--   3. Makes flag/status columns NOT NULL.
--   4. Marks and results must belong to an enrolment (composite FK).
--   5. Deleting a semester is blocked while anything uses it.
--   6. Adds the missing password_resets.resolved_by foreign key.
--   7. Allows only one active semester.
--   8. Adds indexes for the queries the pages actually run.
--   9. Triggers: subjects can only be assigned to lecturers, and mentors
--      must be a lecturer or a flagged peer mentor (not the mentee).

SET NAMES utf8mb4;

-- ── 1. Repair data first so every new constraint can be added ──────────

-- Marks/results without an enrolment: create the missing enrolment
-- (keeps the marks) rather than deleting anything.
INSERT IGNORE INTO student_enrollments (student_id, subject_id, semester_id)
    SELECT p.student_id, p.subject_id, p.semester_id FROM performance p
    LEFT JOIN student_enrollments e
           ON e.student_id = p.student_id AND e.subject_id = p.subject_id AND e.semester_id = p.semester_id
    WHERE e.id IS NULL;
INSERT IGNORE INTO student_enrollments (student_id, subject_id, semester_id)
    SELECT p.student_id, p.subject_id, p.semester_id FROM predictions p
    LEFT JOIN student_enrollments e
           ON e.student_id = p.student_id AND e.subject_id = p.subject_id AND e.semester_id = p.semester_id
    WHERE e.id IS NULL;

-- Reset requests: link by email where user_id is missing; requests that
-- match no account can never be actioned (the admin page joins users).
UPDATE password_resets pr JOIN users u ON u.email = pr.email SET pr.user_id = u.id WHERE pr.user_id IS NULL;
DELETE FROM password_resets WHERE user_id IS NULL;
UPDATE password_resets pr LEFT JOIN users u ON u.id = pr.resolved_by
    SET pr.resolved_by = NULL WHERE pr.resolved_by IS NOT NULL AND u.id IS NULL;

-- At most one active semester: keep the most recent one.
UPDATE semesters SET is_active = 0
    WHERE is_active = 1 AND id <> (SELECT id FROM (SELECT id FROM semesters WHERE is_active = 1 ORDER BY start_date DESC, id DESC LIMIT 1) AS keep_one);

-- NULLs in columns that become NOT NULL.
UPDATE users               SET role = 'student'    WHERE role IS NULL;
UPDATE mentorship_sessions SET status = 'Pending'  WHERE status IS NULL;
UPDATE semesters           SET is_active = 0       WHERE is_active IS NULL;
UPDATE subjects            SET is_archived = 0     WHERE is_archived IS NULL;
UPDATE students            SET is_mentor = 0       WHERE is_mentor IS NULL;
UPDATE password_resets     SET used = 0            WHERE used IS NULL;

-- Results: total must exist; status recalculated where it isn't a known value
-- (same bands as includes/grading.php).
DELETE FROM predictions WHERE total_score IS NULL;
UPDATE predictions SET predicted_status = CASE
        WHEN total_score >= 80 THEN 'Excellent'
        WHEN total_score >= 60 THEN 'Pass'
        WHEN total_score >= 50 THEN 'At Risk'
        ELSE 'Fail' END
    WHERE predicted_status IS NULL OR predicted_status NOT IN ('Excellent', 'Pass', 'At Risk', 'Fail');

-- ── 2. Full Unicode (utf8 in MariaDB is 3-byte only) ───────────────────
ALTER DATABASE CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE account_requests    CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE audit_logs          CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE lecturer_subjects   CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE login_attempts      CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE mentorship_sessions CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE password_resets     CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE performance         CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE predictions         CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE semesters           CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE student_enrollments CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE students            CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE subjects            CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE users               CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ── 3. Flags and statuses can't be NULL ─────────────────────────────────
ALTER TABLE users               MODIFY role ENUM('admin','student','lecturer') NOT NULL DEFAULT 'student';
ALTER TABLE mentorship_sessions MODIFY status ENUM('Pending','Approved','Completed','Declined','Cancelled') NOT NULL DEFAULT 'Pending';
ALTER TABLE semesters           MODIFY is_active TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE subjects            MODIFY is_archived TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE students            MODIFY is_mentor TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE predictions
    MODIFY total_score DECIMAL(5,2) NOT NULL,
    MODIFY predicted_status ENUM('Excellent','Pass','At Risk','Fail') NOT NULL;

-- ── 4 + 5. Marks belong to an enrolment; semesters can't vanish under data
-- ON DELETE CASCADE from the enrolment matches the existing cascades from
-- students/subjects (so deleting a student still works in any order);
-- admin/manage_enrollments.php already refuses to unenrol a student who
-- has marks, so marks are never removed by unenrolling.
ALTER TABLE performance DROP FOREIGN KEY IF EXISTS performance_enrollment_fk;
ALTER TABLE performance
    ADD CONSTRAINT performance_enrollment_fk FOREIGN KEY (student_id, subject_id, semester_id)
        REFERENCES student_enrollments (student_id, subject_id, semester_id) ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE predictions DROP FOREIGN KEY IF EXISTS predictions_enrollment_fk;
ALTER TABLE predictions
    ADD CONSTRAINT predictions_enrollment_fk FOREIGN KEY (student_id, subject_id, semester_id)
        REFERENCES student_enrollments (student_id, subject_id, semester_id) ON DELETE CASCADE ON UPDATE CASCADE;

-- Was ON DELETE CASCADE (silently dropped enrolments) while performance /
-- predictions blocked the delete; now all three block it consistently.
-- The semester index gets a fixed name (MariaDB names auto-created ones
-- differently depending on the install's history).
ALTER TABLE student_enrollments DROP FOREIGN KEY IF EXISTS student_enrollments_ibfk_3;
ALTER TABLE student_enrollments ADD KEY IF NOT EXISTS idx_enrol_semester (semester_id);
ALTER TABLE student_enrollments DROP KEY IF EXISTS semester_id, DROP KEY IF EXISTS student_enrollments_ibfk_3;
ALTER TABLE student_enrollments
    ADD CONSTRAINT student_enrollments_ibfk_3 FOREIGN KEY (semester_id) REFERENCES semesters (id);

-- ── 6. password_resets ──────────────────────────────────────────────────
-- email is kept as a snapshot of the address the request was made with.
-- MariaDB can't change a column while a foreign key uses it, so the
-- user_id key is dropped and re-added around the change.
ALTER TABLE password_resets DROP FOREIGN KEY IF EXISTS password_resets_ibfk_1, DROP FOREIGN KEY IF EXISTS password_resets_admin_fk;
ALTER TABLE password_resets
    MODIFY user_id INT(11) NOT NULL,
    ADD CONSTRAINT password_resets_ibfk_1 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    MODIFY used TINYINT(1) NOT NULL DEFAULT 0,
    ADD KEY IF NOT EXISTS idx_reset_open (used, expires_at),
    ADD KEY IF NOT EXISTS password_resets_admin_fk (resolved_by),
    ADD CONSTRAINT password_resets_admin_fk FOREIGN KEY (resolved_by) REFERENCES users (id) ON DELETE SET NULL;

-- ── 7. Only one active semester ─────────────────────────────────────────
-- active_flag is 1 for the active semester and NULL otherwise; a UNIQUE
-- index allows any number of NULLs but only one 1.
ALTER TABLE semesters
    ADD COLUMN IF NOT EXISTS active_flag TINYINT(1) AS (IF(is_active = 1, 1, NULL)) PERSISTENT,
    ADD UNIQUE KEY IF NOT EXISTS uniq_one_active (active_flag);

-- ── 8. Indexes matching the real queries ────────────────────────────────
-- New composite index first, then drop the single-column one it replaces
-- (each foreign key always keeps an index starting with its column).
-- MariaDB may already have removed an old index by itself if it had
-- created it automatically for the foreign key, hence IF EXISTS.
ALTER TABLE audit_logs          ADD KEY IF NOT EXISTS idx_audit_user_time (user_id, created_at), ADD KEY IF NOT EXISTS idx_audit_time (created_at);
ALTER TABLE audit_logs          DROP KEY IF EXISTS audit_logs_ibfk_1;
ALTER TABLE student_enrollments ADD KEY IF NOT EXISTS idx_enrol_subject_sem (subject_id, semester_id);
ALTER TABLE student_enrollments DROP KEY IF EXISTS subject_id;
ALTER TABLE mentorship_sessions ADD KEY IF NOT EXISTS idx_mentor_date (mentor_id, session_date);
ALTER TABLE mentorship_sessions DROP KEY IF EXISTS mentorship_sessions_ibfk_2;
ALTER TABLE login_attempts      ADD KEY IF NOT EXISTS idx_attempted_at (attempted_at);

-- ── 9. Role rules a foreign key can't express ───────────────────────────
DROP TRIGGER IF EXISTS lecturer_subjects_role_bi;
DROP TRIGGER IF EXISTS lecturer_subjects_role_bu;
DROP TRIGGER IF EXISTS mentorship_mentor_bi;
DROP TRIGGER IF EXISTS mentorship_mentor_bu;

DELIMITER $$

CREATE TRIGGER lecturer_subjects_role_bi BEFORE INSERT ON lecturer_subjects FOR EACH ROW
BEGIN
    IF (SELECT role FROM users WHERE id = NEW.user_id) <> 'lecturer' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Subjects can only be assigned to lecturers';
    END IF;
END$$

CREATE TRIGGER lecturer_subjects_role_bu BEFORE UPDATE ON lecturer_subjects FOR EACH ROW
BEGIN
    IF NEW.user_id <> OLD.user_id AND (SELECT role FROM users WHERE id = NEW.user_id) <> 'lecturer' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Subjects can only be assigned to lecturers';
    END IF;
END$$

-- Checked only when a mentor is set or changed, so status updates on old
-- sessions still work after a peer mentor is un-flagged.
CREATE TRIGGER mentorship_mentor_bi BEFORE INSERT ON mentorship_sessions FOR EACH ROW
BEGIN
    IF NEW.mentor_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM users u LEFT JOIN students s ON s.user_id = u.id
        WHERE u.id = NEW.mentor_id
          AND (u.role = 'lecturer' OR (u.role = 'student' AND s.is_mentor = 1 AND s.id <> NEW.mentee_id))
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Mentor must be a lecturer or a peer mentor other than the mentee';
    END IF;
END$$

CREATE TRIGGER mentorship_mentor_bu BEFORE UPDATE ON mentorship_sessions FOR EACH ROW
BEGIN
    IF NEW.mentor_id IS NOT NULL AND NOT (NEW.mentor_id <=> OLD.mentor_id) AND NOT EXISTS (
        SELECT 1 FROM users u LEFT JOIN students s ON s.user_id = u.id
        WHERE u.id = NEW.mentor_id
          AND (u.role = 'lecturer' OR (u.role = 'student' AND s.is_mentor = 1 AND s.id <> NEW.mentee_id))
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Mentor must be a lecturer or a peer mentor other than the mentee';
    END IF;
END$$

DELIMITER ;
