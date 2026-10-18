<?php
declare(strict_types=1);

// Cleanup companion to fetch_book_covers.php: finds covers THAT TOOL
// created (filename pattern cover_<book_id>.<ext>) which turned out to be
// landscape-oriented -- a strong signal the image is wrong, since a real
// book cover is essentially never landscape. This happens when Open
// Library's crowdsourced data has an unrelated image attached to a work
// that otherwise matched by title+author (confirmed cases: a water-spinach
// photo on a novel literally named after the vegetable, personal photos on
// a few Filipino literature titles, etc.). fetch_book_covers.php itself was
// fixed to reject landscape images going forward -- this tool cleans up
// covers saved before that fix.
//
// Only ever touches files matching the cover_<id>.<ext> naming convention
// (i.e. ones this project's own tool created) -- never a pre-existing
// cover with a different filename, since we don't know that provenance.
//
// Safe by default: dry-run unless --commit is passed. --commit deletes the
// bad image file and sets books.cover back to NULL, so a later
// fetch_book_covers.php run will pick the book up again and try to find a
// correct (portrait) cover -- or correctly skip it if none exists.
//
// The landscape check only catches "obviously a photo, wrong shape." It
// cannot catch a correctly-shaped (portrait) image that's still wrong
// content -- e.g. a scanned table-of-contents page, or a half-title/series
// page instead of real cover art. For those, --force + --book_ids lets a
// human who has actually looked at the image (not just its dimensions)
// override the shape check for specific, named books. --force is ignored
// unless --book_ids is also given, so a bare --force can't blow away
// every cover in the catalog by accident.
//
// Usage:
//   php tools/clear_bad_covers.php
//   php tools/clear_bad_covers.php --commit
//   php tools/clear_bad_covers.php --book_ids=326,603,767   (restrict to specific books)
//   php tools/clear_bad_covers.php --book_ids=1690,768 --force --commit   (human-verified bad, not landscape)

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config/db_connect.php';

const COVERS_DIR = __DIR__ . '/../assets/covers';

$commit = in_array('--commit', $argv, true);
$onlyIds = null;
foreach ($argv as $arg) {
    if (preg_match('/^--book_ids=([\d,]+)$/', (string)$arg, $m)) {
        $onlyIds = array_map('intval', explode(',', $m[1]));
    }
}
// Only meaningful (and only honored) alongside an explicit --book_ids list --
// see the big comment above for why.
$force = in_array('--force', $argv, true) && $onlyIds !== null && !empty($onlyIds);

$where = "cover REGEXP '^cover_[0-9]+\\\\.(jpg|jpeg|png)$'";
if ($onlyIds !== null && !empty($onlyIds)) {
    $where .= ' AND book_id IN (' . implode(',', $onlyIds) . ')';
}

$res = $conn->query("SELECT book_id, title, author, cover FROM books WHERE {$where} ORDER BY book_id");
if (!$res) {
    fwrite(STDERR, 'Query failed: ' . $conn->error . PHP_EOL);
    exit(1);
}

$candidates = [];
while ($row = $res->fetch_assoc()) {
    $candidates[] = $row;
}

echo 'Covers created by fetch_book_covers.php to check: ' . count($candidates) . PHP_EOL;
echo ($commit ? '*** COMMIT MODE -- bad image files will be deleted and books.cover cleared ***' : '(DRY RUN -- pass --commit to actually delete and clear)') . PHP_EOL;
echo PHP_EOL;

$bad = [];
foreach ($candidates as $r) {
    $filename = basename((string)$r['cover']);
    $fullPath = COVERS_DIR . '/' . $filename;
    if (!is_file($fullPath)) {
        continue;
    }
    $info = @getimagesize($fullPath);
    if ($info === false) {
        continue;
    }
    [$width, $height] = $info;
    $isLandscape = $width > $height;
    if ($isLandscape || $force) {
        $bad[] = [
            'row' => $r,
            'path' => $fullPath,
            'filename' => $filename,
            'width' => $width,
            'height' => $height,
            'reason' => $isLandscape ? 'landscape' : 'human-verified bad (--force)',
        ];
    }
}

echo 'Bad covers found: ' . count($bad) . PHP_EOL . PHP_EOL;

$cleared = 0;
$errors = 0;
foreach ($bad as $b) {
    $r = $b['row'];
    echo sprintf(
        '  #%-6d "%s" by %s -- %s (%dx%d) [%s]',
        $r['book_id'], $r['title'], $r['author'], $b['filename'], $b['width'], $b['height'], $b['reason']
    ) . PHP_EOL;

    if ($commit) {
        if (!@unlink($b['path'])) {
            fwrite(STDERR, "  Failed to delete {$b['path']}" . PHP_EOL);
            $errors++;
            continue;
        }
        $stmt = $conn->prepare('UPDATE books SET cover = NULL WHERE book_id = ?');
        $bookId = (int)$r['book_id'];
        $stmt->bind_param('i', $bookId);
        if (!$stmt->execute()) {
            fwrite(STDERR, "  DB update failed for book #{$bookId}: " . $stmt->error . PHP_EOL);
            $errors++;
        } else {
            $cleared++;
        }
        $stmt->close();
    }
}

echo PHP_EOL;
if ($commit) {
    echo "Cleared: {$cleared}" . PHP_EOL;
    echo "Errors:  {$errors}" . PHP_EOL;
    echo PHP_EOL . 'Done. Re-run tools/fetch_book_covers.php --commit to try finding correct covers for these (now-fixed to reject landscape images).' . PHP_EOL;
} else {
    echo 'Dry run complete -- re-run with --commit to delete these files and clear their books.cover value.' . PHP_EOL;
}
?>
