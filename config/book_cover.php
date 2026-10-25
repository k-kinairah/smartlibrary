<?php
/**
 * Generated default book cover, as inline SVG -- shown for any book without a
 * real fetched cover, instead of one repeated gray placeholder.
 *
 * Same approach as config/barcode_code128.php: plain PHP building an SVG
 * string, no GD or external library. book_cover.php serves it as an image
 * (so every existing <img>-based cover slot works unchanged).
 *
 * Design follows the library card ("Midnight") and admin panel: a deep
 * colour from a small curated palette, faint engraved arcs, a spine, the
 * school seal with the SMARTLIB wordmark, a short yellow rule, the title in
 * bold Georgia and the author in small tracked capitals.
 *
 * Layout is in a 200 x 300 (2:3) box. Covers are shown anywhere from 2:3 to
 * nearly square (the kiosk grid on phones crops to ~1.13 with object-fit:
 * cover, cutting ~37 units off the top and bottom), so everything that must
 * stay readable sits between y = 40 and y = 260.
 *
 * Colour is picked by hashing the title, so a title always gets the same
 * cover on every load.
 */

const BOOK_COVER_VERSION = 2;

// [background, spine, engraving line, rule accent, secondary text]
const BOOK_COVER_PALETTE = [
    ['#0f5132', '#0a3a24', '#176240', '#ffd84d', '#cfe3d6'], // card green
    // Burgundy: the admin panel's red (#b52f3a) darkened (x0.55; spine x0.40,
    // engraving x0.64). Replaces card "Midnight" #0c4129, which was only
    // delta-E 8.2 from card green and read as the same colour in a grid.
    ['#641a20', '#481317', '#741e25', '#ffd84d', '#ecd2d4'],
    ['#1c4573', '#12304f', '#25548a', '#ffd84d', '#c9d9ec'], // admin navy
    ['#2e67a6', '#214d7e', '#3a74b4', '#ffd84d', '#dce8f6'], // PHINMA blue
    ['#135454', '#0c3b3b', '#1b6666', '#ffd84d', '#c8e0de'], // green-blue teal
    ['#6b4f0f', '#4d3909', '#7d5d16', '#ffe48a', '#ecdfbe'], // gold, deepened
];

const BOOK_COVER_FONT_SERIF = "Georgia, 'Times New Roman', serif";
const BOOK_COVER_FONT_SANS = 'Arial, Helvetica, sans-serif';

// Text area and title block (viewBox units).
const BOOK_COVER_TEXT_X = 30;
const BOOK_COVER_TEXT_W = 150;
const BOOK_COVER_TITLE_TOP = 94;
const BOOK_COVER_TITLE_BOTTOM = 212;

/** Pick the palette entry for a title. Deterministic: same title, same colour. */
function book_cover_palette(string $title): array {
    $key = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $title)));
    return BOOK_COVER_PALETTE[crc32($key) % count(BOOK_COVER_PALETTE)];
}

/**
 * Approximate rendered width of $text in em, for wrapping. Rough per-glyph
 * widths for bold Georgia/Arial; a small safety margin is applied by callers.
 */
function book_cover_text_em(string $text, float $tracking = 0.0): float {
    $em = 0.0;
    $len = mb_strlen($text);
    for ($i = 0; $i < $len; $i++) {
        $ch = mb_substr($text, $i, 1);
        if ($ch === ' ') {
            $em += 0.26;
        } elseif (strpbrk($ch, "iIl.,:;'!|j") !== false) {
            $em += 0.31;
        } elseif (strpbrk($ch, 'frt()-/[]') !== false) {
            $em += 0.42;
        } elseif (strpbrk($ch, 'mw') !== false) {
            $em += 0.88;
        } elseif (strpbrk($ch, 'MW') !== false) {
            $em += 0.98;
        } elseif (preg_match('/\p{Lu}/u', $ch)) {
            $em += 0.73;
        } elseif (preg_match('/\d/', $ch)) {
            $em += 0.63;
        } else {
            $em += 0.56;
        }
        $em += $tracking;
    }
    return $em;
}

/** Greedy word wrap into lines no wider than $maxWidth at $size. */
function book_cover_wrap(string $text, float $size, float $maxWidth, float $tracking = 0.0): array {
    $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $lines = [];
    $current = '';
    foreach ($words as $word) {
        // A single word wider than the line (rare: URLs, codes) is split by character.
        while (book_cover_text_em($word, $tracking) * $size > $maxWidth && mb_strlen($word) > 1) {
            $cut = mb_strlen($word);
            while ($cut > 1 && book_cover_text_em(mb_substr($word, 0, $cut), $tracking) * $size > $maxWidth) {
                $cut--;
            }
            if ($current !== '') {
                $lines[] = $current;
                $current = '';
            }
            $lines[] = mb_substr($word, 0, $cut);
            $word = mb_substr($word, $cut);
        }
        $candidate = $current === '' ? $word : $current . ' ' . $word;
        if ($current !== '' && book_cover_text_em($candidate, $tracking) * $size > $maxWidth) {
            $lines[] = $current;
            $current = $word;
        } else {
            $current = $candidate;
        }
    }
    if ($current !== '') {
        $lines[] = $current;
    }
    return $lines;
}

/** Keep the first $max lines; the last gets an ellipsis that fits the width. */
function book_cover_clamp(array $lines, int $max, float $size, float $maxWidth, float $tracking = 0.0): array {
    if (count($lines) <= $max) {
        return $lines;
    }
    $lines = array_slice($lines, 0, $max);
    $last = rtrim($lines[$max - 1], " ,.;:-");
    while ($last !== '' && book_cover_text_em($last . '…', $tracking) * $size > $maxWidth) {
        $last = preg_replace('/\s*\S+$/u', '', $last) ?? '';
    }
    $lines[$max - 1] = rtrim($last, " ,.;:-") . '…';
    return $lines;
}

/** Split "Main title: subtitle" so the main title can be set larger. */
function book_cover_split_title(string $title): array {
    $title = trim(preg_replace('/\s+/u', ' ', $title));
    if (preg_match('/^(.{3,110}?)\s*:\s+(.+)$/u', $title, $m)) {
        return [trim($m[1]), trim($m[2])];
    }
    return [$title, ''];
}

/** "Mendoza, Ernesto H. & Garcia, Lorma S.P" -> "Mendoza, Ernesto H. et al." */
function book_cover_author_line(string $author): string {
    $author = trim(preg_replace('/\s+/u', ' ', $author), " ,.;");
    if ($author === '') {
        return '';
    }
    $parts = preg_split('/\s*(?:&|;|\band\b|\bwith\b)\s*/iu', $author, 2);
    $first = trim((string)($parts[0] ?? ''), " ,.;");
    if ($first !== '' && count($parts) > 1) {
        return $first . ' et al.';
    }
    return $author;
}

function book_cover_esc(string $s): string {
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/** Build the cover SVG for a book. */
function book_cover_svg(string $title, string $author = ''): string {
    $title = trim($title) !== '' ? trim($title) : 'Untitled';
    [$bg, $spine, $line, $accent, $muted] = book_cover_palette($title);
    $x = BOOK_COVER_TEXT_X;
    $w = BOOK_COVER_TEXT_W * 0.96; // safety margin on the width estimate

    // ---- Title: largest size whose wrapped lines fit the block ----
    // A size only qualifies if its longest word fits on one line, so real
    // words are never broken mid-word. Below the smallest size the title is
    // clamped with an ellipsis rather than shrunk further, to stay readable.
    [$main, $sub] = book_cover_split_title($title);
    $blockH = BOOK_COVER_TITLE_BOTTOM - BOOK_COVER_TITLE_TOP;
    $lineH = 1.17;
    $sizes = [25, 22, 19.5, 17.5, 15.5, 14.5, 13.5];
    $longestWord = 0.0;
    foreach (preg_split('/\s+/u', $main, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
        $longestWord = max($longestWord, book_cover_text_em($word));
    }
    $size = (float)end($sizes);
    $mainLines = [];
    foreach ($sizes as $try) {
        if ($longestWord * $try > $w) {
            continue;
        }
        $wrapped = book_cover_wrap($main, $try, $w);
        if (count($wrapped) * $try * $lineH <= $blockH) {
            $size = (float)$try;
            $mainLines = $wrapped;
            break;
        }
    }
    if ($mainLines === []) {
        $mainLines = book_cover_clamp(book_cover_wrap($main, $size, $w), (int)floor($blockH / ($size * $lineH)), $size, $w);
    }
    $used = count($mainLines) * $size * $lineH;

    // ---- Subtitle: smaller italic lines in whatever room is left ----
    $subSize = 10.0;
    $subLines = [];
    if ($sub !== '') {
        $room = $blockH - $used - 4;
        $maxSub = min(3, (int)floor($room / ($subSize * 1.25)));
        if ($maxSub >= 1) {
            $subLines = book_cover_clamp(book_cover_wrap($sub, $subSize, $w), $maxSub, $subSize, $w);
        }
    }

    $out = [];
    $out[] = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 300" width="200" height="300" role="img" aria-label="' . book_cover_esc($title) . '">';
    $out[] = '<defs><clipPath id="c"><rect width="200" height="300"/></clipPath></defs>';
    $out[] = '<g clip-path="url(#c)">';
    $out[] = '<rect width="200" height="300" fill="' . $bg . '"/>';

    // Engraved arcs, one shade lighter than the background (as on the card).
    $out[] = '<g fill="none" stroke="' . $line . '" stroke-width="0.9">';
    for ($r = 24; $r <= 300; $r += 8) {
        $out[] = '<circle cx="214" cy="318" r="' . $r . '"/>';
    }
    $out[] = '</g>';

    // Spine: darker strip with a thin highlight line.
    $out[] = '<rect width="13" height="300" fill="' . $spine . '"/>';
    $out[] = '<rect x="13" width="1.2" height="300" fill="' . $line . '"/>';

    // Branding: the school seal and SMARTLIB wordmark (inside the crop-safe zone).
    $seal = __DIR__ . '/../assets/images/stjude_seal_64.png';
    if (is_readable($seal)) {
        $out[] = '<image x="' . $x . '" y="42" width="21" height="21" href="data:image/png;base64,' . base64_encode((string)file_get_contents($seal)) . '"/>';
    }
    $out[] = '<text x="' . ($x + 27) . '" y="51" font-family="' . BOOK_COVER_FONT_SANS . '" font-size="7.6" font-weight="700" letter-spacing="1.6" fill="' . $accent . '">SMARTLIB</text>';
    $out[] = '<text x="' . ($x + 27) . '" y="61" font-family="' . BOOK_COVER_FONT_SANS . '" font-size="5.6" font-weight="700" letter-spacing="0.9" fill="' . $muted . '">PHINMA-SJCDC LIBRARY</text>';

    // Short yellow rule above the title, as on the card's letterhead.
    $out[] = '<rect x="' . $x . '" y="77" width="26" height="2.2" fill="' . $accent . '"/>';

    // Title.
    $y = BOOK_COVER_TITLE_TOP + $size * 0.82;
    $out[] = '<text font-family="' . BOOK_COVER_FONT_SERIF . '" font-weight="700" font-size="' . $size . '" fill="#ffffff">';
    foreach ($mainLines as $i => $text) {
        $out[] = '<tspan x="' . $x . '" y="' . round($y + $i * $size * $lineH, 2) . '">' . book_cover_esc($text) . '</tspan>';
    }
    $out[] = '</text>';

    if ($subLines !== []) {
        $sy = BOOK_COVER_TITLE_TOP + $used + 4 + $subSize * 0.85;
        $out[] = '<text font-family="' . BOOK_COVER_FONT_SERIF . '" font-style="italic" font-size="' . $subSize . '" fill="' . $muted . '">';
        foreach ($subLines as $i => $text) {
            $out[] = '<tspan x="' . $x . '" y="' . round($sy + $i * $subSize * 1.25, 2) . '">' . book_cover_esc($text) . '</tspan>';
        }
        $out[] = '</text>';
    }

    // Author: one line, small tracked capitals.
    $authorLine = book_cover_author_line($author);
    if ($authorLine !== '') {
        $authorSize = 7.4;
        $tracking = 0.12;
        $authorText = mb_strtoupper($authorLine);
        $authorText = book_cover_clamp(book_cover_wrap($authorText, $authorSize, BOOK_COVER_TEXT_W, $tracking), 1, $authorSize, BOOK_COVER_TEXT_W, $tracking)[0] ?? '';
        $out[] = '<rect x="' . $x . '" y="226" width="14" height="1" fill="' . $muted . '"/>';
        $out[] = '<text x="' . $x . '" y="242" font-family="' . BOOK_COVER_FONT_SANS . '" font-size="' . $authorSize . '" font-weight="700" letter-spacing="' . ($authorSize * $tracking) . '" fill="' . $muted . '">' . book_cover_esc($authorText) . '</text>';
    }

    // Thin accent band at the foot of the cover.
    $out[] = '<rect y="294" width="200" height="6" fill="' . $spine . '"/>';
    $out[] = '<rect y="294" width="200" height="1.4" fill="' . $accent . '"/>';

    $out[] = '</g></svg>';
    return implode('', $out);
}

/** URL of the generated cover for a book (for <img src>), relative to the site root. */
function book_cover_url(int $bookId): string {
    return 'book_cover.php?id=' . $bookId . '&v=' . BOOK_COVER_VERSION;
}
