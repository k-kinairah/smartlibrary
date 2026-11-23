-- SmartLib Faculty/Staff department list update (2026-11)
--
-- The Faculty/Staff list becomes, in this order:
--   College of Management and Accountancy
--   College of Education
--   College of Allied Health Sciences
--   College of Criminal Justice Education
--   Student Operations Group
-- Registrar, GSD, OCOO, Finance, Marketing, CSDL and ITS are replaced by the
-- single "Student Operations Group".
--
-- Nothing is deleted. The seven old rows are only RETIRED (is_active = 0):
-- they drop out of the sign-up dropdown and the Manage Users "Add User"
-- form, but people already registered under one keep it (library_users,
-- library_entry_logs and auth_signup_verifications are not touched), the
-- Manage Users edit form still shows it for them, and reports, exports,
-- receipts and the door scanner keep showing the old name.
--
-- Fresh install: run faculty_departments_setup.sql first, then this file;
-- the result is the same as on a database that already had the old list.
-- faculty_departments_setup.sql itself is unchanged.
--
-- PRODUCTION ORDER: export the whole database first, run THIS file in
-- phpMyAdmin (with the SmartLib database selected), and only THEN upload the
-- code. The new code reads departments.is_active (it falls back to listing
-- every department while the column is missing, so uploading the code first
-- would show the old list rather than break).
--
-- Safe to run more than once: the second run retires 0 and adds 0.

ALTER TABLE departments
  ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER sort_order;

-- After every existing row (the old list ends at 110), so it is last.
INSERT INTO departments (department_name, sort_order, is_active)
SELECT 'Student Operations Group', 120, 1
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM departments WHERE department_name = 'Student Operations Group'
);

SELECT ROW_COUNT() AS departments_added;

UPDATE departments
SET is_active = 0
WHERE is_active = 1
  AND department_name IN (
    'Registrar',
    'GSD',
    'OCOO',
    'Finance',
    'Marketing',
    'CSDL',
    'ITS'
  );

SELECT ROW_COUNT() AS departments_retired;

-- The list the sign-up form and Manage Users "Add User" now show.
SELECT department_id, department_name, sort_order
FROM departments
WHERE is_active = 1
ORDER BY sort_order, department_name;
