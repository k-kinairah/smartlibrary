<?php
/**
 * Branded PDF shell for every report export (admin/reports.php).
 *
 * The PDFs are written directly, without a PDF library, so this draws with
 * raw PDF operators: a green header band with the library seal, a faint seal
 * watermark, headline tiles and a summary sentence, the report's own cards,
 * a sign-off line, and a "Page n of N" footer. It only lays out data the
 * export already fetched; it runs no queries.
 *
 * Text goes out in WinAnsi (Windows-1252), so names with ñ and dashes like
 * "–" print properly. The seal PNG is decoded here because this PHP has no
 * GD; its transparency becomes a soft mask.
 */

const RPDF_W = 612.0;
const RPDF_H = 792.0;
const RPDF_M = 36.0;
const RPDF_CONTENT_W = 540.0;
const RPDF_BOTTOM = 742.0;          // content stops here; the footer sits below

const RPDF_GREEN = [0.118, 0.420, 0.275];
const RPDF_GREEN_DEEP = [0.059, 0.247, 0.161];
const RPDF_GOLD = [0.851, 0.647, 0.082];
const RPDF_GOLD_SOFT = [0.961, 0.898, 0.690];
const RPDF_GOLD_TEXT = [0.541, 0.416, 0.0];
const RPDF_INK = [0.133, 0.192, 0.165];
const RPDF_SOFT = [0.365, 0.435, 0.404];
const RPDF_FAINT = [0.576, 0.639, 0.608];
const RPDF_LINE = [0.886, 0.910, 0.894];
const RPDF_CARD_BG = [0.984, 0.992, 0.988];
const RPDF_AMBER_BG = [1.0, 0.945, 0.863];
const RPDF_AMBER_LINE = [0.902, 0.588, 0.157];
const RPDF_AMBER_TEXT = [0.604, 0.357, 0.0];
const RPDF_WHITE = [1.0, 1.0, 1.0];

// ---------------------------------------------------------------- text ----

/** UTF-8 to the WinAnsi bytes the standard fonts use. */
function rpdf_bytes(string $text): string {
    $text = str_replace(["\r", "\n", "\t"], ' ', $text);
    $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);
    return $converted === false ? (preg_replace('/[^\x20-\x7E]/', '', $text) ?? '') : $converted;
}

/** Helvetica advance widths (per 1000 em) for bytes 32-126. */
function rpdf_char_width(int $code): int {
    static $ascii = [278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,
        278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,
        667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,
        722,500,500,500,334,260,334,584];
    if ($code >= 32 && $code <= 126) {
        return $ascii[$code - 32];
    }
    return [183 => 278, 150 => 556, 151 => 1000, 215 => 584][$code] ?? 556;
}

/** Width in points. Bold runs a little wider; Times-Bold a little narrower. */
function rpdf_width(string $text, float $size, string $font = 'F1'): float {
    $bytes = rpdf_bytes($text);
    $units = 0;
    for ($i = 0, $n = strlen($bytes); $i < $n; $i++) {
        $units += rpdf_char_width(ord($bytes[$i]));
    }
    $scale = $font === 'F2' ? 1.06 : ($font === 'F3' ? 0.95 : 1.0);
    return $units * $scale * $size / 1000;
}

/** Shorten with "..." until it fits. */
function rpdf_fit(string $text, float $maxW, float $size, string $font = 'F1'): string {
    if (rpdf_width($text, $size, $font) <= $maxW) {
        return $text;
    }
    while (mb_strlen($text) > 1 && rpdf_width($text . '...', $size, $font) > $maxW) {
        $text = mb_substr($text, 0, -1);
    }
    return rtrim($text) . '...';
}

function rpdf_wrap(string $text, float $maxW, float $size, string $font = 'F1'): array {
    $lines = [];
    $line = '';
    foreach (preg_split('/\s+/', trim($text)) ?: [] as $word) {
        $try = $line === '' ? $word : $line . ' ' . $word;
        if ($line !== '' && rpdf_width($try, $size, $font) > $maxW) {
            $lines[] = $line;
            $line = $word;
        } else {
            $line = $try;
        }
    }
    if ($line !== '') {
        $lines[] = $line;
    }
    return $lines ?: [''];
}

// --------------------------------------------------------------- image ----

/**
 * Decode an 8-bit, non-interlaced PNG into compressed RGB and alpha planes.
 * Null for anything else, in which case the PDF simply goes without the seal.
 */
function rpdf_png(string $path): ?array {
    $data = @file_get_contents($path);
    if ($data === false || substr($data, 0, 8) !== "\x89PNG\r\n\x1a\n") {
        return null;
    }
    $pos = 8;
    $ihdr = null;
    $palette = '';
    $trns = null;
    $idat = '';
    $size = strlen($data);
    while ($pos + 8 <= $size) {
        $len = unpack('N', substr($data, $pos, 4))[1];
        $type = substr($data, $pos + 4, 4);
        $chunk = substr($data, $pos + 8, $len);
        $pos += 12 + $len;
        if ($type === 'IHDR') {
            $ihdr = unpack('Nw/Nh/Cbits/Ccolor/Ccomp/Cfilter/Cinterlace', $chunk);
        } elseif ($type === 'PLTE') {
            $palette = $chunk;
        } elseif ($type === 'tRNS') {
            $trns = $chunk;
        } elseif ($type === 'IDAT') {
            $idat .= $chunk;
        } elseif ($type === 'IEND') {
            break;
        }
    }
    $channels = $ihdr ? ([0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4][$ihdr['color']] ?? 0) : 0;
    if (!$channels || $ihdr['bits'] !== 8 || $ihdr['interlace'] !== 0) {
        return null;
    }
    $raw = @gzuncompress($idat);
    if ($raw === false) {
        return null;
    }
    $w = $ihdr['w'];
    $h = $ihdr['h'];
    $stride = $w * $channels;
    $prev = array_fill(0, $stride, 0);
    $rgb = '';
    $alpha = '';
    for ($y = 0; $y < $h; $y++) {
        $offset = $y * ($stride + 1);
        $filter = ord($raw[$offset]);
        $cur = [];
        for ($i = 0; $i < $stride; $i++) {
            $x = ord($raw[$offset + 1 + $i]);
            $a = $i >= $channels ? $cur[$i - $channels] : 0;
            $b = $prev[$i];
            $c = $i >= $channels ? $prev[$i - $channels] : 0;
            if ($filter === 1) {
                $x += $a;
            } elseif ($filter === 2) {
                $x += $b;
            } elseif ($filter === 3) {
                $x += intdiv($a + $b, 2);
            } elseif ($filter === 4) {
                $p = $a + $b - $c;
                $pa = abs($p - $a);
                $pb = abs($p - $b);
                $pc = abs($p - $c);
                $x += ($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $b : $c);
            }
            $cur[$i] = $x & 255;
        }
        $prev = $cur;
        for ($px = 0; $px < $w; $px++) {
            $o = $px * $channels;
            switch ($ihdr['color']) {
                case 6: $rgb .= chr($cur[$o]) . chr($cur[$o + 1]) . chr($cur[$o + 2]); $alpha .= chr($cur[$o + 3]); break;
                case 2: $rgb .= chr($cur[$o]) . chr($cur[$o + 1]) . chr($cur[$o + 2]); $alpha .= "\xFF"; break;
                case 4: $rgb .= str_repeat(chr($cur[$o]), 3); $alpha .= chr($cur[$o + 1]); break;
                case 0: $rgb .= str_repeat(chr($cur[$o]), 3); $alpha .= "\xFF"; break;
                case 3:
                    $idx = $cur[$o];
                    $rgb .= substr($palette, $idx * 3, 3);
                    $alpha .= ($trns !== null && $idx < strlen($trns)) ? $trns[$idx] : "\xFF";
                    break;
            }
        }
    }
    return ['w' => $w, 'h' => $h, 'rgb' => gzcompress($rgb), 'alpha' => gzcompress($alpha)];
}

// -------------------------------------------------------------- canvas ----

/**
 * Pages of drawing operators. Every method takes y measured from the top of
 * the page, the way the layout is thought about; it is flipped for PDF here.
 */
final class RpdfCanvas {
    public array $pages = [];
    public string $s = '';

    private static function n(float $v): string {
        $t = rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
        return $t === '-0' || $t === '' ? '0' : $t;
    }

    private static function rgb(array $c, bool $stroke = false): string {
        return sprintf('%.3F %.3F %.3F %s', $c[0], $c[1], $c[2], $stroke ? 'RG' : 'rg') . "\n";
    }

    public function newPage(): void {
        if ($this->s !== '') {
            $this->pages[] = $this->s;
        }
        $this->s = '';
    }

    public function finish(): array {
        $this->newPage();
        return $this->pages;
    }

    public function rect(float $x, float $top, float $w, float $h, ?array $fill, ?array $stroke = null, float $lw = 0.75): void {
        $this->path(self::n($x) . ' ' . self::n(RPDF_H - $top - $h) . ' ' . self::n($w) . ' ' . self::n($h) . " re\n", $fill, $stroke, $lw);
    }

    public function roundRect(float $x, float $top, float $w, float $h, float $r, ?array $fill, ?array $stroke = null, float $lw = 0.75): void {
        $this->path($this->roundRectPath($x, $top, $w, $h, $r), $fill, $stroke, $lw);
    }

    /**
     * A rounded rectangle filled top to bottom from one colour to another, in
     * thin flat bands clipped to the corners (smooth on screen and in print).
     */
    public function roundRectFade(float $x, float $top, float $w, float $h, float $r, array $from, array $to): void {
        $this->s .= "q\n" . $this->roundRectPath($x, $top, $w, $h, $r) . "W n\n";
        $bands = 40;
        for ($i = 0; $i < $bands; $i++) {
            $t = $i / ($bands - 1);
            $mix = [$from[0] + ($to[0] - $from[0]) * $t, $from[1] + ($to[1] - $from[1]) * $t, $from[2] + ($to[2] - $from[2]) * $t];
            $this->rect($x, $top + $h / $bands * $i, $w, $h / $bands + 0.5, $mix);
        }
        $this->s .= "Q\n";
    }

    private function roundRectPath(float $x, float $top, float $w, float $h, float $r): string {
        $r = min($r, $w / 2, $h / 2);
        $k = 0.5523 * $r;
        $x0 = $x;
        $x1 = $x + $w;
        $y1 = RPDF_H - $top;
        $y0 = $y1 - $h;
        $n = [self::class, 'n'];
        $p = $n($x0 + $r) . ' ' . $n($y0) . " m\n"
            . $n($x1 - $r) . ' ' . $n($y0) . " l\n"
            . $n($x1 - $r + $k) . ' ' . $n($y0) . ' ' . $n($x1) . ' ' . $n($y0 + $r - $k) . ' ' . $n($x1) . ' ' . $n($y0 + $r) . " c\n"
            . $n($x1) . ' ' . $n($y1 - $r) . " l\n"
            . $n($x1) . ' ' . $n($y1 - $r + $k) . ' ' . $n($x1 - $r + $k) . ' ' . $n($y1) . ' ' . $n($x1 - $r) . ' ' . $n($y1) . " c\n"
            . $n($x0 + $r) . ' ' . $n($y1) . " l\n"
            . $n($x0 + $r - $k) . ' ' . $n($y1) . ' ' . $n($x0) . ' ' . $n($y1 - $r + $k) . ' ' . $n($x0) . ' ' . $n($y1 - $r) . " c\n"
            . $n($x0) . ' ' . $n($y0 + $r) . " l\n"
            . $n($x0) . ' ' . $n($y0 + $r - $k) . ' ' . $n($x0 + $r - $k) . ' ' . $n($y0) . ' ' . $n($x0 + $r) . ' ' . $n($y0) . " c\nh\n";
        return $p;
    }

    public function circle(float $cx, float $cyTop, float $r, ?array $fill, ?array $stroke = null, float $lw = 0.75): void {
        $this->roundRect($cx - $r, $cyTop - $r, 2 * $r, 2 * $r, $r, $fill, $stroke, $lw);
    }

    private function path(string $p, ?array $fill, ?array $stroke, float $lw): void {
        $op = $fill && $stroke ? 'B' : ($fill ? 'f' : 'S');
        $this->s .= "q\n" . ($fill ? self::rgb($fill) : '') . ($stroke ? self::rgb($stroke, true) . self::n($lw) . " w\n" : '') . $p . $op . "\nQ\n";
    }

    public function line(float $x1, float $top1, float $x2, float $top2, array $color, float $lw = 0.75, ?string $dash = null): void {
        $this->s .= "q\n" . self::rgb($color, true) . self::n($lw) . " w\n" . ($dash !== null ? "[$dash] 0 d\n" : '')
            . self::n($x1) . ' ' . self::n(RPDF_H - $top1) . ' m ' . self::n($x2) . ' ' . self::n(RPDF_H - $top2) . " l S\nQ\n";
    }

    /** $baseline is the text baseline, measured from the top. */
    public function text(float $x, float $baseline, string $text, float $size, string $font, array $color): void {
        $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], rpdf_bytes($text));
        $this->s .= 'BT /' . $font . ' ' . self::n($size) . " Tf\n" . self::rgb($color)
            . '1 0 0 1 ' . self::n($x) . ' ' . self::n(RPDF_H - $baseline) . " Tm\n(" . $escaped . ") Tj ET\n";
    }

    public function textRight(float $right, float $baseline, string $text, float $size, string $font, array $color): void {
        $this->text($right - rpdf_width($text, $size, $font), $baseline, $text, $size, $font, $color);
    }

    public function textCenter(float $center, float $baseline, string $text, float $size, string $font, array $color): void {
        $this->text($center - rpdf_width($text, $size, $font) / 2, $baseline, $text, $size, $font, $color);
    }

    public function image(float $x, float $top, float $w, float $h, ?string $gs = null): void {
        $this->s .= "q\n" . ($gs ? '/' . $gs . " gs\n" : '') . self::n($w) . ' 0 0 ' . self::n($h) . ' ' . self::n($x) . ' '
            . self::n(RPDF_H - $top - $h) . " cm\n/Seal Do\nQ\n";
    }
}

// -------------------------------------------------------- PDF assembly ----

function rpdf_assemble(array $pageStreams, ?array $seal): string {
    $objects = [];
    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $next = 3;
    $fontObjs = [];
    foreach (['F1' => 'Helvetica', 'F2' => 'Helvetica-Bold', 'F3' => 'Times-Bold'] as $key => $base) {
        $fontObjs[$key] = $next;
        $objects[$next++] = "<< /Type /Font /Subtype /Type1 /BaseFont /$base /Encoding /WinAnsiEncoding >>";
    }
    $gsWatermark = $next;
    $objects[$next++] = '<< /Type /ExtGState /ca 0.04 /CA 0.04 >>';
    $xobject = '';
    if ($seal) {
        $maskObj = $next++;
        $objects[$maskObj] = "<< /Type /XObject /Subtype /Image /Width {$seal['w']} /Height {$seal['h']} /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode /Length "
            . strlen($seal['alpha']) . " >>\nstream\n" . $seal['alpha'] . "\nendstream";
        $imgObj = $next++;
        $objects[$imgObj] = "<< /Type /XObject /Subtype /Image /Width {$seal['w']} /Height {$seal['h']} /ColorSpace /DeviceRGB /BitsPerComponent 8 /SMask $maskObj 0 R /Filter /FlateDecode /Length "
            . strlen($seal['rgb']) . " >>\nstream\n" . $seal['rgb'] . "\nendstream";
        $xobject = " /XObject << /Seal $imgObj 0 R >>";
    }
    $resources = '<< /Font << /F1 ' . $fontObjs['F1'] . ' 0 R /F2 ' . $fontObjs['F2'] . ' 0 R /F3 ' . $fontObjs['F3'] . ' 0 R >>'
        . " /ExtGState << /GSW $gsWatermark 0 R >>" . $xobject . ' >>';

    $kids = [];
    foreach ($pageStreams as $stream) {
        $pageObj = $next++;
        $contentObj = $next++;
        $kids[] = "$pageObj 0 R";
        $compressed = gzcompress($stream);
        $objects[$contentObj] = '<< /Length ' . strlen($compressed) . " /Filter /FlateDecode >>\nstream\n" . $compressed . "\nendstream";
        $objects[$pageObj] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources ' . $resources . " /Contents $contentObj 0 R >>";
    }
    $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
    ksort($objects);

    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [];
    foreach ($objects as $num => $body) {
        $offsets[$num] = strlen($pdf);
        $pdf .= "$num 0 obj\n$body\nendobj\n";
    }
    $xref = strlen($pdf);
    $max = max(array_keys($objects));
    $pdf .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
    for ($i = 1; $i <= $max; $i++) {
        $pdf .= str_pad((string)($offsets[$i] ?? 0), 10, '0', STR_PAD_LEFT) . " 00000 n \n";
    }
    return $pdf . "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
}

// -------------------------------------------------------------- layout ----

/**
 * Lays out pages from the top: the header band on page 1, a slim running
 * head after that, and a page break whenever the next block won't fit.
 */
final class RpdfLayout {
    public RpdfCanvas $c;
    public float $y = 0;
    private bool $hasSeal;
    private string $title;

    public function __construct(bool $hasSeal, string $title) {
        $this->c = new RpdfCanvas();
        $this->hasSeal = $hasSeal;
        $this->title = $title;
    }

    private function watermark(): void {
        if ($this->hasSeal) {
            $this->c->image(300, 250, 340, 340, 'GSW');
        }
    }

    public function firstPage(string $generated): void {
        $this->watermark();
        $c = $this->c;
        // Green to deep green, left to right, in flat steps (print-safe).
        $bands = 48;
        for ($i = 0; $i < $bands; $i++) {
            $t = $i / ($bands - 1);
            $mix = [];
            foreach ([0, 1, 2] as $k) {
                $mix[$k] = RPDF_GREEN[$k] + (RPDF_GREEN_DEEP[$k] - RPDF_GREEN[$k]) * $t;
            }
            $c->rect(RPDF_W / $bands * $i, 0, RPDF_W / $bands + 0.5, 92, $mix);
        }
        // Thin gold line, lighter in the middle, in flat steps (print-safe).
        $steps = 24;
        for ($i = 0; $i < $steps; $i++) {
            $t = 1 - abs(($i + 0.5) / $steps * 2 - 1);
            $mix = [RPDF_GOLD[0] + (1 - RPDF_GOLD[0]) * 0.75 * $t, RPDF_GOLD[1] + (0.94 - RPDF_GOLD[1]) * 0.75 * $t, RPDF_GOLD[2] + (0.76 - RPDF_GOLD[2]) * 0.75 * $t];
            $c->rect(RPDF_W / $steps * $i, 92, RPDF_W / $steps + 0.5, 3, $mix);
        }
        $c->circle(RPDF_M + 26, 46, 26, RPDF_WHITE);
        if ($this->hasSeal) {
            $c->image(RPDF_M + 3, 23, 46, 46);
        }
        $textX = RPDF_M + 66;
        $c->text($textX, 34, 'PHINMA-SJC DASMA LIBRARY', 8, 'F2', [0.78, 0.88, 0.83]);
        $c->text($textX, 57, rpdf_fit($this->title, RPDF_W - $textX - RPDF_M, 19, 'F3'), 19, 'F3', RPDF_WHITE);
        $c->text($textX, 74, 'Generated: ' . $generated, 8, 'F1', [0.70, 0.82, 0.76]);
        $this->y = 95 + 24;
    }

    public function nextPage(): void {
        $this->c->newPage();
        $this->watermark();
        $this->c->text(RPDF_M, RPDF_M + 6, $this->title . ' (continued)', 9, 'F2', RPDF_SOFT);
        $this->c->rect(RPDF_M, RPDF_M + 13, 60, 2, RPDF_GOLD);
        $this->y = RPDF_M + 32;
    }

    /** Start a new page unless $h points fit. */
    public function need(float $h): void {
        if ($this->y + $h > RPDF_BOTTOM) {
            $this->nextPage();
        }
    }

    public function room(): float {
        return RPDF_BOTTOM - $this->y;
    }

    /** Adds the footer to every page now that the page count is known. */
    public function finish(): array {
        $pages = $this->c->finish();
        $total = count($pages);
        foreach ($pages as $i => $stream) {
            $f = new RpdfCanvas();
            $f->line(RPDF_M, 752, RPDF_W - RPDF_M, 752, RPDF_LINE, 0.75);
            $textX = RPDF_M;
            if ($this->hasSeal) {
                $f->image(RPDF_M, 760, 12, 12);
                $textX += 17;
            }
            $f->text($textX, 769, 'SmartLib · PHINMA-SJC Dasma Library', 7.5, 'F1', RPDF_FAINT);
            $f->textRight(RPDF_W - RPDF_M, 769, 'Page ' . ($i + 1) . ' of ' . $total, 7.5, 'F1', RPDF_FAINT);
            $pages[$i] = $stream . $f->s;
        }
        return $pages;
    }
}

// ------------------------------------------------------------- blocks ----

function rpdf_meta_row(RpdfLayout $l, string $text): void {
    $l->c->text(RPDF_M, $l->y + 11, $text, 11.5, 'F2', RPDF_INK);
    $l->y += 28;
}

/** Big green number tiles beside the summary sentence. */
function rpdf_headline(RpdfLayout $l, array $tiles, string $sentence): void {
    $tileW = 92.0;
    $gap = 10.0;
    $tilesW = count($tiles) * ($tileW + $gap);
    $boxX = RPDF_M + $tilesW;
    $boxW = RPDF_CONTENT_W - $tilesW;
    $lines = rpdf_wrap($sentence, $boxW - 26, 10);
    $h = max(58.0, count($lines) * 14 + 22);
    $l->need($h);
    foreach ($tiles as $i => $tile) {
        $x = RPDF_M + $i * ($tileW + $gap);
        $l->c->roundRectFade($x, $l->y, $tileW, $h, 8, RPDF_GREEN, RPDF_GREEN_DEEP);
        $value = rpdf_fit((string)$tile['value'], $tileW - 12, 22, 'F3');
        $l->c->textCenter($x + $tileW / 2, $l->y + $h / 2 + 4, $value, 22, 'F3', RPDF_WHITE);
        $l->c->textCenter($x + $tileW / 2, $l->y + $h / 2 + 18, rpdf_fit(strtoupper((string)$tile['label']), $tileW - 10, 7, 'F2'), 7, 'F2', [0.80, 0.90, 0.85]);
    }
    $l->c->roundRect($boxX, $l->y, $boxW, $h, 7, RPDF_CARD_BG, RPDF_LINE);
    $l->c->rect($boxX, $l->y + 1, 3, $h - 2, RPDF_GOLD);
    $textTop = $l->y + ($h - count($lines) * 14) / 2 + 10;
    foreach ($lines as $i => $line) {
        $l->c->text($boxX + 14, $textTop + $i * 14, $line, 10, 'F1', RPDF_INK);
    }
    $l->y += $h + 18;
}

/** White card with the gold corner mark; $h is the full height. */
function rpdf_card_frame(RpdfCanvas $c, float $x, float $top, float $w, float $h, string $title): void {
    $c->roundRect($x, $top, $w, $h, 8, RPDF_WHITE, RPDF_LINE);
    $c->line($x + 8, $top + 8, $x + 17, $top + 8, [0.92, 0.82, 0.55], 1.5);
    $c->line($x + 8, $top + 8, $x + 8, $top + 17, [0.92, 0.82, 0.55], 1.5);
    $c->text($x + 14, $top + 24, strtoupper($title), 8, 'F2', RPDF_SOFT);
}

const RPDF_ROW_H = 24.0;
const RPDF_CARD_HEAD = 34.0;

/**
 * Bar rows (label, value, gold bar under them) for a card. Zero rows leave
 * the list and become one muted "Also recorded with 0 ..." line instead.
 * Returns ['rows' => shown, 'note' => footnote lines] for measuring.
 */
function rpdf_bar_parts(array $rows, float $innerW, string $zeroNoun): array {
    $shown = [];
    $zeros = [];
    foreach ($rows as $row) {
        if ((int)$row['total'] > 0) {
            $shown[] = $row;
        } else {
            $zeros[] = (string)$row['label'];
        }
    }
    // With nothing to show, the card's empty sentence already says it all.
    $note = $zeros && $shown ? rpdf_wrap('Also recorded with 0 ' . $zeroNoun . ': ' . implode(', ', $zeros) . '.', $innerW, 7.5) : [];
    return ['rows' => $shown, 'note' => $note];
}

function rpdf_bar_card_height(array $parts): float {
    $h = RPDF_CARD_HEAD + max(1, count($parts['rows'])) * RPDF_ROW_H + 6;
    return $h + ($parts['note'] ? 10 + count($parts['note']) * 10 : 0);
}

function rpdf_draw_bar_card(RpdfCanvas $c, float $x, float $top, float $w, float $h, string $title, array $parts, bool $ranked, string $emptyText): void {
    rpdf_card_frame($c, $x, $top, $w, $h, $title);
    $innerX = $x + 14;
    $innerW = $w - 28;
    $y = $top + RPDF_CARD_HEAD;
    $max = 1;
    foreach ($parts['rows'] as $row) {
        $max = max($max, (int)$row['total']);
    }
    if (!$parts['rows']) {
        $c->text($innerX, $y + 12, $emptyText, 9, 'F1', RPDF_FAINT);
        $y += RPDF_ROW_H;
    }
    foreach ($parts['rows'] as $i => $row) {
        $value = number_format((int)$row['total']);
        $labelX = $innerX + ($ranked ? 16 : 0);
        if ($ranked) {
            $c->text($innerX, $y + 9, (string)($i + 1), 9, 'F2', RPDF_FAINT);
        }
        $muted = (string)$row['label'] === ENTRY_REPORT_NOT_SPECIFIED;
        $labelW = $innerW - ($labelX - $innerX) - rpdf_width($value, 9.5, 'F2') - 10;
        $c->text($labelX, $y + 9, rpdf_fit((string)$row['label'], $labelW, 9.5, 'F2'), 9.5, 'F2', $muted ? RPDF_SOFT : RPDF_INK);
        $c->textRight($innerX + $innerW, $y + 9, $value, 9.5, 'F2', RPDF_GREEN_DEEP);
        $trackW = $innerW - ($labelX - $innerX);
        $c->roundRect($labelX, $y + 13, $trackW, 5, 2.5, RPDF_LINE);
        $fillW = max(5.0, $trackW * (int)$row['total'] / $max);
        $c->roundRect($labelX, $y + 13, $fillW, 5, 2.5, $muted ? [0.75, 0.79, 0.77] : RPDF_GOLD);
        $y += RPDF_ROW_H;
    }
    if ($parts['note']) {
        $y += 2;
        $c->line($innerX, $y, $innerX + $innerW, $y, RPDF_LINE, 0.75, '2 2');
        $y += 11;
        foreach ($parts['note'] as $line) {
            $c->text($innerX, $y, $line, 7.5, 'F1', RPDF_FAINT);
            $y += 10;
        }
    }
}

/** Full-width bar card; long lists continue on the next page. */
function rpdf_bar_card(RpdfLayout $l, string $title, array $rows, bool $ranked, string $zeroNoun, string $emptyText): void {
    $parts = rpdf_bar_parts($rows, RPDF_CONTENT_W - 28, $zeroNoun);
    $all = $parts['rows'];
    $first = true;
    do {
        $fit = (int)floor(($l->room() - RPDF_CARD_HEAD - 6 - ($parts['note'] ? 10 + count($parts['note']) * 10 : 0)) / RPDF_ROW_H);
        if ($fit < 2 && count($all) > 1 || $fit < 1) {
            $l->nextPage();
            continue;
        }
        $chunk = array_slice($all, 0, $fit);
        $all = array_slice($all, count($chunk));
        $piece = ['rows' => $chunk, 'note' => $all ? [] : $parts['note']];
        $h = rpdf_bar_card_height($piece);
        rpdf_draw_bar_card($l->c, RPDF_M, $l->y, RPDF_CONTENT_W, $h, $title . ($first ? '' : ' (continued)'), $piece, $ranked, $emptyText);
        $l->y += $h + 14;
        $first = false;
    } while ($all);
}

/** Cards two to a row, each row as tall as its taller card. */
function rpdf_bar_grid(RpdfLayout $l, array $cards, string $zeroNoun): void {
    $gap = 12.0;
    $w = (RPDF_CONTENT_W - $gap) / 2;
    foreach (array_chunk($cards, 2) as $pair) {
        $parts = array_map(fn($card) => rpdf_bar_parts($card['rows'], $w - 28, $zeroNoun), $pair);
        $h = max(array_map('rpdf_bar_card_height', $parts));
        $l->need($h);
        foreach ($pair as $i => $card) {
            rpdf_draw_bar_card($l->c, RPDF_M + $i * ($w + $gap), $l->y, $w, $h, $card['title'], $parts[$i], false, 'No entries.');
        }
        $l->y += $h + $gap;
    }
    $l->y += 2;
}

/** Six small tiles; overdue, missing and fines turn amber above zero. */
function rpdf_stat_tiles(RpdfLayout $l, array $totals): void {
    $tiles = [
        ['Total Records', (int)$totals['total'], number_format((int)$totals['total']), false],
        ['Borrowed', (int)$totals['borrowed'], number_format((int)$totals['borrowed']), false],
        ['Returned', (int)$totals['returned'], number_format((int)$totals['returned']), false],
        ['Overdue', (int)$totals['overdue'], number_format((int)$totals['overdue']), true],
        ['Missing', (int)$totals['missing'], number_format((int)$totals['missing']), true],
        ['Fines (PHP)', (float)$totals['total_fine'], number_format((float)$totals['total_fine'], 2), true],
    ];
    $gap = 8.0;
    $w = (RPDF_CONTENT_W - 5 * $gap) / 6;
    $h = 44.0;
    $l->need($h);
    foreach ($tiles as $i => [$label, $value, $text, $warn]) {
        $x = RPDF_M + $i * ($w + $gap);
        $alert = $warn && $value > 0;
        $l->c->roundRect($x, $l->y, $w, $h, 6, $alert ? RPDF_AMBER_BG : RPDF_CARD_BG, $alert ? RPDF_AMBER_LINE : RPDF_LINE);
        $l->c->text($x + 9, $l->y + 14, strtoupper($label), 6.5, 'F2', $alert ? RPDF_AMBER_TEXT : RPDF_SOFT);
        $l->c->text($x + 9, $l->y + 33, rpdf_fit($text, $w - 16, 13, 'F2'), 13, 'F2', $alert ? RPDF_AMBER_TEXT : ($value > 0 ? RPDF_INK : RPDF_FAINT));
    }
    $l->y += $h + 14;
}

/** Detailed records, a framed table that continues across pages. */
function rpdf_records_table(RpdfLayout $l, array $rows, string $emptyText): void {
    // Widths plus the 8pt gaps fill the card's inner width (516pt) exactly.
    $cols = [['ID', 32], ['Date', 56], ['User', 130], ['Book', 146], ['Status', 54], ['Fine', 44]];
    $rowH = 16.0;
    $innerX = RPDF_M + 12;
    $innerW = RPDF_CONTENT_W - 24;
    $i = 0;
    $n = count($rows);
    $first = true;
    do {
        $fit = (int)floor(($l->room() - RPDF_CARD_HEAD - 18 - 10) / $rowH);
        if ($fit < 3 && $n > 0) {
            $l->nextPage();
            continue;
        }
        $chunk = array_slice($rows, $i, $fit);
        $h = RPDF_CARD_HEAD + 18 + max(1, count($chunk)) * $rowH + 10;
        rpdf_card_frame($l->c, RPDF_M, $l->y, RPDF_CONTENT_W, $h, 'Loan records' . ($first ? '' : ' (continued)'));
        $y = $l->y + RPDF_CARD_HEAD;
        $l->c->rect($innerX, $y, $innerW, 18, [0.933, 0.961, 0.945]);
        $x = $innerX + 6;
        foreach ($cols as [$label, $w]) {
            $label === 'Fine'
                ? $l->c->textRight($innerX + $innerW - 6, $y + 12, $label, 8, 'F2', RPDF_SOFT)
                : $l->c->text($x, $y + 12, $label, 8, 'F2', RPDF_SOFT);
            $x += $w + 8;
        }
        $y += 18;
        if (!$chunk) {
            $l->c->text($innerX + 6, $y + 11, $emptyText, 9, 'F1', RPDF_FAINT);
        }
        foreach ($chunk as $k => $row) {
            if ($k % 2 === 1) {
                $l->c->rect($innerX, $y, $innerW, $rowH, RPDF_CARD_BG);
            }
            $userNumber = trim((string)($row['user_number'] ?? ''));
            $user = trim(trim((string)($row['borrower_name'] ?? '')) . ($userNumber !== '' ? ' (' . $userNumber . ')' : ''));
            $cells = ['#' . (int)($row['record_id'] ?? 0), (string)($row['activity_date'] ?? '-'), $user !== '' ? $user : '-',
                (string)($row['title'] ?? '-'), ucfirst(strtolower((string)($row['status'] ?? ''))), number_format((float)($row['fine'] ?? 0), 2)];
            $x = $innerX + 6;
            foreach ($cols as $c => [$label, $w]) {
                $label === 'Fine'
                    ? $l->c->textRight($innerX + $innerW - 6, $y + 11, $cells[$c], 8, 'F1', RPDF_INK)
                    : $l->c->text($x, $y + 11, rpdf_fit($cells[$c], $w, 8, 'F1'), 8, $c === 0 ? 'F2' : 'F1', RPDF_INK);
                $x += $w + 8;
            }
            $y += $rowH;
        }
        $l->y += $h + 14;
        $i += count($chunk);
        $first = false;
    } while ($i < $n);
}

function rpdf_signature(RpdfLayout $l): void {
    $l->need(70);
    $l->y += 14;
    $l->c->line(RPDF_M, $l->y, RPDF_M + RPDF_CONTENT_W, $l->y, RPDF_LINE, 0.75);
    $l->y += 38;
    $w = (RPDF_CONTENT_W - 40) / 2;
    $l->c->line(RPDF_M, $l->y, RPDF_M + $w, $l->y, RPDF_SOFT, 0.75);
    $l->c->line(RPDF_M + $w + 40, $l->y, RPDF_M + RPDF_CONTENT_W, $l->y, RPDF_SOFT, 0.75);
    $l->c->text(RPDF_M, $l->y + 12, 'Reviewed by (Librarian)', 8, 'F1', RPDF_FAINT);
    $l->c->text(RPDF_M + $w + 40, $l->y + 12, 'Date', 8, 'F1', RPDF_FAINT);
    $l->y += 20;
}

// ------------------------------------------------------------- reports ----

/** Chart buckets (days/weeks/months, as on screen) as bar-card rows. */
function rpdf_bucket_rows(array $dailyRows, string $from, string $to): array {
    $chart = reports_modal_buckets($dailyRows, $from, $to);
    $label = fn($bar) => $chart['unit'] === 'week' ? 'Week of ' . $bar['label'] : $bar['long'];
    return [$chart['unit'], array_map(fn($bar) => ['label' => $label($bar), 'total' => $bar['total']], $chart['bars'])];
}

/**
 * Build the branded PDF. $r carries what the export already has: report
 * type and title, range, totals, the trend rows, top books, programs,
 * detailed rows and the entry report.
 */
function reports_build_branded_pdf(array $r): string {
    $type = (string)$r['report_type'];
    $mode = (string)$r['mode'];
    $scope = reports_resolve_scope($type, $mode);
    $from = (string)$r['range_from'];
    $to = (string)$r['range_to'];
    $periodWord = reports_modal_period_word((string)$r['period']);
    $totals = (array)$r['totals'];
    $entry = (array)$r['entry_report'];
    $isEntry = $type === 'entry_log';
    $borrowDaily = (array)$r['borrow_daily'];
    $searchDaily = (array)$r['search_daily'];

    $seal = rpdf_png(__DIR__ . '/../../assets/images/stjude_seal_64.png');
    $layout = new RpdfLayout($seal !== null, (string)$r['title']);
    $layout->firstPage((string)$r['generated_at']);

    $rangeLabel = (string)$r['range_label'];
    $meta = (str_starts_with($rangeLabel, 'Custom') ? 'Custom range' : $rangeLabel) . ' · ' . reports_modal_short_range($from, $to);
    if (!$isEntry && $type !== 'search_trend' && (string)$r['status_filter'] !== 'all') {
        $meta .= ' · Status: ' . ucfirst((string)$r['status_filter']);
    }
    rpdf_meta_row($layout, $meta);

    $searchChart = reports_modal_buckets($searchDaily, $from, $to);
    $searchPeak = reports_modal_peak_sentence('Search activity', 'searches', $searchChart, reports_modal_peak_day($searchDaily));
    $searchTotal = array_sum(array_column($searchDaily, 'total'));
    $programs = (array)$r['program_activity'];

    // Headline tiles and the one-line summary, per report.
    if ($isEntry) {
        $ok = !empty($entry['ok']);
        $tiles = [['value' => number_format($ok ? (int)$entry['total'] : 0), 'label' => 'Entries'], ['value' => number_format($ok ? (int)$entry['people'] : 0), 'label' => 'People']];
        // The tiles already give entries and people; keep what comes after.
        $sentence = reports_entry_highlights($entry, $from, $to, $periodWord)['text'];
    } elseif ($type === 'search_trend') {
        $peakDay = reports_modal_peak_day($searchDaily);
        $tiles = [['value' => number_format($searchTotal), 'label' => 'Searches'], ['value' => $peakDay ? number_format($peakDay['total']) : '0', 'label' => $peakDay ? 'Peak: ' . $peakDay['label'] : 'Peak day']];
        $sentence = $searchTotal > 0 ? number_format($searchTotal) . ' searches this ' . $periodWord . '. ' . $searchPeak : reports_modal_empty_text('searches', $periodWord);
    } elseif ($type === 'program_activity') {
        $top = $programs[0] ?? null;
        $tiles = [['value' => number_format(count($programs)), 'label' => 'Programs'], ['value' => $top ? number_format((int)$top['borrow_count']) : '0', 'label' => $top ? 'Top: ' . $top['program_name'] : 'Top program']];
        $sentence = $top ? $top['program_name'] . ' led with ' . number_format((int)$top['borrow_count']) . ' borrows this ' . $periodWord . '.' : reports_modal_empty_text('program borrowing', $periodWord);
    } elseif ($type === 'detailed_transactions') {
        $tiles = [['value' => number_format((int)$totals['total']), 'label' => 'Records'], ['value' => number_format((int)$totals['overdue']), 'label' => 'Overdue'], ['value' => number_format((float)$totals['total_fine'], 0), 'label' => 'Fines (PHP)']];
        $sentence = (int)$totals['total'] > 0 ? (reports_modal_borrow_sentence($totals, $periodWord) . ' Every loan in the range is listed below.') : reports_modal_empty_text('loans', $periodWord);
    } else {
        $tiles = [['value' => number_format((int)$totals['total']), 'label' => 'Total'], ['value' => number_format((int)$totals['borrowed']), 'label' => 'Borrowed'], ['value' => number_format((int)$totals['overdue']), 'label' => 'Overdue']];
        $borrowSentence = reports_modal_borrow_sentence($totals, $periodWord);
        $sentence = trim(($borrowSentence !== '' ? $borrowSentence : 'No books borrowed yet this ' . $periodWord . '.') . ($type !== 'monthly_summary' && $scope['show_search'] ? ' ' . $searchPeak : ''));
        if ($borrowSentence === '' && $searchPeak === '') {
            $sentence = reports_modal_empty_text('borrowing activity', $periodWord);
        }
    }
    rpdf_headline($layout, $tiles, $sentence);

    // Body: each report's own cards.
    if ($isEntry) {
        if (!empty($entry['ok']) && (int)$entry['total'] > 0) {
            rpdf_bar_grid($layout, array_map(fn($s) => ['title' => $s['title'], 'rows' => $s['rows']], $entry['sections']), 'entries');
        }
    } elseif ($type === 'detailed_transactions') {
        rpdf_records_table($layout, (array)$r['detailed_rows'], reports_modal_empty_text('loans', $periodWord));
    } else {
        if ($scope['show_totals']) {
            rpdf_stat_tiles($layout, $totals);
        }
        if ($scope['show_status']) {
            rpdf_bar_card($layout, 'Status breakdown', array_map(fn($row) => ['label' => ucfirst((string)$row['status']), 'total' => $row['total']], (array)$r['status_breakdown']), false, 'loans', 'No loans this ' . $periodWord . '.');
        }
        if ($scope['show_top_books']) {
            rpdf_bar_card($layout, 'Top borrowed books', array_map(fn($row) => ['label' => $row['title'] !== '' ? $row['title'] : 'Unknown title', 'total' => $row['borrow_count']], (array)$r['top_books']), true, 'borrows', 'No books borrowed yet this ' . $periodWord . '.');
        }
        if ($scope['show_borrow'] || $scope['show_activity_month']) {
            [$unit, $rows] = rpdf_bucket_rows($borrowDaily, $from, $to);
            rpdf_bar_card($layout, 'Borrow activity by ' . $unit, $rows, false, 'borrows', 'No borrowing activity yet this ' . $periodWord . '.');
        }
        if ($scope['show_search']) {
            [$unit, $rows] = rpdf_bucket_rows($searchDaily, $from, $to);
            rpdf_bar_card($layout, 'Search activity by ' . $unit, $rows, false, 'searches', 'No searches recorded yet this ' . $periodWord . '.');
        }
        if ($scope['show_program']) {
            rpdf_bar_card($layout, 'Borrows by program', array_map(fn($row) => ['label' => $row['program_name'], 'total' => $row['borrow_count']], $programs), true, 'borrows', 'No borrowing by program yet this ' . $periodWord . '.');
        }
        if (!empty($scope['show_entries']) && !empty($entry['ok']) && (int)$entry['total'] > 0) {
            rpdf_bar_grid($layout, array_map(fn($s) => ['title' => $s['title'], 'rows' => $s['rows']], $entry['sections']), 'entries');
        }
        if ($scope['show_detailed']) {
            rpdf_records_table($layout, (array)$r['detailed_rows'], reports_modal_empty_text('loans', $periodWord));
        }
    }

    rpdf_signature($layout);
    return rpdf_assemble($layout->finish(), $seal);
}
