-- SmartLib local AI recommendation setup (NO external API)
-- Run this in phpMyAdmin SQL tab while database "smartlib" is selected.

CREATE TABLE IF NOT EXISTS search_logs (
    log_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT NULL,
    search_term VARCHAR(255) NOT NULL,
    result_count INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (log_id),
    KEY idx_search_logs_created_at (created_at),
    KEY idx_search_logs_user_id (user_id),
    KEY idx_search_logs_term (search_term),
    CONSTRAINT fk_search_logs_user
        FOREIGN KEY (user_id) REFERENCES library_users(user_id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recommendation_events (
    event_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT NULL,
    book_id INT NULL,
    panel_key VARCHAR(40) NOT NULL,
    event_type ENUM('impression','open','checkout') NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (event_id),
    KEY idx_rec_events_created_at (created_at),
    KEY idx_rec_events_user_id (user_id),
    KEY idx_rec_events_book_id (book_id),
    KEY idx_rec_events_panel_type (panel_key, event_type),
    CONSTRAINT fk_rec_events_user
        FOREIGN KEY (user_id) REFERENCES library_users(user_id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,
    CONSTRAINT fk_rec_events_book
        FOREIGN KEY (book_id) REFERENCES books(book_id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Safe index creation (idempotent)
SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'books'
      AND index_name = 'idx_books_program_created'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE books ADD INDEX idx_books_program_created (program_id, created_at)',
    'SELECT ''idx_books_program_created exists''');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'books'
      AND index_name = 'idx_books_category_created'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE books ADD INDEX idx_books_category_created (category_id, created_at)',
    'SELECT ''idx_books_category_created exists''');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'book_copies'
      AND index_name = 'idx_book_copies_book_status'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE book_copies ADD INDEX idx_book_copies_book_status (book_id, status)',
    'SELECT ''idx_book_copies_book_status exists''');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'borrow_records'
      AND index_name = 'idx_borrow_records_copy_status'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE borrow_records ADD INDEX idx_borrow_records_copy_status (copy_id, status)',
    'SELECT ''idx_borrow_records_copy_status exists''');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'borrow_records'
      AND index_name = 'idx_borrow_records_user_created'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE borrow_records ADD INDEX idx_borrow_records_user_created (user_id, created_at)',
    'SELECT ''idx_borrow_records_user_created exists''');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
