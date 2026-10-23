-- ── 014 LECTURER_SUBJECTS: (user_id, subject_id, semester_id) unique ────
-- Run once, after 013, as MySQL root. Back up first. Safe to run again.
--
-- From the supervisor's second review: mark (UserID, SubjectID, SemesterID)
-- as unique. The primary key stays (subject_id, semester_id) — one lecturer
-- per subject per semester — so the three together were already unique;
-- this states it explicitly. The new index starts with user_id, so it also
-- serves the user_id foreign key and "which subjects does this lecturer
-- teach" lookups, and the old single-column user_id index is dropped.

SET NAMES utf8mb4;

ALTER TABLE lecturer_subjects ADD UNIQUE KEY IF NOT EXISTS uniq_lecturer_subject_semester (user_id, subject_id, semester_id);
ALTER TABLE lecturer_subjects DROP INDEX IF EXISTS user_id;
