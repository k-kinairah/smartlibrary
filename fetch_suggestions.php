<?php
require_once __DIR__ . '/config/request_input.php'; // smartlib_request_string(): arrays in scalar params count as empty
require_once __DIR__ . '/config/db_query.php';
require_once __DIR__ . '/config/session_start.php'; // smartlib_session_start(): Secure cookie on HTTPS
smartlib_session_start();
require 'config/db_connect.php';

header('Content-Type: application/json; charset=utf-8');

function normalize_term(string $raw): string {
    $term = trim($raw);
    if ($term === '') {
        return '';
    }

    $term = preg_replace('/\s+/', ' ', $term);
    return trim((string)$term);
}

$term = normalize_term(smartlib_request_string($_GET, 'q'));
$limit = smartlib_request_int($_GET, 'limit', 12);
if ($limit < 1 || $limit > 12) {
    $limit = 12;
}

if ($term === '') {
    echo json_encode(['suggestions' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

// 2026-10-10: hardcoded -- see index.php for why. categories.category_group
// is confirmed present in smartlib_FULL_BACKUP_20261010.sql.
$hasCategoryGroup = true;

$genreExpr = $hasCategoryGroup
    ? "TRIM(COALESCE(NULLIF(c.category_group, ''), c.category_name))"
    : "TRIM(c.category_name)";

// Prepared (SR-16); a typed % or _ is literal (SR-21).
$likeTerm = smartlib_like_escape($term);
$likeContains = '%' . $likeTerm . '%';
$likePrefix = $likeTerm . '%';

$sql = "
    SELECT
        suggestion,
        MIN(kind_priority) AS kind_priority,
        MIN(prefix_rank) AS prefix_rank,
        COUNT(*) AS hits
    FROM (
        SELECT
            TRIM(b.title) AS suggestion,
            1 AS kind_priority,
            CASE WHEN TRIM(b.title) LIKE ? ESCAPE '\\\\' THEN 0 ELSE 1 END AS prefix_rank
        FROM books b
        WHERE TRIM(COALESCE(b.title, '')) <> ''
          AND TRIM(b.title) LIKE ? ESCAPE '\\\\'

        UNION ALL

        SELECT
            TRIM(b.author) AS suggestion,
            2 AS kind_priority,
            CASE WHEN TRIM(b.author) LIKE ? ESCAPE '\\\\' THEN 0 ELSE 1 END AS prefix_rank
        FROM books b
        WHERE TRIM(COALESCE(b.author, '')) <> ''
          AND TRIM(b.author) LIKE ? ESCAPE '\\\\'

        UNION ALL

        SELECT
            TRIM(b.isbn) AS suggestion,
            3 AS kind_priority,
            CASE WHEN TRIM(b.isbn) LIKE ? ESCAPE '\\\\' THEN 0 ELSE 1 END AS prefix_rank
        FROM books b
        WHERE TRIM(COALESCE(b.isbn, '')) <> ''
          AND TRIM(b.isbn) LIKE ? ESCAPE '\\\\'

        UNION ALL

        SELECT
            {$genreExpr} AS suggestion,
            4 AS kind_priority,
            CASE WHEN {$genreExpr} LIKE ? ESCAPE '\\\\' THEN 0 ELSE 1 END AS prefix_rank
        FROM books b
        LEFT JOIN categories c ON b.category_id = c.category_id
        WHERE TRIM(COALESCE({$genreExpr}, '')) <> ''
          AND {$genreExpr} LIKE ? ESCAPE '\\\\'

        UNION ALL

        SELECT
            TRIM(p.program_name) AS suggestion,
            5 AS kind_priority,
            CASE WHEN TRIM(p.program_name) LIKE ? ESCAPE '\\\\' THEN 0 ELSE 1 END AS prefix_rank
        FROM books b
        LEFT JOIN programs p ON b.program_id = p.program_id
        WHERE TRIM(COALESCE(p.program_name, '')) <> ''
          AND TRIM(p.program_name) LIKE ? ESCAPE '\\\\'
    ) matched
    WHERE suggestion <> ''
    GROUP BY suggestion
    ORDER BY prefix_rank ASC, kind_priority ASC, hits DESC, suggestion ASC
    LIMIT {$limit}
";

$result = smartlib_db_query($conn, $sql, [$likePrefix, $likeContains, $likePrefix, $likeContains, $likePrefix, $likeContains, $likePrefix, $likeContains, $likePrefix, $likeContains]);

$suggestions = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $text = trim((string)($row['suggestion'] ?? ''));
        if ($text === '') {
            continue;
        }

        $suggestions[] = [
            'text' => $text
        ];
    }
}

echo json_encode(['suggestions' => $suggestions], JSON_UNESCAPED_UNICODE);


