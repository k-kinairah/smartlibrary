-- SmartLib borrower favorites (heart icon on book cards / detail modal)
-- Safe to run more than once on MySQL/MariaDB versions that support IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS favorites (
    favorite_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    book_id INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (favorite_id),
    UNIQUE KEY uniq_favorites_user_book (user_id, book_id),
    KEY idx_favorites_user_created (user_id, created_at),
    KEY idx_favorites_book (book_id),
    CONSTRAINT fk_favorites_user
        FOREIGN KEY (user_id) REFERENCES library_users(user_id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT fk_favorites_book
        FOREIGN KEY (book_id) REFERENCES books(book_id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
