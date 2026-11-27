<?php
declare(strict_types=1);
/**
 * Small list thumbnails of the book covers (Prompt 43).
 *
 * The admin Books list shows each cover 40px wide, but the originals in
 * assets/covers/ are full-size (median ~340x500, some over 2000px wide).
 * A thumbnail is assets/covers/thumbs/<same name>.jpg: 160px wide (enough
 * for a 40px slot on a 4x screen), height by the aspect ratio, never
 * upscaled, JPEG quality 80, transparency flattened onto white, EXIF
 * orientation applied. GD only, no network.
 *
 * - smartlib_cover_thumb_make()   builds one (cover saves and the backfill
 *                                 tool tools/make_cover_thumbs.php)
 * - smartlib_cover_thumb_delete() removes one (cover cleared or replaced)
 * - smartlib_cover_thumb_url()    picks the thumbnail when it exists and is
 *                                 not older than the original, else the
 *                                 original. It never builds a thumbnail
 *                                 while a page is being served.
 *
 * None of these throw; a missing thumbnail only means the original is shown.
 */

const SMARTLIB_COVER_THUMB_WIDTH = 160;
const SMARTLIB_COVER_THUMB_QUALITY = 80;
// Decoding needs about 5 bytes per pixel; larger images are skipped (and
// logged) instead of risking the memory limit. 40 MP covers every real cover.
const SMARTLIB_COVER_THUMB_MAX_PIXELS = 40000000;

function smartlib_cover_thumbs_dir(): string {
    return __DIR__ . '/../assets/covers/thumbs';
}

/** cover_12.png -> cover_12.jpg ('' for a name that has no file part). */
function smartlib_cover_thumb_name(string $coverFilename): string {
    $base = basename(trim($coverFilename));
    $stem = (string)preg_replace('/\.[^.]*$/', '', $base);
    return ($stem === '' || $stem === '.' || $stem === '..') ? '' : $stem . '.jpg';
}

function smartlib_cover_thumb_log(string $message): void {
    error_log('SmartLib cover thumbs: ' . $message);
}

/** EXIF orientation of JPEG bytes (1 = as stored). */
function smartlib_cover_thumb_orientation(string $bytes): int {
    if (function_exists('fbc_jpeg_exif_orientation')) {
        return fbc_jpeg_exif_orientation($bytes);
    }
    return 1;
}

/** Turns a decoded image upright for EXIF orientation 2..8. */
function smartlib_cover_thumb_orient($img, int $orientation) {
    if ($orientation === 1 || !function_exists('imagerotate') || !function_exists('imageflip')) {
        return $img;
    }
    if (in_array($orientation, [2, 4, 5, 7], true)) {
        imageflip($img, IMG_FLIP_HORIZONTAL);
    }
    $angle = [3 => 180, 4 => 180, 5 => 270, 6 => 270, 7 => 90, 8 => 90][$orientation] ?? 0;
    if ($angle !== 0) {
        $rotated = imagerotate($img, $angle, 0);
        if ($rotated !== false) {
            imagedestroy($img);
            $img = $rotated;
        }
    }
    return $img;
}

/**
 * Builds (or rebuilds) the thumbnail of one original cover file. Written to a
 * temporary file first and then renamed, so a half-written thumbnail is
 * never served. Returns false, with a short reason in the PHP error log,
 * when GD is missing or the image cannot be read; never throws.
 */
function smartlib_cover_thumb_make(string $originalPath): bool {
    $tmp = null;
    try {
        foreach (['imagecreatefromstring', 'imagecreatetruecolor', 'imagecopyresampled', 'imagejpeg', 'imagefill', 'imagecolorallocate'] as $fn) {
            if (!function_exists($fn)) {
                smartlib_cover_thumb_log('GD function ' . $fn . ' is missing; no thumbnail made.');
                return false;
            }
        }
        $name = smartlib_cover_thumb_name($originalPath);
        if ($name === '' || !is_file($originalPath)) {
            smartlib_cover_thumb_log('no such cover file: ' . basename($originalPath));
            return false;
        }
        $bytes = @file_get_contents($originalPath);
        if ($bytes === false || $bytes === '') {
            smartlib_cover_thumb_log('could not read ' . basename($originalPath));
            return false;
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false || (int)$info[0] < 1 || (int)$info[1] < 1) {
            smartlib_cover_thumb_log('not a readable image: ' . basename($originalPath));
            return false;
        }
        if ((int)$info[0] * (int)$info[1] > SMARTLIB_COVER_THUMB_MAX_PIXELS) {
            smartlib_cover_thumb_log('image too large to decode safely (' . $info[0] . 'x' . $info[1] . '): ' . basename($originalPath));
            return false;
        }
        $dir = smartlib_cover_thumbs_dir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            smartlib_cover_thumb_log('could not create the thumbs folder.');
            return false;
        }
        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            smartlib_cover_thumb_log('GD could not decode ' . basename($originalPath));
            return false;
        }
        if (($info[2] ?? 0) === IMAGETYPE_JPEG) {
            $src = smartlib_cover_thumb_orient($src, smartlib_cover_thumb_orientation($bytes));
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $tw = min(SMARTLIB_COVER_THUMB_WIDTH, $w);
        $th = max(1, (int)round($h * $tw / $w));

        $dst = imagecreatetruecolor($tw, $th);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        if (function_exists('imagealphablending')) {
            imagealphablending($dst, true); // transparent pixels blend onto the white
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
        imagedestroy($src);

        $tmp = $dir . '/.tmp-' . bin2hex(random_bytes(6)) . '.jpg';
        $ok = imagejpeg($dst, $tmp, SMARTLIB_COVER_THUMB_QUALITY);
        imagedestroy($dst);
        if (!$ok || !is_file($tmp) || filesize($tmp) === 0) {
            smartlib_cover_thumb_log('could not write the thumbnail of ' . basename($originalPath));
            @unlink($tmp);
            return false;
        }
        // A small upright JPEG kept at its own size: its own bytes when
        // re-encoding came out larger.
        if (($info[2] ?? 0) === IMAGETYPE_JPEG && $tw === $w && smartlib_cover_thumb_orientation($bytes) === 1
            && filesize($tmp) > strlen($bytes)) {
            if (@file_put_contents($tmp, $bytes) !== strlen($bytes)) {
                @unlink($tmp);
                smartlib_cover_thumb_log('could not copy the small cover ' . basename($originalPath));
                return false;
            }
        }
        @chmod($tmp, 0644);
        if (!@rename($tmp, $dir . '/' . $name)) {
            @unlink($tmp);
            smartlib_cover_thumb_log('could not move the thumbnail of ' . basename($originalPath) . ' into place');
            return false;
        }
        return true;
    } catch (Throwable $e) {
        if ($tmp !== null) {
            @unlink($tmp);
        }
        smartlib_cover_thumb_log('failed for ' . basename($originalPath) . ' (' . $e->getMessage() . ')');
        return false;
    }
}

/** Removes the thumbnail of a cover file name (cover cleared or replaced). */
function smartlib_cover_thumb_delete(string $coverFilename): void {
    $name = smartlib_cover_thumb_name($coverFilename);
    if ($name === '') {
        return;
    }
    $path = smartlib_cover_thumbs_dir() . '/' . $name;
    if (is_file($path)) {
        @unlink($path);
    }
}

/**
 * URL of the small version of a cover for lists: the thumbnail when it
 * exists and is not older than the original (with ?v=<its time> so a rebuilt
 * one is not served from the browser cache), otherwise the original URL as
 * it was before thumbnails existed. $prefix is the path to the site root
 * ('../' from admin/, '' from the root). Only reads file times.
 */
function smartlib_cover_thumb_url(string $coverFilename, string $prefix): string {
    $base = basename(trim($coverFilename));
    $original = $prefix . 'assets/covers/' . $base;
    $name = smartlib_cover_thumb_name($base);
    if ($name === '') {
        return $original;
    }
    $originalPath = __DIR__ . '/../assets/covers/' . $base;
    $thumbPath = smartlib_cover_thumbs_dir() . '/' . $name;
    $thumbTime = is_file($thumbPath) ? @filemtime($thumbPath) : false;
    $originalTime = is_file($originalPath) ? @filemtime($originalPath) : false;
    if ($thumbTime === false || $originalTime === false || $thumbTime < $originalTime) {
        return $original;
    }
    return $prefix . 'assets/covers/thumbs/' . $name . '?v=' . $thumbTime;
}
