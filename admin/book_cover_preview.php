<?php
// Live cover preview for the Add Book window (manage_books.php).
//
// Draws the same generated cover book_cover.php serves for a saved book with
// no real cover, from the title, author and category typed so far (GET
// title, author, category_id). Nothing is saved or looked up online; the
// real cover lookup still happens when the book is added.
//
// Librarian/admin sessions only: anyone else gets a bare 403.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentRole = strtolower(trim((string)($_SESSION['role'] ?? '')));
if (!isset($_SESSION['user_id']) || !in_array($currentRole, ['librarian', 'admin'], true)) {
    http_response_code(403);
    exit;
}
require '../config/db_connect.php';
require_once '../config/session_guard.php';
if (!smartlib_active_staff_session($conn)) {
    http_response_code(403);
    exit;
}
// The session is only read from here on; release its lock so quick
// successive previews (and the page itself) never wait on each other.
session_write_close();

require_once '../config/book_cover.php';

/** A GET value, trimmed, made valid UTF-8 and cut at 255 characters. */
function book_cover_preview_param(string $name): string {
    $value = $_GET[$name] ?? '';
    if (!is_string($value)) {
        return '';
    }
    $value = trim(mb_scrub($value, 'UTF-8'));
    return trim(mb_substr($value, 0, 255, 'UTF-8'));
}

$title = book_cover_preview_param('title');
$author = book_cover_preview_param('author');
$categoryId = (int)book_cover_preview_param('category_id');

// The genre, resolved the way book_cover.php does it: the category's group
// when it has one, otherwise the category name. An unknown or missing
// category gives the neutral default design.
$categoryGroup = '';
if ($categoryId > 0) {
    $hasCategoryGroup = false;
    $colRes = $conn->query("SHOW COLUMNS FROM categories LIKE 'category_group'");
    if ($colRes instanceof mysqli_result) {
        $hasCategoryGroup = $colRes->num_rows > 0;
        $colRes->free();
    }
    $genreExpr = $hasCategoryGroup
        ? "TRIM(COALESCE(NULLIF(c.category_group, ''), c.category_name))"
        : "TRIM(c.category_name)";
    $stmt = $conn->prepare("SELECT {$genreExpr} AS category_group FROM categories c WHERE c.category_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $categoryId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $categoryGroup = (string)($row['category_group'] ?? '');
    }
}

if ($title === '') {
    // No title yet: the neutral default cover with placeholder wording
    // ("Book title" / "Author") at 55% opacity. The header lines stay as is.
    $svg = book_cover_svg('Book title', 'Author', '');
    $svg = preg_replace_callback('/<text\b[^>]*>.*?<\/text>/s', static function (array $m): string {
        $text = strtolower(trim(strip_tags($m[0])));
        if ($text === 'book title' || $text === 'author') {
            return (string)preg_replace('/^<text\b/', '<text opacity="0.55"', $m[0]);
        }
        return $m[0];
    }, $svg) ?? $svg;
} else {
    $svg = book_cover_svg($title, $author, $categoryGroup);
}

header('Content-Type: image/svg+xml; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
echo $svg;
