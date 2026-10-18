<?php
declare(strict_types=1);

// Read-only diagnostic, part 2 of the categorization pass: full per-book
// listing (not just samples) for the four call-number prefixes that were
// deliberately skipped by tools/apply_category_mappings.php because each
// one spans SEVERAL existing categories rather than mapping to just one:
//
//   HV  -- criminology / social pathology / corrections / police / drugs
//          (spans Criminology Theory, Cybercrime, Cybercrime Investigation,
//          Drug Education, Fingerprint Identification, Forensic Science,
//          Juvenile Justice, Police Administration, Police Operations,
//          Policing Systems, Corrections, Criminal Investigation, Criminal
//          Law Evidence, Traffic Investigation, Industrial Security, Human
//          Rights)
//   G   -- geography / travel / tourism / recreation
//          (spans Tour Guiding, Tourism Geography, Tourism Studies,
//          Recreation Management)
//   HD  -- industry / management / labor
//   HF  -- commerce / business / marketing
//          (HD+HF together span Business, Financial Management, Human
//          Resource Management, Leadership Studies, Marketing / Business,
//          Marketing Research Methods, Organizational Behavior)
//
// A single prefix->category rule (like the ones already applied) isn't
// precise enough here -- picking the right category needs the actual LC
// call number (down to the decimal, since real LC sub-ranges are what
// separate e.g. "Police Administration" from "Corrections" within HV) and
// often the title itself. This tool dumps every row so that review can
// happen title-by-title / range-by-range instead of guessing from a
// 4-sample-per-prefix summary.
//
// Usage:
//   php tools/list_multi_category_review.php
//   php tools/list_multi_category_review.php --prefix=HV     (just one prefix)

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config/db_connect.php';

const REVIEW_PREFIXES = ['HV', 'G', 'HD', 'HF'];

function lmcr_extract(string $callNumber): array {
    $c = trim($callNumber);
    if ($c === '') {
        return ['', 0.0];
    }
    $c = preg_replace('/\s+/', ' ', $c) ?? $c;
    $c = preg_replace('/^(FIL|GEN|REF|RES|FIC)\.?\s+/i', '', $c) ?? $c;
    if (preg_match('/^([A-Za-z]{1,3})\s*([\d.]+)/', $c, $m)) {
        return [strtoupper($m[1]), (float)$m[2]];
    }
    if (preg_match('/^([A-Za-z]{1,3})\b/', $c, $m)) {
        return [strtoupper($m[1]), 0.0];
    }
    return ['', 0.0];
}

$only = null;
foreach ($argv as $arg) {
    if (preg_match('/^--prefix=([A-Za-z]+)$/', (string)$arg, $m)) {
        $only = strtoupper($m[1]);
    }
}
$wantedPrefixes = $only !== null ? [$only] : REVIEW_PREFIXES;

$res = $conn->query(
    "SELECT book_id, title, author, call_number
     FROM books
     WHERE category_id IS NULL OR category_id = 0"
);
if (!$res) {
    fwrite(STDERR, 'Query failed: ' . $conn->error . PHP_EOL);
    exit(1);
}

$byPrefix = [];
while ($row = $res->fetch_assoc()) {
    [$prefix, $num] = lmcr_extract((string)($row['call_number'] ?? ''));
    if (!in_array($prefix, $wantedPrefixes, true)) {
        continue;
    }
    $row['_num'] = $num;
    $byPrefix[$prefix][] = $row;
}

$total = array_sum(array_map('count', $byPrefix));
echo "Books to review across " . implode(', ', $wantedPrefixes) . ": {$total}" . PHP_EOL;
echo PHP_EOL;

foreach ($wantedPrefixes as $prefix) {
    $rows = $byPrefix[$prefix] ?? [];
    if (empty($rows)) {
        continue;
    }
    usort($rows, static fn($a, $b) => $a['_num'] <=> $b['_num']);
    echo "=== {$prefix} (" . count($rows) . " books) ===" . PHP_EOL;
    foreach ($rows as $r) {
        echo sprintf(
            "  #%-6d [%-22s] \"%s\" by %s",
            $r['book_id'],
            trim((string)$r['call_number']),
            $r['title'],
            $r['author']
        ) . PHP_EOL;
    }
    echo PHP_EOL;
}
?>
