<?php
declare(strict_types=1);

// Read-only diagnostic for the book-covers feature: reports how many books
// in the catalog currently have a cover filename set on `books.cover`, and
// -- for the ones that don't -- how many have an ISBN we could look up
// against a cover API vs. how many would need title/author matching.
//
// Usage:
//   php tools/check_cover_coverage.php
//   php tools/check_cover_coverage.php --sample=30   (print N sample rows
//                                                      of books with no cover)

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config/db_connect.php';

$sampleSize = 15;
foreach ($argv as $arg) {
    if (preg_match('/^--sample=(\d+)$/', (string)$arg, $m)) {
        $sampleSize = (int)$m[1];
    }
}

$totalRes = $conn->query('SELECT COUNT(*) AS c FROM books');
if (!$totalRes) {
    fwrite(STDERR, 'Query failed: ' . $conn->error . PHP_EOL);
    exit(1);
}
$total = (int)$totalRes->fetch_assoc()['c'];

$withCoverRes = $conn->query(
    "SELECT COUNT(*) AS c FROM books WHERE cover IS NOT NULL AND TRIM(cover) <> ''"
);
$withCover = (int)$withCoverRes->fetch_assoc()['c'];

$noCover = $total - $withCover;

echo "Total books:            {$total}" . PHP_EOL;
echo "Books WITH cover set:   {$withCover}" . PHP_EOL;
echo "Books WITHOUT cover:    {$noCover}" . PHP_EOL;
echo PHP_EOL;

// Of the books actually referencing a cover filename, how many of those
// filenames exist as real files under assets/covers/?
if ($withCover > 0) {
    $coverRes = $conn->query(
        "SELECT book_id, cover FROM books WHERE cover IS NOT NULL AND TRIM(cover) <> ''"
    );
    $missingFile = 0;
    $existsFile = 0;
    $missingList = [];
    while ($row = $coverRes->fetch_assoc()) {
        $name = basename((string)$row['cover']);
        $full = __DIR__ . '/../assets/covers/' . $name;
        if (is_file($full)) {
            $existsFile++;
        } else {
            $missingFile++;
            $missingList[] = $row;
        }
    }
    echo "Of the {$withCover} books with a cover value set:" . PHP_EOL;
    echo "  -> file actually exists in assets/covers/: {$existsFile}" . PHP_EOL;
    echo "  -> file MISSING from assets/covers/:        {$missingFile}" . PHP_EOL;
    if ($missingFile > 0) {
        echo PHP_EOL . "  Books whose cover file is missing:" . PHP_EOL;
        foreach ($missingList as $r) {
            echo "    #{$r['book_id']}  cover='{$r['cover']}'" . PHP_EOL;
        }
    }
    echo PHP_EOL;
}

// Of the books with NO cover, how many have a usable ISBN vs. would need
// title/author matching?
$isbnRes = $conn->query(
    "SELECT COUNT(*) AS c FROM books
     WHERE (cover IS NULL OR TRIM(cover) = '')
       AND isbn IS NOT NULL AND TRIM(isbn) <> ''"
);
$withIsbn = (int)$isbnRes->fetch_assoc()['c'];
$noIsbn = $noCover - $withIsbn;

echo "Of the {$noCover} books with no cover:" . PHP_EOL;
echo "  -> have a non-empty ISBN (lookup by ISBN possible): {$withIsbn}" . PHP_EOL;
echo "  -> no ISBN (would need title/author matching):      {$noIsbn}" . PHP_EOL;
echo PHP_EOL;

if ($sampleSize > 0) {
    $sampleRes = $conn->query(
        "SELECT book_id, title, author, isbn, publisher, year_published
         FROM books
         WHERE cover IS NULL OR TRIM(cover) = ''
         ORDER BY book_id
         LIMIT {$sampleSize}"
    );
    echo "Sample of {$sampleSize} books with no cover:" . PHP_EOL;
    while ($row = $sampleRes->fetch_assoc()) {
        echo sprintf(
            "  #%-6d \"%s\" by %s | isbn=%s | publisher=%s | year=%s",
            $row['book_id'],
            $row['title'],
            $row['author'],
            $row['isbn'] !== null && trim((string)$row['isbn']) !== '' ? trim((string)$row['isbn']) : '(none)',
            $row['publisher'] !== null && trim((string)$row['publisher']) !== '' ? trim((string)$row['publisher']) : '(none)',
            $row['year_published'] !== null && trim((string)$row['year_published']) !== '' ? trim((string)$row['year_published']) : '(none)'
        ) . PHP_EOL;
    }
}
?>
