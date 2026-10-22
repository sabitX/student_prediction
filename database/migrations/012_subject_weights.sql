-- ── 012 Assessment weights per subject per semester ────────────────────
-- Run once, after 011, as MySQL root. Back up first. Safe to run again.
--
-- Until now every subject used the same weights (assignment 20, midterm
-- 30, final exam 40, attendance 10). Real modules differ, so each subject
-- now has its own weights for each semester it runs. Keying them by
-- semester means a subject can change its scheme in a new semester without
-- changing results already published for earlier ones.
--
-- A (subject, semester) with no row uses the old 20/30/40/10, so every
-- existing result is unchanged. Activating a new semester copies each
-- subject's weights from the previous semester (admin/manage_semesters.php).
-- The four components stay the same for every subject; each must carry
-- 5–80% and together they must make 100%.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS subject_weights (
    subject_id        INT NOT NULL,
    semester_id       INT NOT NULL,
    weight_assignment DECIMAL(5,2) NOT NULL,
    weight_midterm    DECIMAL(5,2) NOT NULL,
    weight_final_exam DECIMAL(5,2) NOT NULL,
    weight_attendance DECIMAL(5,2) NOT NULL,
    updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (subject_id, semester_id),
    KEY idx_subject_weights_semester (semester_id),
    CONSTRAINT subject_weights_subject_fk  FOREIGN KEY (subject_id)  REFERENCES subjects (id)  ON DELETE CASCADE,
    CONSTRAINT subject_weights_semester_fk FOREIGN KEY (semester_id) REFERENCES semesters (id) ON DELETE CASCADE,
    CONSTRAINT chk_weight_range CHECK (weight_assignment BETWEEN 5 AND 80 AND weight_midterm BETWEEN 5 AND 80
                                   AND weight_final_exam BETWEEN 5 AND 80 AND weight_attendance BETWEEN 5 AND 80),
    CONSTRAINT chk_weight_total CHECK (weight_assignment + weight_midterm + weight_final_exam + weight_attendance = 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every subject in the active semester gets an explicit row with the
-- weights it has always used, so admins see (and can change) them.
INSERT IGNORE INTO subject_weights (subject_id, semester_id, weight_assignment, weight_midterm, weight_final_exam, weight_attendance)
SELECT s.id, sem.id, 20, 30, 40, 10 FROM subjects s CROSS JOIN semesters sem WHERE sem.is_active = 1;

-- ── Results use each subject's weights for that semester ───────────────
-- Same columns as before; early_risk_predictions also exposes the weights
-- it used (w_*, as fractions) for the early-warning code.

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
LEFT JOIN subject_weights w ON w.subject_id = e.subject_id AND w.semester_id = e.semester_id
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
LEFT JOIN subject_weights w ON w.subject_id = e.subject_id AND w.semester_id = e.semester_id
WHERE p.assignment IS NOT NULL
   OR p.midterm    IS NOT NULL
   OR p.final_exam IS NOT NULL
   OR p.attendance IS NOT NULL;
