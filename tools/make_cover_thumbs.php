<?php
declare(strict_types=1);

// Builds the small list thumbnails (assets/covers/thumbs/<name>.jpg, 160px
// wide, see config/cover_thumbs.php) for every cover in assets/covers/ that
// does not have an up-to-date one yet.
//
// New covers get their thumbnail when they are saved (admin fetch / upload,
// the nightly fetch); run this once after deploying thumbnails, and any time
// later to catch up. Safe to run again: thumbnails that are not older than
// their original are skipped. It never changes the original covers and
// never touches the database.
//
// Default: dry run (prints what it would do). Write with --commit:
//   php tools/make_cover_thumbs.php
//   php tools/make_cover_thumbs.php --commit

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/cover_fetch_helpers.php'; // EXIF orientation helper; loads config/cover_thumbs.php

$commit = in_array('--commit', array_slice($argv, 1), true);
$coversDir = realpath(__DIR__ . '/../assets/covers');
if ($coversDir === false) {
    fwrite(STDERR, 'assets/covers not found.' . PHP_EOL);
    exit(1);
}
$thumbsDir = smartlib_cover_thumbs_dir();

echo ($commit ? '*** COMMIT MODE -- thumbnails will be written ***' : '(DRY RUN -- pass --commit to write the thumbnails)') . PHP_EOL;
if (!function_exists('imagecreatefromstring')) {
    echo 'Note: the PHP gd extension is not enabled, so no thumbnail can be made.' . PHP_EOL;
}

// Originals: image files directly in assets/covers/ (not in thumbs/).
$originals = [];
foreach (scandir($coversDir) ?: [] as $entry) {
    $path = $coversDir . DIRECTORY_SEPARATOR . $entry;
    if (is_file($path) && preg_match('/\.(jpe?g|png)$/i', $entry)) {
        $originals[] = $entry;
    }
}
sort($originals, SORT_STRING);

// Two originals with the same name but different extensions share one
// thumbnail name (e.g. cover_12.jpg left over next to cover_12.png): the
// newest original wins, as it is the one a save wrote last.
$byThumb = [];
$shadowed = [];
foreach ($originals as $entry) {
    $thumb = smartlib_cover_thumb_name($entry);
    if (isset($byThumb[$thumb])) {
        $other = $byThumb[$thumb];
        if (filemtime($coversDir . '/' . $entry) > filemtime($coversDir . '/' . $other)) {
            $shadowed[] = $other . ' (older than ' . $entry . ')';
            $byThumb[$thumb] = $entry;
        } else {
            $shadowed[] = $entry . ' (older than ' . $other . ')';
        }
        continue;
    }
    $byThumb[$thumb] = $entry;
}

$created = 0;
$wouldCreate = 0;
$upToDate = 0;
$failed = 0;
$originalBytes = 0;
foreach ($byThumb as $thumb => $entry) {
    $originalPath = $coversDir . DIRECTORY_SEPARATOR . $entry;
    $thumbPath = $thumbsDir . '/' . $thumb;
    $originalBytes += (int)filesize($originalPath);
    clearstatcache(true, $thumbPath);
    if (is_file($thumbPath) && filemtime($thumbPath) >= filemtime($originalPath)) {
        $upToDate++;
        continue;
    }
    if (!$commit) {
        $wouldCreate++;
        continue;
    }
    if (smartlib_cover_thumb_make($originalPath)) {
        $created++;
    } else {
        $failed++;
        echo '  FAILED: ' . $entry . ' (reason in the PHP error log)' . PHP_EOL;
    }
}

$thumbBytes = 0;
$thumbCount = 0;
$orphans = 0;
if (is_dir($thumbsDir)) {
    foreach (scandir($thumbsDir) ?: [] as $entry) {
        $path = $thumbsDir . '/' . $entry;
        if (!is_file($path) || !preg_match('/\.jpg$/i', $entry) || $entry[0] === '.') {
            continue;
        }
        $thumbCount++;
        $thumbBytes += (int)filesize($path);
        if (!isset($byThumb[$entry])) {
            $orphans++;
        }
    }
}

$mb = static function (int $bytes): string {
    return number_format($bytes / 1048576, 2) . ' MB';
};
echo PHP_EOL . 'Covers: ' . count($originals) . ' files, ' . count($byThumb) . ' thumbnails needed' . PHP_EOL;
if ($commit) {
    echo 'Created: ' . $created . PHP_EOL;
} else {
    echo 'Would create: ' . $wouldCreate . PHP_EOL;
}
echo 'Skipped (up to date): ' . $upToDate . PHP_EOL;
echo 'Failed: ' . $failed . PHP_EOL;
foreach ($shadowed as $line) {
    echo 'Not used (same name as a newer cover): ' . $line . PHP_EOL;
}
if ($orphans > 0) {
    echo 'Thumbnails without an original (left as they are): ' . $orphans . PHP_EOL;
}
echo 'Bytes: originals ' . $mb($originalBytes) . ', thumbnails now ' . $thumbCount . ' files, ' . $mb($thumbBytes) . PHP_EOL;
echo ($commit ? 'Done.' : 'Dry run complete -- re-run with --commit to write the thumbnails.') . PHP_EOL;
exit($failed > 0 ? 1 : 0);
