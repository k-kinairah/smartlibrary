<?php
declare(strict_types=1);

// Part 2 of the categorization pass (see tools/apply_category_mappings.php
// for part 1). Part 1 handled every uncategorized book whose call-number
// prefix maps cleanly to exactly one category. This part handles the two
// buckets that were deliberately left alone back then:
//
//   1. HV / G / HD / HF -- prefixes that each span SEVERAL categories, so
//      every one of the ~378 books in them was reviewed individually by
//      call number (see tools/list_multi_category_review.php's output)
//      and assigned by hand below, in $CATEGORY_BOOK_IDS.
//   2. The 45 books with no call number at all (see
//      tools/list_no_call_number_books.php's output) -- classified
//      individually by title/author/publisher, also in
//      $CATEGORY_BOOK_IDS.
//
// It also creates four new categories -- Education, English, History,
// Mathematics -- for the largest general-education clusters that part 1's
// category list had no slot for at all (LB/LC = education & teaching
// methods, PE = English language, DS = history, QA = mathematics), and
// assigns every uncategorized book under those five prefixes to them.
// The smaller scattered general-ed clusters (biology, engineering,
// philosophy, art, religion, physics, chemistry, political theory -- each
// under 15 books) are deliberately left alone, per the decision to only
// create categories for clusters big enough to be worth a shelf section.
//
// A little over a dozen books in the HV/G/HD/HF/no-call-number review
// didn't fit any existing or new category at all (e.g. gerontology,
// Philippine agrarian history, general physics, disaster-risk-reduction)
// -- those are simply not in $CATEGORY_BOOK_IDS and stay uncategorized.
//
// SAFE BY DEFAULT: dry-run unless --commit is passed.
//
// Usage:
//   php tools/apply_category_mappings_part2.php
//   php tools/apply_category_mappings_part2.php --commit

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config/db_connect.php';

function acm2_extract_prefix(string $callNumber): string {
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

const NEW_CATEGORIES = ['Education', 'English', 'History', 'Mathematics'];

// --- 1. Call-number-prefix clusters that map to exactly one (new) category ---

const PREFIX_CATEGORY = [
    'LB' => 'Education', 'LC' => 'Education',
    'PE' => 'English',
    'DS' => 'History',
    'QA' => 'Mathematics',
];

// --- 2. Specific book_id assignments, from a title-by-title review of
//        every HV/G/HD/HF book and every no-call-number book. Grouped by
//        category for readability; flattened into EXPLICIT_BOOK_CATEGORY
//        below. ---

$CATEGORY_BOOK_IDS = [

    'Criminology Theory' => [
        2058, 2069, 2079, 1928, 2074, 2164, 2124, 307, 315, 2112, 1920, 1935,
        1944, 2053, 2105, 1924, 1921, 2111, 1929, 1916, 318, 2087,
    ],
    'Criminal Investigation' => [
        823, 1933, 2116, 2122, 2125, 2128, 2136, 2085, 2070, 2075, 2086,
        2048, 1940, 1942, 2071, 312,
    ],
    'Cybercrime Investigation' => [2030],
    'Police Administration' => [2113, 310, 2050, 2052, 2061],
    'Police Operations' => [2089, 2114, 2073],
    'Policing Systems' => [1927, 1932, 1934, 2028, 2135, 2083, 2084, 388, 308, 358, 2129],
    'Human Rights' => [1918, 895],
    'Industrial Security' => [2059, 2060, 2062],
    'Professional Ethics' => [1925, 2088, 2099, 1889, 690],
    'Fingerprint Identification' => [2063, 2077, 2138],
    'Forensic Science' => [
        314, 2049, 2094, 2117, 2120, 2133, 1915, 1941, 1936, 1937, 1938,
        1939, 2055, 2066, 2068, 1247,
    ],
    'Corrections' => [1926, 2072, 2082, 311, 2096, 2051, 2104, 2098],
    'Juvenile Justice' => [2090, 2091, 2092, 2093, 2029, 316],
    'Drug Education' => [2127, 2103, 2101, 2102, 309],
    'Leadership Studies' => [2095, 1229, 1964, 224],
    'Organizational Behavior' => [263, 732, 864, 999, 1378, 1955, 2026, 1126, 381, 962, 1486, 884],
    'Workplace Diversity' => [1564],
    'Tour Guiding' => [541, 1764, 288, 559, 737, 1361, 1480],
    'Tourism Geography' => [654, 583, 1485, 1568, 2156, 2161, 1999],
    'Tourism Studies' => [
        557, 287, 327, 516, 581, 612, 613, 614, 616, 655, 669, 714, 729,
        731, 774, 854, 1225, 1331, 1447, 1455, 1497, 1501, 1563, 1566,
        1567, 1571, 1572, 1606, 1665, 1708, 1709, 1710, 1765, 1995, 1996,
        2157, 2160,
        1037, 1991, 1994, 2019, 2020, 2033, 2034,
        1022, 1494,
        1021, 1607,
        615, 757, 1511, 1512, 1986, 1992, 1993, 2155,
        1136, 1594,
        1500, 1499, 2144,
        1456, 1454, 1443, 1403,
    ],
    'Hospitality Operations' => [1987, 1632, 1948, 1990, 341, 1441, 1446, 1448],
    'Business' => [
        1976, 903, 1720, 1513, 1520, 1749, 1951, 1952, 2000, 2021, 2039,
        2146, 580, 782, 955, 956, 958, 1029, 1334, 1498, 1523, 259, 328,
        1981, 1128, 1959, 1963, 2147, 1506, 2046, 609, 1768, 1193, 1482,
        1890, 225, 237, 344, 1316, 1528, 1957, 1958, 688, 821, 1719, 1965,
        319, 2016, 530, 2018, 2166, 2154, 2145, 254, 398, 909, 953, 547,
        1653, 961, 2165, 1393, 1396, 564,
        1450, 1381, 1453,
    ],
    'Business Research' => [1442, 691, 1083, 2009, 1457],
    'Human Resource Management' => [
        843, 971, 2038, 911, 957, 1305, 1335, 1402, 1953, 1977, 2007, 2027,
        775, 305, 960, 1241, 1954,
    ],
    'Marketing / Business' => [
        298, 261, 842, 766, 250, 252, 1435, 1757, 2003, 2005, 2017, 2024,
        253, 1623, 836, 838, 280, 1129, 294, 1975,
    ],
    'Marketing Research Methods' => [2004, 2025],
    'Financial Management' => [
        2014, 1966, 330, 326, 333, 907, 1545, 1622, 1968, 1969, 1970, 1971,
        1525, 1526, 325, 329, 345, 355, 332, 347, 1546, 2010, 2011, 1502,
        1503, 1527, 1543, 346, 349, 351, 1521, 1541, 1542, 1544, 1747,
        1960, 1983, 2150, 2015, 331, 335, 348, 350, 900, 1343, 1508, 1509,
        1961,
        1384, 1418,
    ],
    'Information Technology' => [1318],
    'Psychology' => [365, 621, 1470, 1472, 1874, 1365],
    'Mathematics' => [834, 1419],
    'English' => [1341, 1426],
    'History' => [1303],
    'Literature' => [1300],
    'Classic' => [1380],
    'Behavioral Science' => [1420],

    // Deliberately NOT included (left uncategorized -- no confident fit):
    //   HV: 2115, 2130 (crisis/incident management), 620 (child care
    //       policy), 750, 543, 618 (elder care/gerontology), 894 (9/11
    //       geopolitics), 2106, 2107 (nationalism/character formation --
    //       civics, not criminology)
    //   HD: 970 (agricultural development), 896, 897 (Philippine agrarian
    //       history/sociology), 1422 (fish port operations)
    //   No call number: 1421 (disaster risk reduction -- no matching
    //       category), 1386 (Filipino-language research methods -- no
    //       "Filipino" category), 1401 (facilities planning -- generic
    //       industrial engineering), 1323 (university physics -- physics
    //       wasn't one of the four new categories)
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
        // For the dry-run plan/count below, pretend they exist so the
        // per-category breakdown is accurate.
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
while ($row = $res->fetch_assoc()) {
    $bookId = (int)$row['book_id'];
    if (isset($EXPLICIT_BOOK_CATEGORY[$bookId])) {
        $plan[$bookId] = $EXPLICIT_BOOK_CATEGORY[$bookId];
        continue;
    }
    $prefix = acm2_extract_prefix((string)($row['call_number'] ?? ''));
    if ($prefix !== '' && isset(PREFIX_CATEGORY[$prefix])) {
        $plan[$bookId] = PREFIX_CATEGORY[$prefix];
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
