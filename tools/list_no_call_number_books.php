<?php
declare(strict_types=1);

// Read-only diagnostic: lists every still-uncategorized book (category_id
// IS NULL/0) that has NO call number recorded at all. These can't be
// classified by LC prefix like the rest of the catalog -- the only path is
// title/author/publisher review, one by one.
//
// Usage:
//   php tools/list_no_call_number_books.php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config/db_connect.php';

$res = $conn->query(
    "SELECT book_id, title, author, isbn, publisher, year_published
     FROM books
     WHERE (category_id IS NULL OR category_id = 0)
       AND (call_number IS NULL OR TRIM(call_number) = '')
     ORDER BY title"
);
if (!$res) {
    fwrite(STDERR, 'Query failed: ' . $conn->error . PHP_EOL);
    exit(1);
}

$rows = [];
while ($row = $res->fetch_assoc()) {
    $rows[] = $row;
}

echo 'Uncategorized books with no call number: ' . count($rows) . PHP_EOL;
echo PHP_EOL;

foreach ($rows as $r) {
    echo sprintf(
        "  #%-6d \"%s\" by %s | isbn=%s | publisher=%s | year=%s",
        $r['book_id'],
        $r['title'],
        $r['author'],
        $r['isbn'] !== null && trim((string)$r['isbn']) !== '' ? trim((string)$r['isbn']) : '(none)',
        $r['publisher'] !== null && trim((string)$r['publisher']) !== '' ? trim((string)$r['publisher']) : '(none)',
        $r['year_published'] !== null && trim((string)$r['year_published']) !== '' ? trim((string)$r['year_published']) : '(none)'
    ) . PHP_EOL;
}
?>
