-- SmartLib: assign every book to a loan section (2026-11-14)
--
-- DRAFT. Not run yet. Check database/assign_book_sections_dry_run.sql with
-- the librarian first.
--
-- Mapping by books.call_number prefix (the leading letters, any case):
--   Fil / FIL / FIl (including "Fil-Ref")  -> Filipiniana
--   Fic                                    -> Fiction
--   Ref                                    -> Reference   (room use only)
--   everything else (GEN, NULL, LC letters) -> Circulation
-- Thesis and SHS are not assigned: no call number marks them.
--
-- Existing library_locations rows are never edited:
--   * a book that already has a location gets a CLONE of that row with the
--     new section (class/shelf/row/position copied);
--   * a book with no location gets one placeholder row per section
--     (class/shelf/row/position = '').
-- Old rows (all "General Collection") are left in place, unused.
-- Safe to run twice: books already in their target section are skipped.
--
-- ---------------------------------------------------------------------------
-- STEP 1: BACKUP FIRST (run in PowerShell, NOT part of this SQL):
--
--   New-Item -ItemType Directory -Force C:\xampp\smartlib_backups | Out-Null
--   $ts = Get-Date -Format yyyyMMdd_HHmmss
--   & C:\xampp\mysql\bin\mysqldump.exe -u root -p --single-transaction --routines --triggers --events smartlib --result-file "C:\xampp\smartlib_backups\smartlib_before_sections_$ts.sql"
--
-- Check the dump file is not empty before going on.
--
-- STEP 2: run this file:
--   & C:\xampp\mysql\bin\mysql.exe -u root -p smartlib -e "source C:/xampp/htdocs/SmartLib/database/assign_book_sections.sql"
--
-- Restore if needed:
--   cmd /c "C:\xampp\mysql\bin\mysql.exe -u root -p smartlib < C:\xampp\smartlib_backups\smartlib_before_sections_<ts>.sql"
-- ---------------------------------------------------------------------------

DROP TEMPORARY TABLE IF EXISTS tmp_book_section;

CREATE TEMPORARY TABLE tmp_book_section (
    book_id INT NOT NULL PRIMARY KEY,
    location_id INT UNSIGNED NULL,
    target_section VARCHAR(120) NOT NULL
) ENGINE=InnoDB;

INSERT INTO tmp_book_section (book_id, location_id, target_section)
SELECT
    b.book_id,
    b.location_id,
    CASE
        WHEN LOWER(TRIM(b.call_number)) REGEXP '^fil([^a-z]|$)' THEN 'Filipiniana'
        WHEN LOWER(TRIM(b.call_number)) REGEXP '^fic([^a-z]|$)' THEN 'Fiction'
        WHEN LOWER(TRIM(b.call_number)) REGEXP '^ref([^a-z]|$)' THEN 'Reference'
        ELSE 'Circulation'
    END
FROM books b;

START TRANSACTION;

-- 2a. Books WITH a location: clone the row with the new section.
--     GROUP BY the unique key so two source rows can never collide.
INSERT INTO library_locations (section, class_code, class_name, shelf_code, row_label, position_label, created_at)
SELECT
    m.target_section,
    l.class_code,
    MIN(l.class_name),
    l.shelf_code,
    l.row_label,
    l.position_label,
    NOW()
FROM tmp_book_section m
JOIN library_locations l ON l.location_id = m.location_id
WHERE l.section <> m.target_section
  AND NOT EXISTS (
      SELECT 1 FROM library_locations x
      WHERE x.section = m.target_section
        AND x.class_code = l.class_code
        AND x.shelf_code = l.shelf_code
        AND x.row_label = l.row_label
        AND x.position_label = l.position_label
  )
GROUP BY m.target_section, l.class_code, l.shelf_code, l.row_label, l.position_label;

UPDATE books b
JOIN tmp_book_section m ON m.book_id = b.book_id
JOIN library_locations l ON l.location_id = m.location_id
JOIN library_locations n
  ON n.section = m.target_section
 AND n.class_code = l.class_code
 AND n.shelf_code = l.shelf_code
 AND n.row_label = l.row_label
 AND n.position_label = l.position_label
SET b.location_id = n.location_id
WHERE l.section <> m.target_section;

-- 2b. Books WITHOUT a location: one placeholder row per section.
INSERT INTO library_locations (section, class_code, class_name, shelf_code, row_label, position_label, created_at)
SELECT DISTINCT m.target_section, '', '', '', '', '', NOW()
FROM tmp_book_section m
WHERE m.location_id IS NULL
  AND NOT EXISTS (
      SELECT 1 FROM library_locations x
      WHERE x.section = m.target_section
        AND x.class_code = '' AND x.shelf_code = ''
        AND x.row_label = '' AND x.position_label = ''
  );

UPDATE books b
JOIN tmp_book_section m ON m.book_id = b.book_id
JOIN library_locations p
  ON p.section = m.target_section
 AND p.class_code = '' AND p.shelf_code = ''
 AND p.row_label = '' AND p.position_label = ''
SET b.location_id = p.location_id
WHERE m.location_id IS NULL
  AND b.location_id IS NULL;

-- 3. Check before commit: expect 0. If not 0, type ROLLBACK; instead of
--    letting the COMMIT below run (when running interactively).
SELECT COUNT(*) AS books_not_in_target_section
FROM books b
JOIN tmp_book_section m ON m.book_id = b.book_id
LEFT JOIN library_locations l ON l.location_id = b.location_id
WHERE l.location_id IS NULL OR l.section <> m.target_section;

COMMIT;

DROP TEMPORARY TABLE IF EXISTS tmp_book_section;

-- Verification: books per section (expected from the dry run on 2026-11-14:
-- Circulation 1,018 / Filipiniana 909 / Fiction 81 / Reference 7).
SELECT COALESCE(l.section, '(no location)') AS section, COUNT(*) AS books
FROM books b
LEFT JOIN library_locations l ON l.location_id = b.location_id
GROUP BY COALESCE(l.section, '(no location)')
ORDER BY books DESC;
