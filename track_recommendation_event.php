<?php
require_once __DIR__ . '/config/session_start.php'; // smartlib_session_start(): Secure cookie on HTTPS
smartlib_session_start();
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

// Anti-forgery: Origin, JSON body only, kiosk token (config/request_guard.php).
// filters.js ignores any failure here (analytics only).
require_once 'config/request_guard.php';
$guardBody = smartlib_request_guard([SMARTLIB_KIOSK_CSRF_KEY], true);

if (!table_exists($conn, 'recommendation_events')) {
    respond(['status' => 'success', 'tracked' => false, 'message' => 'recommendation_events table not ready']);
}

$payload = is_array($guardBody) ? $guardBody : [];

require_once 'config/request_input.php';
$eventType = strtolower(trim(smartlib_request_string($payload, 'event_type')));
$panelKey = trim(smartlib_request_string($payload, 'panel_key'));
$bookId = smartlib_request_int($payload, 'book_id');
require_once 'config/session_guard.php';
require_once 'config/rate_limit.php';
$userId = smartlib_active_session_user_id($conn); // 0 when signed out (also applies the idle rule)

$allowed = ['impression', 'open', 'checkout'];
if (!in_array($eventType, $allowed, true)) {
    respond(['status' => 'error', 'message' => 'Invalid event_type'], 422);
}

if ($panelKey === '') {
    $panelKey = 'unknown';
}
$panelKey = substr($panelKey, 0, 40);

// Abuse ceilings (security review 2026-11-24, SR-12): 60 events a minute per
// kiosk session -- far above what browsing produces -- and 600 a minute per
// IP as a flood ceiling only (the school's kiosks share one public IP).
// Fails open: see config/rate_limit.php.
if (!smartlib_rate_limit_allow($conn, 'events_session', session_id(), 60, 60)
    || !smartlib_rate_limit_allow($conn, 'events_ip', smartlib_rate_limit_ip(), 600, 60)) {
    smartlib_rate_limit_refuse('Too many events from this session. Please slow down.', ['tracked' => false]);
}

// A "checkout" event only counts when it matches a real loan: a signed-in
// patron who borrowed this book in the last 10 minutes. Anything else is
// quietly not recorded, so the recommendation statistics cannot be inflated.
if ($eventType === 'checkout') {
    $matchesLoan = false;
    if ($userId > 0 && $bookId > 0) {
        try {
            $loanCheck = $conn->prepare(
                "SELECT 1
                 FROM borrow_records br
                 JOIN book_copies bc ON bc.copy_id = br.copy_id
                 WHERE br.user_id = ? AND bc.book_id = ?
                   AND br.created_at >= (NOW() - INTERVAL 10 MINUTE)
                 LIMIT 1"
            );
            if ($loanCheck) {
                $loanCheck->bind_param('ii', $userId, $bookId);
                $loanCheck->execute();
                $loanCheck->store_result();
                $matchesLoan = $loanCheck->num_rows > 0;
                $loanCheck->close();
            }
        } catch (Throwable $e) {
            error_log('SmartLib track event: checkout match failed: ' . $e->getMessage());
        }
    }
    if (!$matchesLoan) {
        respond(['status' => 'success', 'tracked' => false, 'event_type' => $eventType, 'panel_key' => $panelKey, 'book_id' => $bookId > 0 ? $bookId : null]);
    }
}

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
