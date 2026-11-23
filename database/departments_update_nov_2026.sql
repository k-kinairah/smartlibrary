-- SmartLib Faculty/Staff department list update (2026-11)
--
-- The Faculty/Staff list becomes, in this order:
--   College of Management and Accountancy
--   College of Education
--   College of Allied Health Sciences
--   College of Criminal Justice Education
--   College of Arts and Sciences      (new)
--   Student Operations Group          (new, always the very bottom)
-- Registrar, GSD, OCOO, Finance, Marketing, CSDL and ITS are replaced by the
-- single "Student Operations Group".
--
-- Ordering: College of Arts and Sciences gets sort_order 45, right after the
-- other colleges (10-40). Student Operations Group gets the highest
-- sort_order of every OTHER department row (retired ones included) + 1, so
-- it is always last; once it is there a re-run leaves it unchanged.
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
-- Safe to run more than once: the second run adds 0, moves 0 and retires 0.

ALTER TABLE departments
  ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER sort_order;

-- One INSERT per new department, each only if no row has that name yet.
INSERT INTO departments (department_name, sort_order, is_active)
SELECT 'College of Arts and Sciences', 45, 1
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM departments WHERE department_name = 'College of Arts and Sciences'
);

SELECT ROW_COUNT() AS arts_and_sciences_added;

-- Placeholder sort_order; the UPDATE below puts it last.
INSERT INTO departments (department_name, sort_order, is_active)
SELECT 'Student Operations Group', 120, 1
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM departments WHERE department_name = 'Student Operations Group'
);

SELECT ROW_COUNT() AS student_operations_group_added;

-- Student Operations Group = (highest sort_order of every OTHER row) + 1.
-- Only changes the row when it is not already there, so a re-run moves 0.
UPDATE departments d
JOIN (
  SELECT MAX(sort_order) + 1 AS last_order
  FROM departments
  WHERE department_name <> 'Student Operations Group'
) m
SET d.sort_order = m.last_order
WHERE d.department_name = 'Student Operations Group'
  AND d.sort_order <> m.last_order;

SELECT ROW_COUNT() AS student_operations_group_moved;

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
