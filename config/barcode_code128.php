<?php
/**
 * Dependency-free Code 128 barcode encoder, rendered as inline SVG.
 *
 * Used for the digital library card, which encodes library_users.user_number.
 * Student/faculty IDs are all-digit and even-length (8 / 6), so they use
 * Code Set C (two digits per symbol, the most compact form). Anything else
 * (e.g. staff IDs like "admin002") falls back to Code Set B, which covers
 * printable ASCII 32-126.
 *
 * Sizes are in millimetres so the printed card comes out at a predictable,
 * scanner-friendly size regardless of screen DPI.
 */

// Bar/space widths (in modules) for symbol values 0-106. Each symbol is
// bar, space, bar, space, bar, space; 106 (STOP) has a 7th element.
const CODE128_PATTERNS = [
    '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
    '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
    '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
    '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
    '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
    '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
    '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
    '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
    '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
    '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
    '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
];

const CODE128_START_B = 104;
const CODE128_START_C = 105;
const CODE128_STOP = 106;
const CODE128_QUIET_MODULES = 10;

/**
 * Encode $value as a list of Code 128 symbol values, including start,
 * checksum, and stop. Returns null if $value can't be encoded.
 */
function code128_symbols(string $value): ?array {
    if ($value === '') {
        return null;
    }

    $symbols = [];
    if (preg_match('/^(\d\d)+$/', $value)) {
        $symbols[] = CODE128_START_C;
        foreach (str_split($value, 2) as $pair) {
            $symbols[] = (int)$pair;
        }
    } else {
        $symbols[] = CODE128_START_B;
        $len = strlen($value);
        for ($i = 0; $i < $len; $i++) {
            $ord = ord($value[$i]);
            if ($ord < 32 || $ord > 126) {
                return null;
            }
            $symbols[] = $ord - 32;
        }
    }

    // Checksum: start value plus each data value weighted by its position.
    $sum = $symbols[0];
    $count = count($symbols);
    for ($i = 1; $i < $count; $i++) {
        $sum += $symbols[$i] * $i;
    }
    $symbols[] = $sum % 103;
    $symbols[] = CODE128_STOP;

    return $symbols;
}

/**
 * Render $value as an SVG barcode. $moduleMm is the narrowest bar width;
 * 0.4mm is comfortably above the ~0.25mm minimum handheld laser scanners
 * read reliably. Includes the required quiet zone on both sides.
 */
function code128_svg(string $value, float $moduleMm = 0.4, float $heightMm = 12.0): ?string {
    $symbols = code128_symbols($value);
    if ($symbols === null) {
        return null;
    }

    $x = CODE128_QUIET_MODULES;
    $rects = [];
    foreach ($symbols as $symbol) {
        $widths = CODE128_PATTERNS[$symbol];
        $len = strlen($widths);
        for ($i = 0; $i < $len; $i++) {
            $w = (int)$widths[$i];
            if ($i % 2 === 0) {
                $rects[] = '<rect x="' . $x . '" y="0" width="' . $w . '" height="1"/>';
            }
            $x += $w;
        }
    }
    $totalModules = $x + CODE128_QUIET_MODULES;

    $widthMm = round($totalModules * $moduleMm, 2);
    $label = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

    return '<svg xmlns="http://www.w3.org/2000/svg" class="code128"'
        . ' width="' . $widthMm . 'mm" height="' . $heightMm . 'mm"'
        . ' viewBox="0 0 ' . $totalModules . ' 1" preserveAspectRatio="none"'
        . ' shape-rendering="crispEdges" role="img" aria-label="Barcode ' . $label . '">'
        . '<rect x="0" y="0" width="' . $totalModules . '" height="1" fill="#fff"/>'
        . '<g fill="#000">' . implode('', $rects) . '</g>'
        . '</svg>';
}
