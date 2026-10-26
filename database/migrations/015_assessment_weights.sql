-- ── 015 Second-review fixes: ASSESSMENT_WEIGHTS, LECTURER_SUBJECTS ─────
-- Run once, after 014, as MySQL root. Back up first. Safe to run again.
--
-- From the supervisor's third review:
-- 1. The per-subject, per-semester weights table is named for what it
--    holds: subject_weights → assessment_weights (with its index and
--    foreign keys renamed to match). The two result views read it, so they
--    are recreated.
-- 2. LECTURER_SUBJECTS: the primary key (subject_id, semester_id) already
--    makes (user_id, subject_id, semester_id) unique, so the extra UNIQUE
--    key from migration 014 is dropped and the plain user_id index (needed
--    by its foreign key) comes back.

SET NAMES utf8mb4;

-- ── 1. assessment_weights ──────────────────────────────────────────────
DELIMITER $$
BEGIN NOT ATOMIC
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'subject_weights')
       AND NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'assessment_weights') THEN
        RENAME TABLE subject_weights TO assessment_weights;
    END IF;
END$$
DELIMITER ;

ALTER TABLE assessment_weights DROP FOREIGN KEY IF EXISTS subject_weights_subject_fk;
ALTER TABLE assessment_weights DROP FOREIGN KEY IF EXISTS subject_weights_semester_fk;
ALTER TABLE assessment_weights DROP INDEX IF EXISTS idx_subject_weights_semester;
ALTER TABLE assessment_weights ADD KEY IF NOT EXISTS idx_assessment_weights_semester (semester_id);
ALTER TABLE assessment_weights ADD CONSTRAINT assessment_weights_subject_fk FOREIGN KEY IF NOT EXISTS (subject_id) REFERENCES subjects (id) ON DELETE CASCADE;
ALTER TABLE assessment_weights ADD CONSTRAINT assessment_weights_semester_fk FOREIGN KEY IF NOT EXISTS (semester_id) REFERENCES semesters (id) ON DELETE CASCADE;

-- ── Results use the renamed table (same columns as before) ─────────────
CREATE OR REPLACE ALGORITHM = MERGE SQL SECURITY INVOKER VIEW predictions AS
SELECT
    e.student_id,
    e.subject_id,
    e.semester_id,
    CAST(ROUND(p.assignment * (COALESCE(w.weight_assignment, 20) / 100) + p.midterm * (COALESCE(w.weight_midterm, 30) / 100) + p.final_exam * (COALESCE(w.weight_final_exam, 40) / 100) + p.attendance * (COALESCE(w.weight_attendance, 10) / 100), 2) AS DECIMAL(5,2)) AS total_score,
    CASE
        WHEN ROUND(p.assignment * (COALESCE(w.weight_assignment, 20) / 100) + p.midterm * (COALESCE(w.weight_midterm, 30) / 100) + p.final_exam * (COALESCE(w.weight_final_exam, 40) / 100) + p.attendance * (COALESCE(w.weight_attendance, 10) / 100), 2) >= 80 THEN 'Excellent'
        WHEN ROUND(p.assignment * (COALESCE(w.weight_assignment, 20) / 100) + p.midterm * (COALESCE(w.weight_midterm, 30) / 100) + p.final_exam * (COALESCE(w.weight_final_exam, 40) / 100) + p.attendance * (COALESCE(w.weight_attendance, 10) / 100), 2) >= 60 THEN 'Pass'
        WHEN ROUND(p.assignment * (COALESCE(w.weight_assignment, 20) / 100) + p.midterm * (COALESCE(w.weight_midterm, 30) / 100) + p.final_exam * (COALESCE(w.weight_final_exam, 40) / 100) + p.attendance * (COALESCE(w.weight_attendance, 10) / 100), 2) >= 50 THEN 'At Risk'
        ELSE 'Fail'
    END COLLATE utf8mb4_unicode_ci AS predicted_status
FROM performance p
JOIN student_enrollments e ON e.id = p.enrollment_id
LEFT JOIN assessment_weights w ON w.subject_id = e.subject_id AND w.semester_id = e.semester_id
WHERE p.assignment IS NOT NULL
  AND p.midterm    IS NOT NULL
  AND p.final_exam IS NOT NULL
  AND p.attendance IS NOT NULL;

CREATE OR REPLACE ALGORITHM = MERGE SQL SECURITY INVOKER VIEW early_risk_predictions AS
SELECT
    e.student_id,
    e.subject_id,
    e.semester_id,
    p.assignment,
    p.midterm,
    p.final_exam,
    p.attendance,
    CAST(COALESCE(w.weight_assignment, 20) / 100 AS DECIMAL(5,4)) AS w_assignment,
    CAST(COALESCE(w.weight_midterm, 30) / 100 AS DECIMAL(5,4)) AS w_midterm,
    CAST(COALESCE(w.weight_final_exam, 40) / 100 AS DECIMAL(5,4)) AS w_final_exam,
    CAST(COALESCE(w.weight_attendance, 10) / 100 AS DECIMAL(5,4)) AS w_attendance,
    CAST(  IF(p.assignment IS NULL, 0, (COALESCE(w.weight_assignment, 20) / 100))
         + IF(p.midterm IS NULL, 0, (COALESCE(w.weight_midterm, 30) / 100))
         + IF(p.final_exam IS NULL, 0, (COALESCE(w.weight_final_exam, 40) / 100))
         + IF(p.attendance IS NULL, 0, (COALESCE(w.weight_attendance, 10) / 100)) AS DECIMAL(5,4)) AS assessed_weight,
    CAST(ROUND(
        (  COALESCE(p.assignment * (COALESCE(w.weight_assignment, 20) / 100), 0)
         + COALESCE(p.midterm * (COALESCE(w.weight_midterm, 30) / 100), 0)
         + COALESCE(p.final_exam * (COALESCE(w.weight_final_exam, 40) / 100), 0)
         + COALESCE(p.attendance * (COALESCE(w.weight_attendance, 10) / 100), 0))
      / (  IF(p.assignment IS NULL, 0, (COALESCE(w.weight_assignment, 20) / 100))
         + IF(p.midterm IS NULL, 0, (COALESCE(w.weight_midterm, 30) / 100))
         + IF(p.final_exam IS NULL, 0, (COALESCE(w.weight_final_exam, 40) / 100))
         + IF(p.attendance IS NULL, 0, (COALESCE(w.weight_attendance, 10) / 100))), 2) AS DECIMAL(5,2)) AS current_score,
    CASE
        WHEN ROUND(((  COALESCE(p.assignment * (COALESCE(w.weight_assignment, 20) / 100), 0)
         + COALESCE(p.midterm * (COALESCE(w.weight_midterm, 30) / 100), 0)
         + COALESCE(p.final_exam * (COALESCE(w.weight_final_exam, 40) / 100), 0)
         + COALESCE(p.attendance * (COALESCE(w.weight_attendance, 10) / 100), 0))
      / (  IF(p.assignment IS NULL, 0, (COALESCE(w.weight_assignment, 20) / 100))
         + IF(p.midterm IS NULL, 0, (COALESCE(w.weight_midterm, 30) / 100))
         + IF(p.final_exam IS NULL, 0, (COALESCE(w.weight_final_exam, 40) / 100))
         + IF(p.attendance IS NULL, 0, (COALESCE(w.weight_attendance, 10) / 100)))), 2) >= 80 THEN 'Excellent'
        WHEN ROUND(((  COALESCE(p.assignment * (COALESCE(w.weight_assignment, 20) / 100), 0)
         + COALESCE(p.midterm * (COALESCE(w.weight_midterm, 30) / 100), 0)
         + COALESCE(p.final_exam * (COALESCE(w.weight_final_exam, 40) / 100), 0)
         + COALESCE(p.attendance * (COALESCE(w.weight_attendance, 10) / 100), 0))
      / (  IF(p.assignment IS NULL, 0, (COALESCE(w.weight_assignment, 20) / 100))
         + IF(p.midterm IS NULL, 0, (COALESCE(w.weight_midterm, 30) / 100))
         + IF(p.final_exam IS NULL, 0, (COALESCE(w.weight_final_exam, 40) / 100))
         + IF(p.attendance IS NULL, 0, (COALESCE(w.weight_attendance, 10) / 100)))), 2) >= 60 THEN 'Pass'
        WHEN ROUND(((  COALESCE(p.assignment * (COALESCE(w.weight_assignment, 20) / 100), 0)
         + COALESCE(p.midterm * (COALESCE(w.weight_midterm, 30) / 100), 0)
         + COALESCE(p.final_exam * (COALESCE(w.weight_final_exam, 40) / 100), 0)
         + COALESCE(p.attendance * (COALESCE(w.weight_attendance, 10) / 100), 0))
      / (  IF(p.assignment IS NULL, 0, (COALESCE(w.weight_assignment, 20) / 100))
         + IF(p.midterm IS NULL, 0, (COALESCE(w.weight_midterm, 30) / 100))
         + IF(p.final_exam IS NULL, 0, (COALESCE(w.weight_final_exam, 40) / 100))
         + IF(p.attendance IS NULL, 0, (COALESCE(w.weight_attendance, 10) / 100)))), 2) >= 50 THEN 'At Risk'
        ELSE 'Fail'
    END COLLATE utf8mb4_unicode_ci AS current_status,
    CAST(IF(p.final_exam IS NULL AND p.assignment IS NOT NULL AND p.midterm IS NOT NULL AND p.attendance IS NOT NULL,
            GREATEST(0, ROUND((60 - (p.assignment * (COALESCE(w.weight_assignment, 20) / 100) + p.midterm * (COALESCE(w.weight_midterm, 30) / 100) + p.attendance * (COALESCE(w.weight_attendance, 10) / 100))) / (COALESCE(w.weight_final_exam, 40) / 100), 2)),
            NULL) AS DECIMAL(6,2)) AS min_final_to_pass,
    IF(p.attendance IS NOT NULL AND p.attendance < 80, 1, 0) AS attendance_below_bar
FROM performance p
JOIN student_enrollments e ON e.id = p.enrollment_id
LEFT JOIN assessment_weights w ON w.subject_id = e.subject_id AND w.semester_id = e.semester_id
WHERE p.assignment IS NOT NULL
   OR p.midterm    IS NOT NULL
   OR p.final_exam IS NOT NULL
   OR p.attendance IS NOT NULL;

-- ── 2. lecturer_subjects: no extra UNIQUE ──────────────────────────────
ALTER TABLE lecturer_subjects ADD KEY IF NOT EXISTS user_id (user_id);
ALTER TABLE lecturer_subjects DROP INDEX IF EXISTS uniq_lecturer_subject_semester;
