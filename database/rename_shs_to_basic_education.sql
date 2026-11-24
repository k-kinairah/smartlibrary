-- SmartLib: rename the book section "SHS" to "Basic Education" (2026-11)
--
-- The librarian's name for the section that holds both JHS and SHS books is
-- "Basic Education". This renames library_locations.section from 'SHS' to
-- 'Basic Education'. The 5-day loan rule does not change: the code treats
-- both names as the same section (config/loan_policy.php).
--
-- BEFORE RUNNING: export the database (phpMyAdmin > Export) so it can be
-- restored if anything looks wrong.
--
-- RUN IT AFTER THE CODE IS UPLOADED (admin/manage_books.php and
-- config/loan_policy.php from the same update). Older code does not know
-- "Basic Education" and would lend those books for 7 days.
--
-- library_locations has a UNIQUE key over (section, class_code, shelf_code,
-- row_label, position_label). If a 'Basic Education' row with the same
-- class/shelf/row/position already exists (for example one created from the
-- new Add Book list before this file ran), renaming the 'SHS' row would
-- break that key. So those pairs are merged first: books on the 'SHS' row
-- are moved to the existing 'Basic Education' row, and the now-unused 'SHS'
-- row is deleted. Every other 'SHS' row is then renamed in place, so its
-- location_id (and every book pointing at it) stays the same.
--
-- Safe to run more than once: a second run finds no 'SHS' rows and changes
-- nothing. Comparisons use the column's case-insensitive collation, the same
-- rule the UNIQUE key uses, so 'shs' or 'Shs' rows are included too.
--
-- The two SELECTs show how many 'SHS' rows there were before and how many
-- are left after (0). Run in phpMyAdmin with the SmartLib database selected.

SELECT COUNT(*) AS shs_rows_before FROM library_locations WHERE section = 'SHS';

START TRANSACTION;

-- 1. Books on an 'SHS' row whose 'Basic Education' twin already exists move
--    to the twin.
UPDATE books b
JOIN library_locations s
  ON s.location_id = b.location_id
 AND s.section = 'SHS'
JOIN library_locations t
  ON t.section = 'Basic Education'
 AND t.class_code = s.class_code
 AND t.shelf_code = s.shelf_code
 AND t.row_label = s.row_label
 AND t.position_label = s.position_label
SET b.location_id = t.location_id;

-- 2. Those 'SHS' rows are now unused; remove them.
DELETE s
FROM library_locations s
JOIN library_locations t
  ON t.section = 'Basic Education'
 AND t.class_code = s.class_code
 AND t.shelf_code = s.shelf_code
 AND t.row_label = s.row_label
 AND t.position_label = s.position_label
WHERE s.section = 'SHS';

-- 3. Rename the remaining 'SHS' rows in place.
UPDATE library_locations
SET section = 'Basic Education'
WHERE section = 'SHS';

COMMIT;

SELECT COUNT(*) AS shs_rows_after FROM library_locations WHERE section = 'SHS';
