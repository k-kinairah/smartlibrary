<?php
declare(strict_types=1);

// Writes a short, generic blurb for books that still have no description
// after tools/fetch_book_descriptions.php's real-source pass (Open Library,
// then Google Books -- see config/description_fetch_helpers.php) has already
// run and found nothing. That real-source pass always runs first and always
// takes priority: this file is only ever reached for books it couldn't match
// at all, or matched but found no description text for.
//
// The generated text is deliberately narrow in scope. The prompt gives the
// model ONLY verified catalog metadata -- title, author, subject category,
// academic program -- and explicitly forbids inventing plot details,
// characters, specific events, biographical claims, or any other fact the
// metadata doesn't actually contain. This mirrors the safe, generic-but-
// accurate house style already used by this app's own example descriptions
// (see get_book.php's fallback_book_by_cover(), e.g. "A foundational
// textbook that covers algorithm analysis, data structures, and design
// techniques used in modern computing." -- general subject coverage, no
// specific claims the title/category alone don't support).
//
// Uses OpenAI's chat completions endpoint (config/ai_config.php) with the
// same API key already configured for tools/generate_book_embeddings.php.
// Safe by default: dry run unless --commit is passed (see
// tools/generate_ai_descriptions.php).

require_once __DIR__ . '/ai_config.php';
require_once __DIR__ . '/description_fetch_helpers.php'; // reuses fdc_clean_description()'s length/sentence-boundary trimming

const AI_CHAT_SLEEP_MICROSECONDS = 200000; // be polite to the API: ~5 req/sec cap

if (!function_exists('table_exists')) {
    function table_exists(mysqli $conn, string $table): bool {
        $table = $conn->real_escape_string($table);
        $dbRes = $conn->query('SELECT DATABASE() AS db');
        $db = $dbRes ? (string)($dbRes->fetch_assoc()['db'] ?? '') : '';
        if ($db === '') {
            return false;
        }

        $db = $conn->real_escape_string($db);
        $sql = "SELECT 1 FROM information_schema.tables WHERE table_schema = '$db' AND table_name = '$table' LIMIT 1";
        $res = $conn->query($sql);

        return $res && $res->num_rows > 0;
    }
}

if (!function_exists('column_exists')) {
    function column_exists(mysqli $conn, string $table, string $column): bool {
        $table = $conn->real_escape_string($table);
        $column = $conn->real_escape_string($column);
        $dbRes = $conn->query('SELECT DATABASE() AS db');
        $db = $dbRes ? (string)($dbRes->fetch_assoc()['db'] ?? '') : '';
        if ($db === '') {
            return false;
        }

        $db = $conn->real_escape_string($db);
        $sql = "SELECT 1 FROM information_schema.columns WHERE table_schema = '$db' AND table_name = '$table' AND column_name = '$column' LIMIT 1";
        $res = $conn->query($sql);

        return $res && $res->num_rows > 0;
    }
}

/**
 * The system prompt. Kept tight and repeated verbatim on every call rather
 * than assumed from a prior turn -- each book is its own isolated request,
 * there is no conversation history to drift from.
 */
function adh_system_prompt(): string {
    return <<<PROMPT
You write ONE short catalog blurb (1-2 sentences, under 220 characters) for a library's book-browsing kiosk, in the plain, neutral tone of a library catalog card.

You are given ONLY: title, author, subject category, and (if available) academic program and year. Nothing else about the book is verified, so:
- Never invent plot details, characters, events, settings, or named facts that are not in the given metadata.
- Never invent biographical claims about the author (nationality, credentials, other works, awards).
- Never invent statistics, quotes, or specific claims about the book's contents.
- If the category/title suggests fiction, describe only the general type, genre, and tone implied by the title and category -- not what happens in it.
- If it is clearly an academic, technical, or reference work, describe only the general subject area implied by the category/program -- not specific topics the metadata doesn't name.
- If the metadata is too thin or generic to say anything specific and safe, fall back to a plain, honest sentence that still names the book -- e.g. "⟨Title⟩ is a ⟨category⟩ title held in the library's collection." -- rather than guessing at content. Always work the exact given title into this fallback sentence so it doesn't read as an identical, interchangeable blurb when two thin-metadata books happen to share a category.

Output ONLY the blurb text itself -- no quotation marks, no title repeated as a label, no preamble, no explanation.
PROMPT;
}

/**
 * Builds the per-book user prompt from verified metadata only.
 */
function adh_build_user_prompt(array $book): string {
    $lines = [];
    $lines[] = 'Title: ' . (string)$book['title'];
    if (trim((string)($book['author'] ?? '')) !== '') {
        $lines[] = 'Author: ' . (string)$book['author'];
    }
    if (trim((string)($book['genre_name'] ?? '')) !== '') {
        $lines[] = 'Subject category: ' . (string)$book['genre_name'];
    }
    if (trim((string)($book['program_name'] ?? '')) !== '') {
        $lines[] = 'Academic program: ' . (string)$book['program_name'];
    }
    if (trim((string)($book['year_published'] ?? '')) !== '') {
        $lines[] = 'Year published: ' . (string)$book['year_published'];
    }
    $lines[] = '';
    $lines[] = 'Write the blurb.';

    return implode("\n", $lines);
}

/**
 * Calls OpenAI's chat completions endpoint for one book. Returns the raw
 * generated text, or null on any failure (network error, non-200, empty/
 * refused completion) -- callers treat null as "skip this book, try again
 * another run."
 */
function adh_call_openai_chat(string $systemPrompt, string $userPrompt): ?string {
    if (!function_exists('curl_init')) {
        return null;
    }
    if (!defined('AI_EMBEDDING_API_KEY') || AI_EMBEDDING_API_KEY === '') {
        return null;
    }

    $payload = [
        'model' => AI_CHAT_MODEL,
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ],
        'max_completion_tokens' => AI_CHAT_MAX_OUTPUT_TOKENS, // gpt-6-luna rejects the older 'max_tokens' name with a 400
        'reasoning_effort' => AI_CHAT_REASONING_EFFORT, // gpt-6-luna is a reasoning model; 'low' skips hidden reasoning tokens entirely (confirmed: 0 reasoning_tokens vs. ~80-100 at the default) with no observed quality loss for a task this formulaic -- faster and a little cheaper across ~1,700 calls
        // No 'temperature' here: gpt-6-luna only supports the default value
        // (1) and 400s on anything else, unlike older chat models.
    ];

    $ch = curl_init(AI_CHAT_ENDPOINT);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . AI_EMBEDDING_API_KEY,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $raw = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErrno = curl_errno($ch);
    curl_close($ch);

    if ($curlErrno !== 0 || $raw === false) {
        return null;
    }
    if ($httpCode !== 200) {
        return null;
    }

    $data = json_decode((string)$raw, true);
    $text = $data['choices'][0]['message']['content'] ?? null;
    if (!is_string($text) || trim($text) === '') {
        return null;
    }

    return $text;
}

/**
 * Light cleanup specific to AI output: strips wrapping quotes the model
 * sometimes adds despite being told not to, then runs the exact same
 * whitespace/length trimming fdc_clean_description() applies to real-source
 * descriptions, so AI-generated and real-source text both land in the same
 * house style/length band.
 */
function adh_clean_ai_description(string $raw): string {
    $t = trim($raw);
    $t = trim($t, "\"'“”‘’ \t\n\r");
    return fdc_clean_description($t);
}

/**
 * Central driver. Mirrors fdc_fetch_descriptions_batch()'s shape (same
 * option names: commit, limit, book_id, min_book_id) so the CLI tool and the
 * batch-walking pattern stay consistent between the real-source and
 * AI-fallback passes.
 */
function adh_generate_descriptions_batch(mysqli $conn, array $opts = []): array {
    $commit = !empty($opts['commit']);
    $limit = $opts['limit'] ?? null;
    $onlyBookId = $opts['book_id'] ?? null;
    $minBookId = $opts['min_book_id'] ?? null;

    $hasCategoryTable = table_exists($conn, 'categories');
    $hasProgramTable = table_exists($conn, 'programs');
    $hasCategoryGroup = $hasCategoryTable && column_exists($conn, 'categories', 'category_group');

    $categorySelect = '';
    $categoryJoin = '';
    $programSelect = '';
    $programJoin = '';

    if ($hasCategoryTable) {
        $categoryExpr = $hasCategoryGroup
            ? "TRIM(COALESCE(NULLIF(c.category_group, ''), c.category_name))"
            : "TRIM(c.category_name)";
        $categorySelect = ", {$categoryExpr} AS genre_name";
        $categoryJoin = ' LEFT JOIN categories c ON b.category_id = c.category_id';
    }

    if ($hasProgramTable) {
        $programSelect = ', p.program_name AS program_name';
        $programJoin = ' LEFT JOIN programs p ON b.program_id = p.program_id';
    }

    $where = "(b.description IS NULL OR TRIM(b.description) = '')";
    if ($onlyBookId !== null) {
        $where .= ' AND b.book_id = ' . (int)$onlyBookId;
    }
    if ($minBookId !== null) {
        $where .= ' AND b.book_id >= ' . (int)$minBookId;
    }

    $sql = "SELECT b.book_id, b.title, b.author, b.year_published{$categorySelect}{$programSelect}
            FROM books b{$categoryJoin}{$programJoin}
            WHERE {$where}
            ORDER BY b.book_id";
    if ($limit !== null) {
        $sql .= ' LIMIT ' . (int)$limit;
    }

    $res = $conn->query($sql);
    if (!$res) {
        return ['error' => $conn->error];
    }

    $counters = [
        'generated' => 0,
        'skipped_empty_output' => 0,
        'skipped_api_error' => 0,
        'errors' => 0,
    ];
    $results = [];
    $bookCount = 0;

    while ($book = $res->fetch_assoc()) {
        $bookCount++;
        $bookId = (int)$book['book_id'];
        $title = (string)$book['title'];

        $userPrompt = adh_build_user_prompt($book);
        $raw = adh_call_openai_chat(adh_system_prompt(), $userPrompt);
        usleep(AI_CHAT_SLEEP_MICROSECONDS);

        if ($raw === null) {
            $counters['skipped_api_error']++;
            $results[] = ['book_id' => $bookId, 'title' => $title, 'status' => 'skipped', 'detail' => 'API call failed (network error, rate limit, or non-200 -- re-run later to retry)'];
            continue;
        }

        $cleaned = adh_clean_ai_description($raw);
        if ($cleaned === '') {
            $counters['skipped_empty_output']++;
            $results[] = ['book_id' => $bookId, 'title' => $title, 'status' => 'skipped', 'detail' => 'model returned empty/unusable text'];
            continue;
        }

        $counters['generated']++;
        $results[] = ['book_id' => $bookId, 'title' => $title, 'status' => 'generated', 'description' => $cleaned];

        if ($commit) {
            $stmt = $conn->prepare('UPDATE books SET description = ?, description_source = ? WHERE book_id = ?');
            if ($stmt) {
                $sourceTag = 'ai_generated';
                $stmt->bind_param('ssi', $cleaned, $sourceTag, $bookId);
                if (!$stmt->execute()) {
                    $counters['errors']++;
                }
                $stmt->close();
            } else {
                $counters['errors']++;
            }
        }
    }

    return [
        'book_count' => $bookCount,
        'counters' => $counters,
        'results' => $results,
    ];
}
?>
