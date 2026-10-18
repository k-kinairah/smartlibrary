<?php
declare(strict_types=1);

// Read-only diagnostic: groups every uncategorized book (category_id IS
// NULL/0) by the Library of Congress classification prefix in its call
// number (e.g. "RC 78.7 P37 2011" -> "RC", "Fil KPM 1030.3 M37 2007" ->
// "KPM", after stripping a leading "Fil"/"GEN"/"REF" section marker).
//
// This does NOT assign any category -- it just shows how the uncategorized
// books cluster by call number, with a few sample titles per cluster, so a
// human can review and decide which category each prefix really belongs
// to (LC prefixes are usually far more specific than this catalog's
// category list, e.g. "HV" is criminology/social-welfare law, "KF" is US
// law, "HD" is business/industry -- several different categories here can
// share the same broad single-letter class, so the actual LC prefix is a
// much better signal than that single letter alone).
//
// Usage:
//   php tools/suggest_categories_from_call_numbers.php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config/db_connect.php';

function scn_extract_prefix(string $callNumber): string {
    $c = trim($callNumber);
    if ($c === '') {
        return '(no call number)';
    }
    $c = preg_replace('/\s+/', ' ', $c) ?? $c;
    // Strip a leading section marker (Filipiniana, General collection,
    // Reference, Reserve) -- these aren't part of the LC classification
    // itself, just where the shelf physically lives.
    $c = preg_replace('/^(FIL|GEN|REF|RES)\.?\s+/i', '', $c) ?? $c;
    if (preg_match('/^([A-Za-z]{1,3})\s*[\d.]/', $c, $m)) {
        return strtoupper($m[1]);
    }
    return '(unrecognized: ' . $c . ')';
}

// 2026-10-10: hardcoded -- see index.php for why. books.category_id is
// confirmed present in smartlib_FULL_BACKUP_20261010.sql.
$hasCategoryId = true;
if (!$hasCategoryId) {
    fwrite(STDERR, 'books.category_id column not found.' . PHP_EOL);
    exit(1);
}

$res = $conn->query(
    "SELECT book_id, title, author, call_number
     FROM books
     WHERE category_id IS NULL OR category_id = 0
     ORDER BY call_number"
);
if (!$res) {
    fwrite(STDERR, 'Query failed: ' . $conn->error . PHP_EOL);
    exit(1);
}

$groups = [];
$total = 0;
while ($row = $res->fetch_assoc()) {
    $total++;
    $prefix = scn_extract_prefix((string)($row['call_number'] ?? ''));
    $groups[$prefix][] = $row;
}

echo "Total uncategorized books: {$total}" . PHP_EOL;
echo 'Distinct call-number prefixes among them: ' . count($groups) . PHP_EOL;
echo PHP_EOL;

// Existing categories, for reference while reviewing the groups below.
$catRes = $conn->query('SELECT category_name FROM categories ORDER BY category_name');
if ($catRes && $catRes->num_rows > 0) {
    echo 'Existing categories in this catalog:' . PHP_EOL;
    $names = [];
    while ($c = $catRes->fetch_assoc()) {
        $names[] = (string)$c['category_name'];
    }
    echo '  ' . implode(', ', $names) . PHP_EOL . PHP_EOL;
}

uasort($groups, static fn($a, $b) => count($b) <=> count($a));

echo 'Prefix groups, largest first (up to 4 sample titles each):' . PHP_EOL;
echo PHP_EOL;
foreach ($groups as $prefix => $rows) {
    echo sprintf('%-24s %5d book(s)', $prefix, count($rows)) . PHP_EOL;
    foreach (array_slice($rows, 0, 4) as $r) {
        echo '    #' . $r['book_id'] . '  "' . $r['title'] . '" by ' . $r['author'] . '  [' . $r['call_number'] . ']' . PHP_EOL;
    }
    if (count($rows) > 4) {
        echo '    ... and ' . (count($rows) - 4) . ' more' . PHP_EOL;
    }
    echo PHP_EOL;
}
