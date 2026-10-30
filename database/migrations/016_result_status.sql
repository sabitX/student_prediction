-- 016 Final-result terminology and assessment-weight total constraint.
-- Run after 015, as a database owner with CREATE VIEW and ALTER privileges.
-- Safe to run again. No marks or saved weights are changed.
-- predictions keeps its historical view name; its final classification is
-- now result_status, while projections remain in early_risk_predictions.

SET NAMES utf8mb4;

-- Enforce the same exact 100% total as the PHP validator and weights form.
ALTER TABLE assessment_weights
    DROP CONSTRAINT IF EXISTS chk_weight_total,
    ADD CONSTRAINT chk_weight_total CHECK (
        weight_assignment + weight_midterm + weight_final_exam + weight_attendance = 100
    );

-- Recreate the view to rename predicted_status to result_status.
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
    END COLLATE utf8mb4_unicode_ci AS result_status
FROM performance p
JOIN student_enrollments e ON e.id = p.enrollment_id
LEFT JOIN assessment_weights w ON w.subject_id = e.subject_id AND w.semester_id = e.semester_id
WHERE p.assignment IS NOT NULL
  AND p.midterm    IS NOT NULL
  AND p.final_exam IS NOT NULL
  AND p.attendance IS NOT NULL;
