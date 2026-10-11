-- SPTS — full database schema (structure only).
-- Fresh install: create the database `student_prediction`
-- (CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci), import this file as a
-- user that can CREATE TRIGGER (e.g. root), then follow README
-- "First-time setup". Existing installs: run database/migrations/001–008
-- in order instead.
--
-- Generated from a database with all migrations applied, so a fresh
-- install and an upgraded one end up with the same structure.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE `account_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `role` enum('lecturer','student') NOT NULL DEFAULT 'lecturer',
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `personal_email` varchar(100) NOT NULL,
  `staff_id` varchar(30) DEFAULT NULL,
  `student_id` varchar(20) DEFAULT NULL,
  `course` varchar(100) DEFAULT NULL,
  `year_of_study` tinyint(4) DEFAULT NULL,
  `department` varchar(100) DEFAULT NULL,
  `message` varchar(500) DEFAULT NULL,
  `status` enum('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
  `reject_reason` varchar(255) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `resolved_by` int(11) DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `pending_email` varchar(100) GENERATED ALWAYS AS (if(`status` = 'Pending',`email`,NULL)) STORED,
  `pending_student_id` varchar(20) GENERATED ALWAYS AS (if(`status` = 'Pending',`student_id`,NULL)) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_pending_email` (`pending_email`),
  UNIQUE KEY `uniq_pending_student_id` (`pending_student_id`),
  KEY `idx_account_requests_status` (`status`),
  KEY `idx_account_requests_email` (`email`),
  KEY `account_requests_user_fk` (`user_id`),
  KEY `account_requests_admin_fk` (`resolved_by`),
  CONSTRAINT `account_requests_admin_fk` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `account_requests_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_account_request_role` CHECK (`role` = 'lecturer' and `department` is not null or `role` = 'student' and `student_id` is not null and `course` is not null and `year_of_study` between 1 and 3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `actor_name` varchar(100) DEFAULT NULL,
  `action` varchar(255) NOT NULL,
  `details` mediumtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_audit_user_time` (`user_id`,`created_at`),
  KEY `idx_audit_time` (`created_at`),
  CONSTRAINT `audit_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `lecturer_subjects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_subject_lecturer` (`subject_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `lecturer_subjects_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `lecturer_subjects_ibfk_2` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `login_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `identifier` varchar(191) NOT NULL,
  `attempted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_identifier_time` (`identifier`,`attempted_at`),
  KEY `idx_attempted_at` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `mentorship_sessions` (
  `session_id` int(11) NOT NULL AUTO_INCREMENT,
  `mentee_id` int(11) NOT NULL,
  `mentor_id` int(11) DEFAULT NULL,
  `mentor_name` varchar(100) DEFAULT NULL,
  `subject_id` int(11) NOT NULL,
  `session_date` datetime NOT NULL,
  `status` enum('Pending','Approved','Completed','Declined','Cancelled') NOT NULL DEFAULT 'Pending',
  `meeting_notes` mediumtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`session_id`),
  KEY `mentee_id` (`mentee_id`),
  KEY `subject_id` (`subject_id`),
  KEY `idx_mentor_date` (`mentor_id`,`session_date`),
  CONSTRAINT `mentorship_sessions_ibfk_1` FOREIGN KEY (`mentee_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mentorship_sessions_ibfk_2` FOREIGN KEY (`mentor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mentorship_sessions_ibfk_3` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `email` varchar(100) NOT NULL,
  `token` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used` tinyint(1) NOT NULL DEFAULT 0,
  `resolved_by` int(11) DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_reset_user` (`user_id`),
  KEY `idx_reset_open` (`used`,`expires_at`),
  KEY `password_resets_admin_fk` (`resolved_by`),
  CONSTRAINT `password_resets_admin_fk` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `password_resets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `performance` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `semester_id` int(11) NOT NULL,
  `assignment` decimal(5,2) DEFAULT NULL,
  `midterm` decimal(5,2) DEFAULT NULL,
  `final_exam` decimal(5,2) DEFAULT NULL,
  `attendance` decimal(5,2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_performance` (`student_id`,`subject_id`,`semester_id`),
  KEY `subject_id` (`subject_id`),
  KEY `performance_ibfk_3` (`semester_id`),
  CONSTRAINT `performance_enrollment_fk` FOREIGN KEY (`student_id`, `subject_id`, `semester_id`) REFERENCES `student_enrollments` (`student_id`, `subject_id`, `semester_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_performance_marks` CHECK ((`assignment` is null or `assignment` between 0 and 100) and (`midterm` is null or `midterm` between 0 and 100) and (`final_exam` is null or `final_exam` between 0 and 100) and (`attendance` is null or `attendance` between 0 and 100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `semesters` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `semester_name` varchar(50) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `active_flag` tinyint(1) GENERATED ALWAYS AS (if(`is_active` = 1,1,NULL)) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_one_active` (`active_flag`),
  CONSTRAINT `chk_semesters_dates` CHECK (`end_date` > `start_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `student_enrollments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `semester_id` int(11) NOT NULL,
  `enrolled_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_enrollment` (`student_id`,`subject_id`,`semester_id`),
  KEY `idx_enrol_subject_sem` (`subject_id`,`semester_id`),
  KEY `idx_enrol_semester` (`semester_id`),
  CONSTRAINT `student_enrollments_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `student_enrollments_ibfk_2` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `student_enrollments_ibfk_3` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `students` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `student_id` varchar(20) NOT NULL,
  `course` varchar(100) NOT NULL,
  `academic_level` enum('Diploma','Degree','Masters','PhD') NOT NULL DEFAULT 'Diploma',
  `year_of_study` int(11) NOT NULL,
  `is_mentor` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `student_id` (`student_id`),
  UNIQUE KEY `uniq_student_user` (`user_id`),
  CONSTRAINT `students_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_students_year` CHECK (`year_of_study` between 1 and 3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `subjects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subject_code` varchar(20) NOT NULL,
  `subject_name` varchar(100) NOT NULL,
  `is_archived` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `subject_code` (`subject_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','student','lecturer') NOT NULL DEFAULT 'student',
  `must_change_password` tinyint(1) NOT NULL DEFAULT 0,
  `onboarding_done` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ── Results: calculated from performance when read (see migration 006) ──
CREATE ALGORITHM = MERGE SQL SECURITY INVOKER VIEW predictions AS
SELECT
    p.student_id,
    p.subject_id,
    p.semester_id,
    CAST(ROUND(p.assignment * 0.20 + p.midterm * 0.30 + p.final_exam * 0.40 + p.attendance * 0.10, 2) AS DECIMAL(5,2)) AS total_score,
    CASE
        WHEN ROUND(p.assignment * 0.20 + p.midterm * 0.30 + p.final_exam * 0.40 + p.attendance * 0.10, 2) >= 80 THEN 'Excellent'
        WHEN ROUND(p.assignment * 0.20 + p.midterm * 0.30 + p.final_exam * 0.40 + p.attendance * 0.10, 2) >= 60 THEN 'Pass'
        WHEN ROUND(p.assignment * 0.20 + p.midterm * 0.30 + p.final_exam * 0.40 + p.attendance * 0.10, 2) >= 50 THEN 'At Risk'
        ELSE 'Fail'
    END COLLATE utf8mb4_unicode_ci AS predicted_status
FROM performance p
WHERE p.assignment IS NOT NULL
  AND p.midterm    IS NOT NULL
  AND p.final_exam IS NOT NULL
  AND p.attendance IS NOT NULL;

-- ── Rules a foreign key can't express (see migrations 004 and 005) ─────
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
