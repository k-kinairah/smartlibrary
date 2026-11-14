<?php
session_start();
require 'config/db_connect.php';
require_once 'config/loan_policy.php';

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

require_once 'config/session_guard.php';
$userId = smartlib_active_session_user_id($conn); // 0 if signed out, inactive or deleted
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

// What the client wants. The kiosk sends "add" or "remove" (the heart's
// new state), so a stale heart or a second tab can never flip a favorite
// the wrong way; a request without an action keeps the old toggle.
$action = strtolower(trim((string)($_POST['action'] ?? 'toggle')));
if (!in_array($action, ['add', 'remove', 'toggle'], true)) {
    $action = 'toggle';
}

$favoritesLimit = (int)FAVORITES_LIMIT;

$countFavorites = function () use ($conn, $userId): int {
    $stmt = $conn->prepare('SELECT COUNT(*) FROM favorites WHERE user_id = ?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $count = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
    $stmt->close();
    return $count;
};

$findFavoriteId = function () use ($conn, $userId, $bookId): int {
    $stmt = $conn->prepare('SELECT favorite_id FROM favorites WHERE user_id = ? AND book_id = ? LIMIT 1');
    $stmt->bind_param('ii', $userId, $bookId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (int)$row['favorite_id'] : 0;
};

$success = function (bool $favorited) use ($bookId, $favoritesLimit, $countFavorites): void {
    respond([
        'status' => 'success',
        'favorited' => $favorited,
        'book_id' => $bookId,
        'favorites_count' => $countFavorites(),
        'favorites_limit' => $favoritesLimit
    ]);
};

$favoriteId = $findFavoriteId();

// Removing always works, even for an account that is over the limit.
if ($favoriteId > 0 && $action !== 'add') {
    $del = $conn->prepare('DELETE FROM favorites WHERE favorite_id = ?');
    $del->bind_param('i', $favoriteId);
    $del->execute();
    $del->close();
    $success(false);
}

if ($action === 'remove') {
    $success(false); // nothing saved for this book; already removed
}

// Saving a book that is already saved is not refused and adds no row.
if ($favoriteId > 0) {
    $success(true);
}

// Saving a new book: lock the borrower's own library_users row, then
// count and insert inside the same transaction. Parallel requests from
// the same account wait on that lock, so they cannot all see "9 saved"
// and push the account past the limit. Other accounts are not blocked.
$limitReached = false;
try {
    $conn->begin_transaction();

    $lock = $conn->prepare('SELECT user_id FROM library_users WHERE user_id = ? FOR UPDATE');
    $lock->bind_param('i', $userId);
    if (!$lock->execute()) {
        throw new RuntimeException('lock failed');
    }
    $lock->get_result()->fetch_row();
    $lock->close();

    // A racing request may have saved this same book while we waited.
    if ($findFavoriteId() === 0) {
        if ($countFavorites() >= $favoritesLimit) {
            $limitReached = true;
        } else {
            $ins = $conn->prepare('INSERT INTO favorites (user_id, book_id, created_at) VALUES (?, ?, NOW())');
            $ins->bind_param('ii', $userId, $bookId);
            if (!$ins->execute()) {
                throw new RuntimeException('insert failed');
            }
            $ins->close();
        }
    }

    if ($limitReached) {
        $conn->rollback();
    } else {
        $conn->commit();
    }
} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $ignored) {
        // connection already gone; nothing was committed
    }
    respond(['status' => 'error', 'message' => 'Unable to update favorites right now.'], 500);
}

if ($limitReached) {
    respond([
        'status' => 'error',
        'message' => "You've saved the maximum of {$favoritesLimit} books. Remove one to save another.",
        'limit_reached' => true,
        'favorites_limit' => $favoritesLimit,
        'book_id' => $bookId
    ], 409);
}

$success(true);
