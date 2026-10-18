<?php
declare(strict_types=1);

// Part 3 of the categorization pass (see parts 1 and 2). After parts 1-2,
// 421 books across 78 call-number prefixes were still uncategorized --
// this part is a deliberate push to zero: every prefix from
// tools/suggest_categories_from_call_numbers.php's latest run is placed
// somewhere, either into an existing category that already fits (Nursing,
// Business, Technology, Recreation Management, Criminal Law Evidence,
// Human Rights, Cybercrime, Education, History, Literature, Mathematics,
// Business Law, Business Research, Forensic Science, Traffic
// Investigation, Professional Ethics, Classic -- all of these already
// existed but had no rule routing books to them yet) or into eight new
// categories for subjects this program-specific catalog genuinely had no
// home for at all: Language Studies, Law / Legal Studies, Philosophy,
// Religion, Arts, Natural Science, Social Science, Political Science.
//
// Also fixes a real bug from part 2: 19 no-call-number nursing/allied
// -health books (radiologic tech, maternal-child nursing, pharmacology,
// etc.) were individually reviewed and *intended* to be filed under
// Nursing, but the category name was never actually added to part 2's
// mapping array, so they silently stayed uncategorized. They're included
// here under Nursing.
//
// For prefixes with more books than could be sampled (e.g. "P" = 35
// books, only 4 shown by the diagnostic tool), the whole prefix is
// assigned by what that LC class fundamentally covers (P = general
// linguistics/language, QD = chemistry, GV = recreation/sports, etc.) --
// the same method already used successfully for RT->Nursing, BF-
// >Psychology and the rest in part 1. Individual titles that were visible
// in the sample and clearly belong somewhere more specific (e.g. a
// forensic-medicine book under the public-health "RA" prefix, or a
// "Character Formation" values/ethics textbook under "HV"/"HM"/"BJ") are
// called out by book_id instead of following their prefix's default.
//
// SAFE BY DEFAULT: dry-run unless --commit is passed.
//
// Usage:
//   php tools/apply_category_mappings_part3.php
//   php tools/apply_category_mappings_part3.php --commit

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config/db_connect.php';

function acm3_extract_prefix(string $callNumber): string {
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

// --- New categories this part introduces ---

const NEW_CATEGORIES = [
    'Language Studies', 'Law / Legal Studies', 'Philosophy', 'Religion',
    'Arts', 'Natural Science', 'Social Science', 'Political Science',
];

// --- 1. Call-number-prefix clusters, into existing or new categories ---

const PREFIX_CATEGORY = [
    // Language / linguistics
    'P' => 'Language Studies', 'PC' => 'Language Studies', 'PB' => 'Language Studies',
    // Public health / preventive medicine (the two forensic-medicine
    // titles inside this prefix are routed to Forensic Science instead,
    // via the explicit book_id overrides below)
    'RA' => 'Nursing',
    // General/Philippine law (specific criminal/business law prefixes go
    // to their existing categories individually, below)
    'KF' => 'Law / Legal Studies', 'K' => 'Law / Legal Studies',
    // Recreation / PE / sports
    'GV' => 'Recreation Management',
    // Business / economics
    'HB' => 'Business', 'HC' => 'Business', 'TS' => 'Business', 'HE' => 'Business',
    // Sociology / social science
    'HM' => 'Social Science', 'HN' => 'Social Science', 'HQ' => 'Social Science',
    'GN' => 'Social Science', 'JZ' => 'Social Science', 'U' => 'Social Science',
    // Visual/performing arts and general humanities
    'N' => 'Arts', 'NX' => 'Arts', 'NC' => 'Arts', 'AZ' => 'Arts',
    'MT' => 'Arts', 'M' => 'Arts', 'TT' => 'Arts',
    // Natural science
    'QD' => 'Natural Science', 'QH' => 'Natural Science', 'QC' => 'Natural Science',
    'QL' => 'Natural Science', 'QE' => 'Natural Science', 'QK' => 'Natural Science',
    'Q' => 'Natural Science', 'GE' => 'Natural Science',
    // Philosophy
    'B' => 'Philosophy', 'BJ' => 'Philosophy', 'BD' => 'Philosophy', 'BC' => 'Philosophy',
    // Religion
    'BL' => 'Religion', 'BS' => 'Religion', 'BX' => 'Religion', 'BV' => 'Religion',
    // Political science
    'JC' => 'Political Science', 'HX' => 'Political Science', 'JQ' => 'Political Science',
    // History
    'D' => 'History', 'DA' => 'History', 'E' => 'History', 'F' => 'History', 'CB' => 'History',
    // Technology / engineering
    'TA' => 'Technology', 'TK' => 'Technology', 'TF' => 'Technology',
    'TL' => 'Technology', 'TD' => 'Technology', 'T' => 'Technology',
    // Math
    'HA' => 'Mathematics',
    // Literature (further P-class subclasses beyond part 1's PL/PN/PS/PR/PQ)
    'PA' => 'Literature', 'PJ' => 'Literature', 'PG' => 'Literature', 'GR' => 'Literature',
    // Criminal law / evidence
    'KPH' => 'Criminal Law Evidence', 'KPS' => 'Criminal Law Evidence', 'KJC' => 'Criminal Law Evidence',
    // Human rights
    'KZ' => 'Human Rights',
    // Cybercrime (internet/e-commerce law)
    'KPP' => 'Cybercrime',
    // Education (teaching methods / civics teaching / higher-ed admin)
    'LA' => 'Education', 'L' => 'Education',
    // Research methodology (closest existing analog to general
    // social-science research-methods texts)
    'H' => 'Business Research',
];

// --- 2. Specific book_id overrides -- either a mixed prefix where most
//        titles were reviewed individually, or single small prefixes. ---

$CATEGORY_BOOK_IDS = [

    // RA (public health / preventive medicine) is bulk-mapped to Nursing
    // below via PREFIX_CATEGORY; these two visible samples are clearly
    // forensic-medicine texts instead.
    'Forensic Science' => [778, 2064, 2123 /* TR: police photography */],

    // Nursing: allied-health/no-call-number books individually reviewed
    // in part 2 but never actually added to that part's mapping array
    // (bug) -- included here instead -- plus a few more identified this
    // round (gerontology under HV, homeopathy under RX, nutrition under Z).
    'Nursing' => [
        1390, 1382, 1407, 1406, 1459, 1458, 1408, 1405, 1404, 1427, 1385,
        1414, 1391, 1388, 1412, 1312, 1409, 1410, 1411,
        750, 543, 618, 755, 919,
    ],

    // Industrial Security: crisis/incident/disaster-management titles
    // scattered across HV, HM, K, and one no-call-number book.
    'Industrial Security' => [2115, 2130, 1930, 1919, 1421],

    // Professional Ethics: the "Character Formation" values/ethics
    // series and law-enforcement ethics texts, scattered across HV, HM,
    // and BJ depending on how each printing was cataloged.
    'Professional Ethics' => [2106, 2107, 2076, 2057, 2032, 2056],

    // Social Science: general sociology/child-welfare titles visible in
    // the HV sample.
    'Social Science' => [620],

    // History: geopolitics/history titles visible in the HV, HM, and HD samples.
    'History' => [894, 896, 897, 938],

    // Business: development-economics/operations titles visible in the
    // HD and no-call-number samples.
    'Business' => [1422, 970, 1401],

    // Human Rights: visible in the JC sample.
    'Human Rights' => [1917],

    // Traffic Investigation: visible in the HE sample (the other two HE
    // books are general transportation-management texts -> Business,
    // via the prefix rule).
    'Traffic Investigation' => [1931],

    // Business Law / Criminal Law Evidence / Tourism Studies: the K and
    // KPG samples, each a different existing category by title.
    'Business Law' => [2013, 2141],
    'Criminal Law Evidence' => [2210, 2139, 2137],
    'Tourism Studies' => [1997],

    // Business Research: general research-methodology titles that don't
    // fit the H prefix's bulk default cleanly, plus one Filipino-language
    // research-methods book with no call number.
    'Business Research' => [380, 1386],

    // Philosophy: the one H-prefix book that's actually philosophy, not
    // research methods.
    'Philosophy' => [786],

    // Education: two "Fic Fil"-style double-marker call numbers that the
    // prefix extractor can't parse (same class of bug as the earlier
    // "Fic"/"FIC" fiction-marker miss), a higher-ed library-planning book,
    // and one HF book that the part-2 review missed entirely.
    'Education' => [1491, 1489, 650],
    'Financial Management' => [2012],

    // Classic: a juvenile classics anthology (Grolier) -- same bucket as
    // part 1's other classic children's lit.
    'Classic' => [600],

    // Natural Science: the one no-call-number physics book left
    // unresolved in part 2.
    'Natural Science' => [1323],
];

$EXPLICIT_BOOK_CATEGORY = [];
$seen = [];
$dupErrors = [];
foreach ($CATEGORY_BOOK_IDS as $category => $ids) {
    foreach ($ids as $id) {
        if (isset($seen[$id])) {
            $dupErrors[] = "book_id {$id} appears in both \"{$seen[$id]}\" and \"{$category}\"";
        }
        $seen[$id] = $category;
        $EXPLICIT_BOOK_CATEGORY[$id] = $category;
    }
}
if (!empty($dupErrors)) {
    fwrite(STDERR, "Duplicate book_id assignments found -- aborting before anything is changed:" . PHP_EOL);
    foreach ($dupErrors as $e) {
        fwrite(STDERR, "  {$e}" . PHP_EOL);
    }
    exit(1);
}

// --- load categories, creating the new ones if needed ---

$commit = in_array('--commit', $argv, true);
echo 'Mode: ' . ($commit ? 'COMMIT (writing to the database)' : 'DRY RUN (nothing will be saved -- pass --commit to actually apply)') . PHP_EOL;

$categoryIdByName = [];
$catRes = $conn->query('SELECT category_id, category_name FROM categories');
if ($catRes) {
    while ($c = $catRes->fetch_assoc()) {
        $categoryIdByName[(string)$c['category_name']] = (int)$c['category_id'];
    }
}

$toCreate = [];
foreach (NEW_CATEGORIES as $name) {
    if (!isset($categoryIdByName[$name])) {
        $toCreate[] = $name;
    }
}
if (!empty($toCreate)) {
    echo 'New categories to create: ' . implode(', ', $toCreate) . PHP_EOL;
    if ($commit) {
        $insertCat = $conn->prepare('INSERT INTO categories (category_name) VALUES (?)');
        if (!$insertCat) {
            fwrite(STDERR, 'Could not prepare category insert: ' . $conn->error . PHP_EOL);
            exit(1);
        }
        foreach ($toCreate as $name) {
            try {
                $insertCat->bind_param('s', $name);
                $insertCat->execute();
                $categoryIdByName[$name] = (int)$conn->insert_id;
                echo "  Created \"{$name}\" (category_id {$categoryIdByName[$name]})." . PHP_EOL;
            } catch (Throwable $e) {
                fwrite(STDERR, "  FAILED to create category \"{$name}\": " . $e->getMessage() . PHP_EOL);
                fwrite(STDERR, "  The categories table may require other columns beyond category_name -- add it manually (e.g. via phpMyAdmin) and re-run." . PHP_EOL);
                exit(1);
            }
        }
    } else {
        echo '  (dry run -- not created yet; --commit will create them)' . PHP_EOL;
        foreach ($toCreate as $i => $name) {
            $categoryIdByName[$name] = -1 - $i;
        }
    }
}
echo PHP_EOL;

$neededNames = array_unique(array_merge(array_values(PREFIX_CATEGORY), array_values($EXPLICIT_BOOK_CATEGORY)));
foreach ($neededNames as $name) {
    if (!isset($categoryIdByName[$name])) {
        fwrite(STDERR, "Category \"{$name}\" not found in the categories table -- aborting before anything is changed." . PHP_EOL);
        exit(1);
    }
}

// --- plan ---

$res = $conn->query('SELECT book_id, title, call_number FROM books WHERE category_id IS NULL OR category_id = 0');
if (!$res) {
    fwrite(STDERR, 'Query failed: ' . $conn->error . PHP_EOL);
    exit(1);
}

$plan = []; // book_id => category_name
$unmatched = [];
while ($row = $res->fetch_assoc()) {
    $bookId = (int)$row['book_id'];
    if (isset($EXPLICIT_BOOK_CATEGORY[$bookId])) {
        $plan[$bookId] = $EXPLICIT_BOOK_CATEGORY[$bookId];
        continue;
    }
    $prefix = acm3_extract_prefix((string)($row['call_number'] ?? ''));
    if ($prefix !== '' && isset(PREFIX_CATEGORY[$prefix])) {
        $plan[$bookId] = PREFIX_CATEGORY[$prefix];
    } else {
        $unmatched[] = $row;
    }
}

$counts = [];
foreach ($plan as $category) {
    $counts[$category] = ($counts[$category] ?? 0) + 1;
}
arsort($counts);

echo 'Books that will get a category assigned: ' . count($plan) . PHP_EOL;
foreach ($counts as $category => $n) {
    echo sprintf('  %-28s %5d book(s)', $category, $n) . PHP_EOL;
}
echo PHP_EOL;

if (!empty($unmatched)) {
    echo 'Still unmatched after this pass (' . count($unmatched) . '):' . PHP_EOL;
    foreach ($unmatched as $r) {
        echo '  #' . $r['book_id'] . '  "' . $r['title'] . '"  [' . $r['call_number'] . ']' . PHP_EOL;
    }
    echo PHP_EOL;
}

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
