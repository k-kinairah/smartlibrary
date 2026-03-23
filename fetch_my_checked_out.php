<?php
session_start();
require 'config/db_connect.php';

header('Content-Type: application/json');

function respond(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    respond(['status' => 'error', 'message' => 'Please sign in first.'], 401);
}

$sql = "
    SELECT
        br.record_id,
        br.date_borrowed,
        br.due_date,
        br.status,
        bc.accession_no,
        b.title,
        b.author,
        b.cover
    FROM borrow_records br
    LEFT JOIN book_copies bc ON bc.copy_id = br.copy_id
    LEFT JOIN books b ON b.book_id = bc.book_id
    WHERE br.user_id = ?
      AND (
            br.date_returned IS NULL
            OR br.status IN ('borrowed', 'overdue', 'missing')
          )
    ORDER BY br.created_at DESC, br.record_id DESC
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    respond(['status' => 'error', 'message' => 'Unable to load records.'], 500);
}

$stmt->bind_param('i', $userId);
$stmt->execute();
$res = $stmt->get_result();

$books = [];
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $coverFile = trim((string)($row['cover'] ?? ''));

        $books[] = [
            'record_id' => (int)($row['record_id'] ?? 0),
            'title' => (string)($row['title'] ?? 'Untitled'),
            'author' => (string)($row['author'] ?? 'Unknown Author'),
            'accession_no' => (string)($row['accession_no'] ?? 'N/A'),
            'date_borrowed' => (string)($row['date_borrowed'] ?? ''),
            'due_date' => (string)($row['due_date'] ?? ''),
            'status' => (string)($row['status'] ?? 'borrowed'),
            'cover' => $coverFile !== '' ? ('assets/covers/' . $coverFile) : 'assets/covers/default.jpg'
        ];
    }
}

$stmt->close();

respond([
    'status' => 'success',
    'books' => $books
]);
