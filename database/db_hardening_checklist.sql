-- SmartLib DB hardening checklist (optional, recommended before production)
-- Date: 2026-03-12
-- Notes:
-- 1) Run this only after confirming you have no orphan data.
-- 2) Use phpMyAdmin SQL tab or mysql CLI.

-- =============================
-- A) QUICK HEALTH CHECKS
-- =============================

-- Books with invalid category links
SELECT b.book_id, b.title, b.category_id
FROM books b
LEFT JOIN categories c ON c.category_id = b.category_id
WHERE b.category_id IS NOT NULL AND c.category_id IS NULL;

-- Books with invalid program links
SELECT b.book_id, b.title, b.program_id
FROM books b
LEFT JOIN programs p ON p.program_id = b.program_id
WHERE b.program_id IS NOT NULL AND p.program_id IS NULL;

-- Copies with invalid book links
SELECT bc.copy_id, bc.book_id
FROM book_copies bc
LEFT JOIN books b ON b.book_id = bc.book_id
WHERE b.book_id IS NULL;

-- Borrow rows with invalid user links
SELECT br.record_id, br.user_id
FROM borrow_records br
LEFT JOIN library_users u ON u.user_id = br.user_id
WHERE u.user_id IS NULL;

-- Borrow rows with invalid copy links
SELECT br.record_id, br.copy_id
FROM borrow_records br
LEFT JOIN book_copies bc ON bc.copy_id = br.copy_id
WHERE bc.copy_id IS NULL;


-- =============================
-- B) PERFORMANCE INDEXES
-- =============================
-- Skip any CREATE INDEX that already exists in your DB.

CREATE INDEX idx_books_category_id ON books(category_id);
CREATE INDEX idx_books_program_id ON books(program_id);
CREATE INDEX idx_books_created_at ON books(created_at);

CREATE INDEX idx_book_copies_book_id ON book_copies(book_id);
CREATE INDEX idx_book_copies_status ON book_copies(status);

CREATE INDEX idx_borrow_records_user_id ON borrow_records(user_id);
CREATE INDEX idx_borrow_records_copy_id ON borrow_records(copy_id);
CREATE INDEX idx_borrow_records_status ON borrow_records(status);
CREATE INDEX idx_borrow_records_date_borrowed ON borrow_records(date_borrowed);

CREATE INDEX idx_library_users_role ON library_users(role);
CREATE INDEX idx_library_users_status ON library_users(status);

CREATE INDEX idx_notifications_read_created ON notifications(is_read, created_at);


-- =============================
-- C) OPTIONAL FOREIGN KEYS
-- =============================
-- Run only when all health checks above return zero rows.

ALTER TABLE books
ADD CONSTRAINT fk_books_category
FOREIGN KEY (category_id) REFERENCES categories(category_id)
ON UPDATE CASCADE
ON DELETE SET NULL;

ALTER TABLE books
ADD CONSTRAINT fk_books_program
FOREIGN KEY (program_id) REFERENCES programs(program_id)
ON UPDATE CASCADE
ON DELETE SET NULL;

ALTER TABLE book_copies
ADD CONSTRAINT fk_book_copies_book
FOREIGN KEY (book_id) REFERENCES books(book_id)
ON UPDATE CASCADE
ON DELETE CASCADE;

ALTER TABLE borrow_records
ADD CONSTRAINT fk_borrow_records_user
FOREIGN KEY (user_id) REFERENCES library_users(user_id)
ON UPDATE CASCADE
ON DELETE RESTRICT;

ALTER TABLE borrow_records
ADD CONSTRAINT fk_borrow_records_copy
FOREIGN KEY (copy_id) REFERENCES book_copies(copy_id)
ON UPDATE CASCADE
ON DELETE RESTRICT;
