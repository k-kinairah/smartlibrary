<?php
declare(strict_types=1);

// Writes a short, generic, AI-generated blurb for any book that STILL has
// no description after tools/fetch_book_descriptions.php's real-source pass
// (Open Library, then Google Books) has already run and found nothing.
// Always run that script first -- this one is the fallback for whatever it
// couldn't match at all, or matched but found no description text for.
//
// The model is given ONLY verified catalog metadata -- title, author,
// subject category, academic program, year -- and is explicitly told never
// to invent plot details, characters, specific events, or any other claim
// that metadata doesn't support. See config/ai_description_helpers.php for
// the exact prompt and config/ai_config.php for the model/pricing in use
// (OpenAI, ~$0.10/1M input + $0.50/1M output tokens as of Oct 2026 -- the
// whole catalog costs well under $1 to run).
//
// Safe by default: dry run unless --commit is passed.
//
// Usage:
//   php tools/generate_ai_descriptions.php                (dry run, all books still missing a description)
//   php tools/generate_ai_descriptions.php --commit
//   php tools/generate_ai_descriptions.php --limit=50       (staged test batch)
//   php tools/generate_ai_descriptions.php --book_id=1234    (single book, any mode)
//   php tools/generate_ai_descriptions.php --min_book_id=300  (resume an interrupted run at book_id 300)
//
// All of the actual prompting/API-call logic (the adh_* functions) AND the
// batch driver that walks the catalog (adh_generate_descriptions_batch())
// live in config/ai_description_helpers.php, mirroring how
// config/description_fetch_helpers.php and config/cover_fetch_helpers.php
// are shared with their own CLI tools. This file is just the CLI-facing
// wrapper: parse flags, call the shared batch function, print the result.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (!function_exists('curl_init')) {
    fwrite(STDERR, "The PHP curl extension is not enabled. In XAMPP: php.ini -> uncomment ';extension=curl' -> restart Apache/CLI." . PHP_EOL);
    exit(1);
}

require __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/ai_description_helpers.php';

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
echo (defined('AI_EMBEDDING_API_KEY') && AI_EMBEDDING_API_KEY !== ''
    ? 'OpenAI chat model: ' . AI_CHAT_MODEL
    : 'AI description generation: disabled -- no API key configured (see config/ai_config.php)') . PHP_EOL;
echo PHP_EOL;

$batch = adh_generate_descriptions_batch($conn, [
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
    if ($r['status'] === 'generated') {
        echo sprintf('  #%-6d "%s" -- GENERATED', $r['book_id'], $r['title']) . PHP_EOL;
        echo '      -> ' . $r['description'] . PHP_EOL;
    } else {
        echo sprintf('  #%-6d "%s" -- %s', $r['book_id'], $r['title'], $r['detail']) . PHP_EOL;
    }
}

$c = $batch['counters'];
echo PHP_EOL;
echo 'Generated:                     ' . $c['generated'] . PHP_EOL;
echo 'Skipped (empty/unusable text): ' . $c['skipped_empty_output'] . PHP_EOL;
echo 'Skipped (API call failed):     ' . $c['skipped_api_error'] . PHP_EOL;
if ($commit) {
    echo 'Errors writing to DB:          ' . $c['errors'] . PHP_EOL;
}
echo PHP_EOL;

echo $commit
    ? 'Done.'
    : 'Dry run complete -- re-run with --commit to actually save these descriptions.';
echo PHP_EOL;
?>
