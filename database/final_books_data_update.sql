-- Final one-time SQL generated from BOOKS DATA.xlsx on 2026-04-08
-- Updates books.call_number and realigns books.location_id using LC class from call number.

START TRANSACTION;

CREATE TEMPORARY TABLE IF NOT EXISTS tmp_call_updates (
  isbn VARCHAR(50) NOT NULL PRIMARY KEY,
  call_number VARCHAR(120) NOT NULL,
  section VARCHAR(120) NOT NULL,
  class_code VARCHAR(10) NOT NULL,
  class_name VARCHAR(140) NOT NULL,
  shelf_code VARCHAR(30) NOT NULL,
  row_label VARCHAR(40) NOT NULL,
  position_label VARCHAR(40) NOT NULL
) ENGINE=MEMORY;

TRUNCATE TABLE tmp_call_updates;
INSERT INTO tmp_call_updates (isbn, call_number, section, class_code, class_name, shelf_code, row_label, position_label) VALUES
('9781835353004', 'HV 8076 S56 2025', 'General Collection', 'H', 'Social Sciences', 'GC-05', 'First row', 'Eye Level'),
('9786210450361', 'HF 5415.2 A24 2024', 'General Collection', 'H', 'Social Sciences', 'GC-07', 'First row', 'Eye Level'),
('9786214093021', 'G 155 D57 2025', 'General Collection', 'G', 'Geography, Anthropology, Recreation', 'GC-04', 'Second row', 'Eye Level'),
('9786214161072', 'HG 4026 C33 2021', 'General Collection', 'H', 'Social Sciences', 'GC-07', 'First row', 'Eye Level'),
('9786214161157', 'KPM 1250 S67 2021', 'General Collection', 'K', 'Law', 'YA-08', 'First row', 'Eye Level'),
('9786214161249', 'KPM 1280 S67 2022', 'General Collection', 'K', 'Law', 'YA-08', 'First row', 'Eye Level'),
('9786214271481', 'HF 5415.2 M88 2024', 'General Collection', 'H', 'Social Sciences', 'GC-07', 'First row', 'Eye Level'),
('9786214271825', 'HF 5549 A23 2024', 'General Collection', 'H', 'Social Sciences', 'GC-07', 'First row', 'Eye Level'),
('9786214272358', 'HD 58.7 S47 2025', 'General Collection', 'H', 'Social Sciences', 'GC-07', 'First row', 'Eye Level'),
('9786214272396', 'HD 30.4 S47 2025', 'General Collection', 'H', 'Social Sciences', 'GC-07', 'First row', 'Eye Level'),
('9786214780969', 'HF 5415 M34 2024', 'General Collection', 'H', 'Social Sciences', 'GC-07', 'First row', 'Eye Level'),
('9786214781423', 'G 155.5 Y46 2025', 'General Collection', 'G', 'Geography, Anthropology, Recreation', 'GC-04', 'Second row', 'Eye Level'),
('9786214781553', 'HF 5549.5 Y48 2025', 'General Collection', 'H', 'Social Sciences', 'GC-07', 'First row', 'Eye Level'),
('9786214782000', 'G 155 V59 2026', 'General Collection', 'G', 'Geography, Anthropology, Recreation', 'GC-04', 'Second row', 'Eye Level'),
('9786214880003', 'HV 6025 S25 2024', 'General Collection', 'H', 'Social Sciences', 'GC-05', 'First row', 'Eye Level'),
('9786214880010', 'HE 5621 A64 2024', 'General Collection', 'H', 'Social Sciences', 'GC-08', 'First row', 'Eye Level'),
('9786214880027', 'BJ 1533 L58 2024', 'General Collection', 'B', 'Philosophy, Psychology, Religion', 'PSY-02', 'First row', 'Middle Shelf'),
('9786214880034', 'HV 8073 B85 2024', 'General Collection', 'H', 'Social Sciences', 'GC-05', 'First row', 'Eye Level'),
('9786214880058', 'HV 7936 C37 2024', 'General Collection', 'H', 'Social Sciences', 'GC-05', 'First row', 'Eye Level'),
('9786214880065', 'HV 6773 D48 2024', 'General Collection', 'H', 'Social Sciences', 'GC-05', 'First row', 'Eye Level'),
('9786214880072', 'KF 8975 G33 2024', 'General Collection', 'K', 'Law', 'YA-08', 'First row', 'Eye Level'),
('9786214880133', 'HV 9466 C43 2024', 'General Collection', 'H', 'Social Sciences', 'GC-05', 'First row', 'Eye Level'),
('9786214880140', 'HV 6773 L34 2024', 'General Collection', 'H', 'Social Sciences', 'GC-05', 'First row', 'Eye Level'),
('9786214880157', 'TH 9145 B63 2024', 'General Collection', 'T', 'Technology', 'YA-05', 'First row', 'Eye Level'),
('9786214880164', 'HV 9101 D48 2024', 'General Collection', 'H', 'Social Sciences', 'GC-05', 'First row', 'Eye Level'),
('9786214880232', 'HV 5805 C44 2024', 'General Collection', 'H', 'Social Sciences', 'GC-05', 'First row', 'Eye Level'),
('9786214880300', 'HV 8075 D48 2025', 'General Collection', 'H', 'Social Sciences', 'GC-05', 'First row', 'Eye Level'),
('9786214880317', 'HV 8077 S25 2025', 'General Collection', 'H', 'Social Sciences', 'GC-05', 'First row', 'Eye Level'),
('9786214880324', 'HV 8077 A74 2025', 'General Collection', 'H', 'Social Sciences', 'GC-05', 'First row', 'Eye Level'),
('9786214880331', 'HV 8077 L33 2025', 'General Collection', 'H', 'Social Sciences', 'GC-05', 'First row', 'Eye Level'),
('9788119920334', 'HQ 1236 S56 2024', 'General Collection', 'H', 'Social Sciences', 'GC-07', 'First row', 'Eye Level'),
('9789362243720', 'HV 8073 Y33 2025', 'General Collection', 'H', 'Social Sciences', 'GC-05', 'First row', 'Eye Level'),
('9789362247193', 'HV 8075 H33 2025', 'General Collection', 'H', 'Social Sciences', 'GC-05', 'First row', 'Eye Level'),
('9789719655077', 'HF 5387 B84 2022', 'General Collection', 'H', 'Social Sciences', 'GC-07', 'First row', 'Eye Level'),
('9789719655374', 'TX 911.3 B84 2023', 'General Collection', 'T', 'Technology', 'YA-06', 'First row', 'Eye Level'),
('9789719655657', 'GV 181.3 C67 2023', 'General Collection', 'G', 'Geography, Anthropology, Recreation', 'GC-04', 'Second row', 'Eye Level');

INSERT INTO library_locations (section, class_code, class_name, shelf_code, row_label, position_label, created_at)
SELECT DISTINCT u.section, u.class_code, u.class_name, u.shelf_code, u.row_label, u.position_label, NOW()
FROM tmp_call_updates u
LEFT JOIN library_locations l
  ON l.section = u.section
 AND l.class_code = u.class_code
 AND l.shelf_code = u.shelf_code
 AND l.row_label = u.row_label
 AND l.position_label = u.position_label
WHERE l.location_id IS NULL;

UPDATE books b
JOIN tmp_call_updates u ON u.isbn = b.isbn
JOIN library_locations l
  ON l.section = u.section
 AND l.class_code = u.class_code
 AND l.shelf_code = u.shelf_code
 AND l.row_label = u.row_label
 AND l.position_label = u.position_label
SET
  b.call_number = u.call_number,
  b.location_id = l.location_id;

COMMIT;

SELECT
  COUNT(*) AS books_total,
  SUM(location_id IS NULL OR location_id = 0) AS books_without_location,
  SUM(call_number IS NULL OR call_number = '') AS books_without_call_number
FROM books;

SELECT COUNT(*) AS sheet_isbn_rows FROM tmp_call_updates;
SELECT COUNT(*) AS matched_books FROM books b JOIN tmp_call_updates u ON u.isbn = b.isbn;
SELECT u.isbn, u.call_number FROM tmp_call_updates u LEFT JOIN books b ON b.isbn = u.isbn WHERE b.book_id IS NULL;