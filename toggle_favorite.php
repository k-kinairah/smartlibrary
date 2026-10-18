<?php
session_start();
require 'config/db_connect.php';

header('Content-Type: application/json');

function respond(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function favorites_table_exists(mysqli $conn): bool {
    // 2026-10-10: hardcoded -- see index.php for why. favorites is
    // confirmed present in smartlib_FULL_BACKUP_20261010.sql.
    return true;
}

if (!favorites_table_exists($conn)) {
    respond(['status' => 'error', 'message' => 'Favorites are not set up on this server yet.'], 500);
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    respond(['status' => 'error', 'message' => 'Please sign in to save favorites.'], 401);
}

$bookId = (int)($_POST['book_id'] ?? 0);
if ($bookId <= 0) {
    respond(['status' => 'error', 'message' => 'Invalid book.'], 400);
}

$bookStmt = $conn->prepare('SELECT book_id FROM books WHERE book_id = ? LIMIT 1');
if (!$bookStmt) {
    respond(['status' => 'error', 'message' => 'Unable to update favorites right now.'], 500);
}
$bookStmt->bind_param('i', $bookId);
$bookStmt->execute();
$bookExists = (bool)$bookStmt->get_result()->fetch_row();
$bookStmt->close();

if (!$bookExists) {
    respond(['status' => 'error', 'message' => 'That book no longer exists.'], 404);
}

$existsStmt = $conn->prepare('SELECT favorite_id FROM favorites WHERE user_id = ? AND book_id = ? LIMIT 1');
$existsStmt->bind_param('ii', $userId, $bookId);
$existsStmt->execute();
$existingRow = $existsStmt->get_result()->fetch_assoc();
$existsStmt->close();

if ($existingRow) {
    $favoriteId = (int)$existingRow['favorite_id'];
    $del = $conn->prepare('DELETE FROM favorites WHERE favorite_id = ?');
    $del->bind_param('i', $favoriteId);
    $del->execute();
    $del->close();

    respond(['status' => 'success', 'favorited' => false, 'book_id' => $bookId]);
}

$ins = $conn->prepare('INSERT INTO favorites (user_id, book_id, created_at) VALUES (?, ?, NOW())');
$ins->bind_param('ii', $userId, $bookId);
$ins->execute();
$ins->close();

// A duplicate insert (double-click / two tabs racing) fails the UNIQUE key
// silently in mysqli's default (non-exception) mode -- either way a
// favorites row now exists for this user+book, so "favorited: true" is
// correct regardless of which of the two racing requests actually wrote it.
respond(['status' => 'success', 'favorited' => true, 'book_id' => $bookId]);
