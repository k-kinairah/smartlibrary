-- SmartLib: Bulk update call numbers from librarian sheet
-- Use in phpMyAdmin (SQL tab) after filling the VALUES block.
-- Required columns from your list: ISBN, Class (call number)

START TRANSACTION;

CREATE TEMPORARY TABLE IF NOT EXISTS tmp_call_updates (
    isbn VARCHAR(50) NOT NULL,
    call_number VARCHAR(120) NOT NULL,
    PRIMARY KEY (isbn)
) ENGINE=MEMORY;

TRUNCATE TABLE tmp_call_updates;

-- Replace sample rows with your real librarian list.
INSERT INTO tmp_call_updates (isbn, call_number) VALUES
('9786214272396', 'HF 30.4 S47 2025 c.1'),
('9786214780969', 'HF 5415 M48 2024 c.1');

UPDATE books b
JOIN tmp_call_updates u ON u.isbn = b.isbn
SET b.call_number = NULLIF(TRIM(u.call_number), '')
WHERE TRIM(u.call_number) <> '';

COMMIT;

SELECT
    COUNT(*) AS books_total,
    SUM(call_number IS NULL OR call_number = '') AS books_without_call_number
FROM books;