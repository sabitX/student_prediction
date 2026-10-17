-- ── 009 Credit hours, learning outcomes (CLO/PLO) and mid-semester early warning ──
-- Run once, after 008, as MySQL root. Back up first. Safe to run again.
--
-- 1. subjects.credit_hours: the transcript used a hard-coded map
--    (EC2320 = 4, everything else 3); credits are now data an admin edits.
-- 2. Outcome-Based Education. Each subject has CLO1–CLO4 (Course Learning
--    Outcomes), each tagged with a Bloom's level and one PLO (Programme
--    Learning Outcome). Each of the four existing mark components is mapped
--    to one CLO of its subject (clo_assessments), so attainment comes from
--    the marks lecturers already enter — no extra mark columns. A CLO
--    assessed by several components scores their weighted average.
-- 3. cqi_actions: a lecturer's Continuous Quality Improvement note when a
--    CLO misses its cohort target (root cause + remedial plan).
-- 4. VIEW early_risk_predictions: results from whatever marks exist so far,
--    so a student can be warned before the final exam is marked. The
--    predictions view (006/007) still needs all four marks and is unchanged.

SET NAMES utf8mb4;

-- ── 1. Credit hours ─────────────────────────────────────────────────────
ALTER TABLE subjects
    ADD COLUMN IF NOT EXISTS credit_hours DECIMAL(3,1) NOT NULL DEFAULT 3.0 AFTER subject_name;
ALTER TABLE subjects DROP CONSTRAINT IF EXISTS chk_subject_credits;
ALTER TABLE subjects ADD CONSTRAINT chk_subject_credits CHECK (credit_hours BETWEEN 1.0 AND 6.0);

-- The value the transcript used to hard-code.
UPDATE subjects SET credit_hours = 4.0 WHERE subject_code = 'EC2320';

-- ── 2. Programme Learning Outcomes ──────────────────────────────────────
-- target_threshold: score a student needs on a CLO to attain it.
-- cohort_kpi_pct:   share of the class that must attain it; below this the
--                   CLO is flagged "CQI action required".
CREATE TABLE IF NOT EXISTS plos (
    id               INT NOT NULL AUTO_INCREMENT,
    plo_code         VARCHAR(10)  NOT NULL,
    plo_title        VARCHAR(100) NOT NULL,
    description      VARCHAR(255) NOT NULL,
    target_threshold DECIMAL(5,2) NOT NULL DEFAULT 50.00,
    cohort_kpi_pct   DECIMAL(5,2) NOT NULL DEFAULT 80.00,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_plo_code (plo_code),
    CONSTRAINT chk_plo_pcts CHECK (target_threshold BETWEEN 0 AND 100 AND cohort_kpi_pct BETWEEN 0 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PLO4 is measured by attendance, so its target matches the 80%
-- attendance needed to sit the final exam.
INSERT IGNORE INTO plos (plo_code, plo_title, description, target_threshold, cohort_kpi_pct) VALUES
('PLO1', 'Knowledge & Understanding',    'Demonstrate knowledge and understanding of computing fundamentals and theory.',          50.00, 80.00),
('PLO2', 'Problem Analysis',             'Analyse, formulate and solve computing problems using analytical reasoning.',            50.00, 80.00),
('PLO3', 'Practical & Technical Skills', 'Design, implement and evaluate computing solutions with modern tools.',                    50.00, 80.00),
('PLO4', 'Professionalism & Engagement', 'Show professional responsibility, commitment and consistent attendance.',                 80.00, 80.00);

-- ── 3. Course Learning Outcomes ─────────────────────────────────────────
-- UNIQUE (id, subject_id) lets clo_assessments check, with a foreign key,
-- that a component is mapped to a CLO of the same subject.
CREATE TABLE IF NOT EXISTS subject_clos (
    id              INT NOT NULL AUTO_INCREMENT,
    subject_id      INT NOT NULL,
    clo_code        ENUM('CLO1','CLO2','CLO3','CLO4') NOT NULL,
    clo_description VARCHAR(255) NOT NULL,
    bloom_level     ENUM('C1','C2','C3','C4','C5','C6','P1','P2','P3','P4','P5','P6','P7','A1','A2','A3','A4','A5') NOT NULL DEFAULT 'C3',
    plo_id          INT NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_subject_clo (subject_id, clo_code),
    UNIQUE KEY uniq_clo_subject (id, subject_id),
    KEY idx_clo_plo (plo_id),
    CONSTRAINT subject_clos_subject_fk FOREIGN KEY (subject_id) REFERENCES subjects (id) ON DELETE CASCADE,
    CONSTRAINT subject_clos_plo_fk     FOREIGN KEY (plo_id)     REFERENCES plos (id)     ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which CLO each mark component assesses: one CLO per component per
-- subject (the primary key); a CLO may be assessed by several components.
CREATE TABLE IF NOT EXISTS clo_assessments (
    subject_id INT NOT NULL,
    component  ENUM('assignment','midterm','final_exam','attendance') NOT NULL,
    clo_id     INT NOT NULL,
    PRIMARY KEY (subject_id, component),
    KEY idx_clo_assessment_clo (clo_id, subject_id),
    CONSTRAINT clo_assessments_clo_fk FOREIGN KEY (clo_id, subject_id) REFERENCES subject_clos (id, subject_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default outcomes for every existing subject (new subjects get the same
-- from includes/obe.php). Admins reword them per subject.
INSERT IGNORE INTO subject_clos (subject_id, clo_code, clo_description, bloom_level, plo_id)
SELECT s.id, d.clo_code, d.clo_description, d.bloom_level, p.id
FROM subjects s
CROSS JOIN (
    SELECT 'CLO1' AS clo_code, 'Explain the fundamental concepts and theory of the subject.' AS clo_description, 'C2' AS bloom_level, 'PLO1' AS plo_code
    UNION ALL SELECT 'CLO2', 'Apply the subject''s techniques to solve practical problems.',        'C3', 'PLO3'
    UNION ALL SELECT 'CLO3', 'Analyse problems and justify suitable solutions.',                      'C4', 'PLO2'
    UNION ALL SELECT 'CLO4', 'Demonstrate professional commitment through consistent attendance.',    'A2', 'PLO4'
) d
JOIN plos p ON p.plo_code = d.plo_code COLLATE utf8mb4_unicode_ci;

-- midterm → CLO1, assignment → CLO2, final exam → CLO3, attendance → CLO4
INSERT IGNORE INTO clo_assessments (subject_id, component, clo_id)
SELECT c.subject_id, d.component, c.id
FROM subject_clos c
JOIN (
    SELECT 'CLO1' AS clo_code, 'midterm' AS component
    UNION ALL SELECT 'CLO2', 'assignment'
    UNION ALL SELECT 'CLO3', 'final_exam'
    UNION ALL SELECT 'CLO4', 'attendance'
) d ON d.clo_code = c.clo_code COLLATE utf8mb4_unicode_ci;

-- ── 4. CQI actions ──────────────────────────────────────────────────────
-- One action per CLO per semester (logging again updates it). The subject
-- comes from the CLO, so it isn't stored again. attainment_pct is the
-- cohort attainment when the action was logged — a record of what the
-- lecturer responded to, which later mark changes don't rewrite.
CREATE TABLE IF NOT EXISTS cqi_actions (
    id             INT NOT NULL AUTO_INCREMENT,
    clo_id         INT NOT NULL,
    semester_id    INT NOT NULL,
    attainment_pct DECIMAL(5,2) NOT NULL,
    root_cause     VARCHAR(500) NOT NULL,
    remedial_plan  VARCHAR(500) NOT NULL,
    logged_by      INT DEFAULT NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT current_timestamp(),
    updated_at     TIMESTAMP NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (id),
    UNIQUE KEY uniq_cqi_clo_semester (clo_id, semester_id),
    KEY cqi_semester_fk (semester_id),
    KEY cqi_user_fk (logged_by),
    CONSTRAINT cqi_clo_fk      FOREIGN KEY (clo_id)      REFERENCES subject_clos (id) ON DELETE CASCADE,
    CONSTRAINT cqi_semester_fk FOREIGN KEY (semester_id) REFERENCES semesters (id),
    CONSTRAINT cqi_user_fk     FOREIGN KEY (logged_by)   REFERENCES users (id)        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 5. Early-warning view ───────────────────────────────────────────────
-- One row per performance row with at least one mark.
--   assessed_weight  share of the course marked so far (0.10 – 1.00)
--   current_score    weighted average of the components marked so far,
--                    rescaled to 0–100. With all four marks it equals
--                    predictions.total_score.
--   current_status   the usual bands applied to current_score
--   min_final_to_pass  Final Exam % needed for a total of 60 (Pass), given
--                    the coursework. Only when Assignment, Midterm and
--                    Attendance are all in and the final isn't; can be
--                    above 100 (cannot pass) — kept, so pages can say so.
--   attendance_below_bar  1 when attendance < 80% (barred from the final
--                    exam — BE). Same threshold as includes/early_warning.php.
CREATE OR REPLACE ALGORITHM = MERGE SQL SECURITY INVOKER VIEW early_risk_predictions AS
SELECT
    p.student_id,
    p.subject_id,
    p.semester_id,
    p.assignment,
    p.midterm,
    p.final_exam,
    p.attendance,
    CAST(  IF(p.assignment IS NULL, 0, 0.20) + IF(p.midterm    IS NULL, 0, 0.30)
         + IF(p.final_exam IS NULL, 0, 0.40) + IF(p.attendance IS NULL, 0, 0.10) AS DECIMAL(3,2)) AS assessed_weight,
    CAST(ROUND(
        (  COALESCE(p.assignment * 0.20, 0) + COALESCE(p.midterm    * 0.30, 0)
         + COALESCE(p.final_exam * 0.40, 0) + COALESCE(p.attendance * 0.10, 0))
      / (  IF(p.assignment IS NULL, 0, 0.20) + IF(p.midterm    IS NULL, 0, 0.30)
         + IF(p.final_exam IS NULL, 0, 0.40) + IF(p.attendance IS NULL, 0, 0.10)), 2) AS DECIMAL(5,2)) AS current_score,
    CASE
        WHEN ROUND((  COALESCE(p.assignment * 0.20, 0) + COALESCE(p.midterm    * 0.30, 0)
                    + COALESCE(p.final_exam * 0.40, 0) + COALESCE(p.attendance * 0.10, 0))
                 / (  IF(p.assignment IS NULL, 0, 0.20) + IF(p.midterm    IS NULL, 0, 0.30)
                    + IF(p.final_exam IS NULL, 0, 0.40) + IF(p.attendance IS NULL, 0, 0.10)), 2) >= 80 THEN 'Excellent'
        WHEN ROUND((  COALESCE(p.assignment * 0.20, 0) + COALESCE(p.midterm    * 0.30, 0)
                    + COALESCE(p.final_exam * 0.40, 0) + COALESCE(p.attendance * 0.10, 0))
                 / (  IF(p.assignment IS NULL, 0, 0.20) + IF(p.midterm    IS NULL, 0, 0.30)
                    + IF(p.final_exam IS NULL, 0, 0.40) + IF(p.attendance IS NULL, 0, 0.10)), 2) >= 60 THEN 'Pass'
        WHEN ROUND((  COALESCE(p.assignment * 0.20, 0) + COALESCE(p.midterm    * 0.30, 0)
                    + COALESCE(p.final_exam * 0.40, 0) + COALESCE(p.attendance * 0.10, 0))
                 / (  IF(p.assignment IS NULL, 0, 0.20) + IF(p.midterm    IS NULL, 0, 0.30)
                    + IF(p.final_exam IS NULL, 0, 0.40) + IF(p.attendance IS NULL, 0, 0.10)), 2) >= 50 THEN 'At Risk'
        ELSE 'Fail'
    END COLLATE utf8mb4_unicode_ci AS current_status,
    CAST(IF(p.final_exam IS NULL AND p.assignment IS NOT NULL AND p.midterm IS NOT NULL AND p.attendance IS NOT NULL,
            GREATEST(0, ROUND((60 - (p.assignment * 0.20 + p.midterm * 0.30 + p.attendance * 0.10)) / 0.40, 2)),
            NULL) AS DECIMAL(6,2)) AS min_final_to_pass,
    IF(p.attendance IS NOT NULL AND p.attendance < 80, 1, 0) AS attendance_below_bar
FROM performance p
WHERE p.assignment IS NOT NULL
   OR p.midterm    IS NOT NULL
   OR p.final_exam IS NOT NULL
   OR p.attendance IS NOT NULL;
