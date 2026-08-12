-- SmartLib: Apply Library of Congress style locations by category
-- Safe to re-run. This fills books.location_id for records that are still missing a location.

START TRANSACTION;

CREATE TEMPORARY TABLE IF NOT EXISTS tmp_lc_category_map (
    category_name VARCHAR(120) NOT NULL PRIMARY KEY,
    section VARCHAR(120) NOT NULL,
    class_code VARCHAR(10) NOT NULL,
    class_name VARCHAR(140) NOT NULL,
    shelf_code VARCHAR(30) NOT NULL,
    row_label VARCHAR(40) NOT NULL,
    position_label VARCHAR(40) NOT NULL
) ENGINE=MEMORY;

TRUNCATE TABLE tmp_lc_category_map;

INSERT INTO tmp_lc_category_map (category_name, section, class_code, class_name, shelf_code, row_label, position_label) VALUES
('Information Technology', 'General Collection', 'T', 'Technology', 'YA-05', 'First row', 'Eye Level'),
('Adventure', 'General Collection', 'P', 'Language and Literature', 'YA-01', 'Second row', 'Eye Level'),
('Business', 'General Collection', 'H', 'Social Sciences', 'GC-07', 'First row', 'Eye Level'),
('Classic', 'General Collection', 'P', 'Language and Literature', 'YA-03', 'Second row', 'Eye Level'),
('Technology', 'General Collection', 'T', 'Technology', 'YA-05', 'First row', 'Eye Level'),
('Literature', 'General Collection', 'P', 'Language and Literature', 'YA-04', 'Second row', 'Eye Level'),
('Behavioral Science', 'General Collection', 'B', 'Philosophy, Psychology, Religion', 'PSY-01', 'First row', 'Middle Shelf'),
('Nursing', 'General Collection', 'R', 'Medicine', 'NUR-01', 'First row', 'Eye Level'),
('Psychology', 'General Collection', 'B', 'Philosophy, Psychology, Religion', 'PSY-02', 'First row', 'Eye Level'),
('Business Law', 'General Collection', 'K', 'Law', 'YA-08', 'First row', 'Eye Level'),
('Business Research', 'General Collection', 'H', 'Social Sciences', 'GC-07', 'First row', 'Eye Level'),
('Corporate Law', 'General Collection', 'K', 'Law', 'YA-08', 'First row', 'Eye Level'),
('Corrections', 'General Collection', 'H', 'Social Sciences', 'GC-05', 'First row', 'Eye Level'),
('Criminal Investigation', 'General Collection', 'H', 'Social Sciences', 'GC-05', 'First row', 'Eye Level'),
('Criminal Law Evidence', 'General Collection', 'K', 'Law', 'YA-08', 'First row', 'Eye Level'),
('Criminology Theory', 'General Collection', 'H', 'Social Sciences', 'GC-05', 'First row', 'Eye Level'),
('Cybercrime Investigation', 'General Collection', 'H', 'Social Sciences', 'GC-05', 'First row', 'Eye Level'),
('Drug Education', 'General Collection', 'H', 'Social Sciences', 'GC-05', 'First row', 'Eye Level'),
('Financial Management', 'General Collection', 'H', 'Social Sciences', 'GC-06', 'First row', 'Eye Level'),
('Fingerprint Identification', 'General Collection', 'H', 'Social Sciences', 'GC-06', 'First row', 'Eye Level'),
('Forensic Science', 'General Collection', 'Q', 'Science', 'GC-06', 'Second row', 'Eye Level'),
('Hospitality Operations', 'General Collection', 'T', 'Technology', 'YA-06', 'First row', 'Eye Level'),
('Human Resource Management', 'General Collection', 'H', 'Social Sciences', 'GC-07', 'First row', 'Eye Level'),
('Human Rights', 'General Collection', 'K', 'Law', 'YA-08', 'First row', 'Eye Level'),
('Industrial Security', 'General Collection', 'H', 'Social Sciences', 'GC-05', 'First row', 'Eye Level'),
('Juvenile Justice', 'General Collection', 'H', 'Social Sciences', 'GC-05', 'First row', 'Eye Level'),
('Leadership Studies', 'General Collection', 'H', 'Social Sciences', 'GC-07', 'First row', 'Eye Level'),
('Marketing / Business', 'General Collection', 'H', 'Social Sciences', 'GC-07', 'First row', 'Eye Level'),
('Marketing Research Methods', 'General Collection', 'H', 'Social Sciences', 'GC-07', 'First row', 'Eye Level'),
('Organizational Behavior', 'General Collection', 'H', 'Social Sciences', 'GC-07', 'First row', 'Eye Level'),
('Police Administration', 'General Collection', 'H', 'Social Sciences', 'GC-08', 'First row', 'Eye Level'),
('Police Operations', 'General Collection', 'H', 'Social Sciences', 'GC-08', 'First row', 'Eye Level'),
('Policing Systems', 'General Collection', 'H', 'Social Sciences', 'GC-08', 'First row', 'Eye Level'),
('Professional Ethics', 'General Collection', 'H', 'Social Sciences', 'GC-08', 'Second row', 'Eye Level'),
('Recreation Management', 'General Collection', 'G', 'Geography, Anthropology, Recreation', 'GC-04', 'Second row', 'Eye Level'),
('Tour Guiding', 'General Collection', 'G', 'Geography, Anthropology, Recreation', 'GC-04', 'Second row', 'Eye Level'),
('Tourism Geography', 'General Collection', 'G', 'Geography, Anthropology, Recreation', 'GC-04', 'Second row', 'Eye Level'),
('Tourism Studies', 'General Collection', 'G', 'Geography, Anthropology, Recreation', 'GC-04', 'Second row', 'Eye Level'),
('Traffic Investigation', 'General Collection', 'H', 'Social Sciences', 'GC-08', 'First row', 'Eye Level'),
('Workplace Diversity', 'General Collection', 'H', 'Social Sciences', 'GC-07', 'First row', 'Eye Level'),
('Cybercrime', 'General Collection', 'H', 'Social Sciences', 'GC-05', 'First row', 'Eye Level');

INSERT INTO library_locations (section, class_code, class_name, shelf_code, row_label, position_label, created_at)
SELECT DISTINCT
    m.section,
    m.class_code,
    m.class_name,
    m.shelf_code,
    m.row_label,
    m.position_label,
    NOW()
FROM tmp_lc_category_map m
LEFT JOIN library_locations l
    ON l.section = m.section
   AND l.class_code = m.class_code
   AND l.shelf_code = m.shelf_code
   AND l.row_label = m.row_label
   AND l.position_label = m.position_label
WHERE l.location_id IS NULL;

UPDATE books b
JOIN categories c ON c.category_id = b.category_id
JOIN tmp_lc_category_map m ON m.category_name = c.category_name
JOIN library_locations l
    ON l.section = m.section
   AND l.class_code = m.class_code
   AND l.shelf_code = m.shelf_code
   AND l.row_label = m.row_label
   AND l.position_label = m.position_label
SET b.location_id = l.location_id
WHERE b.location_id IS NULL OR b.location_id = 0;

COMMIT;

SELECT
    COUNT(*) AS books_total,
    SUM(location_id IS NULL OR location_id = 0) AS books_without_location,
    SUM(call_number IS NULL OR call_number = '') AS books_without_call_number
FROM books;