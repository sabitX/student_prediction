-- ── 005 Integrity checks ────────────────────────────────────────────────
-- Run once, after 004, as MySQL root (creates a trigger). Back up first.
-- Safe to run again if it stops partway.
--
--   1. CHECK constraints for values the app already validates, so bad
--      data can't get in by any other route (marks 0–100, year 1–3,
--      semester ends after it starts).
--   2. At most one pending account request per university email.
--   3. A user's role can't be changed away from lecturer/student while
--      rows still depend on it (assigned subjects / student profile).
--   4. Removes the single-column foreign keys on performance/predictions
--      that the enrolment composite key (004) made redundant.

SET NAMES utf8mb4;

-- ── 1. Value ranges ─────────────────────────────────────────────────────
ALTER TABLE performance DROP CONSTRAINT IF EXISTS chk_performance_marks;
ALTER TABLE performance ADD CONSTRAINT chk_performance_marks CHECK (
        (assignment IS NULL OR assignment BETWEEN 0 AND 100)
    AND (midterm    IS NULL OR midterm    BETWEEN 0 AND 100)
    AND (final_exam IS NULL OR final_exam BETWEEN 0 AND 100)
    AND (attendance IS NULL OR attendance BETWEEN 0 AND 100));

ALTER TABLE predictions DROP CONSTRAINT IF EXISTS chk_predictions_total;
ALTER TABLE predictions ADD CONSTRAINT chk_predictions_total CHECK (total_score BETWEEN 0 AND 100);

ALTER TABLE students DROP CONSTRAINT IF EXISTS chk_students_year;
ALTER TABLE students ADD CONSTRAINT chk_students_year CHECK (year_of_study BETWEEN 1 AND 3);

ALTER TABLE semesters DROP CONSTRAINT IF EXISTS chk_semesters_dates;
ALTER TABLE semesters ADD CONSTRAINT chk_semesters_dates CHECK (end_date > start_date);

-- ── 2. One pending request per email ────────────────────────────────────
-- pending_email is the email while the request is Pending, NULL after it
-- is reviewed; UNIQUE allows many NULLs, so old requests don't conflict.
-- auth/request_account.php turns a duplicate into a friendly message.
ALTER TABLE account_requests
    ADD COLUMN IF NOT EXISTS pending_email VARCHAR(100) AS (IF(status = 'Pending', email, NULL)) PERSISTENT,
    ADD UNIQUE KEY IF NOT EXISTS uniq_pending_email (pending_email);

-- ── 3. Role changes that would orphan dependent rows ────────────────────
DROP TRIGGER IF EXISTS users_role_bu;

DELIMITER $$

CREATE TRIGGER users_role_bu BEFORE UPDATE ON users FOR EACH ROW
BEGIN
    IF OLD.role = 'lecturer' AND NEW.role <> 'lecturer'
       AND EXISTS (SELECT 1 FROM lecturer_subjects WHERE user_id = OLD.id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unassign this lecturer''s subjects before changing their role';
    END IF;
    IF OLD.role = 'student' AND NEW.role <> 'student'
       AND EXISTS (SELECT 1 FROM students WHERE user_id = OLD.id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A student account with a student profile can''t change role';
    END IF;
END$$

DELIMITER ;

-- ── 4. Redundant foreign keys ───────────────────────────────────────────
-- performance/predictions already point at the enrolment, which points at
-- the student, subject and semester (deletes cascade/block through it).
-- The indexes stay: pages filter and count by subject and semester.
ALTER TABLE performance
    DROP FOREIGN KEY IF EXISTS performance_ibfk_1,
    DROP FOREIGN KEY IF EXISTS performance_ibfk_2,
    DROP FOREIGN KEY IF EXISTS performance_ibfk_3;
ALTER TABLE predictions
    DROP FOREIGN KEY IF EXISTS predictions_ibfk_1,
    DROP FOREIGN KEY IF EXISTS predictions_ibfk_2,
    DROP FOREIGN KEY IF EXISTS predictions_ibfk_3;
