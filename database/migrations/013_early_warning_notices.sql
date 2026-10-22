-- ── 013 Early-warning emails ────────────────────────────────────────────
-- Run once, after 012, as MySQL root. Back up first. Safe to run again.
--
-- A lecturer can email a student who is flagged by the mid-semester early
-- warning (lecturer dashboard). One row per enrolment records the latest
-- email: when, at what risk level and by whom, so the lecturer can see who
-- has been told and "email all" never emails the same student twice for
-- the same level.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS early_warning_notices (
    enrollment_id INT NOT NULL,
    risk_level    ENUM('warning','critical') NOT NULL,
    sent_at       DATETIME NOT NULL,
    sent_by       INT DEFAULT NULL,
    PRIMARY KEY (enrollment_id),
    KEY idx_ew_notices_sent_by (sent_by),
    CONSTRAINT ew_notices_enrollment_fk FOREIGN KEY (enrollment_id) REFERENCES student_enrollments (id) ON DELETE CASCADE,
    CONSTRAINT ew_notices_sender_fk     FOREIGN KEY (sent_by)       REFERENCES users (id)               ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
