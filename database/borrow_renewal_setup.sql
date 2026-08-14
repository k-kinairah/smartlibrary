-- SmartLib borrower renewals
-- Safe to run more than once on MySQL/MariaDB versions that support IF NOT EXISTS.

ALTER TABLE borrow_records
  ADD COLUMN IF NOT EXISTS renew_count INT NOT NULL DEFAULT 0 AFTER status,
  ADD COLUMN IF NOT EXISTS last_renewed_at DATETIME NULL AFTER renew_count;