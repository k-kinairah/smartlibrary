-- SmartLib: give every book's main program a 'primary' row in book_programs (2026-11)
--
-- Books can now be linked to several programs. books.program_id stays the
-- MAIN program; every linked program, the main one included, is a row in
-- book_programs (source 'primary' for the main one, 'manual' for programs a
-- librarian ticks, 'auto' for tools/auto_categorize_books.php). Books saved
-- before this change may have a main program but no 'primary' row. This
-- adds the missing rows so the Edit Book window, the Books list, the
-- searches and the CSV export all see the main program as linked.
--
-- BEFORE RUNNING: export the database (phpMyAdmin > Export) so it can be
-- restored if anything looks wrong. Order relative to the code upload does
-- not matter (the new code reads books.program_id either way).
--
-- Safe to run more than once: INSERT IGNORE skips a book/program pair that
-- already has a row (the table's primary key is book_id + program_id), so
-- an existing row keeps its source and nothing is ever duplicated or
-- deleted. The two SELECTs show the row count before and after; the
-- difference is how many rows this run added (0 on a second run).
--
-- Run in phpMyAdmin with the SmartLib database selected.

SELECT COUNT(*) AS book_programs_rows_before FROM book_programs;

INSERT IGNORE INTO book_programs (book_id, program_id, source)
SELECT book_id, program_id, 'primary'
FROM books
WHERE program_id IS NOT NULL;

SELECT COUNT(*) AS book_programs_rows_after FROM book_programs;
