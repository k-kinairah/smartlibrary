-- SmartLib: DRY RUN for database/assign_book_sections.sql (read-only).
-- Changes nothing. Shows what the migration would do.

-- 1. Books per target section, split by whether they already have a location.
SELECT
    t.target_section,
    COUNT(*) AS books,
    SUM(t.location_id IS NOT NULL) AS with_location_clone,
    SUM(t.location_id IS NULL) AS to_placeholder
FROM (
    SELECT
        b.location_id,
        CASE
            WHEN LOWER(TRIM(b.call_number)) REGEXP '^fil-ref([^a-z]|$)' THEN 'Reference'
            WHEN LOWER(TRIM(b.call_number)) REGEXP '^fil([^a-z]|$)' THEN 'Filipiniana'
            WHEN LOWER(TRIM(b.call_number)) REGEXP '^fic([^a-z]|$)' THEN 'Fiction'
            WHEN LOWER(TRIM(b.call_number)) REGEXP '^ref([^a-z]|$)' THEN 'Reference'
            ELSE 'Circulation'
        END AS target_section
    FROM books b
) t
GROUP BY t.target_section
ORDER BY books DESC;

-- 2. The Ref and Fil-Ref books (become Room use only). Check with the librarian.
SELECT b.book_id, b.title, b.call_number
FROM books b
WHERE LOWER(TRIM(b.call_number)) REGEXP '^(fil-)?ref([^a-z]|$)'
ORDER BY b.book_id;

-- 3. Call numbers whose prefix has punctuation (e.g. "Fil-Ref", which now
--    maps to Reference). Anything else listed here needs a decision.
SELECT b.book_id, b.title, b.call_number
FROM books b
WHERE LOWER(TRIM(b.call_number)) REGEXP '^(fil|fic|ref)[^a-z ]'
ORDER BY b.book_id;
