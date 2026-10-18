<?php
declare(strict_types=1);

// Read-only diagnostic: for every book that currently has a cover filename
// set, inspects the actual image file (dimensions, aspect ratio, and --
// for JPEGs -- camera EXIF metadata) to flag ones that are very unlikely
// to be real book cover art. Prompted by spot-checks that found several
// books displaying what look like personal photos (selfies, group photos,
// unrelated snapshots) instead of a book cover.
//
// Heuristics used (a book cover is almost always a portrait-or-square
// scan/render with no camera metadata):
//   - LANDSCAPE: width > height. Real cover art is essentially never
//     landscape; a landscape photo is a strong signal of a snapshot.
//   - CAMERA_EXIF: the file carries EXIF Make/Model/DateTimeOriginal --
//     a downloaded cover image from a publisher or a covers API would not.
//   - VERY_LARGE: width or height over 1600px. Typical downloaded web
//     cover art is small (a few hundred px); modern phone photos are huge.
// None of these alone is proof, but a book tripping more than one is
// worth a manual look before trusting it as a real cover.
//
// Usage:
//   php tools/check_cover_quality.php
//   php tools/check_cover_quality.php --suspicious-only

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config/db_connect.php';

const COVERS_DIR = __DIR__ . '/../assets/covers';

$suspiciousOnly = in_array('--suspicious-only', $argv, true);

$res = $conn->query(
    "SELECT book_id, title, author, cover FROM books
     WHERE cover IS NOT NULL AND TRIM(cover) <> ''
     ORDER BY book_id"
);
if (!$res) {
    fwrite(STDERR, 'Query failed: ' . $conn->error . PHP_EOL);
    exit(1);
}

$hasExif = function_exists('exif_read_data');
if (!$hasExif) {
    echo '(Note: PHP exif extension not available -- skipping camera-metadata check, using dimension checks only.)' . PHP_EOL . PHP_EOL;
}

$total = 0;
$missing = 0;
$suspicious = 0;
$clean = 0;

$rows = [];
while ($row = $res->fetch_assoc()) {
    $rows[] = $row;
}

foreach ($rows as $r) {
    $total++;
    $filename = basename((string)$r['cover']);
    $fullPath = COVERS_DIR . '/' . $filename;

    if (!is_file($fullPath)) {
        $missing++;
        echo sprintf("  #%-6d \"%s\" -- MISSING FILE (cover='%s')", $r['book_id'], $r['title'], $filename) . PHP_EOL;
        continue;
    }

    $info = @getimagesize($fullPath);
    if ($info === false) {
        $suspicious++;
        echo sprintf("  #%-6d \"%s\" -- SUSPICIOUS: file exists but is not a readable image (%s)", $r['book_id'], $r['title'], $filename) . PHP_EOL;
        continue;
    }
    [$width, $height, $type] = $info;
    $flags = [];

    if ($width > $height) {
        $flags[] = 'LANDSCAPE';
    }
    if ($width > 1600 || $height > 1600) {
        $flags[] = 'VERY_LARGE';
    }
    if ($hasExif && $type === IMAGETYPE_JPEG) {
        $exif = @exif_read_data($fullPath, 'IFD0', false);
        if (is_array($exif) && (isset($exif['Make']) || isset($exif['Model']) || isset($exif['DateTimeOriginal']))) {
            $camera = trim(($exif['Make'] ?? '') . ' ' . ($exif['Model'] ?? ''));
            $flags[] = 'CAMERA_EXIF(' . ($camera !== '' ? $camera : 'metadata present') . ')';
        }
    }

    if (!empty($flags)) {
        $suspicious++;
        echo sprintf(
            "  #%-6d \"%s\" by %s -- SUSPICIOUS [%s] -- %s (%dx%d)",
            $r['book_id'], $r['title'], $r['author'], implode(', ', $flags), $filename, $width, $height
        ) . PHP_EOL;
    } else {
        $clean++;
        if (!$suspiciousOnly) {
            echo sprintf(
                "  #%-6d \"%s\" by %s -- looks fine -- %s (%dx%d)",
                $r['book_id'], $r['title'], $r['author'], $filename, $width, $height
            ) . PHP_EOL;
        }
    }
}

echo PHP_EOL;
echo "Total covers checked: {$total}" . PHP_EOL;
echo "  Missing file:  {$missing}" . PHP_EOL;
echo "  Suspicious:    {$suspicious}" . PHP_EOL;
echo "  Looks fine:    {$clean}" . PHP_EOL;
?>
