<?php
declare(strict_types=1);

// Shared description-matching logic for SmartLib -- the description-fetch
// counterpart to config/cover_fetch_helpers.php, built the same way and for
// the same reason: so tools/fetch_book_descriptions.php (the batch job that
// backfills missing descriptions for the whole catalog) keeps all of its
// matching rules in one place, reusable by anything else that wants the same
// cascade later (e.g. a future admin-panel "fetch description now" action,
// mirroring admin/manage_books.php's existing cover actions).
//
// This file deliberately reuses cover_fetch_helpers.php's constants and
// generic plumbing (fbc_normalize(), fbc_title_similarity(),
// fbc_author_surname(), fbc_http_get(), GOOGLE_BOOKS_API_KEY,
// REQUEST_SLEEP_MICROSECONDS, TITLE_SIMILARITY_THRESHOLD, and the Google
// rate-limit/quota-cooldown functions) instead of redefining them -- same
// key, same free-tier quota, same "don't hammer a free API" politeness
// budget, so there's exactly one cooldown clock and one similarity rule for
// the whole catalog-enrichment system, not two that could disagree.
//
// Cascade order (first confident match wins, deterministic sources before
// fuzzy ones, free-forever sources before the quota-limited one):
//   1. Open Library ISBN lookup (openlibrary.org/isbn/{isbn}.json, falling
//      back to that edition's work record -- editions rarely carry their own
//      description, but the shared work record almost always does). No API
//      key, no quota.
//   2. Open Library title+author search -- only if step 1 found nothing.
//      Same confidence gating as the cover-fetch cascade (title similarity
//      >= TITLE_SIMILARITY_THRESHOLD, author surname must appear).
//   3. Google Books ISBN lookup -- only if steps 1-2 found nothing. Same
//      "verify the record's own ISBN actually matches ours before trusting
//      it" rule the cover cascade uses.
//   4. Google Books title+author search -- only if steps 1-3 found nothing.
//
// Unlike covers, a Google Books description needs no extra HTTP round trip
// once a candidate is found -- volumeInfo.description is already present in
// the exact same search response fbc_try_google_isbn()/
// fbc_try_google_title_author() already fetch for covers, just not extracted
// there. That makes description-fetching noticeably cheaper on Google's
// quota, call-for-call, than cover-fetching is.

require_once __DIR__ . '/cover_fetch_helpers.php';

const DESCRIPTION_TARGET_MAX_CHARS = 280; // matches the house style of the 48 hand-written descriptions (avg ~116 chars)
const DESCRIPTION_HARD_MAX_CHARS = 420;   // absolute ceiling if no sentence boundary is found in time

/** Pulls a plain description string out of an Open Library edition or work record, or null. */
function fdc_extract_ol_description(array $data): ?string {
    $d = $data['description'] ?? null;
    $text = null;
    if (is_string($d) && trim($d) !== '') {
        $text = $d;
    } elseif (is_array($d) && isset($d['value']) && is_string($d['value']) && trim($d['value']) !== '') {
        $text = $d['value'];
    }
    if ($text === null || !fdc_looks_like_real_description($text)) {
        return null;
    }
    return $text;
}

/**
 * Open Library's "description" field is crowd-edited and occasionally holds
 * something that isn't a description at all -- a one-word non-review
 * ("fantastic"), or a catalog physical-extent note that got copied into the
 * wrong field (e.g. "xviii, 446 p. : 23 cm", "351 p. ; 28 cm"). Google Books
 * descriptions have not been observed to have this problem, but the same
 * check is applied there too since it's a cheap, harmless safety net.
 * Treating these as "no real description" (rather than saving them) lets
 * the cascade correctly fall through to the next source instead of settling
 * for junk.
 */
function fdc_looks_like_real_description(string $text): bool {
    $t = trim($text);
    if (mb_strlen($t) < 20) {
        return false;
    }
    if (preg_match('/^[ivxlcdm\d,.\s]+p\.?\s*[:;,]/i', $t)) {
        return false; // "xviii, 446 p. : 23 cm" / "351 p. ; 28 cm" style physical-extent notes
    }
    if (preg_match('/\d+\s*cm\.?\s*$/i', $t) && mb_strlen($t) < 80) {
        return false; // short text ending in a page-size measurement, same issue
    }
    if (substr($t, -1) === '?') {
        // Open Library's "description" field is crowd-edited, and someone's
        // leftover question about the book (observed: book #879, "pede po
        // bang sabihin nyo po sa amen yung kung sino sinu ang mga tauhan ng
        // hulig timawa ?" -- a reader asking who the characters are, in
        // Tagalog) can end up stored there instead of a removed or
        // never-written synopsis. That has one tell: the WHOLE text is a
        // single question, with no other sentence before it. A real blurb
        // can legitimately end on a rhetorical hook question while still
        // being a real description (observed: book #1082, "Rumor
        // psychology", whose back-cover copy is "Each day, we struggle to
        // distinguish rumor from fact. Did the U.S. government blow up
        // levees ... ? Did American soldiers ... ?" -- three real sentences,
        // the last two of which happen to be questions). Counting
        // sentence-ending punctuation marks tells the two apart: only reject
        // when there's one sentence total (the lone trailing "?" IS the
        // whole text), never when earlier sentences precede it.
        if (preg_match_all('/[.!?]/', $t) <= 1) {
            return false;
        }
    }
    return true;
}

/**
 * Strips HTML, decodes entities, collapses whitespace, strips stray wrapping
 * quote characters, and trims a raw fetched/generated description down to a
 * tidy blurb length -- cutting at the last sentence boundary at or before
 * DESCRIPTION_TARGET_MAX_CHARS when one exists (so it reads as a complete
 * thought), or at the last whole word before DESCRIPTION_HARD_MAX_CHARS with
 * a trailing ellipsis otherwise.
 *
 * The quote strip (observed: 37 of 281 real-source descriptions, e.g. book
 * #575 "Essentials of Understanding Psychology" -- Google Books' own
 * volumeInfo.description field sometimes carries a publisher pull-quote that
 * opens with a literal " or ' character whose matching close falls past
 * where DESCRIPTION_TARGET_MAX_CHARS/the source's own truncation cuts the
 * text, leaving a dangling leading quote mark with no partner. All 37
 * observed cases were leading-only with zero matching trailing quote, so
 * trimming quote characters from both ends is safe here -- it mirrors the
 * same trim() adh_clean_ai_description() already applies to AI-generated
 * text, just centralized here so every source (real and AI) gets it, not
 * only AI output.
 */
function fdc_clean_description(string $raw): string {
    $text = strip_tags($raw);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
    $text = preg_replace('/\s+/', ' ', $text) ?? $text;
    $text = trim($text);
    $text = trim($text, "\"'“”‘’ \t\n\r");
    if ($text === '') {
        return '';
    }
    if (mb_strlen($text) <= DESCRIPTION_TARGET_MAX_CHARS) {
        return $text;
    }
    $slice = mb_substr($text, 0, DESCRIPTION_TARGET_MAX_CHARS);
    if (preg_match('/^(.*[.!?])\s/su', $slice . ' ', $m) && mb_strlen($m[1]) >= 40) {
        return trim($m[1]);
    }
    $slice = mb_substr($text, 0, DESCRIPTION_HARD_MAX_CHARS);
    $lastSpace = mb_strrpos($slice, ' ');
    if ($lastSpace !== false && $lastSpace > 40) {
        $slice = mb_substr($slice, 0, $lastSpace);
    }
    return trim($slice) . '…';
}

/** Try a direct Open Library ISBN lookup. Returns description+via, or null. */
function fdc_try_open_library_isbn(string $isbn): ?array {
    $clean = preg_replace('/[^0-9Xx]/', '', $isbn) ?? '';
    if ($clean === '') {
        return null;
    }
    $url = 'https://openlibrary.org/isbn/' . rawurlencode($clean) . '.json';
    [$status, $body] = fbc_http_get($url, 10);
    if ($status !== 200 || $body === '') {
        return null;
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        return null;
    }
    $desc = fdc_extract_ol_description($data);
    if ($desc !== null) {
        return ['description' => $desc, 'via' => "Open Library ISBN {$clean} (edition record)"];
    }
    $works = $data['works'] ?? [];
    if (is_array($works) && !empty($works[0]['key'])) {
        $workKey = (string)$works[0]['key'];
        $workUrl = 'https://openlibrary.org' . $workKey . '.json';
        [$wStatus, $wBody] = fbc_http_get($workUrl, 10);
        if ($wStatus === 200 && $wBody !== '') {
            $wData = json_decode($wBody, true);
            if (is_array($wData)) {
                $wDesc = fdc_extract_ol_description($wData);
                if ($wDesc !== null) {
                    return ['description' => $wDesc, 'via' => "Open Library ISBN {$clean} (work record)"];
                }
            }
        }
    }
    return null;
}

/** Try an Open Library title/author search match. Returns description+via+confidence info, or null. */
function fdc_try_open_library_title_author(string $title, string $author): ?array {
    if (trim($title) === '') {
        return null;
    }
    $url = 'https://openlibrary.org/search.json?title=' . rawurlencode($title)
        . '&author=' . rawurlencode($author)
        . '&limit=5&fields=title,author_name,key';
    [$status, $body] = fbc_http_get($url, 10);
    if ($status !== 200 || $body === '') {
        return null;
    }
    $data = json_decode($body, true);
    if (!is_array($data) || empty($data['docs'])) {
        return null;
    }
    $wantedSurname = fbc_author_surname($author);
    $bestNoDescMatch = null;
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
        if ($bestNoDescMatch === null) {
            $bestNoDescMatch = ['matched_title' => $docTitle, 'similarity' => $similarity];
        }
        $workKey = $doc['key'] ?? null;
        if (!is_string($workKey) || $workKey === '') {
            continue;
        }
        $workUrl = 'https://openlibrary.org' . $workKey . '.json';
        [$wStatus, $wBody] = fbc_http_get($workUrl, 10);
        if ($wStatus !== 200 || $wBody === '') {
            continue;
        }
        $wData = json_decode($wBody, true);
        if (!is_array($wData)) {
            continue;
        }
        $desc = fdc_extract_ol_description($wData);
        if ($desc === null) {
            continue;
        }
        return [
            'description' => $desc,
            'via' => sprintf('Open Library title/author match "%s" (%.0f%% title similarity)', $docTitle, $similarity),
        ];
    }
    if ($bestNoDescMatch !== null) {
        return ['no_description_for_match' => true] + $bestNoDescMatch;
    }
    return null;
}

/** Try a Google Books ISBN lookup. Returns description+via, ['no_description_for_match'=>true,...], ['rate_limited'=>true], or null. */
function fdc_try_google_isbn(string $isbn): ?array {
    if (GOOGLE_BOOKS_API_KEY === '') {
        return null; // fallback not configured -- see config/google_books_config.php
    }
    if (time() < fbc_google_cooldown_until()) {
        return ['rate_limited' => true];
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
    $matchedNoDescTitle = null;
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
            continue;
        }
        $desc = $volumeInfo['description'] ?? null;
        if (!is_string($desc) || !fdc_looks_like_real_description($desc)) {
            $matchedNoDescTitle ??= (string)($volumeInfo['title'] ?? '') ?: "ISBN {$clean}";
            continue;
        }
        return ['description' => $desc, 'via' => "Google Books ISBN {$clean}"];
    }
    if ($matchedNoDescTitle !== null) {
        return ['no_description_for_match' => true, 'matched_title' => $matchedNoDescTitle, 'similarity' => 100.0];
    }
    return null;
}

/** Try a Google Books title/author search. Returns description+via+confidence info, ['rate_limited'=>true], or null. */
function fdc_try_google_title_author(string $title, string $author): ?array {
    if (GOOGLE_BOOKS_API_KEY === '') {
        return null;
    }
    if (time() < fbc_google_cooldown_until()) {
        return ['rate_limited' => true];
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
    $bestNoDescMatch = null;
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
        if ($bestNoDescMatch === null) {
            $bestNoDescMatch = ['matched_title' => $docTitle, 'similarity' => $similarity];
        }
        $desc = $volumeInfo['description'] ?? null;
        if (!is_string($desc) || !fdc_looks_like_real_description($desc)) {
            continue;
        }
        return [
            'description' => $desc,
            'via' => sprintf('Google Books title/author match "%s" (%.0f%% title similarity)', $docTitle, $similarity),
        ];
    }
    if ($bestNoDescMatch !== null) {
        return ['no_description_for_match' => true] + $bestNoDescMatch;
    }
    return null;
}

/**
 * Walks every book missing a description and runs the full cascade on each
 * one (Open Library ISBN -> Open Library title/author -> Google ISBN ->
 * Google title/author), stopping at the first confident match. This is the
 * one place the actual batch logic lives -- both
 * tools/fetch_book_descriptions.php (the real CLI tool) and any one-off
 * HTTP-driven run of the same backlog call this exact function, so there is
 * nowhere else for the walking-the-catalog behavior to drift out of sync.
 *
 * $opts: ['commit'=>bool, 'limit'=>?int, 'book_id'=>?int]
 * Returns: ['book_count'=>int, 'results'=>array<row>, 'counters'=>array, 'commit'=>bool]
 * or ['error'=>string, 'results'=>[], 'counters'=>[]] if the query itself failed.
 *
 * Each result row is one of:
 *   ['book_id','title','status'=>'matched','kind','via','source_tag','description']
 *   ['book_id','title','status'=>'skipped_matched_no_description','detail']
 *   ['book_id','title','status'=>'skipped_no_match','detail']
 */
function fdc_fetch_descriptions_batch(mysqli $conn, array $opts = []): array {
    $commit = (bool)($opts['commit'] ?? false);
    $limit = $opts['limit'] ?? null;
    $onlyBookId = $opts['book_id'] ?? null;
    $minBookId = $opts['min_book_id'] ?? null;

    // $minBookId lets a caller resume an ORDER BY book_id walk partway
    // through, without it the same unmatched books at the front of the list
    // (description still NULL after a confident-no-match result) would be
    // re-attempted by every subsequent call instead of making progress --
    // important for driving this a chunk at a time over HTTP, where a single
    // request can't realistically walk the whole catalog in one shot the way
    // the CLI tool's own single run does.
    $where = "(description IS NULL OR TRIM(description) = '')";
    if ($onlyBookId !== null) {
        $where .= ' AND book_id = ' . (int)$onlyBookId;
    }
    if ($minBookId !== null) {
        $where .= ' AND book_id >= ' . (int)$minBookId;
    }
    $sql = "SELECT book_id, title, author, isbn FROM books WHERE {$where} ORDER BY book_id";
    if ($limit !== null) {
        $sql .= ' LIMIT ' . (int)$limit;
    }
    $res = $conn->query($sql);
    if (!$res) {
        return ['error' => $conn->error, 'results' => [], 'counters' => []];
    }
    $books = [];
    while ($row = $res->fetch_assoc()) {
        $books[] = $row;
    }

    $counters = [
        'open_library_isbn' => 0,
        'open_library_title_author' => 0,
        'google_isbn' => 0,
        'google_title_author' => 0,
        'skipped_no_match' => 0,
        'skipped_matched_no_description' => 0,
        'google_rate_limited' => 0,
        'errors' => 0,
    ];
    $results = [];

    foreach ($books as $b) {
        $bookId = (int)$b['book_id'];
        $title = (string)$b['title'];
        $author = (string)$b['author'];
        $isbn = trim((string)($b['isbn'] ?? ''));

        $found = null;
        $kind = null;
        $matchedButNoDescLogged = false;

        if ($isbn !== '') {
            $found = fdc_try_open_library_isbn($isbn);
            usleep(REQUEST_SLEEP_MICROSECONDS);
            if ($found !== null) {
                $kind = 'open_library_isbn';
            }
        }

        if ($found === null) {
            $r = fdc_try_open_library_title_author($title, $author);
            usleep(REQUEST_SLEEP_MICROSECONDS);
            if ($r !== null && isset($r['no_description_for_match'])) {
                $counters['skipped_matched_no_description']++;
                $matchedButNoDescLogged = true;
                $results[] = [
                    'book_id' => $bookId, 'title' => $title, 'status' => 'skipped_matched_no_description',
                    'detail' => sprintf('matched "%s" (%.0f%% similar) on Open Library but no description available', $r['matched_title'], $r['similarity']),
                ];
            } elseif ($r !== null) {
                $found = $r;
                $kind = 'open_library_title_author';
            }
        }

        if ($found === null && $isbn !== '') {
            $g = fdc_try_google_isbn($isbn);
            usleep(REQUEST_SLEEP_MICROSECONDS);
            if ($g !== null && isset($g['rate_limited'])) {
                $counters['google_rate_limited']++;
            } elseif ($g !== null && isset($g['no_description_for_match'])) {
                if (!$matchedButNoDescLogged) {
                    $counters['skipped_matched_no_description']++;
                    $matchedButNoDescLogged = true;
                    $results[] = [
                        'book_id' => $bookId, 'title' => $title, 'status' => 'skipped_matched_no_description',
                        'detail' => sprintf('Google Books has a record for ISBN %s but no description for it', $isbn),
                    ];
                }
            } elseif ($g !== null) {
                $found = $g;
                $kind = 'google_isbn';
            }
        }

        if ($found === null) {
            $g = fdc_try_google_title_author($title, $author);
            usleep(REQUEST_SLEEP_MICROSECONDS);
            if ($g !== null && isset($g['rate_limited'])) {
                $counters['google_rate_limited']++;
            } elseif ($g !== null && isset($g['no_description_for_match'])) {
                if (!$matchedButNoDescLogged) {
                    $counters['skipped_matched_no_description']++;
                    $matchedButNoDescLogged = true;
                    $results[] = [
                        'book_id' => $bookId, 'title' => $title, 'status' => 'skipped_matched_no_description',
                        'detail' => sprintf('matched "%s" (%.0f%% similar) on Google Books but no description for it', $g['matched_title'], $g['similarity']),
                    ];
                }
            } elseif ($g !== null) {
                $found = $g;
                $kind = 'google_title_author';
            }
        }

        if ($found === null) {
            if ($kind === null && !$matchedButNoDescLogged) {
                $counters['skipped_no_match']++;
                $results[] = [
                    'book_id' => $bookId, 'title' => $title, 'status' => 'skipped_no_match',
                    'detail' => sprintf('by %s [isbn=%s] -- no confident match found', $author, $isbn !== '' ? $isbn : '(none)'),
                ];
            }
            continue;
        }

        $counters[$kind]++;
        $sourceTag = (strpos($kind, 'open_library') === 0) ? 'open_library' : 'google_books';
        $clean = fdc_clean_description($found['description']);

        if ($clean === '') {
            // Cleaned down to nothing (shouldn't normally happen, but don't
            // save an empty string as if it were a real description).
            $counters['skipped_no_match']++;
            $results[] = [
                'book_id' => $bookId, 'title' => $title, 'status' => 'skipped_no_match',
                'detail' => 'matched text cleaned down to nothing, skipped',
            ];
            continue;
        }

        $results[] = [
            'book_id' => $bookId, 'title' => $title, 'status' => 'matched', 'kind' => $kind,
            'via' => $found['via'], 'source_tag' => $sourceTag, 'description' => $clean,
        ];

        if ($commit) {
            $stmt = $conn->prepare('UPDATE books SET description = ?, description_source = ? WHERE book_id = ?');
            $stmt->bind_param('ssi', $clean, $sourceTag, $bookId);
            if (!$stmt->execute()) {
                $counters['errors']++;
            }
            $stmt->close();
        }
    }

    return ['book_count' => count($books), 'results' => $results, 'counters' => $counters, 'commit' => $commit];
}
?>
