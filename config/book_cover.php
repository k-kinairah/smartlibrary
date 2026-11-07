<?php
/**
 * Generated default book cover, as inline SVG -- shown for any book without a
 * real fetched cover, instead of one repeated gray placeholder.
 *
 * Same approach as config/barcode_code128.php: plain PHP building an SVG
 * string, no GD or external library. book_cover.php serves it as an image
 * (so every existing <img>-based cover slot works unchanged).
 *
 * v3 redesign (2026-11): color is now genre-driven instead of a random hash.
 * Every book's category resolves to one of the broad subject groups shown
 * on the kiosk's own "Filter by subject/genre" chips (the `category_group`
 * column on `categories`, the same field fetch_books.php uses for those
 * chips -- see CATEGORY_FAMILIES below). A read-only audit of the live
 * catalog (2026-11, 2,015 books) found 20 distinct values in use; that's
 * too many for 20 individually-distinguishable colors at cover-thumbnail
 * size, so related subjects share one of 9 color families instead -- a few
 * families are deliberately keyed under two names (e.g. "Behavioral
 * Sciences" and the catalog's actual "Psychology & Behavioral Sciences")
 * where an earlier guess at the live name turned out to be close but not
 * exact; both keys are harmless to keep. Within a family, a small
 * per-title shade jitter keeps same-genre covers from looking like
 * identical twins. 3 of the 9 families are new hues (purple, pink/violet,
 * rose, terracotta) that didn't exist in the old 6-color palette -- Health
 * & Nursing, Business & Management, Criminology & Public Safety, and the
 * Education/Literature blue and Math/Science teal keep their original
 * colors, which are used elsewhere in the app (admin panel red, PHINMA
 * blue, etc.). Any category_group value this table doesn't recognize
 * falls back to the neutral 'default' family below rather than erroring.
 *
 * Layout is still a 200 x 300 (2:3) box, everything readable still sits
 * between y = 40 and y = 260 (see the original v2 docblock for why), but
 * every text element -- the SMARTLIB/seal masthead, the genre label, the
 * title, the author line -- is now centered on one shared vertical axis
 * instead of left-aligned, and the title/author group is vertically
 * centered as a unit within its zone (so a short title like "Calculus"
 * doesn't leave a big empty gap above the author line the way v2 did). A
 * large, very-low-opacity watermark icon for the book's genre fills
 * whatever space is left below the text -- bigger for short titles,
 * nearly invisible for long ones.
 */

const BOOK_COVER_VERSION = 3;

const BOOK_COVER_FONT_SERIF = "Georgia, 'Times New Roman', serif";
const BOOK_COVER_FONT_SANS = 'Arial, Helvetica, sans-serif';

// Text area and title block (viewBox units).
const BOOK_COVER_TEXT_X = 30;
const BOOK_COVER_TEXT_W = 150;
const BOOK_COVER_ZONE_TOP = 98;
const BOOK_COVER_ZONE_BOTTOM = 248;

// Shared horizontal center for every centered element. The spine-to-edge
// area is x=13..200, so its true center is 106.5, not the full-cover 100.
const BOOK_COVER_CENTER_X = 106.5;

// Standard brand gold used for the top accent bar, bottom band, and (on
// most families) the genre label/author rule -- the one constant across
// every genre, the same way the gold glow bar is used kiosk-wide (footer,
// panel tops). A family only overrides it (see 'accent' below) when gold
// doesn't read clearly against that family's own background.
const BOOK_COVER_GOLD = '#ffd84d';

/**
 * Genre color families. Keyed by `categories.category_group` exactly as
 * fetch_books.php's filter chips show it. Multiple category_group values
 * intentionally share one family (see the module docblock) -- duplicate
 * array values below are correct, not a mistake.
 *
 * Verified (scripts/ tooling used during design, not shipped):
 *  - every family's text color (accent on bg, and white on bg) clears
 *    WCAG AA (>= 4.5:1)
 *  - every pair of families is distinguishable by hue (>= 23 deg apart)
 *    or, where hue is closer, by a real lightness difference
 *
 * 'default' is used for any category_group this table doesn't recognize,
 * including NULL/uncategorized books -- a deliberately neutral slate, not
 * a 10th genre color, so an uncategorized book doesn't masquerade as a
 * specific subject.
 */
const CATEGORY_FAMILIES = [
    'Health & Nursing' => [
        'bg' => '#0f5132', 'accent' => BOOK_COVER_GOLD, 'icon' => 'cross',
    ],
    'Business & Management' => [
        'bg' => '#6b4f0f', 'accent' => '#ffe48a', 'icon' => 'briefcase',
    ],
    'Criminology & Public Safety' => [
        'bg' => '#641a20', 'accent' => BOOK_COVER_GOLD, 'icon' => 'shield',
    ],
    // Live in the catalog under this exact name (33 books, 2026-11 audit) --
    // closest existing family (authority/justice) is Criminology & Public Safety.
    'Law / Legal Studies' => [
        'bg' => '#641a20', 'accent' => BOOK_COVER_GOLD, 'icon' => 'shield',
    ],
    'Education' => [
        'bg' => '#2e67a6', 'accent' => '#ffe9a8', 'icon' => 'book',
    ],
    'English' => [
        'bg' => '#2e67a6', 'accent' => '#ffe9a8', 'icon' => 'book',
    ],
    'Literature & Language' => [
        'bg' => '#2e67a6', 'accent' => '#ffe9a8', 'icon' => 'book',
    ],
    'History' => [
        'bg' => '#2e67a6', 'accent' => '#ffe9a8', 'icon' => 'book',
    ],
    // Live in the catalog's `categories` table under this exact name (40
    // books, 2026-11 audit) -- same family as Literature & Language/English.
    'Language Studies' => [
        'bg' => '#2e67a6', 'accent' => '#ffe9a8', 'icon' => 'book',
    ],
    'Mathematics' => [
        'bg' => '#135454', 'accent' => BOOK_COVER_GOLD, 'icon' => 'flask',
    ],
    'Natural Science' => [
        'bg' => '#135454', 'accent' => BOOK_COVER_GOLD, 'icon' => 'flask',
    ],
    'Technology & Computing' => [
        'bg' => '#722183', 'accent' => BOOK_COVER_GOLD, 'icon' => 'chip',
    ],
    'Philosophy' => [
        'bg' => '#3d2a6b', 'accent' => BOOK_COVER_GOLD, 'icon' => 'lightbulb',
    ],
    'Religion' => [
        'bg' => '#3d2a6b', 'accent' => BOOK_COVER_GOLD, 'icon' => 'lightbulb',
    ],
    'Social Science' => [
        'bg' => '#3d2a6b', 'accent' => BOOK_COVER_GOLD, 'icon' => 'lightbulb',
    ],
    'Behavioral Sciences' => [
        'bg' => '#3d2a6b', 'accent' => BOOK_COVER_GOLD, 'icon' => 'lightbulb',
    ],
    // The catalog's actual category name (63 books, 2026-11 audit) -- no book
    // uses the shorter "Behavioral Sciences" above, which was my guess at
    // the name before the audit; kept both keys since it's harmless.
    'Psychology & Behavioral Sciences' => [
        'bg' => '#3d2a6b', 'accent' => BOOK_COVER_GOLD, 'icon' => 'lightbulb',
    ],
    'Social Sciences & Humanities' => [
        'bg' => '#3d2a6b', 'accent' => BOOK_COVER_GOLD, 'icon' => 'lightbulb',
    ],
    // Live in the catalog under this exact name (5 books, 2026-11 audit) --
    // same family as the other social-science subjects.
    'Political Science' => [
        'bg' => '#3d2a6b', 'accent' => BOOK_COVER_GOLD, 'icon' => 'lightbulb',
    ],
    'Arts' => [
        'bg' => '#6b1f51', 'accent' => BOOK_COVER_GOLD, 'icon' => 'palette',
    ],
    'Tourism & Hospitality' => [
        'bg' => '#933b15', 'accent' => BOOK_COVER_GOLD, 'icon' => 'compass',
    ],
    'default' => [
        'bg' => '#2c3e50', 'accent' => BOOK_COVER_GOLD, 'icon' => 'book',
    ],
];

/** Look up a genre's color family, falling back to the neutral default. */
function book_cover_family(?string $categoryGroup): array {
    $key = trim((string)$categoryGroup);
    return CATEGORY_FAMILIES[$key] ?? CATEGORY_FAMILIES['default'];
}

/** Lighten a #rrggbb hex color toward white by $pct (0-1). */
function book_cover_lighten(string $hex, float $pct): string {
    $hex = ltrim($hex, '#');
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    $r = (int)round($r + (255 - $r) * $pct);
    $g = (int)round($g + (255 - $g) * $pct);
    $b = (int)round($b + (255 - $b) * $pct);
    return sprintf('#%02x%02x%02x', $r, $g, $b);
}

/** Darken a #rrggbb hex color toward black by $pct (0-1) -- for spine/engraving-line shades. */
function book_cover_darken(string $hex, float $pct): string {
    $hex = ltrim($hex, '#');
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    $r = (int)round($r * (1 - $pct));
    $g = (int)round($g * (1 - $pct));
    $b = (int)round($b * (1 - $pct));
    return sprintf('#%02x%02x%02x', $r, $g, $b);
}

/**
 * Small per-title shade jitter within a genre family, so two books in the
 * same genre aren't pixel-identical. Deterministic: same title, same shade.
 */
function book_cover_shade(string $bg, string $title): string {
    $key = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $title)));
    $jitter = (crc32($key) % 3) * 0.06; // 0%, 6%, 12% lighter
    return $jitter > 0 ? book_cover_lighten($bg, $jitter) : $bg;
}

/** Inline stroke-icon paths, 24x24 viewBox, matching the kiosk's existing --ic-* icon style. */
function book_cover_icon_path(string $name): string {
    return match ($name) {
        'cross' => '<path d="M12 4v16M4 12h16" stroke-width="2.4"/>',
        'briefcase' => '<rect x="3.5" y="8" width="17" height="11" rx="1.5"/><path d="M8 8V6a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M3.5 13h17"/>',
        'shield' => '<path d="M12 3l7 3v5c0 4.5-3 7.8-7 9-4-1.2-7-4.5-7-9V6l7-3z"/>',
        'chip' => '<rect x="6" y="6" width="12" height="12" rx="1.5"/><path d="M9 6V3M15 6V3M9 21v-3M15 21v-3M3 9h3M3 15h3M18 9h3M18 15h3"/>',
        'compass' => '<circle cx="12" cy="12" r="9"/><path d="M15.5 8.5l-2 5-5 2 2-5z"/>',
        'flask' => '<path d="M9 3h6M10 3v5l-4.5 9a2 2 0 0 0 1.8 3h9.4a2 2 0 0 0 1.8-3L14 8V3"/><path d="M7.5 15h9"/>',
        'lightbulb' => '<path d="M9 18h6M10 21h4M12 3a6 6 0 0 0-3 11.2c.6.4 1 1.1 1 1.8h4c0-.7.4-1.4 1-1.8A6 6 0 0 0 12 3z"/>',
        'palette' => '<path d="M12 3C7 3 3 6.8 3 11.5 3 15 5.8 17 9 17h.5a1.5 1.5 0 0 1 1.5 1.5c0 .8-.5 1.3-.5 2 0 1 1 1.5 2 1.5 5 0 8.5-4 8.5-9C21 7.8 17 3 12 3z"/><circle cx="8" cy="11" r="1"/><circle cx="12" cy="8" r="1"/><circle cx="16" cy="11" r="1"/>',
        default => '<path d="M12 6.5c-1.8-1.6-4.2-2.2-6.6-1.7a1 1 0 0 0-.8 1v11.6a1 1 0 0 0 1.2 1c2.1-.4 4.3.1 6.2 1.6 1.9-1.5 4.1-2 6.2-1.6a1 1 0 0 0 1.2-1V5.8a1 1 0 0 0-.8-1c-2.4-.5-4.8.1-6.6 1.7z"/><path d="M12 6.5v13.5"/>',
    };
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

/** Build the cover SVG for a book. $categoryGroup is categories.category_group (or '' if none). */
function book_cover_svg(string $title, string $author = '', string $categoryGroup = ''): string {
    $title = trim($title) !== '' ? trim($title) : 'Untitled';
    $family = book_cover_family($categoryGroup);
    $bg = book_cover_shade($family['bg'], $title);
    $bgLight = book_cover_lighten($bg, 0.22);
    $spine = book_cover_darken($bg, 0.30);
    $line = book_cover_darken($bg, 0.10);
    $accent = $family['accent'];
    $muted = book_cover_lighten($bg, 0.68);
    $cx = BOOK_COVER_CENTER_X;
    $x = BOOK_COVER_TEXT_X;
    $w = BOOK_COVER_TEXT_W * 0.96; // safety margin on the width estimate

    // ---- Title: largest size whose wrapped lines fit the block ----
    [$main, $sub] = book_cover_split_title($title);
    $lineH = 1.17;
    $sizes = [25, 22, 19.5, 17.5, 15.5, 14.5, 13.5];
    $longestWord = 0.0;
    foreach (preg_split('/\s+/u', $main, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
        $longestWord = max($longestWord, book_cover_text_em($word));
    }
    // Generous block height for the size-fitting pass; the final zone the
    // title/subtitle/author group centers within is computed below from the
    // actual wrapped content, not this upper bound.
    $tryBlockH = (BOOK_COVER_ZONE_BOTTOM - BOOK_COVER_ZONE_TOP) - 40; // reserve ~40 for the author block
    $size = (float)end($sizes);
    $mainLines = [];
    foreach ($sizes as $try) {
        if ($longestWord * $try > $w) {
            continue;
        }
        $wrapped = book_cover_wrap($main, $try, $w);
        if (count($wrapped) * $try * $lineH <= $tryBlockH) {
            $size = (float)$try;
            $mainLines = $wrapped;
            break;
        }
    }
    if ($mainLines === []) {
        $mainLines = book_cover_clamp(book_cover_wrap($main, $size, $w), (int)floor($tryBlockH / ($size * $lineH)), $size, $w);
    }
    $titleH = count($mainLines) * $size * $lineH;

    // ---- Subtitle: smaller italic lines in whatever room is left ----
    $subSize = 10.0;
    $subLines = [];
    if ($sub !== '') {
        $room = $tryBlockH - $titleH - 4;
        $maxSub = min(3, (int)floor($room / ($subSize * 1.25)));
        if ($maxSub >= 1) {
            $subLines = book_cover_clamp(book_cover_wrap($sub, $subSize, $w), $maxSub, $subSize, $w);
        }
    }
    $subH = $subLines !== [] ? (4 + count($subLines) * $subSize * 1.25) : 0;

    // ---- Author: one line, small tracked capitals ----
    $authorLine = book_cover_author_line($author);
    $authorSize = 7.4;
    $authorTracking = 0.12;
    $authorText = '';
    if ($authorLine !== '') {
        $authorText = mb_strtoupper($authorLine);
        $authorText = book_cover_clamp(book_cover_wrap($authorText, $authorSize, BOOK_COVER_TEXT_W, $authorTracking), 1, $authorSize, BOOK_COVER_TEXT_W, $authorTracking)[0] ?? '';
    }
    $authorBlockH = $authorText !== '' ? 34 : 0; // gap + rule + text

    // ---- Vertically center the title/subtitle/author group within the zone ----
    $zoneTop = BOOK_COVER_ZONE_TOP;
    $zoneH = BOOK_COVER_ZONE_BOTTOM - BOOK_COVER_ZONE_TOP;
    $totalH = $titleH + $subH + $authorBlockH;
    $startY = $zoneTop + max(0.0, ($zoneH - $totalH) / 2);

    $out = [];
    $out[] = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 300" width="200" height="300" role="img" aria-label="' . book_cover_esc($title) . '">';
    $out[] = '<defs>';
    $out[] = '<clipPath id="c"><rect width="200" height="300"/></clipPath>';
    $out[] = '<linearGradient id="bgGrad" x1="0%" y1="0%" x2="100%" y2="100%">';
    $out[] = '<stop offset="0%" stop-color="' . $bgLight . '"/>';
    $out[] = '<stop offset="100%" stop-color="' . $bg . '"/>';
    $out[] = '</linearGradient>';
    $out[] = '<filter id="glow" x="-80%" y="-80%" width="260%" height="260%">';
    $out[] = '<feGaussianBlur stdDeviation="2.4" result="b"/><feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge>';
    $out[] = '</filter>';
    $out[] = '</defs>';
    $out[] = '<g clip-path="url(#c)">';
    $out[] = '<rect width="200" height="300" fill="url(#bgGrad)"/>';

    // Engraved arcs, one shade lighter than the background (as on the card).
    $out[] = '<g fill="none" stroke="' . $line . '" stroke-width="1" opacity="0.55">';
    for ($r = 24; $r <= 300; $r += 8) {
        $out[] = '<circle cx="214" cy="318" r="' . $r . '"/>';
    }
    $out[] = '</g>';

    // Large low-opacity genre watermark -- fills dead space on short titles,
    // reads as deliberate texture rather than a UI element or a glitch.
    $iconSize = 150;
    $iconCy = min(max($startY + $titleH + 58, 155), 238);
    $out[] = '<g transform="translate(25,' . ($iconCy - $iconSize / 2) . ') scale(' . ($iconSize / 24) . ')" opacity="0.08" fill="none" stroke="#ffffff" stroke-width="0.9" stroke-linecap="round" stroke-linejoin="round">' . book_cover_icon_path($family['icon']) . '</g>';

    // Spine: darker strip with a thin highlight line.
    $out[] = '<rect width="13" height="300" fill="' . $spine . '"/>';
    $out[] = '<rect x="13" width="1.2" height="300" fill="' . $line . '"/>';

    // Top accent hairline + glowing gold center bar (kiosk's signature motif,
    // same as the footer / panel tops) -- always gold, the one constant
    // across every genre, same as the gold glow used kiosk-wide.
    $out[] = '<rect x="13" y="0" width="187" height="1" fill="' . BOOK_COVER_GOLD . '" opacity="0.35"/>';
    $out[] = '<rect x="' . ($cx - 16) . '" y="0" width="32" height="2.6" rx="1.3" fill="' . BOOK_COVER_GOLD . '" filter="url(#glow)"/>';

    // Branding: seal + SMARTLIB wordmark, centered as one row.
    $sealSize = 21.0;
    $headerGap = 6.0;
    $smartlibSize = 7.6;
    $smartlibTracking = 1.6;
    $librarySize = 5.6;
    $libraryTracking = 0.9;
    $smartlibW = book_cover_text_em('SMARTLIB') * $smartlibSize + $smartlibTracking * 7;
    $libraryW = book_cover_text_em('PHINMA-SJCDC LIBRARY') * $librarySize + $libraryTracking * 20;
    $headerTextW = max($smartlibW, $libraryW);
    $headerW = $sealSize + $headerGap + $headerTextW;
    $headerX = $cx - $headerW / 2;
    $headerTextX = $headerX + $sealSize + $headerGap;

    $seal = __DIR__ . '/../assets/images/stjude_seal_64.png';
    if (is_readable($seal)) {
        $out[] = '<image x="' . round($headerX, 2) . '" y="40" width="' . $sealSize . '" height="' . $sealSize . '" href="data:image/png;base64,' . base64_encode((string)file_get_contents($seal)) . '"/>';
    }
    $out[] = '<text x="' . round($headerTextX, 2) . '" y="49" font-family="' . BOOK_COVER_FONT_SANS . '" font-size="' . $smartlibSize . '" font-weight="700" letter-spacing="' . $smartlibTracking . '" fill="' . BOOK_COVER_GOLD . '">SMARTLIB</text>';
    $out[] = '<text x="' . round($headerTextX, 2) . '" y="59" font-family="' . BOOK_COVER_FONT_SANS . '" font-size="' . $librarySize . '" font-weight="700" letter-spacing="' . $libraryTracking . '" fill="' . $muted . '">PHINMA-SJCDC LIBRARY</text>';

    // Genre label, directly under the masthead. Uppercase BEFORE escaping --
    // escaping first and uppercasing after turns "&amp;" into the invalid
    // "&AMP;" for any genre with "&" in its name (Health & Nursing, Business
    // & Management, Tourism & Hospitality, etc.), which Chrome's <img>
    // SVG parser rejects outright as malformed XML.
    if (trim($categoryGroup) !== '') {
        $out[] = '<text x="' . $cx . '" y="76" text-anchor="middle" font-family="' . BOOK_COVER_FONT_SANS . '" font-size="6.2" font-weight="700" letter-spacing="1.3" fill="' . $accent . '">' . book_cover_esc(strtoupper($categoryGroup)) . '</text>';
    }

    // Title.
    $y = $startY + $size * 0.82;
    $out[] = '<text x="' . $cx . '" text-anchor="middle" font-family="' . BOOK_COVER_FONT_SERIF . '" font-weight="700" font-size="' . $size . '" fill="#ffffff">';
    foreach ($mainLines as $i => $text) {
        $out[] = '<tspan x="' . $cx . '" y="' . round($y + $i * $size * $lineH, 2) . '">' . book_cover_esc($text) . '</tspan>';
    }
    $out[] = '</text>';

    if ($subLines !== []) {
        $sy = $startY + $titleH + 4 + $subSize * 0.85;
        $out[] = '<text x="' . $cx . '" text-anchor="middle" font-family="' . BOOK_COVER_FONT_SERIF . '" font-style="italic" font-size="' . $subSize . '" fill="' . $muted . '">';
        foreach ($subLines as $i => $text) {
            $out[] = '<tspan x="' . $cx . '" y="' . round($sy + $i * $subSize * 1.25, 2) . '">' . book_cover_esc($text) . '</tspan>';
        }
        $out[] = '</text>';
    }

    if ($authorText !== '') {
        $ry = $startY + $titleH + $subH + 14;
        $ty = $ry + 16;
        $out[] = '<rect x="' . ($cx - 7) . '" y="' . round($ry, 2) . '" width="14" height="1" fill="' . $muted . '"/>';
        $out[] = '<text x="' . $cx . '" y="' . round($ty, 2) . '" text-anchor="middle" font-family="' . BOOK_COVER_FONT_SANS . '" font-size="' . $authorSize . '" font-weight="700" letter-spacing="' . ($authorSize * $authorTracking) . '" fill="' . $muted . '">' . book_cover_esc($authorText) . '</text>';
    }

    // Thin accent band at the foot of the cover -- always gold, see the top bar comment above.
    $out[] = '<rect y="294" width="200" height="6" fill="' . $spine . '"/>';
    $out[] = '<rect y="294" width="200" height="1.4" fill="' . BOOK_COVER_GOLD . '"/>';

    $out[] = '</g></svg>';
    return implode('', $out);
}

/** URL of the generated cover for a book (for <img src>), relative to the site root. */
function book_cover_url(int $bookId): string {
    return 'book_cover.php?id=' . $bookId . '&v=' . BOOK_COVER_VERSION;
}
