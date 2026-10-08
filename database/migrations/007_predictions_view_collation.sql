-- ── 007 Fixed text collation for the predictions view ──────────────────
-- Run once, after 006, as MySQL root. Safe to run again.
--
-- The view's predicted_status column took its collation from whichever
-- connection created it (e.g. utf8mb4_general_ci from XAMPP). On a server
-- with a different default (MariaDB 11.8: utf8mb4_uca1400_ai_ci), queries
-- like  predicted_status = 'Excellent'  then failed with "Illegal mix of
-- collations". The column is now always utf8mb4_unicode_ci, like the tables.
-- Same formula and bands as migration 006 and includes/grading.php.

SET NAMES utf8mb4;

CREATE OR REPLACE ALGORITHM = MERGE SQL SECURITY INVOKER VIEW predictions AS
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
