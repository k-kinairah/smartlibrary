-- SmartLib multi-category / multi-program setup.
-- Run this in phpMyAdmin SQL tab while database "smartlib" is selected.
--
-- Why: books.category_id and books.program_id only let a book belong to
-- ONE category and ONE program. Some books are genuinely useful to more
-- than one course (e.g. a research-methods book for both Criminology and
-- Computer Science). This adds two new tables that let a book link to
-- SEVERAL categories/programs, without touching or breaking anything that
-- already reads books.category_id / books.program_id -- those stay exactly
-- as they are and keep working as the book's one "primary" listing.

CREATE TABLE IF NOT EXISTS book_categories (
    book_id INT NOT NULL,
    category_id INT NOT NULL,
    source ENUM('primary','manual','auto') NOT NULL DEFAULT 'manual',
    confidence FLOAT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (book_id, category_id),
    CONSTRAINT fk_book_categories_book
        FOREIGN KEY (book_id) REFERENCES books(book_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_book_categories_category
        FOREIGN KEY (category_id) REFERENCES categories(category_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS book_programs (
    book_id INT NOT NULL,
    program_id INT NOT NULL,
    source ENUM('primary','manual','auto') NOT NULL DEFAULT 'manual',
    confidence FLOAT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (book_id, program_id),
    CONSTRAINT fk_book_programs_book
        FOREIGN KEY (book_id) REFERENCES books(book_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_book_programs_program
        FOREIGN KEY (program_id) REFERENCES programs(program_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill: copy every book's existing single category/program in as its
-- "primary" listing, so nothing is missing on day one. Safe to run more
-- than once (INSERT IGNORE skips rows that already exist).
INSERT IGNORE INTO book_categories (book_id, category_id, source)
SELECT b.book_id, b.category_id, 'primary'
FROM books b
WHERE b.category_id IS NOT NULL;

INSERT IGNORE INTO book_programs (book_id, program_id, source)
SELECT b.book_id, b.program_id, 'primary'
FROM books b
WHERE b.program_id IS NOT NULL;

-- tools/auto_categorize_books.php adds further rows here with source='auto'
-- for the extra courses a book applies to, based on the AI fingerprints
-- from tools/generate_book_embeddings.php. Nothing here calls any external
-- API -- this file only prepares local storage for it.
