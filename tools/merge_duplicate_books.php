<?php
declare(strict_types=1);

// One-off (but reusable) cleanup: merges a duplicate `books` record into a
// canonical one -- re-points every `book_copies` row from the duplicate
// onto the canonical book_id, copies over the canonical's isbn/call_number
// if the canonical is missing one but the duplicate has it, then deletes
// the now-empty duplicate `books` row.
//
// SAFE BY DEFAULT: dry-run unless --commit is passed. Always run once
// without --commit first and read the summary before committing.
//
// Usage:
//   php tools/merge_duplicate_books.php --pair=<keepId>:<mergeId> [--pair=<keepId>:<mergeId> ...] [--commit]
//
// Example (merging 3 pairs found by tools/find_duplicate_title_author_books.php):
//   php tools/merge_duplicate_books.php --pair=1719:2008 --pair=1981:1980 --pair=1998:2047 --commit

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config/db_connect.php';

function mdb_norm(string $s): string {
    $s = trim($s);
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    return mb_strtolower($s);
}

$commit = in_array('--commit', $argv, true);
$pairs = [];
foreach ($argv as $arg) {
    if (preg_match('/^--pair=(\d+):(\d+)$/', (string)$arg, $m)) {
        $pairs[] = ['keep' => (int)$m[1], 'merge' => (int)$m[2]];
    }
}

if (empty($pairs)) {
    fwrite(STDERR, 'Usage: php tools/merge_duplicate_books.php --pair=<keepId>:<mergeId> [--pair=... ...] [--commit]' . PHP_EOL);
    exit(1);
}

echo 'Mode: ' . ($commit ? 'COMMIT (writing to the database)' : 'DRY RUN (nothing will be saved -- pass --commit to actually merge)') . PHP_EOL;
echo '';

$fetchBook = $conn->prepare('SELECT book_id, title, author, isbn, call_number FROM books WHERE book_id = ?');
$countCopies = $conn->prepare('SELECT COUNT(*) AS c FROM book_copies WHERE book_id = ?');
$moveCopies = $conn->prepare('UPDATE book_copies SET book_id = ? WHERE book_id = ?');
$fillIsbn = $conn->prepare("UPDATE books SET isbn = ? WHERE book_id = ? AND (isbn IS NULL OR isbn = '')");
$fillCallNumber = $conn->prepare("UPDATE books SET call_number = ? WHERE book_id = ? AND (call_number IS NULL OR call_number = '')");
$deleteBook = $conn->prepare('DELETE FROM books WHERE book_id = ?');

if (!$fetchBook || !$countCopies || !$moveCopies || !$deleteBook) {
    fwrite(STDERR, 'Could not prepare statements: ' . $conn->error . PHP_EOL);
    exit(1);
}

foreach ($pairs as $pair) {
    $keepId = $pair['keep'];
    $mergeId = $pair['merge'];

    echo "--- Merging book_id {$mergeId} into book_id {$keepId} ---" . PHP_EOL;

    $fetchBook->bind_param('i', $keepId);
    $fetchBook->execute();
    $keepRow = $fetchBook->get_result()->fetch_assoc();

    $fetchBook->bind_param('i', $mergeId);
    $fetchBook->execute();
    $mergeRow = $fetchBook->get_result()->fetch_assoc();

    if (!$keepRow) {
        echo "  SKIPPED: keep book_id {$keepId} does not exist (already merged/deleted?)." . PHP_EOL . PHP_EOL;
        continue;
    }
    if (!$mergeRow) {
        echo "  SKIPPED: merge book_id {$mergeId} does not exist (already merged/deleted?)." . PHP_EOL . PHP_EOL;
        continue;
    }

    if (mdb_norm((string)$keepRow['title']) !== mdb_norm((string)$mergeRow['title'])
        || mdb_norm((string)$keepRow['author']) !== mdb_norm((string)$mergeRow['author'])) {
        echo "  SKIPPED (safety check failed): titles/authors don't match." . PHP_EOL;
        echo "    keep  #{$keepId}: \"{$keepRow['title']}\" by {$keepRow['author']}" . PHP_EOL;
        echo "    merge #{$mergeId}: \"{$mergeRow['title']}\" by {$mergeRow['author']}" . PHP_EOL . PHP_EOL;
        continue;
    }

    $countCopies->bind_param('i', $keepId);
    $countCopies->execute();
    $keepCopies = (int)($countCopies->get_result()->fetch_assoc()['c'] ?? 0);

    $countCopies->bind_param('i', $mergeId);
    $countCopies->execute();
    $mergeCopies = (int)($countCopies->get_result()->fetch_assoc()['c'] ?? 0);

    echo "  \"{$keepRow['title']}\" by {$keepRow['author']}" . PHP_EOL;
    echo "    keep  #{$keepId}: isbn=" . ($keepRow['isbn'] ?: '(none)') . ", call_number=" . ($keepRow['call_number'] ?: '(none)') . ", {$keepCopies} copies" . PHP_EOL;
    echo "    merge #{$mergeId}: isbn=" . ($mergeRow['isbn'] ?: '(none)') . ", call_number=" . ($mergeRow['call_number'] ?: '(none)') . ", {$mergeCopies} copies" . PHP_EOL;
    echo '    ' . ($commit ? 'Moving' : 'Would move') . " {$mergeCopies} copies from #{$mergeId} to #{$keepId}, then deleting #{$mergeId}." . PHP_EOL;

    if ($commit) {
        $conn->begin_transaction();
        try {
            $moveCopies->bind_param('ii', $keepId, $mergeId);
            $moveCopies->execute();

            if (empty($keepRow['isbn']) && !empty($mergeRow['isbn'])) {
                $fillIsbn->bind_param('si', $mergeRow['isbn'], $keepId);
                $fillIsbn->execute();
                echo "    Copied isbn '{$mergeRow['isbn']}' onto #{$keepId} (it had none)." . PHP_EOL;
            }
            if (empty($keepRow['call_number']) && !empty($mergeRow['call_number'])) {
                $fillCallNumber->bind_param('si', $mergeRow['call_number'], $keepId);
                $fillCallNumber->execute();
                echo "    Copied call_number '{$mergeRow['call_number']}' onto #{$keepId} (it had none)." . PHP_EOL;
            }

            $deleteBook->bind_param('i', $mergeId);
            $deleteBook->execute();

            $conn->commit();
            echo "    Done. #{$keepId} now holds " . ($keepCopies + $mergeCopies) . ' copies. #' . $mergeId . ' deleted.' . PHP_EOL;
        } catch (Throwable $e) {
            $conn->rollback();
            fwrite(STDERR, "    FAILED, rolled back: " . $e->getMessage() . PHP_EOL);
        }
    }

    echo PHP_EOL;
}

if (!$commit) {
    echo 'This was a DRY RUN -- nothing was written. Re-run with --commit to actually merge.' . PHP_EOL;
}
?>
