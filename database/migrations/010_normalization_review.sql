-- ── 010 Normalization review fixes ──────────────────────────────────────
-- Run once, after 009, as MySQL root. Back up first. Safe to run again.
--
-- From the supervisor's review of the 0NF → 3NF write-up:
-- 1. users.password → password_hash: it holds a bcrypt hash, never a password.
-- 2. COURSES: a programme belongs to exactly one academic level
--    (Course → AcademicLevel), so storing both on STUDENTS was a transitive
--    dependency. Students now reference a course; the level lives there.
-- 3. PERFORMANCE: it repeated the enrolment's key (student, subject,
--    semester). Marks now belong to one enrolment by enrollment_id, which is
--    both their primary key and their foreign key (one marks row per
--    enrolment). The predictions views read the student, subject and
--    semester through the enrolment, so they expose the same columns.
-- 4. LECTURER_SUBJECTS: a lecturer teaches a subject in a given semester,
--    not forever. semester_id added; one lecturer per subject per semester,
--    so (subject_id, semester_id) is the primary key. Existing assignments
--    are kept for the active semester and for every semester that has
--    enrolments in that subject.

SET NAMES utf8mb4;

-- ── 1. Password hash ────────────────────────────────────────────────────
ALTER TABLE users CHANGE COLUMN IF EXISTS password password_hash VARCHAR(255) NOT NULL;

-- ── 2. Courses ──────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS courses (
    id             INT NOT NULL AUTO_INCREMENT,
    course_name    VARCHAR(100) NOT NULL,
    academic_level ENUM('Diploma','Degree','Masters','PhD') NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_course_name (course_name),
    KEY idx_course_level (academic_level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The programme catalogue the forms offered (includes/academic_level.php).
INSERT IGNORE INTO courses (course_name, academic_level) VALUES
('Diploma in Information Technology',             'Diploma'),
('Diploma in Computer Science',                   'Diploma'),
('Bachelor of Information Technology (Hons)',     'Degree'),
('Bachelor of Computer Science (Hons)',           'Degree'),
('Bachelor of Software Engineering (Hons)',       'Degree'),
('Master of Information Technology',              'Masters'),
('Master of Computer Science',                    'Masters'),
('Doctor of Philosophy (Computer Science)',       'PhD'),
('Doctor of Philosophy (Information Technology)', 'PhD');

DELIMITER $$
BEGIN NOT ATOMIC
    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND COLUMN_NAME = 'course') THEN
        -- Any programme name in use that isn't in the catalogue keeps its level.
        INSERT IGNORE INTO courses (course_name, academic_level)
            SELECT DISTINCT course, academic_level FROM students;
        ALTER TABLE students ADD COLUMN course_id INT NULL AFTER student_id;
        UPDATE students s JOIN courses c ON c.course_name = s.course SET s.course_id = c.id;
        ALTER TABLE students
            MODIFY course_id INT NOT NULL,
            ADD KEY idx_student_course (course_id),
            ADD CONSTRAINT students_course_fk FOREIGN KEY (course_id) REFERENCES courses (id),
            DROP COLUMN course,
            DROP COLUMN academic_level;
    END IF;
END$$

-- ── 3. Performance keyed by its enrolment ───────────────────────────────
BEGIN NOT ATOMIC
    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'performance' AND COLUMN_NAME = 'student_id') THEN
        ALTER TABLE performance ADD COLUMN IF NOT EXISTS enrollment_id INT NULL FIRST;
        UPDATE performance p
            JOIN student_enrollments e ON e.student_id = p.student_id AND e.subject_id = p.subject_id AND e.semester_id = p.semester_id
            SET p.enrollment_id = e.id;
        ALTER TABLE performance DROP FOREIGN KEY IF EXISTS performance_enrollment_fk;
        ALTER TABLE performance MODIFY id INT NOT NULL;
        ALTER TABLE performance
            DROP PRIMARY KEY,
            DROP INDEX uniq_performance,
            DROP INDEX subject_id,
            DROP INDEX performance_ibfk_3,
            DROP COLUMN id,
            DROP COLUMN student_id,
            DROP COLUMN subject_id,
            DROP COLUMN semester_id,
            MODIFY enrollment_id INT NOT NULL,
            ADD PRIMARY KEY (enrollment_id),
            ADD CONSTRAINT performance_enrollment_fk FOREIGN KEY (enrollment_id) REFERENCES student_enrollments (id) ON DELETE CASCADE;
    END IF;
END$$

-- ── 4. Lecturer assignments per semester ────────────────────────────────
BEGIN NOT ATOMIC
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lecturer_subjects' AND COLUMN_NAME = 'semester_id') THEN
        ALTER TABLE lecturer_subjects
            ADD COLUMN semester_id INT NULL AFTER subject_id,
            ADD UNIQUE KEY uniq_subject_semester (subject_id, semester_id);
        ALTER TABLE lecturer_subjects DROP INDEX uniq_subject_lecturer;
        INSERT INTO lecturer_subjects (user_id, subject_id, semester_id)
            SELECT ls.user_id, ls.subject_id, sem.id
            FROM lecturer_subjects ls
            JOIN semesters sem
            WHERE ls.semester_id IS NULL
              AND (sem.is_active = 1 OR EXISTS (SELECT 1 FROM student_enrollments e
                                                WHERE e.subject_id = ls.subject_id AND e.semester_id = sem.id));
        DELETE FROM lecturer_subjects WHERE semester_id IS NULL;
        ALTER TABLE lecturer_subjects
            MODIFY semester_id INT NOT NULL,
            ADD KEY idx_lecturer_semester (semester_id),
            ADD CONSTRAINT lecturer_subjects_semester_fk FOREIGN KEY (semester_id) REFERENCES semesters (id) ON DELETE CASCADE;
    END IF;
END$$

-- One lecturer per subject per semester: (subject_id, semester_id) is the
-- natural key, so it becomes the primary key and the unused surrogate id goes.
BEGIN NOT ATOMIC
    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lecturer_subjects' AND COLUMN_NAME = 'id') THEN
        ALTER TABLE lecturer_subjects MODIFY id INT NOT NULL;
        ALTER TABLE lecturer_subjects
            DROP PRIMARY KEY,
            DROP COLUMN id,
            ADD PRIMARY KEY (subject_id, semester_id);
        ALTER TABLE lecturer_subjects DROP INDEX uniq_subject_semester;
    END IF;
END$$
DELIMITER ;

-- ── 5. Results views read the enrolment's key ───────────────────────────
-- Same columns, formula and bands as migrations 007 and 009.
CREATE OR REPLACE ALGORITHM = MERGE SQL SECURITY INVOKER VIEW predictions AS
SELECT
    e.student_id,
    e.subject_id,
    e.semester_id,
    CAST(ROUND(p.assignment * 0.20 + p.midterm * 0.30 + p.final_exam * 0.40 + p.attendance * 0.10, 2) AS DECIMAL(5,2)) AS total_score,
    CASE
        WHEN ROUND(p.assignment * 0.20 + p.midterm * 0.30 + p.final_exam * 0.40 + p.attendance * 0.10, 2) >= 80 THEN 'Excellent'
        WHEN ROUND(p.assignment * 0.20 + p.midterm * 0.30 + p.final_exam * 0.40 + p.attendance * 0.10, 2) >= 60 THEN 'Pass'
        WHEN ROUND(p.assignment * 0.20 + p.midterm * 0.30 + p.final_exam * 0.40 + p.attendance * 0.10, 2) >= 50 THEN 'At Risk'
        ELSE 'Fail'
    END COLLATE utf8mb4_unicode_ci AS predicted_status
FROM performance p
JOIN student_enrollments e ON e.id = p.enrollment_id
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
JOIN student_enrollments e ON e.id = p.enrollment_id
WHERE p.assignment IS NOT NULL
   OR p.midterm    IS NOT NULL
   OR p.final_exam IS NOT NULL
   OR p.attendance IS NOT NULL;
