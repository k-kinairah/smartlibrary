<?php
declare(strict_types=1);

// Bulk import of physical-copy accession-register data (e.g. exported from
// a spreadsheet like database/librarybooks1_import.tsv) into the real
// `books` and `book_copies` tables.
//
// Input is a TAB-separated file with this exact header row:
//   accession_no  call_number  author  title  isbn  edition  pages
//   no_titles  no_vols  copy_no  publisher  year
//
// Multiple rows that are really the same edition (same title + author +
// call number, ignoring a trailing "c.1"/"c.2"/... copy marker) are
// grouped into ONE books row, with one book_copies row per physical copy
// (per accession number) -- this mirrors how a real library accession
// register works: every physical copy gets its own line and its own
// globally-unique accession number, but they share one catalog record.
//
// category_id and program_id are intentionally left NULL for every book
// this script creates -- this data source has no category/program
// information, and guessing from a call number alone risks the same kind
// of false-positive tools/auto_categorize_books.php already had to be
// tuned away from. These books ARE searchable and borrowable immediately;
// they just won't show up in an admin/kiosk "browse by category" filter
// until someone (a person, or a future AI pass reviewed before applying)
// assigns a category. Run tools/generate_book_embeddings.php and
// tools/auto_categorize_books.php afterward so at least the AI cross-link
// side of things can start working on these once they're tagged.
//
// SAFE BY DEFAULT: this only reads from the database and reports what it
// WOULD do until you pass --commit. Always run it once without --commit
// first and read the summary before committing.
//
// Usage:
//   php tools/import_books_bulk.php database/librarybooks1_import.tsv
//   php tools/import_books_bulk.php database/librarybooks1_import.tsv --commit
//   php tools/import_books_bulk.php database/librarybooks1_import.tsv --commit --limit=50   (test on a small slice first)

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config/db_connect.php';

function ibb_log(string $message): void {
    echo $message . PHP_EOL;
}

function ibb_table_exists(mysqli $conn, string $table): bool {
    // 2026-10-10: hardcoded against a known-good table list instead of
    // querying information_schema. Live SHOW/information_schema lookups
    // crash this MariaDB build while it's running in InnoDB emergency
    // recovery mode (innodb_force_recovery=6) -- confirmed via repeated
    // mysqld crash dumps, including on this exact kind of check. Every
    // name below is confirmed present in smartlib_FULL_BACKUP_20261010.sql
    // (taken moments before the corruption). Revert to a live query once
    // the database has been rebuilt into a fresh data directory.
    static $knownTables = [
        'auth_2fa_challenges', 'auth_login_attempts', 'auth_pin_reset_tokens',
        'books', 'book_categories', 'book_copies', 'book_embeddings',
        'book_programs', 'borrow_records', 'categories',
        'due_soon_reminder_logs', 'favorites', 'library_locations',
        'library_users', 'notifications', 'overdue_reminder_logs',
        'programs', 'recommendation_events', 'search_logs',
        'admin_activity_logs',
    ];
    return in_array($table, $knownTables, true);
}

function ibb_column_exists(mysqli $conn, string $table, string $column): bool {
    // 2026-10-10: see ibb_table_exists() above -- same reason, same fix.
    static $knownColumns = [
        'categories' => ['category_group'],
        'books' => ['location_id', 'call_number', 'publisher', 'category_id', 'program_id'],
        'library_locations' => ['class_code'],
        'borrow_records' => ['renew_count', 'last_renewed_at'],
    ];
    return in_array($column, $knownColumns[$table] ?? [], true);
}

function ibb_norm(string $s): string {
    $s = trim($s);
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    return mb_strtolower($s);
}

// Strips a trailing "c.1", "c. 12", etc copy marker off a call number so
// copies of the same edition group together under one base call number.
function ibb_base_call_number(string $call): string {
    $call = trim($call);
    $stripped = preg_replace('/\s*c\.\s*\d+\s*$/i', '', $call);
    return trim($stripped ?? $call);
}

// --- args ---

$path = $argv[1] ?? '';
if ($path === '' || !is_file($path)) {
    fwrite(STDERR, 'Usage: php tools/import_books_bulk.php <path-to-tsv> [--commit] [--limit=N]' . PHP_EOL);
    exit(1);
}

$commit = in_array('--commit', $argv, true);
$limit = 0;
foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d+)$/', (string)$arg, $m)) {
        $limit = (int)$m[1];
    }
}

ibb_log('Mode: ' . ($commit ? 'COMMIT (writing to the database)' : 'DRY RUN (nothing will be saved -- pass --commit to actually import)'));

// --- schema checks (mirrors admin/manage_books.php's defensive style) ---

$hasCallNumber = ibb_column_exists($conn, 'books', 'call_number');
$hasCategoryId = ibb_column_exists($conn, 'books', 'category_id');
$hasProgramId = ibb_column_exists($conn, 'books', 'program_id');

if (!ibb_table_exists($conn, 'books') || !ibb_table_exists($conn, 'book_copies')) {
    fwrite(STDERR, 'books / book_copies tables not found.' . PHP_EOL);
    exit(1);
}

// --- read and parse the TSV ---

$fh = fopen($path, 'r');
if (!$fh) {
    fwrite(STDERR, "Could not open {$path}" . PHP_EOL);
    exit(1);
}

$header = fgetcsv($fh, 0, "\t");
$expectedHeader = ['accession_no','call_number','author','title','isbn','edition','pages','no_titles','no_vols','copy_no','publisher','year'];
if ($header === false || array_map('trim', $header) !== $expectedHeader) {
    fwrite(STDERR, 'Unexpected header row. Expected: ' . implode(',', $expectedHeader) . PHP_EOL);
    exit(1);
}

$rows = [];
while (($line = fgetcsv($fh, 0, "\t")) !== false) {
    if (count($line) < 12) {
        continue;
    }
    $rows[] = array_combine($expectedHeader, array_slice($line, 0, 12));
}
fclose($fh);

if ($limit > 0) {
    $rows = array_slice($rows, 0, $limit);
}

ibb_log('Rows read from file: ' . count($rows));

// --- group physical copies into editions ---

$groups = [];
$groupOrder = [];
foreach ($rows as $row) {
    $title = trim((string)$row['title']);
    $author = trim((string)$row['author']);
    $baseCall = ibb_base_call_number((string)$row['call_number']);
    $key = ibb_norm($title) . '|' . ibb_norm($author) . '|' . ibb_norm($baseCall);

    if (!isset($groups[$key])) {
        $groups[$key] = [];
        $groupOrder[] = $key;
    }
    $groups[$key][] = $row;
}

ibb_log('Rows group into ' . count($groups) . ' distinct editions (title + author + call number).');

// --- preload existing data for fast, single-pass lookups ---

$existingAccessions = [];
$res = $conn->query('SELECT accession_no FROM book_copies WHERE accession_no IS NOT NULL AND accession_no <> \'\'');
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $existingAccessions[(string)$r['accession_no']] = true;
    }
}
ibb_log('Existing accession numbers already in the database: ' . count($existingAccessions));

$existingByIsbn = [];
$res = $conn->query('SELECT book_id, isbn FROM books WHERE isbn IS NOT NULL AND isbn <> \'\'');
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $existingByIsbn[trim((string)$r['isbn'])] = (int)$r['book_id'];
    }
}

// Each candidate carries its call_number too, not just its book_id -- see
// the ambiguous-match handling below, which uses it to tell apart two
// different editions of the same title+author (e.g. a 1993 printing vs. a
// 2017 one) without having to guess.
$existingByTitleAuthor = [];
$res = $conn->query('SELECT book_id, title, author, call_number FROM books');
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $key = ibb_norm((string)$r['title']) . '|' . ibb_norm((string)$r['author']);
        $existingByTitleAuthor[$key][] = ['book_id' => (int)$r['book_id'], 'call_number' => (string)($r['call_number'] ?? '')];
    }
}

// --- process each edition group ---

$newBooksCreated = 0;
$matchedExistingBooks = 0;
$ambiguousMatches = 0;
$copiesInserted = 0;
$copiesSkippedDuplicateAccession = 0;
$booksFailed = 0;
$copiesFailed = 0;
$ambiguousReport = [];
$dryRunFakeIdCounter = -1;

if ($commit) {
    $conn->begin_transaction();
}

$insertBook = null;
if ($hasCategoryId && $hasProgramId && $hasCallNumber) {
    $insertBook = $conn->prepare(
        "INSERT INTO books (isbn, title, author, publisher, year_published, category_id, program_id, call_number, created_at)
         VALUES (NULLIF(?, ''), ?, ?, ?, ?, NULL, NULL, NULLIF(?, ''), NOW())"
    );
} elseif ($hasCallNumber) {
    $insertBook = $conn->prepare(
        "INSERT INTO books (isbn, title, author, publisher, year_published, call_number, created_at)
         VALUES (NULLIF(?, ''), ?, ?, ?, ?, NULLIF(?, ''), NOW())"
    );
} else {
    $insertBook = $conn->prepare(
        "INSERT INTO books (isbn, title, author, publisher, year_published, created_at)
         VALUES (NULLIF(?, ''), ?, ?, ?, ?, NOW())"
    );
}
if (!$insertBook) {
    fwrite(STDERR, 'Could not prepare book insert: ' . $conn->error . PHP_EOL);
    exit(1);
}

$insertCopy = $conn->prepare(
    "INSERT INTO book_copies (book_id, accession_no, isbn, status, created_at)
     VALUES (?, NULLIF(?, ''), NULLIF(?, ''), 'available', NOW())"
);
if (!$insertCopy) {
    fwrite(STDERR, 'Could not prepare copy insert: ' . $conn->error . PHP_EOL);
    exit(1);
}

$processedGroups = 0;
foreach ($groupOrder as $key) {
    $groupRows = $groups[$key];
    $first = $groupRows[0];

    $title = trim((string)$first['title']);
    $author = trim((string)$first['author']);
    $publisher = trim((string)$first['publisher']);
    $year = trim((string)$first['year']);
    $yearInt = ctype_digit($year) ? (int)$year : 0;
    $baseCall = ibb_base_call_number((string)$first['call_number']);

    // Prefer any non-empty ISBN within the group.
    $isbn = '';
    foreach ($groupRows as $r) {
        if (trim((string)$r['isbn']) !== '') {
            $isbn = trim((string)$r['isbn']);
            break;
        }
    }

    // Decide: existing book, new book, or ambiguous (needs a human).
    $taKey = ibb_norm($title) . '|' . ibb_norm($author);
    $bookId = 0;
    $skipAmbiguous = false;
    if ($isbn !== '' && isset($existingByIsbn[$isbn])) {
        $bookId = $existingByIsbn[$isbn];
        $matchedExistingBooks++;
    } else {
        $candidates = $existingByTitleAuthor[$taKey] ?? [];
        if (count($candidates) === 1) {
            $bookId = $candidates[0]['book_id'];
            $matchedExistingBooks++;
        } elseif (count($candidates) > 1) {
            // Multiple existing books share this exact normalized
            // title+author -- most often genuinely different editions
            // (e.g. a 1993 printing vs. a 2017 one), each correctly its own
            // catalog record. Before giving up, see if exactly one of them
            // has the SAME call number as this edition (after stripping the
            // "c.N" copy suffix, just like grouping does) -- an exact call
            // number match is a reliable, non-guessing way to tell them
            // apart, since a call number is effectively that edition's own
            // classmark.
            $callMatches = [];
            foreach ($candidates as $c) {
                if ($baseCall !== '' && ibb_norm(ibb_base_call_number($c['call_number'])) === ibb_norm($baseCall)) {
                    $callMatches[] = $c;
                }
            }

            if (count($callMatches) === 1) {
                $bookId = $callMatches[0]['book_id'];
                $matchedExistingBooks++;
            } else {
                // Still can't tell -- don't guess, and don't create yet
                // another duplicate on top. Skip this edition's copies
                // entirely and report it so a human can merge the existing
                // duplicate(s) (or fix the call number) first. Re-running
                // afterward will then pick these accessions up cleanly.
                $ambiguousMatches++;
                $accNos = implode(',', array_map(static fn($r) => trim((string)$r['accession_no']), $groupRows));
                $candidateIds = implode(',', array_column($candidates, 'book_id'));
                $ambiguousReport[] = "\"{$title}\" by {$author} matches " . count($candidates) . " existing book_id(s): {$candidateIds} -- SKIPPED, not imported. Merge the duplicate book_id(s) first, then re-run to pick up accession(s): {$accNos}";
                $skipAmbiguous = true;
            }
        }
    }

    if ($skipAmbiguous) {
        $processedGroups++;
        continue;
    }

    if ($bookId === 0) {
        // New book record.
        if ($commit) {
            $yearParam = $yearInt > 0 ? (string)$yearInt : '';
            $bindIsbnInsert = static function (string $isbnToUse) use ($insertBook, &$title, &$author, &$publisher, &$yearParam, &$baseCall, $hasCategoryId, $hasProgramId, $hasCallNumber): void {
                if ($hasCategoryId && $hasProgramId && $hasCallNumber) {
                    $insertBook->bind_param('ssssss', $isbnToUse, $title, $author, $publisher, $yearParam, $baseCall);
                } elseif ($hasCallNumber) {
                    $insertBook->bind_param('ssssss', $isbnToUse, $title, $author, $publisher, $yearParam, $baseCall);
                } else {
                    $insertBook->bind_param('sssss', $isbnToUse, $title, $author, $publisher, $yearParam);
                }
            };

            $bindIsbnInsert($isbn);
            // mysqli throws on error by default (PHP 8.1+) rather than
            // returning false, so a single bad row would otherwise crash
            // the whole run and roll back everything processed so far.
            // Catch it, log it, and move on to the next edition instead.
            try {
                $insertBook->execute();
                $bookId = (int)$conn->insert_id;
                $newBooksCreated++;
                $existingByTitleAuthor[$taKey][] = ['book_id' => $bookId, 'call_number' => $baseCall];
            } catch (Throwable $e) {
                if ($isbn === '' || stripos($e->getMessage(), "for key 'isbn'") === false) {
                    fwrite(STDERR, "Failed to insert book \"{$title}\": " . $e->getMessage() . PHP_EOL);
                    $booksFailed++;
                    continue;
                }

                // Some other book already holds this exact ISBN -- either
                // an existing book, or one this same run just inserted a
                // moment ago (this run's own $existingByIsbn map is only
                // seeded from the database at the start, so it doesn't yet
                // know about books it has itself just created). Find out
                // which book, and whether it's really the same title.
                $holderTitle = '';
                $holderAuthor = '';
                $holderId = 0;
                $lookup = $conn->prepare('SELECT book_id, title, author FROM books WHERE isbn = ? LIMIT 1');
                if ($lookup) {
                    $lookup->bind_param('s', $isbn);
                    $lookup->execute();
                    $lookupRes = $lookup->get_result();
                    if ($holderRow = $lookupRes->fetch_assoc()) {
                        $holderId = (int)$holderRow['book_id'];
                        $holderTitle = (string)$holderRow['title'];
                        $holderAuthor = (string)$holderRow['author'];
                    }
                    $lookup->close();
                }

                if ($holderId <= 0) {
                    // Couldn't even find who holds it -- give up on this one.
                    fwrite(STDERR, "Failed to insert book \"{$title}\": ISBN {$isbn} conflict, but couldn't find the existing holder." . PHP_EOL);
                    $booksFailed++;
                    continue;
                }

                if (ibb_norm($holderTitle) === ibb_norm($title) && ibb_norm($holderAuthor) === ibb_norm($author)) {
                    // Genuinely the same book -- just grouped as a separate
                    // edition (e.g. a slightly different call number). Attach
                    // this edition's copies to the existing book instead of
                    // losing them.
                    $bookId = $holderId;
                    $existingByIsbn[$isbn] = $holderId;
                    $matchedExistingBooks++;
                    ibb_log("  Note: \"{$title}\" shares ISBN {$isbn} with an identical existing title -- merged into book_id {$holderId} instead of creating a duplicate.");
                } else {
                    // A DIFFERENT title/author genuinely shares this ISBN --
                    // common with multi-volume sets and course-module
                    // series that get one publisher ISBN for the whole set
                    // (e.g. "Field study I" / "Field study II"). Keep this
                    // as its own distinct catalog title (don't merge titles
                    // that aren't the same book), just without the ISBN,
                    // rather than losing the whole record and its copies.
                    $bindIsbnInsert('');
                    try {
                        $insertBook->execute();
                        $bookId = (int)$conn->insert_id;
                        $newBooksCreated++;
                        $existingByTitleAuthor[$taKey][] = ['book_id' => $bookId, 'call_number' => $baseCall];
                        ibb_log("  Note: \"{$title}\" shares ISBN {$isbn} with a DIFFERENT title (\"{$holderTitle}\") -- kept as its own book, ISBN left blank to avoid the conflict.");
                    } catch (Throwable $e2) {
                        fwrite(STDERR, "Failed to insert book \"{$title}\" even without its ISBN: " . $e2->getMessage() . PHP_EOL);
                        $booksFailed++;
                        continue;
                    }
                }
            }
        } else {
            $newBooksCreated++;
            $existingByTitleAuthor[$taKey][] = ['book_id' => $dryRunFakeIdCounter--, 'call_number' => $baseCall];
        }
    }

    // Insert one copy per physical-copy row in this group.
    foreach ($groupRows as $r) {
        $accessionNo = trim((string)$r['accession_no']);
        if (isset($existingAccessions[$accessionNo])) {
            $copiesSkippedDuplicateAccession++;
            continue;
        }

        $copyIsbn = trim((string)$r['isbn']);
        if ($commit && $bookId > 0) {
            $insertCopy->bind_param('iss', $bookId, $accessionNo, $copyIsbn);
            try {
                $insertCopy->execute();
            } catch (Throwable $e) {
                fwrite(STDERR, "Failed to insert copy {$accessionNo}: " . $e->getMessage() . PHP_EOL);
                $copiesFailed++;
                continue;
            }
        }
        $existingAccessions[$accessionNo] = true; // guard against dupes within this same file
        $copiesInserted++;
    }

    $processedGroups++;
    if ($commit && $processedGroups % 200 === 0) {
        $conn->commit();
        $conn->begin_transaction();
        ibb_log("...committed after {$processedGroups} of " . count($groupOrder) . ' editions');
    }
}

if ($commit) {
    $conn->commit();
}

$insertBook->close();
$insertCopy->close();

ibb_log('');
ibb_log('=== Summary ===');
ibb_log(($commit ? 'New book records created: ' : 'New book records that WOULD be created: ') . $newBooksCreated);
ibb_log('Editions matched to an already-existing book (only new copies added, no duplicate book record): ' . $matchedExistingBooks);
ibb_log('Ambiguous title+author matches (multiple existing books already share that title+author -- SKIPPED, not imported, see list below): ' . $ambiguousMatches);
ibb_log(($commit ? 'New physical copies inserted: ' : 'New physical copies that WOULD be inserted: ') . $copiesInserted);
ibb_log('Copies skipped -- accession number already exists in the database: ' . $copiesSkippedDuplicateAccession);
if ($booksFailed > 0 || $copiesFailed > 0) {
    ibb_log('Books that FAILED to insert (see errors above): ' . $booksFailed);
    ibb_log('Copies that FAILED to insert (see errors above): ' . $copiesFailed);
}

if (!empty($ambiguousReport)) {
    ibb_log('');
    ibb_log('Ambiguous matches -- skipped, need a human to merge the duplicate book_id(s) first (first 200):');
    foreach (array_slice($ambiguousReport, 0, 200) as $line) {
        ibb_log('  ' . $line);
    }
}

if (!$commit) {
    ibb_log('');
    ibb_log('This was a DRY RUN -- nothing was written. Re-run with --commit to actually import.');
}
?>
