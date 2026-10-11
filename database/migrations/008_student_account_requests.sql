-- ── 008 Account requests from new students ──────────────────────────────
-- Run once, after 007, as MySQL root. Back up first. Safe to run again.
--
-- Self-registration is off (anyone could claim any Student ID), so newly
-- admitted students ask for an account instead: auth/request_student_account.php
-- files a request, an administrator checks it in admin/account_requests.php,
-- and approving creates the student account (user + student profile) and
-- emails a temporary password. The same table already holds lecturer
-- requests; `role` tells them apart.

SET NAMES utf8mb4;

ALTER TABLE account_requests
    ADD COLUMN IF NOT EXISTS role ENUM('lecturer','student') NOT NULL DEFAULT 'lecturer' AFTER id,
    ADD COLUMN IF NOT EXISTS student_id VARCHAR(20) NULL AFTER staff_id,
    ADD COLUMN IF NOT EXISTS course VARCHAR(100) NULL AFTER student_id,
    ADD COLUMN IF NOT EXISTS year_of_study TINYINT NULL AFTER course,
    -- Students have a programme instead of a department.
    MODIFY department VARCHAR(100) NULL,
    -- One pending request per Student ID (like uniq_pending_email).
    ADD COLUMN IF NOT EXISTS pending_student_id VARCHAR(20) AS (IF(status = 'Pending', student_id, NULL)) PERSISTENT,
    ADD UNIQUE KEY IF NOT EXISTS uniq_pending_student_id (pending_student_id);

-- Each kind of request carries its own required details.
ALTER TABLE account_requests DROP CONSTRAINT IF EXISTS chk_account_request_role;
ALTER TABLE account_requests ADD CONSTRAINT chk_account_request_role CHECK (
    (role = 'lecturer' AND department IS NOT NULL)
 OR (role = 'student' AND student_id IS NOT NULL AND course IS NOT NULL AND year_of_study BETWEEN 1 AND 3)
);
