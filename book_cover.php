<?php
// Serves the generated default cover (config/book_cover.php) for a book with
// no real cover, as an SVG image so it drops into any <img> cover slot.
require 'config/db_connect.php';
require 'config/book_cover.php';

// Same column check as fetch_books.php (copied verbatim): a hardcoded
// known-column list rather than a live information_schema lookup, for the
// reason given on table_exists() in fetch_books.php.
if (!function_exists('column_exists')) {
    function column_exists(mysqli $conn, string $table, string $column): bool {
        // 2026-10-10: see table_exists() above -- same reason, same fix.
        static $knownColumns = [
            'categories' => ['category_group'],
            'books' => ['location_id', 'call_number', 'publisher', 'category_id', 'program_id'],
            'library_locations' => ['class_code'],
            'borrow_records' => ['renew_count', 'last_renewed_at'],
        ];
        return in_array($column, $knownColumns[$table] ?? [], true);
    }
}

// The book's genre, resolved exactly as fetch_books.php's genre filter chips
// do: the category's group when it has one, otherwise the category name.
$genreExpr = column_exists($conn, 'categories', 'category_group')
    ? "TRIM(COALESCE(NULLIF(c.category_group, ''), c.category_name))"
    : "TRIM(c.category_name)";

$bookId = (int)($_GET['id'] ?? 0);
$title = '';
$author = '';
$categoryGroup = '';

if ($bookId > 0) {
    $stmt = $conn->prepare(
        "SELECT b.title, b.author, {$genreExpr} AS category_group
         FROM books b
         LEFT JOIN categories c ON b.category_id = c.category_id
         WHERE b.book_id = ? LIMIT 1"
    );
    if ($stmt) {
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $title = (string)($row['title'] ?? '');
        $author = (string)($row['author'] ?? '');
        $categoryGroup = (string)($row['category_group'] ?? '');
    }
}

// Deterministic output, so let browsers cache it; the ETag changes when the
// title, author, category or design version does.
$etag = '"' . md5(BOOK_COVER_VERSION . '|' . $title . '|' . $author . '|' . $categoryGroup) . '"';
header('Content-Type: image/svg+xml; charset=utf-8');
header('Cache-Control: public, max-age=86400');
header('ETag: ' . $etag);
if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}

echo book_cover_svg($title, $author, $categoryGroup);
