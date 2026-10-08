-- ── 006 Results become a view (strict 3NF) ──────────────────────────────
-- Run once, after 005, as MySQL root. Back up first. Safe to run again.
--
-- The predictions TABLE stored total_score and predicted_status, which are
-- both calculated from the four marks in performance (and the status from
-- the total) — a transitive dependency, so the table broke 3NF. It is
-- replaced by a VIEW with the same name and columns, calculated when read,
-- so results can never disagree with the marks.
--
-- Same formula and bands as includes/grading.php:
--   total = Assignment×20% + Midterm×30% + Final Exam×40% + Attendance×10%,
--           rounded to 2 decimals
--   >= 80 Excellent | >= 60 Pass | >= 50 At Risk | < 50 Fail
-- A result exists only when all four marks are entered (as before).
--
-- SQL SECURITY INVOKER: the app's own SELECT rights on performance apply,
-- so the view works whichever MySQL account created it.

SET NAMES utf8mb4;

DROP TABLE IF EXISTS predictions;
DROP VIEW IF EXISTS predictions;

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
