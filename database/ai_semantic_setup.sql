-- SmartLib semantic recommendation setup (one-time AI tagging)
-- Run this in phpMyAdmin SQL tab while database "smartlib" is selected.
-- This creates ONE table: a place to store each book's AI "fingerprint"
-- (an embedding vector) after it has been tagged once by
-- tools/generate_book_embeddings.php. Nothing here calls any external
-- API -- this file only prepares local storage for it.

CREATE TABLE IF NOT EXISTS book_embeddings (
    book_id INT NOT NULL,
    model VARCHAR(60) NOT NULL,
    dimensions SMALLINT UNSIGNED NOT NULL,
    source_hash CHAR(64) NOT NULL,
    embedding LONGTEXT NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (book_id),
    CONSTRAINT fk_book_embeddings_book
        FOREIGN KEY (book_id) REFERENCES books(book_id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- source_hash lets the tagging script detect when a book's title/author/
-- category has changed since it was last tagged, so it only re-calls the
-- API for books that are new or actually changed -- safe to run again and
-- again as more of the ~10k-book catalog gets entered over time.
