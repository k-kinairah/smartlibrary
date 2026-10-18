<?php
declare(strict_types=1);

// Applies category assignments to currently-uncategorized books
// (category_id IS NULL/0), based on the review done against
// tools/suggest_categories_from_call_numbers.php's output.
//
// Two sources of truth, checked in this order for each uncategorized book:
//   1. EXPLICIT_BOOK_CATEGORY -- specific book_id overrides, used for the
//      ~80 recreational fiction titles that share LC codes with unrelated
//      non-fiction (their call numbers start with a "Fic"/"FIC" marker the
//      diagnostic tool doesn't strip, e.g. "Fic PS 3511 N67 1966" for The
//      Great Gatsby vs. plain "PS ..." for literary criticism) -- these
//      need per-title judgment, not a blanket prefix rule.
//   2. PREFIX_CATEGORY -- the call number's LC prefix (same extraction
//      logic as the diagnostic tool), for every other confidently-mapped
//      cluster (nursing/allied health, hospitality, financial management,
//      psychology, literature).
// Anything matching neither is left alone -- most of the catalog's
// remaining uncategorized books are general-education subjects (teaching
// methods, math, history, philosophy, science, religion, engineering) that
// this category list, which is entirely program/major-specific, has no
// slot for at all. That's a taxonomy gap, not something this script
// guesses around.
//
// Also inserts a matching book_categories row (source='primary') for every
// book it assigns, the same way the one-time backfill in
// database/multi_category_setup.sql did for pre-existing books -- this is
// what lets tools/auto_categorize_books.php build a real profile for these
// categories going forward (it needs 5+ "primary" books per category
// before it will trust a profile).
//
// SAFE BY DEFAULT: dry-run unless --commit is passed.
//
// Usage:
//   php tools/apply_category_mappings.php
//   php tools/apply_category_mappings.php --commit

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config/db_connect.php';

function acm_extract_prefix(string $callNumber): string {
    $c = trim($callNumber);
    if ($c === '') {
        return '';
    }
    $c = preg_replace('/\s+/', ' ', $c) ?? $c;
    $c = preg_replace('/^(FIL|GEN|REF|RES)\.?\s+/i', '', $c) ?? $c;
    if (preg_match('/^([A-Za-z]{1,3})\s*[\d.]/', $c, $m)) {
        return strtoupper($m[1]);
    }
    return '';
}

// --- 1. Call-number-prefix clusters (see tools/suggest_categories_from_call_numbers.php's output for the full review) ---

const PREFIX_CATEGORY = [
    // Nursing / allied health (RT is the exact LC subclass for Nursing;
    // the rest are the surrounding medicine/pharmacology/anatomy/pathology
    // subclasses that this catalog's nursing and allied-health courses use)
    'RT' => 'Nursing', 'RC' => 'Nursing', 'RJ' => 'Nursing', 'RG' => 'Nursing',
    'RD' => 'Nursing', 'RM' => 'Nursing', 'QP' => 'Nursing', 'RB' => 'Nursing',
    'RS' => 'Nursing', 'QR' => 'Nursing', 'R' => 'Nursing', 'RK' => 'Nursing',
    'RZ' => 'Nursing', 'QM' => 'Nursing',
    // Hospitality / tourism operations (TX = home economics/food/sanitation;
    // GT = events; TP = food science; KPM here is entirely Philippine
    // tourism/hospitality law titles, confirmed by title, not just class code)
    'TX' => 'Hospitality Operations', 'GT' => 'Hospitality Operations',
    'TP' => 'Hospitality Operations', 'KPM' => 'Hospitality Operations',
    // Finance (HG is the exact LC subclass for Finance; HJ is public finance/taxation)
    'HG' => 'Financial Management', 'HJ' => 'Financial Management',
    // Psychology (BF is the exact LC subclass for Psychology)
    'BF' => 'Psychology',
    // Literature (Filipino, general, and English/American literary
    // criticism and anthologies -- NOT the "Fic"/"FIC"-marked recreational
    // novels, which are handled individually below instead)
    'PL' => 'Literature', 'PN' => 'Literature', 'PS' => 'Literature',
    'PR' => 'Literature', 'PQ' => 'Literature',
];

// --- 2. Specific book_id overrides ---

const EXPLICIT_BOOK_CATEGORY = [
    // Recreational fiction -- modern thrillers/contemporary fiction -> Adventure
    2191 => 'Adventure', 2192 => 'Adventure', 2199 => 'Adventure', 2201 => 'Adventure',
    2200 => 'Adventure', 2198 => 'Adventure', 2176 => 'Adventure', 2185 => 'Adventure',
    2204 => 'Adventure', 2205 => 'Adventure', 2180 => 'Adventure', 2175 => 'Adventure',
    2187 => 'Adventure', 2178 => 'Adventure', 2174 => 'Adventure', 2217 => 'Adventure',
    2214 => 'Adventure', 2184 => 'Adventure', 2207 => 'Adventure', 2173 => 'Adventure',
    2208 => 'Adventure', 2209 => 'Adventure', 2203 => 'Adventure', 2188 => 'Adventure',
    2219 => 'Adventure', 2206 => 'Adventure', 2218 => 'Adventure', 2182 => 'Adventure',
    2215 => 'Adventure', 2213 => 'Adventure', 2183 => 'Adventure', 2216 => 'Adventure',
    2186 => 'Adventure', 2211 => 'Adventure', 2189 => 'Adventure', 2196 => 'Adventure',
    2190 => 'Adventure', 2193 => 'Adventure', 2194 => 'Adventure', 2202 => 'Adventure',
    2179 => 'Adventure', 2197 => 'Adventure', 2212 => 'Adventure', 2195 => 'Adventure',
    // Recreational fiction -- older/canonical literary works (Filipino
    // classics, Western classics, classic children's lit) -> Classic
    385 => 'Classic', 1216 => 'Classic', 891 => 'Classic', 845 => 'Classic',
    888 => 'Classic', 876 => 'Classic', 885 => 'Classic', 889 => 'Classic',
    880 => 'Classic', 898 => 'Classic', 424 => 'Classic', 879 => 'Classic',
    886 => 'Classic', 881 => 'Classic', 877 => 'Classic', 892 => 'Classic',
    878 => 'Classic', 887 => 'Classic', 890 => 'Classic', 846 => 'Classic',
    475 => 'Classic', 525 => 'Classic', 386 => 'Classic', 2177 => 'Classic',
    468 => 'Classic', 467 => 'Classic', 603 => 'Classic', 481 => 'Classic',
    493 => 'Classic', 499 => 'Classic', 994 => 'Classic', 550 => 'Classic',
    940 => 'Classic', 411 => 'Classic', 470 => 'Classic', 567 => 'Classic',
    2181 => 'Classic',
    // Call numbers with a formatting quirk (double "Fil Fil" prefix, a
    // hyphenated "Fil-Ref" marker, or a missing digit) that made them miss
    // the TX/Hospitality prefix rule above, even though they clearly
    // belong with it by title.
    1575 => 'Hospitality Operations', 1496 => 'Hospitality Operations', 1557 => 'Hospitality Operations',
];

// --- load categories, uncategorized books ---

$categoryIdByName = [];
$catRes = $conn->query('SELECT category_id, category_name FROM categories');
if ($catRes) {
    while ($c = $catRes->fetch_assoc()) {
        $categoryIdByName[(string)$c['category_name']] = (int)$c['category_id'];
    }
}

$neededNames = array_unique(array_merge(array_values(PREFIX_CATEGORY), array_values(EXPLICIT_BOOK_CATEGORY)));
foreach ($neededNames as $name) {
    if (!isset($categoryIdByName[$name])) {
        fwrite(STDERR, "Category \"{$name}\" not found in the categories table -- aborting before anything is changed." . PHP_EOL);
        exit(1);
    }
}

$commit = in_array('--commit', $argv, true);
echo 'Mode: ' . ($commit ? 'COMMIT (writing to the database)' : 'DRY RUN (nothing will be saved -- pass --commit to actually apply)') . PHP_EOL;

$res = $conn->query('SELECT book_id, title, call_number FROM books WHERE category_id IS NULL OR category_id = 0');
if (!$res) {
    fwrite(STDERR, 'Query failed: ' . $conn->error . PHP_EOL);
    exit(1);
}

$plan = []; // book_id => category_name
while ($row = $res->fetch_assoc()) {
    $bookId = (int)$row['book_id'];
    if (isset(EXPLICIT_BOOK_CATEGORY[$bookId])) {
        $plan[$bookId] = EXPLICIT_BOOK_CATEGORY[$bookId];
        continue;
    }
    $prefix = acm_extract_prefix((string)($row['call_number'] ?? ''));
    if ($prefix !== '' && isset(PREFIX_CATEGORY[$prefix])) {
        $plan[$bookId] = PREFIX_CATEGORY[$prefix];
    }
}

$counts = [];
foreach ($plan as $category) {
    $counts[$category] = ($counts[$category] ?? 0) + 1;
}
arsort($counts);

echo PHP_EOL;
echo 'Books that will get a category assigned: ' . count($plan) . PHP_EOL;
foreach ($counts as $category => $n) {
    echo sprintf('  %-24s %5d book(s)', $category, $n) . PHP_EOL;
}
echo PHP_EOL;

if ($commit) {
    $updateBook = $conn->prepare('UPDATE books SET category_id = ? WHERE book_id = ?');
    $insertLink = $conn->prepare("INSERT IGNORE INTO book_categories (book_id, category_id, source) VALUES (?, ?, 'primary')");
    if (!$updateBook || !$insertLink) {
        fwrite(STDERR, 'Could not prepare statements: ' . $conn->error . PHP_EOL);
        exit(1);
    }

    $conn->begin_transaction();
    $done = 0;
    foreach ($plan as $bookId => $category) {
        $categoryId = $categoryIdByName[$category];
        $updateBook->bind_param('ii', $categoryId, $bookId);
        $updateBook->execute();
        $insertLink->bind_param('ii', $bookId, $categoryId);
        $insertLink->execute();
        $done++;
        if ($done % 200 === 0) {
            $conn->commit();
            $conn->begin_transaction();
            echo "...committed after {$done} of " . count($plan) . ' books' . PHP_EOL;
        }
    }
    $conn->commit();
    echo PHP_EOL . "Done. {$done} books updated." . PHP_EOL;
} else {
    echo 'This was a DRY RUN -- nothing was written. Re-run with --commit to actually apply.' . PHP_EOL;
}
?>
