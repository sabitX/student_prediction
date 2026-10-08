-- ── Account requests from new lecturers ──────────────────────────────────
-- Filed from auth/request_account.php and reviewed by an administrator in
-- admin/account_requests.php. Approving creates a lecturer account with a
-- temporary password, which is emailed to personal_email.
CREATE TABLE IF NOT EXISTS account_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL,            -- university email; becomes the sign-in email
    personal_email VARCHAR(100) NOT NULL,   -- where the sign-in details are sent
    staff_id VARCHAR(30) NULL,
    department VARCHAR(100) NOT NULL,
    message VARCHAR(500) NULL,
    status ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
    reject_reason VARCHAR(255) NULL,
    user_id INT NULL,                       -- the account created on approval
    resolved_by INT NULL,
    resolved_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_account_requests_status (status),
    KEY idx_account_requests_email (email),
    CONSTRAINT account_requests_user_fk  FOREIGN KEY (user_id)     REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT account_requests_admin_fk FOREIGN KEY (resolved_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
