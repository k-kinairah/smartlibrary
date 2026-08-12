<?php
session_start();
require 'config/db_connect.php';

header('Content-Type: application/json; charset=utf-8');

function esc(mysqli $conn, string $str): string {
    return mysqli_real_escape_string($conn, $str);
}

function normalize_term(string $raw): string {
    $term = trim($raw);
    if ($term === '') {
        return '';
    }

    $term = preg_replace('/\s+/', ' ', $term);
    return trim((string)$term);
}

$term = normalize_term((string)($_GET['q'] ?? ''));
$limit = (int)($_GET['limit'] ?? 12);
if ($limit < 1 || $limit > 12) {
    $limit = 12;
}

if ($term === '') {
    echo json_encode(['suggestions' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$hasCategoryGroup = false;
$groupColRes = $conn->query("SHOW COLUMNS FROM categories LIKE 'category_group'");
if ($groupColRes && $groupColRes->num_rows > 0) {
    $hasCategoryGroup = true;
}

$genreExpr = $hasCategoryGroup
    ? "TRIM(COALESCE(NULLIF(c.category_group, ''), c.category_name))"
    : "TRIM(c.category_name)";

$safeTerm = esc($conn, $term);
$likeContains = '%' . $safeTerm . '%';
$likePrefix = $safeTerm . '%';

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
            CASE WHEN TRIM(b.title) LIKE '{$likePrefix}' THEN 0 ELSE 1 END AS prefix_rank
        FROM books b
        WHERE TRIM(COALESCE(b.title, '')) <> ''
          AND TRIM(b.title) LIKE '{$likeContains}'

        UNION ALL

        SELECT
            TRIM(b.author) AS suggestion,
            2 AS kind_priority,
            CASE WHEN TRIM(b.author) LIKE '{$likePrefix}' THEN 0 ELSE 1 END AS prefix_rank
        FROM books b
        WHERE TRIM(COALESCE(b.author, '')) <> ''
          AND TRIM(b.author) LIKE '{$likeContains}'

        UNION ALL

        SELECT
            TRIM(b.isbn) AS suggestion,
            3 AS kind_priority,
            CASE WHEN TRIM(b.isbn) LIKE '{$likePrefix}' THEN 0 ELSE 1 END AS prefix_rank
        FROM books b
        WHERE TRIM(COALESCE(b.isbn, '')) <> ''
          AND TRIM(b.isbn) LIKE '{$likeContains}'

        UNION ALL

        SELECT
            {$genreExpr} AS suggestion,
            4 AS kind_priority,
            CASE WHEN {$genreExpr} LIKE '{$likePrefix}' THEN 0 ELSE 1 END AS prefix_rank
        FROM books b
        LEFT JOIN categories c ON b.category_id = c.category_id
        WHERE TRIM(COALESCE({$genreExpr}, '')) <> ''
          AND {$genreExpr} LIKE '{$likeContains}'

        UNION ALL

        SELECT
            TRIM(p.program_name) AS suggestion,
            5 AS kind_priority,
            CASE WHEN TRIM(p.program_name) LIKE '{$likePrefix}' THEN 0 ELSE 1 END AS prefix_rank
        FROM books b
        LEFT JOIN programs p ON b.program_id = p.program_id
        WHERE TRIM(COALESCE(p.program_name, '')) <> ''
          AND TRIM(p.program_name) LIKE '{$likeContains}'
    ) matched
    WHERE suggestion <> ''
    GROUP BY suggestion
    ORDER BY prefix_rank ASC, kind_priority ASC, hits DESC, suggestion ASC
    LIMIT {$limit}
";

$result = $conn->query($sql);

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


