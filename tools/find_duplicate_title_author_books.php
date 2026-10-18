<?php
declare(strict_types=1);

// Read-only diagnostic: finds groups of `books` rows that share the exact
// same normalized title + author -- i.e. more than one book_id for what
// looks like the same catalog title. This is NOT itself a bug detector for
// every case (a library can legitimately hold two different editions of
// the same title by the same author), but in this catalog most such
// clusters are leftover duplicate records created by an import run that
// (before a fix) didn't track which title+author pairs it had already
// created book records for during that same run.
//
// For each duplicate cluster this prints every book_id in it along with
// isbn / year / publisher / call_number / copy_count / created_at, so a
// human (or a follow-up merge script) can tell apart:
//   - a true duplicate (near-identical isbn/year/publisher/call_number,
//     often created seconds apart) -> merge: re-point book_copies to one
//     book_id, delete the rest.
//   - a genuinely different edition (different year/isbn/publisher)
//     -> leave alone, it's real.
//
// Usage:
//   php tools/find_duplicate_title_author_books.php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config/db_connect.php';

function fdtab_norm(string $s): string {
    $s = trim($s);
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    return mb_strtolower($s);
}

function fdtab_field(?string $v, int $max = 30): string {
    if ($v === null || trim($v) === '') {
        return '(none)';
    }
    $v = trim($v);
    return mb_strlen($v) > $max ? mb_substr($v, 0, $max - 3) . '...' : $v;
}

$sql = "SELECT b.book_id, b.isbn, b.title, b.author, b.publisher, b.year_published,
               b.call_number, b.created_at,
               (SELECT COUNT(*) FROM book_copies bc WHERE bc.book_id = b.book_id) AS copy_count
        FROM books b
        ORDER BY b.book_id";
$res = $conn->query($sql);
if (!$res) {
    fwrite(STDERR, 'Query failed: ' . $conn->error . PHP_EOL);
    exit(1);
}

$clusters = [];
$totalBooks = 0;
while ($row = $res->fetch_assoc()) {
    $totalBooks++;
    $key = fdtab_norm((string)$row['title']) . '|' . fdtab_norm((string)$row['author']);
    $clusters[$key][] = $row;
}

$dupClusters = array_filter($clusters, static fn($rows) => count($rows) > 1);

$excessBooks = 0;
$zeroCopyDupes = 0;
foreach ($dupClusters as $rows) {
    $excessBooks += count($rows) - 1;
    foreach ($rows as $r) {
        if ((int)$r['copy_count'] === 0) {
            $zeroCopyDupes++;
        }
    }
}

echo 'Total books in catalog: ' . $totalBooks . PHP_EOL;
echo 'Distinct title+author clusters: ' . count($clusters) . PHP_EOL;
echo 'Clusters with more than one book_id (possible duplicates): ' . count($dupClusters) . PHP_EOL;
echo 'Total "excess" book records across those clusters (cluster size - 1, summed): ' . $excessBooks . PHP_EOL;
echo 'Of which have ZERO copies attached (safe to delete outright, nothing to re-point): ' . $zeroCopyDupes . PHP_EOL;
echo PHP_EOL;

if (empty($dupClusters)) {
    echo 'No title+author duplicate clusters found.' . PHP_EOL;
    exit(0);
}

$i = 0;
foreach ($dupClusters as $rows) {
    $i++;
    usort($rows, static fn($a, $b) => $a['book_id'] <=> $b['book_id']);
    echo "Cluster #{$i}: \"{$rows[0]['title']}\" by {$rows[0]['author']} (" . count($rows) . ' book_ids)' . PHP_EOL;
    foreach ($rows as $r) {
        echo sprintf(
            "  book_id=%-6d copies=%-3d isbn=%-16s year=%-6s publisher=%-25s call_number=%-25s created_at=%s\n",
            $r['book_id'],
            $r['copy_count'],
            fdtab_field($r['isbn'], 16),
            fdtab_field($r['year_published'], 6),
            fdtab_field($r['publisher'], 25),
            fdtab_field($r['call_number'], 25),
            $r['created_at']
        );
    }
    echo PHP_EOL;
}
