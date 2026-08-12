<?php
session_start();
require 'config/db_connect.php';
require 'config/auth_security.php';

header('Content-Type: application/json; charset=utf-8');

function respond_json(array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_json(['status' => 'error', 'message' => 'Invalid request method.'], 405);
}

$pending = $_SESSION['pending_2fa'] ?? null;
if (!is_array($pending)) {
    respond_json(['status' => 'error', 'message' => 'No pending 2FA session. Please sign in again.'], 401);
}

$challengeId = (int)($pending['challenge_id'] ?? 0);
$userId = (int)($pending['user_id'] ?? 0);
$identifier = trim((string)($pending['identifier'] ?? ''));
$accountType = trim((string)($pending['account_type'] ?? 'librarian'));
$code = preg_replace('/\D+/', '', (string)($_POST['code'] ?? ''));
$clientIp = auth_client_ip();

if ($challengeId <= 0 || $userId <= 0 || $identifier === '') {
    unset($_SESSION['pending_2fa']);
    respond_json(['status' => 'error', 'message' => '2FA session is invalid. Please sign in again.'], 401);
}

if (!auth_ensure_2fa_table($conn) || !auth_ensure_login_attempts_table($conn)) {
    respond_json(['status' => 'error', 'message' => 'Security services are unavailable right now.'], 500);
}

if ($code === '' || strlen($code) !== 6) {
    respond_json(['status' => 'error', 'message' => 'Enter the 6-digit verification code.'], 422);
}

$challenge = auth_get_2fa_challenge($conn, $challengeId, $userId);
if (!$challenge) {
    unset($_SESSION['pending_2fa']);
    respond_json(['status' => 'error', 'message' => '2FA challenge was not found. Please sign in again.'], 401);
}

if (!empty($challenge['used_at'])) {
    unset($_SESSION['pending_2fa']);
    respond_json(['status' => 'error', 'message' => '2FA code already used. Please sign in again.'], 401);
}

$expiresAt = trim((string)($challenge['expires_at'] ?? ''));
$expiresTs = strtotime($expiresAt);
if ($expiresTs === false || $expiresTs < time()) {
    auth_mark_2fa_used($conn, $challengeId);
    unset($_SESSION['pending_2fa']);
    respond_json(['status' => 'expired', 'message' => '2FA code expired. Please sign in again.'], 401);
}

$maxAttempts = (int)($challenge['max_attempts'] ?? AUTH_2FA_MAX_ATTEMPTS);
$attemptCount = (int)($challenge['attempt_count'] ?? 0);
if ($attemptCount >= $maxAttempts) {
    auth_mark_2fa_used($conn, $challengeId);
    auth_record_login_attempt($conn, $identifier, $accountType, $clientIp, false, '2fa_attempts_exceeded');
    unset($_SESSION['pending_2fa']);
    respond_json(['status' => 'error', 'message' => 'Too many incorrect codes. Please sign in again.'], 429);
}

$hash = (string)($challenge['code_hash'] ?? '');
$isValid = $hash !== '' && password_verify($code, $hash);

if (!$isValid) {
    auth_increment_2fa_attempt($conn, $challengeId);
    $remaining = max(0, $maxAttempts - ($attemptCount + 1));

    if ($remaining <= 0) {
        auth_mark_2fa_used($conn, $challengeId);
        auth_record_login_attempt($conn, $identifier, $accountType, $clientIp, false, '2fa_attempts_exceeded');
        unset($_SESSION['pending_2fa']);
        respond_json(['status' => 'error', 'message' => 'Too many incorrect codes. Please sign in again.'], 429);
    }

    respond_json([
        'status' => 'error',
        'message' => 'Incorrect verification code. ' . $remaining . ' attempt(s) left.'
    ], 401);
}

auth_mark_2fa_used($conn, $challengeId);

$userData = $pending['user'] ?? [];
if (!is_array($userData) || (int)($userData['user_id'] ?? 0) <= 0) {
    unset($_SESSION['pending_2fa']);
    respond_json(['status' => 'error', 'message' => 'Unable to complete sign-in. Please sign in again.'], 500);
}

$normalizedRole = trim((string)($userData['role_normalized'] ?? 'librarian'));
if ($normalizedRole === '') {
    $normalizedRole = 'librarian';
}

auth_finalize_session_login(
    [
        'user_id' => (int)($userData['user_id'] ?? 0),
        'user_number' => (string)($userData['user_number'] ?? ''),
        'first_name' => (string)($userData['first_name'] ?? ''),
        'last_name' => (string)($userData['last_name'] ?? '')
    ],
    $normalizedRole
);

if ($identifier !== '') {
    auth_clear_failed_login_attempts($conn, $identifier, $clientIp);
    auth_record_login_attempt($conn, $identifier, $accountType, $clientIp, true, '2fa_login_success');
}

respond_json([
    'status' => 'success',
    'role' => $normalizedRole
]);
