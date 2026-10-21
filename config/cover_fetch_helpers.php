<?php
declare(strict_types=1);

// Shared cover-matching / cover-saving logic for SmartLib.
//
// This file holds every function that both tools/fetch_book_covers.php (the
// nightly batch job that backfills missing covers for the whole catalog)
// and admin/manage_books.php (the librarian-facing "fetch cover now" /
// "re-fetch cover" / "upload cover" / "clear cover" actions) need, so the
// matching rules live in exactly one place.
//
// IMPORTANT: tools/fetch_book_covers.php's own driver loop (the part that
// walks every book with no cover, decides --commit vs dry-run, and prints
// the run summary) was deliberately left untouched when this file was
// split out -- only its function/constant definitions moved here, verbatim.
// If matching behavior ever needs to change, change it here; both the
// nightly job and the admin panel pick it up automatically.
//
// fbc_find_cover(), fbc_save_cover_bytes(), fbc_validate_uploaded_image()
// and fbc_clear_cover() near the bottom of this file are NOT used by
// tools/fetch_book_covers.php -- its own driver loop still calls the
// fbc_try_* functions directly, exactly as it always has, so its existing
// counters and run-summary output are completely unchanged. Those four
// functions exist for the admin panel's single-book actions.

const COVERS_DIR = __DIR__ . '/../assets/covers';
const USER_AGENT = 'SmartLibCoverFetch/1.0 (+library kiosk cataloging tool)';
const TITLE_SIMILARITY_THRESHOLD = 90.0; // percent, via similar_text()
const MIN_IMAGE_BYTES = 1000; // Open Library's "not found" placeholder is tiny; real covers aren't.
const REQUEST_SLEEP_MICROSECONDS = 250000; // be polite to the free API: ~4 req/sec cap

// Google Books fallback is optional -- if config/google_books_config.php
// doesn't exist yet, or its key is blank, GOOGLE_BOOKS_API_KEY just stays
// '' and the Google steps below are skipped (Open Library-only behavior).
$googleBooksConfigPath = __DIR__ . '/google_books_config.php';
if (is_file($googleBooksConfigPath)) {
    require $googleBooksConfigPath;
}
if (!defined('GOOGLE_BOOKS_API_KEY')) {
    define('GOOGLE_BOOKS_API_KEY', '');
}
unset($googleBooksConfigPath);

// Books confirmed, by a human actually looking at the downloaded image (not
// just its dimensions), to only ever resolve to a wrong cover via this API --
// either because the match is a deterministic ISBN lookup that always returns
// the same bad image, or because Open Library's search consistently ranks
// the same wrong candidate first. Re-running the nightly job for these
// book_ids just re-downloads the identical wrong result (confirmed
// 2026-10-01: all 9 of these were cleared via
// tools/clear_bad_covers.php --force --commit, then the nightly job
// re-fetched the exact same bad file, byte-for-byte, the very next run).
// Skipped unconditionally by the nightly job -- no API call spent on them --
// until someone removes an entry here after sourcing a correct cover
// manually. The admin panel's "re-fetch cover" action still shows these
// book_ids a caution note (see admin/manage_books.php) but does not block
// a librarian from trying anyway, since a human is right there to look at
// the result before it's saved.
const EXCLUDED_BOOK_IDS = [
    324  => 'ISBN lookup returns a section-divider page, not cover art',
    336  => 'ISBN lookup returns a PREFACE page from an unrelated book',
    491  => 'title/author search top match is a personal photo, no title text',
    569  => 'ISBN lookup returns a corrupted/garbled image file',
    803  => 'ISBN lookup returns a textbook worksheet page ("Lesson Master")',
    948  => 'title/author search top match is a random body-text page',
    1062 => 'title/author search top match is a random body-text page',
    1487 => 'title/author search top match is a selfie of someone holding the book',
    1653 => 'title/author search top match is a generic "cover image unavailable" placeholder graphic, not a real cover',
];

function fbc_curl_available(): bool {
    return function_exists('curl_init');
}

function fbc_normalize(string $s): string {
    $s = strtolower($s);
    $s = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $s) ?? $s;
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    return trim($s);
}

function fbc_title_similarity(string $a, string $b): float {
    $a = fbc_normalize($a);
    $b = fbc_normalize($b);
    if ($a === '' || $b === '') {
        return 0.0;
    }
    similar_text($a, $b, $pct);
    return $pct;
}

function fbc_author_surname(string $author): string {
    $author = trim($author);
    if ($author === '') {
        return '';
    }
    // Handle "Last, First" and "First Last" both -- take the most distinctive
    // single word (the longest one) rather than assuming a specific order.
    $parts = preg_split('/[,\s]+/', $author) ?: [];
    $parts = array_filter($parts, static fn($p) => mb_strlen($p) > 1);
    if (empty($parts)) {
        return fbc_normalize($author);
    }
    usort($parts, static fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
    return fbc_normalize((string)$parts[0]);
}

/** @return array{0:int,1:string}  [http_status, response_body] */
function fbc_http_get(string $url, int $timeoutSeconds = 15): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_USERAGENT => USER_AGENT,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErrno = curl_errno($ch);
    $curlError = $curlErrno !== 0 ? curl_error($ch) : '';
    curl_close($ch);
    // Stash the curl-level outcome so a caller that gets back status 0 (no HTTP
    // response at all -- DNS failure, SSL/certificate problem, connect timeout,
    // etc.) can ask what actually went wrong, instead of that looking identical
    // to an ordinary "no match" result.
    fbc_last_curl_error($curlErrno, $curlError);
    return [$status, $body === false ? '' : (string)$body];
}

/**
 * Call with $errno set (from fbc_http_get) to record the curl errno/message from
 * the most recent request; call with no arguments to read it back. 0/'' means
 * that request got a real HTTP response (even an error status like 429) rather
 * than failing at the connection level.
 */
function fbc_last_curl_error(?int $errno = null, string $error = ''): array {
    static $lastErrno = 0;
    static $lastError = '';
    if ($errno !== null) {
        $lastErrno = $errno;
        $lastError = $error;
    }
    return [$lastErrno, $lastError];
}

function fbc_image_extension(string $bytes): ?string {
    if (strlen($bytes) < 12) {
        return null;
    }
    if (substr($bytes, 0, 3) === "\xFF\xD8\xFF") {
        return 'jpg';
    }
    if (substr($bytes, 0, 8) === "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A") {
        return 'png';
    }
    return null;
}

/** A real book cover is portrait or square, essentially never landscape. */
function fbc_is_portrait_or_square(string $bytes): bool {
    $info = @getimagesizefromstring($bytes);
    if ($info === false) {
        return false;
    }
    [$width, $height] = $info;
    return $height >= $width;
}

/** Try a direct ISBN cover lookup. Returns image bytes+ext, or null. */
function fbc_try_isbn(string $isbn): ?array {
    $clean = preg_replace('/[^0-9Xx]/', '', $isbn) ?? '';
    if ($clean === '') {
        return null;
    }
    $url = 'https://covers.openlibrary.org/b/isbn/' . rawurlencode($clean) . '-L.jpg?default=false';
    [$status, $body] = fbc_http_get($url);
    if ($status !== 200 || strlen($body) < MIN_IMAGE_BYTES) {
        return null;
    }
    $ext = fbc_image_extension($body);
    if ($ext === null) {
        return null;
    }
    if (!fbc_is_portrait_or_square($body)) {
        return null;
    }
    return ['bytes' => $body, 'ext' => $ext, 'via' => "ISBN {$clean}"];
}

/** Try a title/author search match. Returns image bytes+ext+confidence info, or null. */
function fbc_try_title_author(string $title, string $author): ?array {
    if (trim($title) === '') {
        return null;
    }
    $url = 'https://openlibrary.org/search.json?title=' . rawurlencode($title)
        . '&author=' . rawurlencode($author)
        . '&limit=5&fields=title,author_name,cover_i';
    [$status, $body] = fbc_http_get($url, 10);
    if ($status !== 200 || $body === '') {
        return null;
    }
    $data = json_decode($body, true);
    if (!is_array($data) || empty($data['docs'])) {
        return null;
    }
    $wantedSurname = fbc_author_surname($author);
    $bestNoCoverMatch = null; // remember the first confident text match, in case no candidate has a usable image
    foreach ($data['docs'] as $doc) {
        $docTitle = (string)($doc['title'] ?? '');
        $similarity = fbc_title_similarity($title, $docTitle);
        if ($similarity < TITLE_SIMILARITY_THRESHOLD) {
            continue;
        }
        $authorNames = $doc['author_name'] ?? [];
        if (!is_array($authorNames)) {
            $authorNames = [];
        }
        $authorMatches = $wantedSurname === '';
        foreach ($authorNames as $name) {
            if ($wantedSurname !== '' && str_contains(fbc_normalize((string)$name), $wantedSurname)) {
                $authorMatches = true;
                break;
            }
        }
        if (!$authorMatches) {
            continue;
        }
        if ($bestNoCoverMatch === null) {
            $bestNoCoverMatch = ['matched_title' => $docTitle, 'similarity' => $similarity];
        }
        $coverId = $doc['cover_i'] ?? null;
        if ($coverId === null) {
            // Confident title+author match, but Open Library has no cover for it.
            // Keep checking other candidates before giving up entirely.
            continue;
        }
        $coverUrl = 'https://covers.openlibrary.org/b/id/' . (int)$coverId . '-L.jpg?default=false';
        [$imgStatus, $imgBody] = fbc_http_get($coverUrl);
        if ($imgStatus !== 200 || strlen($imgBody) < MIN_IMAGE_BYTES) {
            continue;
        }
        $ext = fbc_image_extension($imgBody);
        if ($ext === null) {
            continue;
        }
        if (!fbc_is_portrait_or_square($imgBody)) {
            // A landscape image is almost never a real cover -- Open Library's
            // crowdsourced data occasionally attaches the wrong image to an
            // otherwise correct title+author match. Don't accept it; try the
            // next candidate instead of settling for a known-bad image.
            continue;
        }
        return [
            'bytes' => $imgBody,
            'ext' => $ext,
            'via' => sprintf('title/author match "%s" (%.0f%% title similarity)', $docTitle, $similarity),
        ];
    }
    if ($bestNoCoverMatch !== null) {
        return ['no_cover_for_match' => true] + $bestNoCoverMatch;
    }
    return null;
}

/**
 * Google's imageLinks URLs come back as plain http:// and usually at a small
 * "zoom=1" thumbnail size. Prefer an https, higher-zoom variant first (the
 * same cover id just served bigger), but keep the original as a fallback in
 * case the bigger variant doesn't actually exist for that book.
 * @return string[]
 */
function fbc_google_cover_url_variants(string $url): array {
    $url = preg_replace('#^http://#', 'https://', $url) ?? $url;
    $variants = [$url];
    if (preg_match('/[?&]zoom=\d+/', $url)) {
        $bigger = preg_replace('/([?&]zoom=)\d+/', '${1}3', $url);
        if (is_string($bigger) && $bigger !== $url) {
            array_unshift($variants, $bigger);
        }
    }
    return $variants;
}

/**
 * Writes a diagnostic line to STDERR when running under the CLI SAPI (the
 * only SAPI where the STDERR constant actually exists), or to the PHP error
 * log otherwise. These fbc_try_google_* functions are called both from the
 * CLI nightly job (tools/fetch_book_covers.php) and from HTTP requests --
 * admin/manage_books.php's single-book "fetch cover now" / "re-fetch cover"
 * actions, and (reusing these same Google-quota helpers)
 * tools/fetch_book_descriptions.php's HTTP-driven equivalent. Writing to
 * STDERR unconditionally used to throw "Undefined constant STDERR" and fatal
 * the whole request the first time a web-triggered call actually hit a
 * 429/403 and reached this logging path -- this guard fixes that for every
 * caller, not just the one that happened to hit it first.
 */
function fbc_log_line(string $msg): void {
    if (defined('STDERR')) {
        fwrite(STDERR, $msg);
    } else {
        error_log(rtrim($msg, "\r\n"));
    }
}

/**
 * Prints Google's actual HTTP status + response body, once per run (not
 * once per book), the first time a 429/403 is hit. This is diagnostic
 * only -- it exists to tell apart a transient burst-rate 429 (slow down and
 * retry later) from a hard 403 (e.g. "API key required", a policy change, or
 * a block) that no amount of waiting will fix. Safe to remove once the real
 * cause is known and handled properly.
 */
function fbc_log_google_diagnostic_once(int $status, string $body, string $url): void {
    static $printed = false;
    if ($printed) {
        return;
    }
    $printed = true;
    fbc_log_line(PHP_EOL . '  [diagnostic] Google Books returned HTTP ' . $status . ' for: ' . $url . PHP_EOL);
    fbc_log_line('  [diagnostic] Response body (first 500 chars): ' . substr($body, 0, 500) . PHP_EOL . PHP_EOL);
}

/** Prints once: a Google Books request that failed at the connection level (DNS,
 *  SSL/certificate, timeout, refused) rather than getting any HTTP response --
 *  without this, that failure looks exactly like an ordinary "no match found"
 *  for every single book, with nothing to tell the two apart. */
function fbc_log_google_connection_error_once(int $curlErrno, string $curlError, string $url): void {
    static $printed = false;
    if ($printed) {
        return;
    }
    $printed = true;
    fbc_log_line(PHP_EOL . '  [diagnostic] Google Books request FAILED to connect (curl errno ' . $curlErrno . '): ' . $curlError . PHP_EOL);
    fbc_log_line('  [diagnostic] URL: ' . $url . PHP_EOL . PHP_EOL);
}

// How long to stop bothering Google after a 429/403, before trying it again.
// Confirmed by direct observation across two real runs (2026-10-05): Google
// here behaves like a short, recoverable burst limit, NOT a one-way "done
// for the day" gate -- one run hit a 429 almost immediately, then kept
// finding real matches starting ~1000 books later once enough wall-clock
// time had passed. A permanent "give up for the rest of this run" switch
// (tried first, and it was a mistake) threw away exactly that recovery,
// because most of this backlog has no Open Library match at all, so it
// reached Google on nearly every early book and tripped a one-strike cutoff
// within the first minute -- long before quota had any chance to come back.
// A periodic cooldown fixes this properly: skip Google for free (no HTTP
// call) while in cooldown, but automatically try again once it elapses, so
// a long run rediscovers a recovered quota on its own instead of needing a
// human to notice and start a fresh process.
const GOOGLE_COOLDOWN_SECONDS = 90;

/**
 * The unix timestamp before which Google shouldn't be tried again. Call
 * with no argument to check it; call with $untilUnixTime to (re)start a
 * cooldown after a 429/403. Every failure restarts the full window from
 * "now" -- a persistent outage just keeps renewing it (at most one wasted
 * request every GOOGLE_COOLDOWN_SECONDS, not one per book), while a single
 * transient miss costs only one short pause before normal attempts resume.
 */
function fbc_google_cooldown_until(?int $untilUnixTime = null): int {
    static $until = 0;
    if ($untilUnixTime !== null) {
        $until = $untilUnixTime;
    }
    return $until;
}

/**
 * True if a Google Books 429/403 error body says the DAY's quota is what's
 * exhausted (Google's own text names the metric: "limit 'Queries per day'"),
 * as opposed to a short burst-rate limit. Confirmed by direct observation
 * (2026-10-05): a short GOOGLE_COOLDOWN_SECONDS retry is right for an ordinary
 * 429, but a "per day" 429 doesn't recover by retrying sooner -- it only
 * clears at Google's daily reset, so retrying every 90 seconds for hours
 * against this one just wastes requests for nothing.
 */
function fbc_is_daily_quota_message(string $body): bool {
    return stripos($body, 'per day') !== false;
}

/**
 * Unix timestamp of the next Google Books quota reset: midnight Pacific Time
 * (Google resets daily API quotas in the America/Los_Angeles timezone, which
 * handles the PDT/PST switch automatically), plus a few minutes of slack in
 * case the actual reset lags the clock boundary slightly.
 */
function fbc_next_google_quota_reset_unix(): int {
    $pacific = new DateTimeZone('America/Los_Angeles');
    $resetPacific = new DateTime((new DateTime('now', $pacific))->format('Y-m-d') . ' 00:00:00', $pacific);
    $resetPacific->modify('+1 day');
    return $resetPacific->getTimestamp() + 300;
}

/** Prints once: Google's daily quota (not a short burst limit) is exhausted, and when
 *  it actually resets -- so a long silent run isn't mistaken for the tool being stuck,
 *  and the user knows exactly when a re-run would actually find something new. */
function fbc_log_google_daily_quota_once(int $resetAtUnix): void {
    static $printed = false;
    if ($printed) {
        return;
    }
    $printed = true;
    $pacific = (new DateTime('@' . $resetAtUnix))->setTimezone(new DateTimeZone('America/Los_Angeles'));
    $utc = (new DateTime('@' . $resetAtUnix))->setTimezone(new DateTimeZone('UTC'));
    fbc_log_line(PHP_EOL . "  [diagnostic] Google says the DAY's quota is exhausted (not a short burst limit) --" . PHP_EOL);
    fbc_log_line('  retrying sooner will not help. Resets daily at midnight Pacific Time: ' . $pacific->format('Y-m-d H:i T') . ' (' . $utc->format('Y-m-d H:i T') . ').' . PHP_EOL);
    fbc_log_line('  Google Books will be skipped for free (no HTTP calls) until then -- re-run after that time' . PHP_EOL);
    fbc_log_line('  to pick up any books still missing a cover.' . PHP_EOL . PHP_EOL);
}

/** Try a Google Books ISBN lookup. Returns image bytes+ext, ['no_cover_for_match'=>true,...], ['rate_limited'=>true], or null. */
function fbc_try_google_isbn(string $isbn): ?array {
    if (GOOGLE_BOOKS_API_KEY === '') {
        return null; // fallback not configured -- see config/google_books_config.php
    }
    if (time() < fbc_google_cooldown_until()) {
        return ['rate_limited' => true]; // still cooling down from an earlier 429 -- no HTTP call spent confirming it again yet
    }
    $clean = preg_replace('/[^0-9Xx]/', '', $isbn) ?? '';
    if ($clean === '') {
        return null;
    }
    $url = 'https://www.googleapis.com/books/v1/volumes?q=' . rawurlencode('isbn:' . $clean)
        . '&key=' . rawurlencode(GOOGLE_BOOKS_API_KEY);
    [$status, $body] = fbc_http_get($url, 10);
    if ($status === 0) {
        [$curlErrno, $curlError] = fbc_last_curl_error();
        fbc_log_google_connection_error_once($curlErrno, $curlError, $url);
        fbc_google_cooldown_until(time() + GOOGLE_COOLDOWN_SECONDS);
        return ['rate_limited' => true];
    }
    if ($status === 429 || $status === 403) {
        fbc_log_google_diagnostic_once($status, $body, $url);
        if (fbc_is_daily_quota_message($body)) {
            $resetAt = fbc_next_google_quota_reset_unix();
            fbc_google_cooldown_until($resetAt);
            fbc_log_google_daily_quota_once($resetAt);
        } else {
            fbc_google_cooldown_until(time() + GOOGLE_COOLDOWN_SECONDS);
        }
        return ['rate_limited' => true];
    }
    if ($status !== 200 || $body === '') {
        return null;
    }
    $data = json_decode($body, true);
    if (!is_array($data) || empty($data['items'])) {
        return null;
    }
    $matchedNoCoverTitle = null; // an ISBN-confirmed record exists, but no usable image -- remember for logging
    foreach ($data['items'] as $item) {
        $volumeInfo = $item['volumeInfo'] ?? [];
        $identifiers = $volumeInfo['industryIdentifiers'] ?? [];
        if (!is_array($identifiers)) {
            $identifiers = [];
        }
        $isbnMatches = false;
        foreach ($identifiers as $id) {
            $idVal = preg_replace('/[^0-9Xx]/', '', (string)($id['identifier'] ?? '')) ?? '';
            if ($idVal !== '' && $idVal === $clean) {
                $isbnMatches = true;
                break;
            }
        }
        if (!$isbnMatches) {
            // Google's isbn: search can rank a different edition first --
            // don't trust it unless the record's own ISBN actually matches.
            continue;
        }
        $imageLinks = $volumeInfo['imageLinks'] ?? null;
        if (!is_array($imageLinks)) {
            $matchedNoCoverTitle ??= (string)($volumeInfo['title'] ?? '') ?: "ISBN {$clean}";
            continue;
        }
        $thumbUrl = $imageLinks['thumbnail'] ?? $imageLinks['smallThumbnail'] ?? null;
        if (!is_string($thumbUrl) || $thumbUrl === '') {
            $matchedNoCoverTitle ??= (string)($volumeInfo['title'] ?? '') ?: "ISBN {$clean}";
            continue;
        }
        foreach (fbc_google_cover_url_variants($thumbUrl) as $variant) {
            [$imgStatus, $imgBody] = fbc_http_get($variant);
            if ($imgStatus !== 200 || strlen($imgBody) < MIN_IMAGE_BYTES) {
                continue;
            }
            $ext = fbc_image_extension($imgBody);
            if ($ext === null) {
                continue;
            }
            if (!fbc_is_portrait_or_square($imgBody)) {
                continue;
            }
            return ['bytes' => $imgBody, 'ext' => $ext, 'via' => "Google Books ISBN {$clean}"];
        }
        $matchedNoCoverTitle ??= (string)($volumeInfo['title'] ?? '') ?: "ISBN {$clean}";
    }
    if ($matchedNoCoverTitle !== null) {
        return ['no_cover_for_match' => true, 'matched_title' => $matchedNoCoverTitle, 'similarity' => 100.0];
    }
    return null;
}

/** Try a Google Books title/author search. Returns image bytes+ext+confidence info, ['rate_limited'=>true], or null. */
function fbc_try_google_title_author(string $title, string $author): ?array {
    if (GOOGLE_BOOKS_API_KEY === '') {
        return null; // fallback not configured -- see config/google_books_config.php
    }
    if (time() < fbc_google_cooldown_until()) {
        return ['rate_limited' => true]; // still cooling down from an earlier 429 -- no HTTP call spent confirming it again yet
    }
    if (trim($title) === '') {
        return null;
    }
    $query = 'intitle:' . $title;
    if (trim($author) !== '') {
        $query .= '+inauthor:' . $author;
    }
    $url = 'https://www.googleapis.com/books/v1/volumes?q=' . rawurlencode($query) . '&maxResults=5'
        . '&key=' . rawurlencode(GOOGLE_BOOKS_API_KEY);
    [$status, $body] = fbc_http_get($url, 10);
    if ($status === 0) {
        [$curlErrno, $curlError] = fbc_last_curl_error();
        fbc_log_google_connection_error_once($curlErrno, $curlError, $url);
        fbc_google_cooldown_until(time() + GOOGLE_COOLDOWN_SECONDS);
        return ['rate_limited' => true];
    }
    if ($status === 429 || $status === 403) {
        fbc_log_google_diagnostic_once($status, $body, $url);
        if (fbc_is_daily_quota_message($body)) {
            $resetAt = fbc_next_google_quota_reset_unix();
            fbc_google_cooldown_until($resetAt);
            fbc_log_google_daily_quota_once($resetAt);
        } else {
            fbc_google_cooldown_until(time() + GOOGLE_COOLDOWN_SECONDS);
        }
        return ['rate_limited' => true];
    }
    if ($status !== 200 || $body === '') {
        return null;
    }
    $data = json_decode($body, true);
    if (!is_array($data) || empty($data['items'])) {
        return null;
    }
    $wantedSurname = fbc_author_surname($author);
    $bestNoCoverMatch = null;
    foreach ($data['items'] as $item) {
        $volumeInfo = $item['volumeInfo'] ?? [];
        $docTitle = (string)($volumeInfo['title'] ?? '');
        $similarity = fbc_title_similarity($title, $docTitle);
        if ($similarity < TITLE_SIMILARITY_THRESHOLD) {
            continue;
        }
        $authorNames = $volumeInfo['authors'] ?? [];
        if (!is_array($authorNames)) {
            $authorNames = [];
        }
        $authorMatches = $wantedSurname === '';
        foreach ($authorNames as $name) {
            if ($wantedSurname !== '' && str_contains(fbc_normalize((string)$name), $wantedSurname)) {
                $authorMatches = true;
                break;
            }
        }
        if (!$authorMatches) {
            continue;
        }
        if ($bestNoCoverMatch === null) {
            $bestNoCoverMatch = ['matched_title' => $docTitle, 'similarity' => $similarity];
        }
        $imageLinks = $volumeInfo['imageLinks'] ?? null;
        if (!is_array($imageLinks)) {
            continue;
        }
        $thumbUrl = $imageLinks['thumbnail'] ?? $imageLinks['smallThumbnail'] ?? null;
        if (!is_string($thumbUrl) || $thumbUrl === '') {
            continue;
        }
        foreach (fbc_google_cover_url_variants($thumbUrl) as $variant) {
            [$imgStatus, $imgBody] = fbc_http_get($variant);
            if ($imgStatus !== 200 || strlen($imgBody) < MIN_IMAGE_BYTES) {
                continue;
            }
            $ext = fbc_image_extension($imgBody);
            if ($ext === null) {
                continue;
            }
            if (!fbc_is_portrait_or_square($imgBody)) {
                continue;
            }
            return [
                'bytes' => $imgBody,
                'ext' => $ext,
                'via' => sprintf('Google Books title/author match "%s" (%.0f%% title similarity)', $docTitle, $similarity),
            ];
        }
    }
    if ($bestNoCoverMatch !== null) {
        return ['no_cover_for_match' => true] + $bestNoCoverMatch;
    }
    return null;
}

/**
 * Single-book cover-matching cascade: ISBN -> title/author -> Google ISBN ->
 * Google title/author, stopping at the first success -- the exact same order
 * and gating tools/fetch_book_covers.php's driver loop uses, just packaged
 * for one book at a time instead of walking the whole catalog.
 *
 * $isbnOnly skips both title/author steps (mirrors --isbn-only).
 * $useGoogle=false skips both Google steps entirely, regardless of whether a
 * key is configured -- used by the Add Book "fetch cover now" action so that
 * saving a new book stays fast and doesn't spend any of the shared Google
 * daily quota that the nightly job relies on; the nightly job's own call
 * sites (tools/fetch_book_covers.php) don't go through this function at all,
 * but any other future caller that wants the full cascade should pass
 * $useGoogle=true (the default).
 *
 * Returns, when a cover is found:
 *   ['status' => 'found', 'kind' => 'isbn'|'title_author'|'google_isbn'|'google_title_author',
 *    'bytes' => string, 'ext' => string, 'via' => string,
 *    'rate_limited_count' => int, 'no_cover_notice' => array|null]
 * Returns, when nothing was found:
 *   ['status' => 'no_match', 'rate_limited_count' => int, 'no_cover_notice' => array|null]
 *
 * no_cover_notice, when not null, is
 *   ['source' => 'open_library'|'google', 'matched_title' => string, 'similarity' => float]
 * -- a confident title+author match was found but that source had no actual
 * cover image for it (first such notice only, matching the nightly job's
 * own "don't double-report the same book" behavior).
 */
function fbc_find_cover(string $title, string $author, string $isbn, bool $isbnOnly = false, bool $useGoogle = true): array {
    $isbn = trim($isbn);
    $noCoverNotice = null;
    $rateLimitedCount = 0;
    $result = null;

    if ($isbn !== '') {
        $found = fbc_try_isbn($isbn);
        usleep(REQUEST_SLEEP_MICROSECONDS);
        if ($found !== null) {
            $result = ['status' => 'found', 'kind' => 'isbn'] + $found;
        }
    }

    if ($result === null && !$isbnOnly) {
        $r = fbc_try_title_author($title, $author);
        usleep(REQUEST_SLEEP_MICROSECONDS);
        if ($r !== null && isset($r['no_cover_for_match'])) {
            $noCoverNotice ??= ['source' => 'open_library', 'matched_title' => $r['matched_title'], 'similarity' => $r['similarity']];
        } elseif ($r !== null) {
            $result = ['status' => 'found', 'kind' => 'title_author'] + $r;
        }
    }

    if ($result === null && $useGoogle && $isbn !== '') {
        $g = fbc_try_google_isbn($isbn);
        usleep(REQUEST_SLEEP_MICROSECONDS);
        if ($g !== null && isset($g['rate_limited'])) {
            $rateLimitedCount++;
        } elseif ($g !== null && isset($g['no_cover_for_match'])) {
            $noCoverNotice ??= ['source' => 'google', 'matched_title' => $g['matched_title'], 'similarity' => $g['similarity']];
        } elseif ($g !== null) {
            $result = ['status' => 'found', 'kind' => 'google_isbn'] + $g;
        }
    }

    if ($result === null && $useGoogle && !$isbnOnly) {
        $g = fbc_try_google_title_author($title, $author);
        usleep(REQUEST_SLEEP_MICROSECONDS);
        if ($g !== null && isset($g['rate_limited'])) {
            $rateLimitedCount++;
        } elseif ($g !== null && isset($g['no_cover_for_match'])) {
            $noCoverNotice ??= ['source' => 'google', 'matched_title' => $g['matched_title'], 'similarity' => $g['similarity']];
        } elseif ($g !== null) {
            $result = ['status' => 'found', 'kind' => 'google_title_author'] + $g;
        }
    }

    if ($result === null) {
        $result = ['status' => 'no_match'];
    }

    $result['rate_limited_count'] = $rateLimitedCount;
    $result['no_cover_notice'] = $noCoverNotice;

    return $result;
}

/**
 * Saves cover image bytes for a book: writes assets/covers/cover_<id>.<ext>
 * and points books.cover at it. Used for every "a cover is now set" path --
 * auto-matched (an fbc_find_cover() result) or manually uploaded -- so every
 * saved cover follows the same cover_<id>.<ext> naming convention that
 * tools/clear_bad_covers.php (and fbc_clear_cover() below) rely on to know a
 * file is safe to delete.
 *
 * @return array{ok:bool,filename?:string,error?:string}
 */
function fbc_save_cover_bytes(mysqli $conn, int $bookId, string $bytes, string $ext): array {
    if (!is_dir(COVERS_DIR)) {
        if (!mkdir(COVERS_DIR, 0755, true) && !is_dir(COVERS_DIR)) {
            return ['ok' => false, 'error' => 'Could not create the covers folder.'];
        }
    }
    $filename = 'cover_' . $bookId . '.' . $ext;
    $fullPath = COVERS_DIR . '/' . $filename;

    // If this book already has a saved cover under a DIFFERENT extension
    // (e.g. a re-fetch or re-upload replaces a .jpg with a .png), remove the
    // old file so it doesn't linger as an orphan once books.cover below no
    // longer points at it. Only ever touches cover_<this book's id>.<ext>
    // files -- the same naming convention tools/clear_bad_covers.php and
    // fbc_clear_cover() rely on to know a file is safe to delete.
    foreach (glob(COVERS_DIR . '/cover_' . $bookId . '.*') as $oldFile) {
        if (basename($oldFile) !== $filename) {
            @unlink($oldFile);
        }
    }

    $written = file_put_contents($fullPath, $bytes);
    if ($written === false) {
        return ['ok' => false, 'error' => 'Could not write the cover image file.'];
    }
    $stmt = $conn->prepare('UPDATE books SET cover = ? WHERE book_id = ?');
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Database error preparing the cover update.'];
    }
    $stmt->bind_param('si', $filename, $bookId);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) {
        return ['ok' => false, 'error' => 'Database error saving the cover filename.'];
    }
    return ['ok' => true, 'filename' => $filename];
}

/**
 * Validates a librarian-uploaded cover image file (from $_FILES, already
 * moved/accessible at $tmpPath). Deliberately does NOT enforce the
 * portrait-or-square check that the automated matching functions above use
 * -- that check exists to catch an automated mismatch; it has no business
 * second-guessing an image a human looked at and chose on purpose.
 *
 * @return array{ok:bool,bytes?:string,ext?:string,error?:string}
 */
function fbc_validate_uploaded_image(string $tmpPath): array {
    $bytes = @file_get_contents($tmpPath);
    if ($bytes === false || $bytes === '') {
        return ['ok' => false, 'error' => 'Could not read the uploaded file.'];
    }
    if (strlen($bytes) < MIN_IMAGE_BYTES) {
        return ['ok' => false, 'error' => 'That file is too small to be a real cover image.'];
    }
    $ext = fbc_image_extension($bytes);
    if ($ext === null) {
        return ['ok' => false, 'error' => 'Only JPG and PNG images are supported.'];
    }
    return ['ok' => true, 'bytes' => $bytes, 'ext' => $ext];
}

/**
 * Clears a book's cover: deletes the image file (only if its name matches
 * the cover_<id>.<ext> convention this project's own tools use -- never a
 * differently-named pre-existing cover, since we don't know its provenance;
 * same safety rule tools/clear_bad_covers.php follows) and sets books.cover
 * back to NULL so the nightly job will try to find a replacement.
 *
 * @return array{ok:bool,error?:string}
 */
function fbc_clear_cover(mysqli $conn, int $bookId, ?string $coverFilename): array {
    $coverFilename = trim((string)$coverFilename);
    if ($coverFilename !== '' && preg_match('/^cover_[0-9]+\.(jpg|jpeg|png)$/i', $coverFilename)) {
        $fullPath = COVERS_DIR . '/' . $coverFilename;
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
    }
    $stmt = $conn->prepare('UPDATE books SET cover = NULL WHERE book_id = ?');
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Database error preparing the cover clear.'];
    }
    $stmt->bind_param('i', $bookId);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) {
        return ['ok' => false, 'error' => 'Database error clearing the cover.'];
    }
    return ['ok' => true];
}
