-- ── First-login welcome guide for lecturers and students ─────────────────
-- includes/onboarding.php shows the guide once, then sets this flag.
-- Existing accounts have already used the system, so they are marked done;
-- only accounts created after this migration see the guide.
ALTER TABLE users ADD COLUMN onboarding_done TINYINT(1) NOT NULL DEFAULT 0 AFTER must_change_password;
UPDATE users SET onboarding_done = 1;
