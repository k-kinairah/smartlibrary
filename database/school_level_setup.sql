-- SmartLib school level at sign-up (2026-11)
--
-- Junior High (JHS) and Senior High (SHS) students sign up on the same form
-- as college students and keep role 'student' (same loan rules, renewals,
-- fines and kiosk). They choose only JHS or SHS: no grade, no program, no
-- year level. This adds a nullable school_level after department_id:
--   'college' | 'jhs' | 'shs'   (NULL for faculty, librarian and admin)
-- on library_users (indexed), auth_signup_verifications and
-- library_entry_logs.
--
-- Existing students are set to 'college' below. Entry logs and pending
-- signups are NOT backfilled: in the code a student with NULL school_level
-- means College (legacy rows, and signups started before the deploy).
--
-- PRODUCTION ORDER: export the whole database first, run THIS file in
-- phpMyAdmin (with the SmartLib database selected), and only THEN upload the
-- code. The new code reads these columns, so uploading the code first would
-- break library-card sign-up, Manage Users and the kiosk.
--
-- Safe to run more than once (same style as faculty_departments_setup.sql);
-- the second run backfills 0 students.

-- Plain VARCHAR, no foreign key or ENUM (department_id has none either); the
-- code only stores the three values above.
ALTER TABLE library_users
  ADD COLUMN IF NOT EXISTS school_level VARCHAR(10) NULL AFTER department_id,
  ADD KEY IF NOT EXISTS idx_library_users_school_level (school_level);

ALTER TABLE auth_signup_verifications
  ADD COLUMN IF NOT EXISTS school_level VARCHAR(10) NULL AFTER department_id;

ALTER TABLE library_entry_logs
  ADD COLUMN IF NOT EXISTS school_level VARCHAR(10) NULL AFTER department_id;

-- Every existing student is a college student. Faculty, librarian and admin
-- stay NULL.
UPDATE library_users
SET school_level = 'college'
WHERE role = 'student'
  AND school_level IS NULL;

SELECT ROW_COUNT() AS students_backfilled;
