-- ── 011 Contact Admin support requests ──────────────────────────────────
-- Run once, after 010, as MySQL root. Back up first. Safe to run again.
--
-- Students and lecturers can contact the administrator: from the sign-in
-- pages when they can't sign in (forgot password, cannot log in), and from
-- inside the system for anything else. Each request gets a reference number
-- and moves Open → In Progress → Resolved.
--
-- This replaces PASSWORD_RESETS. A forgotten password is now a support
-- request; the recovery itself is unchanged (an admin verifies the person
-- and issues a one-time temporary password that must be changed at the
-- next sign-in). Existing reset requests are copied across, then the old
-- table is dropped.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS support_requests (
    id            INT NOT NULL AUTO_INCREMENT,
    reference_no  CHAR(11) NOT NULL,                  -- e.g. SR-7KQ2M9XD (random, not a count)
    role          ENUM('student','lecturer') NOT NULL,
    full_name     VARCHAR(100) NOT NULL,              -- as typed by the requester
    id_number     VARCHAR(30) DEFAULT NULL,           -- Student ID / Staff ID as typed
    email         VARCHAR(100) NOT NULL,              -- registered email as typed
    category      ENUM('forgot_password','cannot_login','other') NOT NULL,
    message       VARCHAR(1000) DEFAULT NULL,
    user_id       INT DEFAULT NULL,                   -- set only when sent while signed in
    status        ENUM('Open','In Progress','Resolved') NOT NULL DEFAULT 'Open',
    admin_note    VARCHAR(500) DEFAULT NULL,
    handled_by    INT DEFAULT NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME DEFAULT NULL,
    resolved_at   DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_support_reference (reference_no),
    KEY idx_support_status (status, created_at),
    KEY idx_support_user (user_id),
    KEY idx_support_email (email),
    KEY idx_support_handled_by (handled_by),
    CONSTRAINT support_requests_user_fk  FOREIGN KEY (user_id)    REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT support_requests_admin_fk FOREIGN KEY (handled_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Copy old password-reset requests across, then drop the old table.
DELIMITER $$
BEGIN NOT ATOMIC
    IF EXISTS (SELECT 1 FROM information_schema.tables
               WHERE table_schema = DATABASE() AND table_name = 'password_resets') THEN
        INSERT INTO support_requests (reference_no, role, full_name, id_number, email, category, message,
                                      status, admin_note, handled_by, created_at, updated_at, resolved_at)
        SELECT CONCAT('SR-', UPPER(SUBSTRING(SHA2(CONCAT('password_resets:', pr.id, ':', pr.token), 256), 1, 8))),
               IF(u.role = 'student', 'student', 'lecturer'), u.full_name, s.student_id, pr.email, 'forgot_password',
               'Sent from the old Forgot Password page.',
               IF(pr.used = 0 AND pr.expires_at > NOW(), 'Open', 'Resolved'),
               CASE WHEN pr.used = 1 THEN 'Handled on the old Password Requests page.'
                    WHEN pr.expires_at <= NOW() THEN 'Expired on the old Password Requests page.' END,
               pr.resolved_by, pr.created_at, pr.resolved_at,
               CASE WHEN pr.used = 1 THEN pr.resolved_at WHEN pr.expires_at <= NOW() THEN pr.expires_at END
        FROM password_resets pr
        JOIN users u ON u.id = pr.user_id
        LEFT JOIN students s ON s.user_id = u.id
        WHERE u.role IN ('student', 'lecturer');

        DROP TABLE password_resets;
    END IF;
END$$
DELIMITER ;
