<?php
declare(strict_types=1);

// Finds and fills in real descriptions for books that don't have one yet,
// pulling from two free sources in order -- Open Library first (no API key,
// no quota), then Google Books as a fallback (your own key, see
// config/google_books_config.php -- 1,000 free queries/day, shared with
// tools/fetch_book_covers.php since both use the same key):
//
//   1. Open Library ISBN lookup (openlibrary.org/isbn/{isbn}.json, falling
//      back to that edition's work record, which usually carries the actual
//      description) -- deterministic, non-fuzzy.
//   2. Open Library title+author search -- only if step 1 found nothing.
//      Same confidence gating fetch_book_covers.php uses for its own
//      fuzzy matches (title similarity >= 90%, author surname must appear).
//   3. Google Books ISBN lookup -- only if steps 1-2 found nothing, plus an
//      ISBN-ownership check (Google's isbn: search can rank a different
//      edition first than the one you actually have).
//   4. Google Books title+author search -- only if steps 1-3 found nothing.
//
// A book already matched by an earlier step never reaches a later one, and
// Google is never contacted at all for a book Open Library already solved.
// Fetched text is stripped of HTML, decoded, and trimmed to a tidy blurb
// length at a sentence boundary where possible. Safe by default: dry run
// unless --commit is passed.
//
// Any book this still can't find a real description for is left untouched
// here -- see tools/generate_ai_descriptions.php for the AI-written
// fallback pass over whatever's left after this one has run.
//
// Usage:
//   php tools/fetch_book_descriptions.php                (dry run, all books missing a description)
//   php tools/fetch_book_descriptions.php --commit
//   php tools/fetch_book_descriptions.php --limit=50       (staged test batch)
//   php tools/fetch_book_descriptions.php --book_id=1234    (single book, any mode)
//   php tools/fetch_book_descriptions.php --min_book_id=300  (resume an interrupted run at book_id 300)
//
// All of the actual matching logic (the fdc_* functions) AND the batch
// driver that walks the catalog (fdc_fetch_descriptions_batch()) live in
// config/description_fetch_helpers.php, mirroring how
// config/cover_fetch_helpers.php is shared between the cover-fetch job and
// admin/manage_books.php. This file is just the CLI-facing wrapper: parse
// flags, call the shared batch function, print the result.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (!function_exists('curl_init')) {
    fwrite(STDERR, "The PHP curl extension is not enabled. In XAMPP: php.ini -> uncomment ';extension=curl' -> restart Apache/CLI." . PHP_EOL);
    exit(1);
}

require __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/description_fetch_helpers.php';

$commit = false;
$limit = null;
$onlyBookId = null;
$minBookId = null;
foreach ($argv as $arg) {
    if ($arg === '--commit') {
        $commit = true;
    } elseif (preg_match('/^--limit=(\d+)$/', (string)$arg, $m)) {
        $limit = (int)$m[1];
    } elseif (preg_match('/^--book_id=(\d+)$/', (string)$arg, $m)) {
        $onlyBookId = (int)$m[1];
    } elseif (preg_match('/^--min_book_id=(\d+)$/', (string)$arg, $m)) {
        $minBookId = (int)$m[1];
    }
}

echo ($commit ? '*** COMMIT MODE -- books.description will be updated ***' : '(DRY RUN -- pass --commit to actually save descriptions)') . PHP_EOL;
echo (GOOGLE_BOOKS_API_KEY !== ''
    ? 'Google Books fallback: enabled'
    : 'Google Books fallback: disabled -- no API key configured (see config/google_books_config.php)') . PHP_EOL;
echo PHP_EOL;

$batch = fdc_fetch_descriptions_batch($conn, [
    'commit' => $commit,
    'limit' => $limit,
    'book_id' => $onlyBookId,
    'min_book_id' => $minBookId,
]);

if (isset($batch['error'])) {
    fwrite(STDERR, 'Query failed: ' . $batch['error'] . PHP_EOL);
    exit(1);
}

echo 'Books missing a description to process: ' . $batch['book_count'] . PHP_EOL . PHP_EOL;

foreach ($batch['results'] as $r) {
    if ($r['status'] === 'matched') {
        echo sprintf('  #%-6d "%s" -- MATCH via %s [%s]', $r['book_id'], $r['title'], $r['via'], $r['source_tag']) . PHP_EOL;
        echo '      -> ' . $r['description'] . PHP_EOL;
    } else {
        echo sprintf('  #%-6d "%s" -- %s', $r['book_id'], $r['title'], $r['detail']) . PHP_EOL;
    }
}

$c = $batch['counters'];
echo PHP_EOL;
echo 'Matched via Open Library (ISBN):             ' . $c['open_library_isbn'] . PHP_EOL;
echo 'Matched via Open Library (title/author):     ' . $c['open_library_title_author'] . PHP_EOL;
echo 'Matched via Google Books (ISBN):              ' . $c['google_isbn'] . PHP_EOL;
echo 'Matched via Google Books (title/author):      ' . $c['google_title_author'] . PHP_EOL;
echo 'Skipped (matched, no description available):  ' . $c['skipped_matched_no_description'] . PHP_EOL;
echo 'Skipped (no confident match):                 ' . $c['skipped_no_match'] . PHP_EOL;
if ($c['google_rate_limited'] > 0) {
    echo 'Google Books rate-limited (retry later, re-run to pick these up): ' . $c['google_rate_limited'] . PHP_EOL;
}
if ($commit) {
    echo 'Errors writing to DB:                         ' . $c['errors'] . PHP_EOL;
}
echo PHP_EOL;

echo $commit
    ? 'Done.'
    : 'Dry run complete -- re-run with --commit to actually save these descriptions.';
echo PHP_EOL;
?>
