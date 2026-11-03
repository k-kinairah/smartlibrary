-- SmartLib: rejected-cover record (added 2026-11-04)
-- Safe to run more than once (IF NOT EXISTS).
--
-- A book listed here has had a fetched cover rejected as wrong -- a scanned
-- title/contents page, an ad, an unrelated photo -- by a librarian ("Clear
-- cover" in admin/manage_books.php) or by tools/clear_bad_covers.php
-- --commit. The nightly fetch (tools/fetch_book_covers.php) skips these
-- books, because clearing books.cover alone just made the next run download
-- the same image again: on 2026-10-29..11-03 it re-saved 24 of 27 covers
-- cleared in late October, byte-for-byte. A librarian can still fetch or
-- upload a cover for a rejected book by hand from the admin panel.
--
-- Complements the hard-coded EXCLUDED_BOOK_IDS in
-- config/cover_fetch_helpers.php (the original 9, with their history).
CREATE TABLE IF NOT EXISTS book_cover_rejections (
    book_id INT NOT NULL,
    reason VARCHAR(255) NOT NULL,
    rejected_cover_md5 CHAR(32) NULL,
    rejected_by VARCHAR(100) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (book_id),
    CONSTRAINT fk_cover_rejections_book
        FOREIGN KEY (book_id) REFERENCES books(book_id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
