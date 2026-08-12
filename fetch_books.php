<?php
session_start();
require 'config/db_connect.php';

function esc(mysqli $conn, string $str): string {
    return mysqli_real_escape_string($conn, $str);
}

function table_exists(mysqli $conn, string $table): bool {
    $safe = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $res && $res->num_rows > 0;
}

function column_exists(mysqli $conn, string $table, string $column): bool {
    $safeTable = $conn->real_escape_string($table);
    $safeColumn = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    return $res && $res->num_rows > 0;
}

function normalize_search_term(string $raw): string {
    $term = trim($raw);
    if ($term === '') {
        return '';
    }

    $term = preg_replace('/\s+/', ' ', $term);
    return trim((string)$term);
}

function log_search_query(mysqli $conn, string $search, int $resultCount): void {
    $term = normalize_search_term($search);
    if (
        $term === '' ||
        strlen($term) < 4 ||
        !preg_match('/[a-z0-9]/i', $term) ||
        !table_exists($conn, 'search_logs')
    ) {
        return;
    }

    $now = time();
    $lastTerm = isset($_SESSION['last_logged_search_term']) ? (string)$_SESSION['last_logged_search_term'] : '';
    $lastAt = isset($_SESSION['last_logged_search_at']) ? (int)$_SESSION['last_logged_search_at'] : 0;
    if ($lastTerm !== '' && strcasecmp($lastTerm, $term) === 0 && ($now - $lastAt) < 30) {
        return;
    }
    $_SESSION['last_logged_search_term'] = $term;
    $_SESSION['last_logged_search_at'] = $now;

    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

    if ($userId > 0) {
        $stmt = $conn->prepare(
            "INSERT INTO search_logs (user_id, search_term, result_count, created_at)
             VALUES (?, ?, ?, NOW())"
        );
        if (!$stmt) return;

        $stmt->bind_param('isi', $userId, $term, $resultCount);
        $stmt->execute();
        $stmt->close();
        return;
    }

    $stmt = $conn->prepare(
        "INSERT INTO search_logs (user_id, search_term, result_count, created_at)
         VALUES (NULL, ?, ?, NOW())"
    );
    if (!$stmt) return;

    $stmt->bind_param('si', $term, $resultCount);
    $stmt->execute();
    $stmt->close();
}

$search = trim($_GET['search'] ?? '');
$genres = $_GET['genre'] ?? [];
$yearFromRaw = trim((string)($_GET['year_from'] ?? ''));
$yearToRaw = trim((string)($_GET['year_to'] ?? ''));

$hasCategoryGroup = false;
$groupColRes = $conn->query("SHOW COLUMNS FROM categories LIKE 'category_group'");
if ($groupColRes && $groupColRes->num_rows > 0) {
    $hasCategoryGroup = true;
}

$genreExpr = $hasCategoryGroup
    ? "TRIM(COALESCE(NULLIF(c.category_group, ''), c.category_name))"
    : "TRIM(c.category_name)";

$hasLocationTable = table_exists($conn, 'library_locations');
$hasBookLocationId = column_exists($conn, 'books', 'location_id');
$hasBookCallNumber = column_exists($conn, 'books', 'call_number');
$hasLocationClassCode = $hasLocationTable && column_exists($conn, 'library_locations', 'class_code');

$locationJoin = '';
if ($hasLocationTable && $hasBookLocationId) {
    $locationJoin = ' LEFT JOIN library_locations loc ON b.location_id = loc.location_id';
}

$callNumberExpr = $hasBookCallNumber
    ? "NULLIF(TRIM(b.call_number), '')"
    : "NULL";

$locationClassExpr = ($hasLocationTable && $hasBookLocationId && $hasLocationClassCode)
    ? "NULLIF(TRIM(loc.class_code), '')"
    : "NULL";

$lcClassExpr = "UPPER(COALESCE({$locationClassExpr}, {$callNumberExpr}, ''))";
$lcMainLetterExpr = "LEFT({$lcClassExpr}, 1)";

$lcOrderExpr = "CASE {$lcMainLetterExpr}
    WHEN 'A' THEN 1
    WHEN 'B' THEN 2
    WHEN 'C' THEN 3
    WHEN 'D' THEN 4
    WHEN 'E' THEN 5
    WHEN 'F' THEN 6
    WHEN 'G' THEN 7
    WHEN 'H' THEN 8
    WHEN 'J' THEN 9
    WHEN 'K' THEN 10
    WHEN 'L' THEN 11
    WHEN 'M' THEN 12
    WHEN 'N' THEN 13
    WHEN 'P' THEN 14
    WHEN 'Q' THEN 15
    WHEN 'R' THEN 16
    WHEN 'S' THEN 17
    WHEN 'T' THEN 18
    WHEN 'U' THEN 19
    WHEN 'V' THEN 20
    WHEN 'Z' THEN 21
    ELSE 99
END";

$callNumberSortExpr = $hasBookCallNumber
    ? "COALESCE(NULLIF(TRIM(b.call_number), ''), 'ZZZ 9999')"
    : "'ZZZ 9999'";

$sql = "
    SELECT
        b.book_id,
        b.title,
        b.author,
        b.isbn,
        b.cover,
        b.year_published,
        {$genreExpr} AS genre_name,
        p.program_name AS program_name
    FROM books b
    LEFT JOIN categories c ON b.category_id = c.category_id
    LEFT JOIN programs p ON b.program_id = p.program_id
    {$locationJoin}
";

$where = [];

if ($search !== '') {
    $s = esc($conn, $search);
    $where[] = "(
        b.title LIKE '%$s%'
        OR b.author LIKE '%$s%'
        OR b.isbn LIKE '%$s%'
        OR {$genreExpr} LIKE '%$s%'
        OR p.program_name LIKE '%$s%'
    )";
}

if (is_array($genres) && count($genres) > 0) {
    $list = array_map(fn($g) => "'" . esc($conn, (string)$g) . "'", $genres);
    $where[] = "{$genreExpr} IN (" . implode(',', $list) . ")";
}

$yearFrom = ctype_digit($yearFromRaw) ? (int)$yearFromRaw : null;
$yearTo = ctype_digit($yearToRaw) ? (int)$yearToRaw : null;

if ($yearFrom !== null && $yearTo !== null && $yearFrom > $yearTo) {
    [$yearFrom, $yearTo] = [$yearTo, $yearFrom];
}

if ($yearFrom !== null) {
    $where[] = 'CAST(b.year_published AS UNSIGNED) >= ' . $yearFrom;
}

if ($yearTo !== null) {
    $where[] = 'CAST(b.year_published AS UNSIGNED) <= ' . $yearTo;
}

if (!empty($where)) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= " ORDER BY
    {$lcOrderExpr} ASC,
    {$lcClassExpr} ASC,
    {$callNumberSortExpr} ASC,
    CAST(COALESCE(NULLIF(b.year_published, ''), '0') AS UNSIGNED) ASC,
    b.title ASC";

$res = mysqli_query($conn, $sql);
$resultCount = $res ? mysqli_num_rows($res) : 0;
log_search_query($conn, $search, $resultCount);

if ($res && $resultCount > 0) {
    while ($row = mysqli_fetch_assoc($res)) {
        $cover = !empty($row['cover']) ? 'assets/covers/' . $row['cover'] : 'assets/covers/default.jpg';
        $bookId = (int)($row['book_id'] ?? 0);
        $title = htmlspecialchars((string)($row['title'] ?? 'Untitled'));
        $author = htmlspecialchars((string)($row['author'] ?? 'Unknown Author'));

        echo "
        <div class='book-card' data-id='{$bookId}'>
            <img class='book-cover' src='{$cover}' alt='Book cover'>
            <div class='book-meta'>
                <div class='book-title'>{$title}</div>
                <div class='book-subtitle'>{$author}</div>
            </div>
        </div>";
    }
} else {
    echo "<p style='padding:10px;'>No books found.</p>";
}
?>
