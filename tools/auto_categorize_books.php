<?php
declare(strict_types=1);

// Auto cross-listing for books that fit more than one course.
//
// What this does: for every category/program, it builds a "profile"
// fingerprint by averaging the AI fingerprints (from
// tools/generate_book_embeddings.php) of the books already filed there.
// Then, for every book, it compares that book's own fingerprint to every
// profile. When a book is genuinely close to a course it ISN'T primarily
// filed under (e.g. a research-methods book that's close to both
// Criminology's and Computer Science's profile), it links the book to
// that extra course too -- so students browsing either program see it.
//
// This does NOT call any external API -- it's pure local math over
// fingerprints tools/generate_book_embeddings.php already generated. Run
// that script first. Safe to run this again and again as more books get
// tagged; by default it never removes a link, it only adds ones that are
// missing -- use --reset if you want to clear out previous auto-links and
// redo them (e.g. after tightening --threshold because some auto-links
// looked wrong).
//
// Defaults (0.65 similarity, 5+ example books per profile) were tuned up
// from an earlier, looser pass that produced a couple of weak false
// matches -- see tools/explain_book_match.php to check any specific book.
//
// Usage:
//   php tools/auto_categorize_books.php
//   php tools/auto_categorize_books.php --threshold=0.7 --max-extra=2
//   php tools/auto_categorize_books.php --dry-run
//   php tools/auto_categorize_books.php --reset --threshold=0.7   (undo previous auto-links, redo with a different cutoff)

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config/db_connect.php';

function autocat_log(string $message): void {
    echo $message . PHP_EOL;
}

function autocat_table_exists(mysqli $conn, string $table): bool {
    // 2026-10-10: hardcoded against a known-good table list instead of
    // querying information_schema -- see index.php for why. Every name
    // below is confirmed present in smartlib_FULL_BACKUP_20261010.sql.
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

function autocat_cosine_similarity(array $a, array $b): float {
    $n = min(count($a), count($b));
    if ($n === 0) {
        return 0.0;
    }

    $dot = 0.0;
    $magA = 0.0;
    $magB = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $dot += $a[$i] * $b[$i];
        $magA += $a[$i] * $a[$i];
        $magB += $b[$i] * $b[$i];
    }

    if ($magA <= 0.0 || $magB <= 0.0) {
        return 0.0;
    }

    return $dot / (sqrt($magA) * sqrt($magB));
}

function autocat_load_embeddings(mysqli $conn): array {
    $map = [];
    $res = $conn->query('SELECT book_id, embedding FROM book_embeddings');
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $bookId = (int)($row['book_id'] ?? 0);
            $vector = json_decode((string)($row['embedding'] ?? '[]'), true);
            if ($bookId > 0 && is_array($vector) && count($vector) > 0) {
                $map[$bookId] = array_map('floatval', $vector);
            }
        }
    }
    return $map;
}

function autocat_average_vector(array $vectors): array {
    $vectors = array_values($vectors);
    if (count($vectors) === 0) {
        return [];
    }

    $dimensions = count($vectors[0]);
    $sum = array_fill(0, $dimensions, 0.0);

    foreach ($vectors as $vector) {
        foreach ($vector as $i => $value) {
            if ($i < $dimensions) {
                $sum[$i] += $value;
            }
        }
    }

    $count = count($vectors);
    for ($i = 0; $i < $dimensions; $i++) {
        $sum[$i] /= $count;
    }

    return $sum;
}

// Builds one profile vector per group (category_id or program_id), from
// the embeddings of books already primarily filed in that group. Groups
// with too few example books are skipped -- not enough signal to trust.
function autocat_build_profiles(mysqli $conn, string $linkTable, string $groupColumn, array $embeddings, int $minExamples): array {
    $profiles = [];
    $counts = [];

    $sql = "SELECT {$groupColumn}, book_id FROM {$linkTable} WHERE source = 'primary'";
    $res = $conn->query($sql);
    if (!$res) {
        return [$profiles, $counts];
    }

    $grouped = [];
    while ($row = $res->fetch_assoc()) {
        $groupId = (int)($row[$groupColumn] ?? 0);
        $bookId = (int)($row['book_id'] ?? 0);
        if ($groupId <= 0 || $bookId <= 0 || !isset($embeddings[$bookId])) {
            continue;
        }
        $grouped[$groupId][] = $embeddings[$bookId];
    }

    foreach ($grouped as $groupId => $vectors) {
        $counts[$groupId] = count($vectors);
        if (count($vectors) < $minExamples) {
            continue;
        }
        $profiles[$groupId] = autocat_average_vector($vectors);
    }

    return [$profiles, $counts];
}

// --- main ---

if (!autocat_table_exists($conn, 'book_embeddings')) {
    fwrite(STDERR, "The book_embeddings table doesn't exist yet." . PHP_EOL);
    fwrite(STDERR, 'Run database/ai_semantic_setup.sql, then tools/generate_book_embeddings.php, then run this again.' . PHP_EOL);
    exit(1);
}

if (!autocat_table_exists($conn, 'book_categories') || !autocat_table_exists($conn, 'book_programs')) {
    fwrite(STDERR, "The book_categories / book_programs tables don't exist yet." . PHP_EOL);
    fwrite(STDERR, 'Run database/multi_category_setup.sql first, then run this again.' . PHP_EOL);
    exit(1);
}

$threshold = 0.65;
$maxExtra = 2;
$minExamples = 5;
$dryRun = false;
$reset = false;

foreach ($argv as $arg) {
    if (preg_match('/^--threshold=([0-9.]+)$/', (string)$arg, $m)) {
        $threshold = (float)$m[1];
    } elseif (preg_match('/^--max-extra=(\d+)$/', (string)$arg, $m)) {
        $maxExtra = (int)$m[1];
    } elseif (preg_match('/^--min-examples=(\d+)$/', (string)$arg, $m)) {
        $minExamples = (int)$m[1];
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    } elseif ($arg === '--reset') {
        $reset = true;
    }
}

autocat_log("Settings: threshold={$threshold}, max-extra-per-book={$maxExtra}, min-examples-per-course={$minExamples}" . ($dryRun ? ' (dry run -- nothing will be saved)' : ''));

if ($reset) {
    if ($dryRun) {
        $countRes = $conn->query("SELECT COUNT(*) AS n FROM book_categories WHERE source = 'auto'");
        $catCount = $countRes ? (int)($countRes->fetch_assoc()['n'] ?? 0) : 0;
        $countRes = $conn->query("SELECT COUNT(*) AS n FROM book_programs WHERE source = 'auto'");
        $progCount = $countRes ? (int)($countRes->fetch_assoc()['n'] ?? 0) : 0;
        autocat_log("--reset (dry run): would remove {$catCount} existing auto category link(s) and {$progCount} existing auto program link(s) before rebuilding.");
    } else {
        $conn->query("DELETE FROM book_categories WHERE source = 'auto'");
        $catRemoved = $conn->affected_rows;
        $conn->query("DELETE FROM book_programs WHERE source = 'auto'");
        $progRemoved = $conn->affected_rows;
        autocat_log("--reset: removed {$catRemoved} existing auto category link(s) and {$progRemoved} existing auto program link(s). Rebuilding from scratch.");
    }
}

$embeddings = autocat_load_embeddings($conn);
autocat_log('Books with AI fingerprints available: ' . count($embeddings));

if (count($embeddings) === 0) {
    fwrite(STDERR, 'No book fingerprints found. Run tools/generate_book_embeddings.php first.' . PHP_EOL);
    exit(1);
}

[$categoryProfiles, $categoryCounts] = autocat_build_profiles($conn, 'book_categories', 'category_id', $embeddings, $minExamples);
[$programProfiles, $programCounts] = autocat_build_profiles($conn, 'book_programs', 'program_id', $embeddings, $minExamples);

autocat_log('Category profiles built: ' . count($categoryProfiles) . ' of ' . count($categoryCounts) . ' categories (others have fewer than ' . $minExamples . ' primary books so far)');
autocat_log('Program profiles built: ' . count($programProfiles) . ' of ' . count($programCounts) . ' programs (others have fewer than ' . $minExamples . ' primary books so far)');

// Which category/program each book is ALREADY linked to (any source) --
// we never re-add or duplicate these.
$existingCategoryLinks = [];
$res = $conn->query('SELECT book_id, category_id FROM book_categories');
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $existingCategoryLinks[(int)$row['book_id']][(int)$row['category_id']] = true;
    }
}

$existingProgramLinks = [];
$res = $conn->query('SELECT book_id, program_id FROM book_programs');
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $existingProgramLinks[(int)$row['book_id']][(int)$row['program_id']] = true;
    }
}

$categoryInsert = $conn->prepare(
    'INSERT IGNORE INTO book_categories (book_id, category_id, source, confidence) VALUES (?, ?, \'auto\', ?)'
);
$programInsert = $conn->prepare(
    'INSERT IGNORE INTO book_programs (book_id, program_id, source, confidence) VALUES (?, ?, \'auto\', ?)'
);

$categoryLinksAdded = 0;
$programLinksAdded = 0;
$booksTouched = 0;

foreach ($embeddings as $bookId => $vector) {
    $touchedThisBook = false;

    if (count($categoryProfiles) > 0) {
        $scored = [];
        foreach ($categoryProfiles as $categoryId => $profile) {
            if (isset($existingCategoryLinks[$bookId][$categoryId])) {
                continue;
            }
            $sim = autocat_cosine_similarity($vector, $profile);
            if ($sim >= $threshold) {
                $scored[] = ['id' => $categoryId, 'sim' => $sim];
            }
        }
        usort($scored, static function ($a, $b) {
            return $b['sim'] <=> $a['sim'];
        });
        $scored = array_slice($scored, 0, $maxExtra);

        foreach ($scored as $pick) {
            $touchedThisBook = true;
            $categoryLinksAdded++;
            if (!$dryRun && $categoryInsert) {
                $categoryId = $pick['id'];
                $confidence = $pick['sim'];
                $categoryInsert->bind_param('iid', $bookId, $categoryId, $confidence);
                $categoryInsert->execute();
            }
        }
    }

    if (count($programProfiles) > 0) {
        $scored = [];
        foreach ($programProfiles as $programId => $profile) {
            if (isset($existingProgramLinks[$bookId][$programId])) {
                continue;
            }
            $sim = autocat_cosine_similarity($vector, $profile);
            if ($sim >= $threshold) {
                $scored[] = ['id' => $programId, 'sim' => $sim];
            }
        }
        usort($scored, static function ($a, $b) {
            return $b['sim'] <=> $a['sim'];
        });
        $scored = array_slice($scored, 0, $maxExtra);

        foreach ($scored as $pick) {
            $touchedThisBook = true;
            $programLinksAdded++;
            if (!$dryRun && $programInsert) {
                $programId = $pick['id'];
                $confidence = $pick['sim'];
                $programInsert->bind_param('iid', $bookId, $programId, $confidence);
                $programInsert->execute();
            }
        }
    }

    if ($touchedThisBook) {
        $booksTouched++;
    }
}

if ($categoryInsert) {
    $categoryInsert->close();
}
if ($programInsert) {
    $programInsert->close();
}

autocat_log(($dryRun ? 'Would add' : 'Added') . " {$categoryLinksAdded} extra category link(s) and {$programLinksAdded} extra program link(s) across {$booksTouched} book(s).");
autocat_log('Done.');
exit(0);
?>
