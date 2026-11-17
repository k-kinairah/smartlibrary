-- SmartLib faculty/staff departments (2026-11)
--
-- Faculty/staff pick a Department (this list, from the school) instead of
-- the student Program / course. Students are unchanged. Departments live in
-- their own table: the programs table is also the BOOK course list
-- (books.program_id, book_programs, dashboard charts, Program Activity), so
-- department rows there would show up in all of that.
--
-- Existing faculty keep their program_id; department_id starts empty and the
-- librarian sets it in Manage Users. No automatic mapping.
--
-- PRODUCTION ORDER: export the whole database first, run THIS file in
-- phpMyAdmin (with the SmartLib database selected), and only THEN upload the
-- code. The new code reads these columns, so uploading the code first would
-- break library-card sign-up and the kiosk.
--
-- Safe to run more than once on MySQL/MariaDB versions that support
-- IF NOT EXISTS (same style as borrow_renewal_setup.sql).

CREATE TABLE IF NOT EXISTS departments (
  department_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  department_name VARCHAR(100) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_departments_name (department_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Names exactly as the school wrote them; the dropdown follows sort_order.
INSERT INTO departments (department_name, sort_order) VALUES
  ('College of Management and Accountancy', 10),
  ('College of Education', 20),
  ('College of Allied Health Sciences', 30),
  ('College of Criminal Justice Education', 40),
  ('Registrar', 50),
  ('GSD', 60),
  ('OCOO', 70),
  ('Finance', 80),
  ('Marketing', 90),
  ('CSDL', 100),
  ('ITS', 110)
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order);

-- Plain INT columns, no foreign keys (library_users.program_id has none either).
ALTER TABLE library_users
  ADD COLUMN IF NOT EXISTS department_id INT NULL AFTER program_id,
  ADD KEY IF NOT EXISTS idx_library_users_department (department_id);

ALTER TABLE auth_signup_verifications
  ADD COLUMN IF NOT EXISTS department_id INT NULL AFTER program_id;

ALTER TABLE library_entry_logs
  ADD COLUMN IF NOT EXISTS department_id INT NULL AFTER program_id;
