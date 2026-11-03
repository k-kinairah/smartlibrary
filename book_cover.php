<?php
// Serves the generated default cover (config/book_cover.php) for a book with
// no real cover, as an SVG image so it drops into any <img> cover slot.
require 'config/db_connect.php';
require 'config/book_cover.php';

$bookId = (int)($_GET['id'] ?? 0);
$title = '';
$author = '';

if ($bookId > 0) {
    $stmt = $conn->prepare('SELECT title, author FROM books WHERE book_id = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $title = (string)($row['title'] ?? '');
        $author = (string)($row['author'] ?? '');
    }
}

// Deterministic output, so let browsers cache it; the ETag changes when the
// title, author or design version does.
$etag = '"' . md5(BOOK_COVER_VERSION . '|' . $title . '|' . $author) . '"';
header('Content-Type: image/svg+xml; charset=utf-8');
header('Cache-Control: public, max-age=86400');
header('ETag: ' . $etag);
if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}

echo book_cover_svg($title, $author);
