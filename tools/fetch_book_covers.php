<?php
declare(strict_types=1);

// Finds and downloads real cover images for books that don't have one yet.
// Tries two free, no-API-key sources in order -- Open Library first, then
// Google Books as a fallback for whatever Open Library couldn't find:
//
//   1. Open Library ISBN lookup -- if the book has an ISBN, look up that
//      ISBN directly (https://covers.openlibrary.org/b/isbn/{isbn}-L.jpg).
//      Deterministic, non-fuzzy: if Open Library has a cover filed under
//      that exact ISBN, it's the right cover.
//   2. Open Library title+author search (https://openlibrary.org/search.json)
//      -- only if step 1 found nothing. Accepts the top result only if BOTH
//      the title is a close match (normalized similarity >=
//      TITLE_SIMILARITY_THRESHOLD) AND the book's author surname appears in
//      the result's author list.
//   3. Google Books ISBN lookup (https://www.googleapis.com/books/v1/volumes)
//      -- only if steps 1-2 found nothing. Same deterministic trust model as
//      step 1, plus an extra check that the returned record's own ISBN
//      actually matches ours (Google's isbn: search can occasionally rank a
//      different edition first).
//   4. Google Books title+author search -- only if steps 1-3 found nothing.
//      Same confidence gating as step 2.
//
// Every step above is tried, in order, until one succeeds; a book already
// matched by an earlier step never reaches a later one, and Google is never
// contacted at all for a book Open Library already solved. Matched images
// are downloaded, sanity-checked (real JPEG/PNG bytes, minimum size -- rules
// out tiny "not found" placeholder icons -- and portrait-or-square
// orientation -- rejects landscape images, since a real book cover is
// essentially never landscape; both sources occasionally have an
// unrelated/wrong image attached to an otherwise correct title+author
// match, and this catches nearly all of those), saved under assets/covers/
// as cover_<book_id>.<ext>, and books.cover is set to that filename. Safe by
// default: dry-run unless --commit is passed.
//
// Usage:
//   php tools/fetch_book_covers.php                 (dry run, all books with no cover)
//   php tools/fetch_book_covers.php --commit
//   php tools/fetch_book_covers.php --limit=50               (staged test batch)
//   php tools/fetch_book_covers.php --isbn-only               (skip title/author fallback)
//   php tools/fetch_book_covers.php --book_id=1234             (single book, any mode)
//
// All of the actual matching logic (the fbc_* functions), the shared
// constants, and the Google API key loading live in
// config/cover_fetch_helpers.php now, so admin/manage_books.php can reuse
// the exact same matching rules for its single-book "fetch cover now" /
// "re-fetch cover" actions. This file's own driver loop below (the part
// that walks every book with no cover, prints match lines, and tallies the
// run summary) is unchanged from before that split.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (!function_exists('curl_init')) {
    fwrite(STDERR, "The PHP curl extension is not enabled. In XAMPP: php.ini -> uncomment ';extension=curl' -> restart Apache/CLI." . PHP_EOL);
    exit(1);
}

require __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/cover_fetch_helpers.php';

$commit = false;
$limit = null;
$isbnOnly = false;
$onlyBookId = null;
foreach ($argv as $arg) {
    if ($arg === '--commit') {
        $commit = true;
    } elseif ($arg === '--isbn-only') {
        $isbnOnly = true;
    } elseif (preg_match('/^--limit=(\d+)$/', (string)$arg, $m)) {
        $limit = (int)$m[1];
    } elseif (preg_match('/^--book_id=(\d+)$/', (string)$arg, $m)) {
        $onlyBookId = (int)$m[1];
    }
}

// --- Build the work list ---

$where = "(cover IS NULL OR TRIM(cover) = '')";
if ($onlyBookId !== null) {
    $where .= " AND book_id = " . (int)$onlyBookId;
}
$sql = "SELECT book_id, title, author, isbn FROM books WHERE {$where} ORDER BY book_id";
if ($limit !== null) {
    $sql .= " LIMIT {$limit}";
}
$res = $conn->query($sql);
if (!$res) {
    fwrite(STDERR, 'Query failed: ' . $conn->error . PHP_EOL);
    exit(1);
}
$books = [];
while ($row = $res->fetch_assoc()) {
    $books[] = $row;
}

echo 'Books with no cover to process: ' . count($books) . PHP_EOL;
echo ($commit ? '*** COMMIT MODE -- files will be saved and books.cover will be updated ***' : '(DRY RUN -- pass --commit to actually download and save)') . PHP_EOL;
echo (GOOGLE_BOOKS_API_KEY !== ''
    ? 'Google Books fallback: enabled'
    : 'Google Books fallback: disabled -- no API key configured (see config/google_books_config.php)') . PHP_EOL;
echo PHP_EOL;

if (!is_dir(COVERS_DIR)) {
    if ($commit) {
        mkdir(COVERS_DIR, 0755, true);
    }
}

$isbnMatched = 0;
$titleAuthorMatched = 0;
$googleIsbnMatched = 0;
$googleTitleAuthorMatched = 0;
$skippedNoMatch = 0;
$skippedMatchedNoCover = 0;
$skippedExcluded = 0;
$googleRateLimited = 0;
$errors = 0;
$skippedDetails = [];

foreach ($books as $i => $b) {
    $bookId = (int)$b['book_id'];
    $title = (string)$b['title'];
    $author = (string)$b['author'];
    $isbn = trim((string)($b['isbn'] ?? ''));

    if (array_key_exists($bookId, EXCLUDED_BOOK_IDS)) {
        $skippedExcluded++;
        $skippedDetails[] = sprintf(
            '  #%-6d "%s" -- manually excluded, known bad match (%s)',
            $bookId, $title, EXCLUDED_BOOK_IDS[$bookId]
        );
        continue;
    }

    $found = null;
    $matchedButNoCoverLogged = false;

    if ($isbn !== '') {
        $found = fbc_try_isbn($isbn);
        usleep(REQUEST_SLEEP_MICROSECONDS);
    }

    $matchKind = $found !== null ? 'isbn' : null;

    if ($found === null && !$isbnOnly) {
        $result = fbc_try_title_author($title, $author);
        usleep(REQUEST_SLEEP_MICROSECONDS);
        if ($result !== null && isset($result['no_cover_for_match'])) {
            $skippedMatchedNoCover++;
            $matchedButNoCoverLogged = true;
            $skippedDetails[] = sprintf(
                '  #%-6d "%s" -- matched "%s" (%.0f%% similar) but Open Library has no cover image for it',
                $bookId, $title, $result['matched_title'], $result['similarity']
            );
        } elseif ($result !== null) {
            $found = $result;
            $matchKind = 'title_author';
        }
    }

    // --- Google Books fallback: only reached when Open Library (above) found
    // nothing at all for this book. Not attempted for a book already matched. ---

    if ($found === null && $isbn !== '') {
        $gResult = fbc_try_google_isbn($isbn);
        usleep(REQUEST_SLEEP_MICROSECONDS);
        if ($gResult !== null && isset($gResult['rate_limited'])) {
            $googleRateLimited++;
        } elseif ($gResult !== null && isset($gResult['no_cover_for_match'])) {
            if (!$matchedButNoCoverLogged) {
                $skippedMatchedNoCover++;
                $matchedButNoCoverLogged = true;
                $skippedDetails[] = sprintf(
                    '  #%-6d "%s" -- Google Books has a record for ISBN %s but no cover image for it',
                    $bookId, $title, $isbn
                );
            }
        } elseif ($gResult !== null) {
            $found = $gResult;
            $matchKind = 'google_isbn';
        }
    }

    if ($found === null && !$isbnOnly) {
        $gResult = fbc_try_google_title_author($title, $author);
        usleep(REQUEST_SLEEP_MICROSECONDS);
        if ($gResult !== null && isset($gResult['rate_limited'])) {
            $googleRateLimited++;
        } elseif ($gResult !== null && isset($gResult['no_cover_for_match'])) {
            if (!$matchedButNoCoverLogged) {
                $skippedMatchedNoCover++;
                $matchedButNoCoverLogged = true;
                $skippedDetails[] = sprintf(
                    '  #%-6d "%s" -- matched "%s" (%.0f%% similar) but Google Books has no cover image for it',
                    $bookId, $title, $gResult['matched_title'], $gResult['similarity']
                );
            }
        } elseif ($gResult !== null) {
            $found = $gResult;
            $matchKind = 'google_title_author';
        }
    }

    if ($found === null) {
        if ($matchKind === null && !$matchedButNoCoverLogged) {
            $skippedNoMatch++;
            $skippedDetails[] = sprintf(
                '  #%-6d "%s" by %s [isbn=%s] -- no confident match found',
                $bookId, $title, $author, $isbn !== '' ? $isbn : '(none)'
            );
        }
        continue;
    }

    if ($matchKind === 'isbn') {
        $isbnMatched++;
    } elseif ($matchKind === 'title_author') {
        $titleAuthorMatched++;
    } elseif ($matchKind === 'google_isbn') {
        $googleIsbnMatched++;
    } else {
        $googleTitleAuthorMatched++;
    }

    $filename = 'cover_' . $bookId . '.' . $found['ext'];
    echo sprintf('  #%-6d "%s" -- MATCH via %s -> %s', $bookId, $title, $found['via'], $filename) . PHP_EOL;

    if ($commit) {
        $fullPath = COVERS_DIR . '/' . $filename;
        $written = file_put_contents($fullPath, $found['bytes']);
        if ($written === false) {
            fwrite(STDERR, "  Failed to write {$fullPath}" . PHP_EOL);
            $errors++;
            continue;
        }
        $stmt = $conn->prepare('UPDATE books SET cover = ? WHERE book_id = ?');
        $stmt->bind_param('si', $filename, $bookId);
        if (!$stmt->execute()) {
            fwrite(STDERR, "  DB update failed for book #{$bookId}: " . $stmt->error . PHP_EOL);
            $errors++;
        }
        $stmt->close();
    }
}

echo PHP_EOL;
echo 'Matched via Open Library (ISBN):          ' . $isbnMatched . PHP_EOL;
echo 'Matched via Open Library (title/author):  ' . $titleAuthorMatched . PHP_EOL;
echo 'Matched via Google Books (ISBN):          ' . $googleIsbnMatched . PHP_EOL;
echo 'Matched via Google Books (title/author):  ' . $googleTitleAuthorMatched . PHP_EOL;
echo 'Skipped (matched, no cover available):    ' . $skippedMatchedNoCover . PHP_EOL;
echo 'Skipped (no confident match):             ' . $skippedNoMatch . PHP_EOL;
echo 'Skipped (manually excluded):              ' . $skippedExcluded . PHP_EOL;
if ($googleRateLimited > 0) {
    echo 'Google Books rate-limited (retry later, re-run to pick these up): ' . $googleRateLimited . PHP_EOL;
}
if ($commit) {
    echo 'Errors writing/updating:                  ' . $errors . PHP_EOL;
}
echo PHP_EOL;

if (!empty($skippedDetails)) {
    echo 'Skipped books (for manual review):' . PHP_EOL;
    foreach ($skippedDetails as $line) {
        echo $line . PHP_EOL;
    }
}

echo PHP_EOL;
echo $commit
    ? 'Done.'
    : 'Dry run complete -- re-run with --commit to actually download covers and update the database.';
echo PHP_EOL;
?>
