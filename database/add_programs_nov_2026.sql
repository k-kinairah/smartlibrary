-- SmartLib: new college programs (2026-11)
--
-- Adds programs the sign-up form, Manage Users, the admin book forms and the
-- dashboard read straight from the programs table (sorted by name), so no
-- code change is needed for them to appear.
--
-- Each name is inserted only if no program with that name exists yet, so
-- this is safe to run more than once. programs has no unique key on
-- program_name, hence the NOT EXISTS check instead of INSERT IGNORE. Other
-- columns: program_id is AUTO_INCREMENT and created_at defaults to now, the
-- same as the existing rows.
--
-- To add more later: append one line to the list below (keep one program per
-- line, in the existing "BS ..." naming style) and run the file again.
--
-- Run in phpMyAdmin with the SmartLib database selected. Order does not
-- matter relative to the code upload.

INSERT INTO programs (program_name)
SELECT n.program_name
FROM (
  SELECT 'BS Radiologic Technology' AS program_name
  UNION ALL SELECT 'BS Accountancy'
) AS n
WHERE NOT EXISTS (
  SELECT 1 FROM programs p WHERE p.program_name = n.program_name
);

SELECT ROW_COUNT() AS programs_added;
