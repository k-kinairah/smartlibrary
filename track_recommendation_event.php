<?php
session_start();
require 'config/db_connect.php';

header('Content-Type: application/json; charset=utf-8');

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function table_exists(mysqli $conn, string $table): bool {
    // 2026-10-10: hardcoded against a known-good table list instead of
    // querying information_schema -- see index.php for why. Every name
    // below is confirmed present in smartlib_FULL_BACKUP_20261010.sql.
    static $knownTables = [
        'auth_2fa_challenges', 'auth_login_attempts', 'auth_pin_reset_tokens',
        'books', 'book_categories', 'book_copies', 'book_embeddings',
        'book_programs', 'borrow_records', 'categories',
        'due_soon_reminder_logs', 'favorites', 'library_locations',
        'library_users', 'notifications', 'overdue_reminder_logs',
        'programs', 'recommendation_events', 'search_logs',
        'admin_activity_logs',
    ];
    return in_array($table, $knownTables, true);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['status' => 'error', 'message' => 'Method not allowed'], 405);
}

if (!table_exists($conn, 'recommendation_events')) {
    respond(['status' => 'success', 'tracked' => false, 'message' => 'recommendation_events table not ready']);
}

$raw = file_get_contents('php://input');
$payload = json_decode((string)$raw, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$eventType = strtolower(trim((string)($payload['event_type'] ?? '')));
$panelKey = trim((string)($payload['panel_key'] ?? ''));
$bookId = isset($payload['book_id']) ? (int)$payload['book_id'] : 0;
$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

$allowed = ['impression', 'open', 'checkout'];
if (!in_array($eventType, $allowed, true)) {
    respond(['status' => 'error', 'message' => 'Invalid event_type'], 422);
}

if ($panelKey === '') {
    $panelKey = 'unknown';
}
$panelKey = substr($panelKey, 0, 40);

$stmt = null;

if ($userId > 0 && $bookId > 0) {
    $stmt = $conn->prepare(
        "INSERT INTO recommendation_events (user_id, book_id, panel_key, event_type, created_at)
         VALUES (?, ?, ?, ?, NOW())"
    );
    if ($stmt) {
        $stmt->bind_param('iiss', $userId, $bookId, $panelKey, $eventType);
    }
} elseif ($userId > 0) {
    $stmt = $conn->prepare(
        "INSERT INTO recommendation_events (user_id, book_id, panel_key, event_type, created_at)
         VALUES (?, NULL, ?, ?, NOW())"
    );
    if ($stmt) {
        $stmt->bind_param('iss', $userId, $panelKey, $eventType);
    }
} elseif ($bookId > 0) {
    $stmt = $conn->prepare(
        "INSERT INTO recommendation_events (user_id, book_id, panel_key, event_type, created_at)
         VALUES (NULL, ?, ?, ?, NOW())"
    );
    if ($stmt) {
        $stmt->bind_param('iss', $bookId, $panelKey, $eventType);
    }
} else {
    $stmt = $conn->prepare(
        "INSERT INTO recommendation_events (user_id, book_id, panel_key, event_type, created_at)
         VALUES (NULL, NULL, ?, ?, NOW())"
    );
    if ($stmt) {
        $stmt->bind_param('ss', $panelKey, $eventType);
    }
}

if (!$stmt) {
    respond(['status' => 'error', 'message' => 'Failed to prepare tracking query'], 500);
}

$ok = $stmt->execute();
$stmt->close();

if (!$ok) {
    respond(['status' => 'error', 'message' => 'Failed to track event'], 500);
}

respond([
    'status' => 'success',
    'tracked' => true,
    'event_type' => $eventType,
    'panel_key' => $panelKey,
    'book_id' => $bookId > 0 ? $bookId : null
]);
?>
