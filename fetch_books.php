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

function log_search_query(mysqli $conn, string $search, int $resultCount): void {
    $term = trim($search);
    if ($term === '' || !table_exists($conn, 'search_logs')) {
        return;
    }

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
$years = $_GET['year_published'] ?? [];

$hasCategoryGroup = false;
$groupColRes = $conn->query("SHOW COLUMNS FROM categories LIKE 'category_group'");
if ($groupColRes && $groupColRes->num_rows > 0) {
    $hasCategoryGroup = true;
}

$genreExpr = $hasCategoryGroup
    ? "TRIM(COALESCE(NULLIF(c.category_group, ''), c.category_name))"
    : "TRIM(c.category_name)";

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

if (is_array($years) && count($years) > 0) {
    $list = array_map(fn($y) => "'" . esc($conn, (string)$y) . "'", $years);
    $where[] = "b.year_published IN (" . implode(',', $list) . ")";
}

if (!empty($where)) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY b.created_at DESC';

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
