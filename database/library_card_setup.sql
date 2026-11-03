-- SmartLib digital library card + door entry logging (Phase 1 proposal)
-- STATUS: applied to the local database (2026-10-23).
-- Safe to run more than once on MariaDB 10.4+ (IF NOT EXISTS everywhere).

-- ---------------------------------------------------------------------------
-- 1. library_users: verification + card state
--
-- The school ID number already lives in library_users.user_number (8-digit
-- student IDs, 6-digit faculty IDs) and the PHINMA address in
-- library_users.email, so neither gets a new column. The barcode encodes
-- user_number directly and is rendered on demand, so it isn't stored either:
-- a stored copy would go stale whenever an admin edits user_number in
-- admin/manage_users.php.
-- ---------------------------------------------------------------------------
ALTER TABLE library_users
    ADD COLUMN IF NOT EXISTS email_verified_at DATETIME NULL AFTER email,
    ADD COLUMN IF NOT EXISTS card_issued_at DATETIME NULL AFTER email_verified_at,
    ADD COLUMN IF NOT EXISTS registration_source ENUM('admin','self_signup') NOT NULL DEFAULT 'admin' AFTER card_issued_at;

-- The door scanner looks accounts up by user_number, and signup must reject
-- an ID or email that's already registered. Both are currently only checked
-- in PHP; no duplicates exist today, so these apply cleanly. NULLs (e.g. an
-- account with no email) are still allowed more than once.
ALTER TABLE library_users
    ADD UNIQUE KEY IF NOT EXISTS uniq_library_users_number (user_number),
    ADD UNIQUE KEY IF NOT EXISTS uniq_library_users_email (email);

-- ---------------------------------------------------------------------------
-- 2. Pending self-signups (mirrors auth_2fa_challenges)
--
-- A library_users row is only created after the emailed code is confirmed,
-- so unverified signups never appear in the users table or reach kiosk login.
-- user_id is filled in once the account has been created.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS auth_signup_verifications (
    verification_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT NULL,
    user_number VARCHAR(50) NOT NULL,
    account_type VARCHAR(20) NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    program_id INT NULL,
    email_to VARCHAR(190) NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    requested_ip VARCHAR(64) NOT NULL,
    attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (verification_id),
    KEY idx_auth_signup_email_time (email_to, created_at),
    KEY idx_auth_signup_number_state (user_number, used_at, expires_at),
    KEY idx_auth_signup_ip_time (requested_ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The same table also holds "reprint my card" codes for existing card
-- holders (purpose = 'reprint', user_id set from the start).
ALTER TABLE auth_signup_verifications
    ADD COLUMN IF NOT EXISTS purpose ENUM('signup','reprint') NOT NULL DEFAULT 'signup' AFTER verification_id;

-- ---------------------------------------------------------------------------
-- 3. Door entry scans (entry events only; no exit tracking yet)
--
-- user_number and account_type are snapshotted so the report still reads
-- correctly if the account is later edited or deleted.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS library_entry_logs (
    entry_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT NULL,
    user_number VARCHAR(50) NOT NULL,
    account_type VARCHAR(20) NOT NULL,
    scanned_by_user_id INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (entry_id),
    KEY idx_entry_logs_created_at (created_at),
    KEY idx_entry_logs_user_created (user_id, created_at),
    KEY idx_entry_logs_type_created (account_type, created_at),
    CONSTRAINT fk_entry_logs_user
        FOREIGN KEY (user_id) REFERENCES library_users(user_id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 4. Report demographics: course, year level, gender (added 2026-10-23)
--
-- Course reuses the existing library_users.program_id / programs table.
-- year_level is students only (1-5, where 5 = 5th year or above); faculty
-- keep it NULL. gender NULL means "never asked" (e.g. librarian-created
-- accounts); 'prefer_not_to_say' is an explicit answer.
-- ---------------------------------------------------------------------------
ALTER TABLE library_users
    ADD COLUMN IF NOT EXISTS year_level TINYINT UNSIGNED NULL AFTER program_id,
    ADD COLUMN IF NOT EXISTS gender ENUM('female','male','prefer_not_to_say') NULL AFTER year_level;

-- Pending signups carry the answers until the account is created.
ALTER TABLE auth_signup_verifications
    ADD COLUMN IF NOT EXISTS year_level TINYINT UNSIGNED NULL AFTER program_id,
    ADD COLUMN IF NOT EXISTS gender ENUM('female','male','prefer_not_to_say') NULL AFTER year_level;

-- Snapshotted at scan time like user_number/account_type, so a student
-- moving up a year (or changing program) doesn't rewrite past reports.
ALTER TABLE library_entry_logs
    ADD COLUMN IF NOT EXISTS program_id INT NULL AFTER account_type,
    ADD COLUMN IF NOT EXISTS year_level TINYINT UNSIGNED NULL AFTER program_id,
    ADD COLUMN IF NOT EXISTS gender ENUM('female','male','prefer_not_to_say') NULL AFTER year_level;

-- ---------------------------------------------------------------------------
-- 5. Self-chosen kiosk PIN at signup (added 2026-11-04)
--
-- The signer picks their kiosk PIN on the signup form. Only its
-- password_hash() is staged here -- never the plain PIN -- and it's copied
-- into library_users.password when the account is created. NULL for reprint
-- rows and for signups staged before this change.
-- ---------------------------------------------------------------------------
ALTER TABLE auth_signup_verifications
    ADD COLUMN IF NOT EXISTS pin_hash VARCHAR(255) NULL AFTER code_hash;
