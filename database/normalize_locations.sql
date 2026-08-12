-- SmartLib location normalization migration
-- Safe to run multiple times.

CREATE TABLE IF NOT EXISTS library_locations (
    location_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    section VARCHAR(120) NOT NULL,
    class_code VARCHAR(10) NOT NULL,
    class_name VARCHAR(140) NOT NULL,
    shelf_code VARCHAR(30) NOT NULL,
    row_label VARCHAR(40) NOT NULL,
    position_label VARCHAR(40) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_library_location (section, class_code, shelf_code, row_label, position_label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE books
    ADD COLUMN IF NOT EXISTS location_id INT UNSIGNED NULL AFTER program_id,
    ADD COLUMN IF NOT EXISTS call_number VARCHAR(60) NULL AFTER location_id;

SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'books'
      AND INDEX_NAME = 'idx_books_location_id'
);

SET @idx_sql := IF(
    @idx_exists = 0,
    'ALTER TABLE books ADD INDEX idx_books_location_id (location_id)',
    'SELECT 1'
);

PREPARE stmt_idx FROM @idx_sql;
EXECUTE stmt_idx;
DEALLOCATE PREPARE stmt_idx;

SET @fk_exists := (
    SELECT COUNT(*)
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'books'
      AND CONSTRAINT_NAME = 'fk_books_location_id'
);

SET @fk_sql := IF(
    @fk_exists = 0,
    'ALTER TABLE books ADD CONSTRAINT fk_books_location_id FOREIGN KEY (location_id) REFERENCES library_locations(location_id) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT 1'
);

PREPARE stmt_fk FROM @fk_sql;
EXECUTE stmt_fk;
DEALLOCATE PREPARE stmt_fk;

-- Optional later cleanup (run only after you fully migrate old data):
-- ALTER TABLE books DROP COLUMN location;
