-- ── 017 Keep past teaching assignments when a lecturer is deleted ───────
-- Run once, after 016, as MySQL root (it changes a foreign key and creates
-- triggers). Back up first. Safe to run again. No existing rows change.
--
-- Until now, deleting a lecturer's account deleted their lecturer_subjects
-- rows for every semester (ON DELETE CASCADE), so the record of who taught
-- which subject in earlier semesters was lost. Now:
-- 1. user_id becomes NULL-able and is SET NULL when the account goes.
-- 2. former_lecturer_name keeps the name, written by a trigger just before
--    the account is deleted. It is empty while the account exists (the name
--    then comes from users.full_name), so nothing is stored twice.
-- 3. New assignments must still name a real lecturer (trigger).

SET NAMES utf8mb4;

ALTER TABLE lecturer_subjects DROP FOREIGN KEY IF EXISTS lecturer_subjects_ibfk_1;
ALTER TABLE lecturer_subjects
    MODIFY user_id int(11) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS former_lecturer_name varchar(100) DEFAULT NULL AFTER user_id;
ALTER TABLE lecturer_subjects ADD CONSTRAINT lecturer_subjects_ibfk_1 FOREIGN KEY IF NOT EXISTS (user_id) REFERENCES users (id) ON DELETE SET NULL;

DROP TRIGGER IF EXISTS lecturer_subjects_role_bi;
DROP TRIGGER IF EXISTS users_keep_teaching_bd;

DELIMITER $$

CREATE TRIGGER lecturer_subjects_role_bi BEFORE INSERT ON lecturer_subjects FOR EACH ROW
BEGIN
    IF NEW.user_id IS NULL OR (SELECT role FROM users WHERE id = NEW.user_id) <> 'lecturer' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Subjects can only be assigned to lecturers';
    END IF;
END$$

-- Foreign-key actions don't fire triggers, so the name is saved here,
-- before the SET NULL clears user_id.
CREATE TRIGGER users_keep_teaching_bd BEFORE DELETE ON users FOR EACH ROW
BEGIN
    UPDATE lecturer_subjects SET former_lecturer_name = OLD.full_name WHERE user_id = OLD.id;
END$$

DELIMITER ;
