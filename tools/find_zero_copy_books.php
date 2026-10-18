<?php
declare(strict_types=1);

// Diagnostic: lists every book in the catalog that has ZERO physical
// copies registered in book_copies. This exists because
// recommend_books.php silently hides any such book from every
// recommendation panel -- so a whole book can look perfectly set up
// (category, program, AI fingerprint) and still never surface anywhere,
// with no error or warning.
//
// This is also the fastest way to spot leftover seed/sample data: real
// books entered through the normal librarian workflow almost always get
// at least one physical copy added at the same time, so a cluster of
// zero-copy books with no matching entry in a real import file (see
// database/books_data_extract.tsv) is a strong signal they're test data
// rather than real catalog entries.
//
// Read-only -- this never changes anything in the database.
//
// Usage:
//   php tools/find_zero_copy_books.php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config/db_connect.php';

function fzc_log(string $message): void {
    echo $message . PHP_EOL;
}

$sql = "SELECT
            b.book_id,
            b.title,
            b.author,
            b.created_at,
            c.category_name,
            p.program_name,
            COUNT(bc.copy_id) AS total_copies
        FROM books b
        LEFT JOIN book_copies bc ON bc.book_id = b.book_id
        LEFT JOIN categories c ON c.category_id = b.category_id
        LEFT JOIN programs p ON p.program_id = b.program_id
        GROUP BY b.book_id
        HAVING total_copies = 0
        ORDER BY b.created_at DESC, b.book_id DESC";

$res = $conn->query($sql);
if (!$res) {
    fwrite(STDERR, 'Query failed: ' . $conn->error . PHP_EOL);
    exit(1);
}

$rows = [];
while ($row = $res->fetch_assoc()) {
    $rows[] = $row;
}

$total = $conn->query('SELECT COUNT(*) AS n FROM books');
$totalBooks = $total ? (int)($total->fetch_assoc()['n'] ?? 0) : 0;

if (count($rows) === 0) {
    fzc_log("No zero-copy books found. All {$totalBooks} book(s) in the catalog have at least one registered copy.");
    exit(0);
}

fzc_log(count($rows) . " of {$totalBooks} book(s) in the catalog have ZERO registered copies -- invisible to every recommendation panel:");
fzc_log('');

foreach ($rows as $row) {
    $bookId = (int)$row['book_id'];
    $title = (string)$row['title'];
    $author = (string)$row['author'];
    $category = $row['category_name'] !== null ? (string)$row['category_name'] : '(none)';
    $program = $row['program_name'] !== null ? (string)$row['program_name'] : '(none)';
    $createdAt = (string)$row['created_at'];
    fzc_log("#{$bookId}  {$title}  ({$author})");
    fzc_log("      category: {$category}   program: {$program}   added: {$createdAt}");
}

fzc_log('');
fzc_log('IDs only (for a DELETE statement, if you confirm these should be removed): ' . implode(',', array_map(static fn($r) => (int)$r['book_id'], $rows)));
?>
