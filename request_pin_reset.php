<?php
require_once __DIR__ . '/config/request_input.php'; // smartlib_request_string(): arrays in scalar params count as empty
session_start();
require 'config/db_connect.php';
require 'config/auth_security.php';
require_once 'config/library_card_helpers.php';
require_once 'config/auth_email_template.php';

header('Content-Type: application/json; charset=utf-8');

function respond_json(array $payload, int $statusCode = 200): void {
    pin_reset_send_reply($payload, $statusCode);
    exit;
}

/**
 * Sends the whole reply and closes the connection, but lets the script go
 * on (security review 2026-11-24, SR-13): the reset email is sent AFTER the
 * visitor already has the answer, so a registered ID does not answer
 * slower than an unregistered one. Every reply of this endpoint goes out
 * this same way, so the headers are identical too.
 */
function pin_reset_send_reply(array $payload, int $statusCode = 200): void {
    $body = (string)json_encode($payload, JSON_UNESCAPED_UNICODE);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    ignore_user_abort(true);
    http_response_code($statusCode);
    header('Content-Length: ' . strlen($body));
    header('Connection: close');
    echo $body;
    if (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
        return;
    }
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        return;
    }
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    flush();
}

// One server-log line per outcome, so "I never got the email" can be
// answered from the PHP error log. Only the (already letters/digits-only)
// ID, the token row id and a masked address: never a full email, token or
// link.
function pin_reset_log(string $detail): void {
    error_log('SmartLib pin reset: ' . $detail);
}

function pin_reset_log_skip(string $reason, string $userNumber): void {
    pin_reset_log('skipped reason=' . $reason . ' user_number=' . substr($userNumber, 0, 40));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_json(['status' => 'error', 'message' => 'Invalid request method.'], 405);
}

// Anti-forgery: Origin + the kiosk sign-in token (the same one
// login_handler.php checks), config/request_guard.php.
require_once 'config/request_guard.php';
smartlib_request_guard(['signin_csrf']);

// Same ID normalization as login_handler.php (dashes as printed on the card).
$identifier = library_card_normalize_id(smartlib_request_string($_POST, 'identifier'));
// The account's real role decides; a posted account_type is ignored.
$clientIp = auth_client_ip();
// The same answer whether or not anything was sent (no account
// enumeration).
$genericMessage = 'If your ID is registered, a reset link was sent to your email.';
$genericSuccess = [
    'status' => 'success',
    'message' => $genericMessage
];


if ($identifier === '') {
    pin_reset_log_skip('unknown_id', '');
    respond_json($genericSuccess);
}

if (!auth_ensure_reset_table($conn) || !auth_ensure_2fa_table($conn)) {
    respond_json(['status' => 'error', 'message' => 'PIN reset service is unavailable right now.'], 500);
}

// Security review 2026-11-24, SR-13: the 60-second cooldown and the hourly
// cap are counted on the SUBMITTED ID, whether or not it exists, so a
// registered and an unregistered ID get exactly the same replies in the
// same pattern. (The older checks below count reset tokens, which exist only
// for real accounts; they stay as a backstop and for when the counter table
// is unavailable -- the limiter fails open, see config/rate_limit.php.)
require_once 'config/rate_limit.php';
$idKey = strtolower($identifier);
$idMinute = smartlib_rate_limit_count($conn, 'pin_reset_id_min', $idKey, AUTH_RESET_MIN_SECONDS_BETWEEN_REQUESTS);
$idHour = smartlib_rate_limit_count($conn, 'pin_reset_id_hour', $idKey, 3600);
if (($idMinute !== null && $idMinute >= 1) || ($idHour !== null && $idHour >= AUTH_RESET_MAX_REQUESTS_PER_HOUR)) {
    pin_reset_log_skip('throttled_id', $identifier);
    respond_json([
        'status' => 'success',
        'message' => $genericMessage . ' Please wait before requesting another reset.'
    ]);
}
smartlib_rate_limit_record($conn, 'pin_reset_id_min', $idKey);
smartlib_rate_limit_record($conn, 'pin_reset_id_hour', $idKey);

// Token-based cooldown and hourly cap per ID (backstop); the IP only has a
// high abuse ceiling (see auth_reset_rate_limit_status()).
$rate = auth_reset_rate_limit_status($conn, $identifier, $clientIp);
$throttledReason = '';
if (!empty($rate['too_many']) || !empty($rate['cooldown'])) {
    $throttledReason = 'throttled_id';
} elseif (!empty($rate['ip_too_many'])) {
    $throttledReason = 'throttled_ip';
}
if ($throttledReason !== '') {
    pin_reset_log_skip($throttledReason, $identifier);
    respond_json([
        'status' => 'success',
        'message' => $genericMessage . ' Please wait before requesting another reset.'
    ]);
}

$stmt = $conn->prepare(
    "SELECT user_id, user_number, first_name, last_name, role, status, email,
            registration_source, password
     FROM library_users
     WHERE user_number = ?
     LIMIT 1"
);

if (!$stmt) {
    respond_json(['status' => 'error', 'message' => 'Unable to process reset request right now.'], 500);
}

$stmt->bind_param('s', $identifier);
$stmt->execute();
$res = $stmt->get_result();
$user = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$user) {
    pin_reset_log_skip('unknown_id', $identifier);
    respond_json($genericSuccess);
}

$rawRole = strtolower(trim((string)($user['role'] ?? 'student')));
$status = strtolower(trim((string)($user['status'] ?? 'active')));

// Same roles as before (student, faculty, librarian/admin all get a reset
// link); anything else gets the same generic response.
$normalizedRole = in_array($rawRole, ['admin', 'librarian'], true) ? 'librarian' : $rawRole;
$roleAllowed = in_array($normalizedRole, ['student', 'faculty', 'librarian'], true);

if (!$roleAllowed) {
    pin_reset_log_skip('role_not_allowed', $identifier);
    respond_json($genericSuccess);
}

if ($status !== '' && $status !== 'active') {
    pin_reset_log_skip('inactive', $identifier);
    respond_json($genericSuccess);
}

// Legacy guard: self-signups created before 2026-11-04 have no PIN and no
// kiosk access (a librarian sets that up separately). Without this, "reset"
// would let them set a first PIN themselves. Self-signups since then always
// choose a PIN at signup, so they pass through here and reset normally, as
// does any legacy account once a librarian has given it a PIN.
$isSelfSignup = (string)($user['registration_source'] ?? '') === 'self_signup';
if ($isSelfSignup && trim((string)($user['password'] ?? '')) === '') {
    pin_reset_log_skip('self_signup_no_pin', $identifier);
    respond_json($genericSuccess);
}

$email = trim((string)($user['email'] ?? ''));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    pin_reset_log_skip('no_valid_email', $identifier);
    respond_json($genericSuccess);
}

$tokenInfo = auth_create_reset_token(
    $conn,
    (int)($user['user_id'] ?? 0),
    (string)($user['user_number'] ?? ''),
    $normalizedRole,
    $email,
    $clientIp
);

if (!$tokenInfo) {
    pin_reset_log('token_failed user_number=' . substr($identifier, 0, 40));
    respond_json(['status' => 'error', 'message' => 'Unable to create reset token. Please try again.'], 500);
}

$rawToken = (string)($tokenInfo['token'] ?? '');
$resetUrl = auth_build_absolute_url('/reset_pin.php?token=' . urlencode($rawToken));
if ($resetUrl === '') {
    // No trusted address for the link (see auth_build_absolute_url(), which
    // logged why): retire the token, send nothing, and answer exactly as for
    // any other request so the visitor learns nothing.
    auth_mark_reset_token_used($conn, (int)($tokenInfo['token_id'] ?? 0));
    pin_reset_log('link_refused token_id=' . (int)($tokenInfo['token_id'] ?? 0) . ' user_number=' . substr($identifier, 0, 40));
    respond_json($genericSuccess);
}
$expiryMinutes = AUTH_RESET_TOKEN_TTL_MINUTES;
// Librarian/admin accounts reset a password (they sign in with a password
// plus 2FA); students and faculty reset a PIN.
$isStaffAccount = $normalizedRole === 'librarian';
$secretWord = $isStaffAccount ? 'password' : 'PIN';
$firstName = trim((string)($user['first_name'] ?? ''));
$greeting = $firstName !== '' ? 'Hi ' . $firstName : 'Hi there';

$subject = 'SMARTLIB PIN RESET LINK';
$emailOptions = [
    'variant' => 'link',
    'preheader' => "Your SmartLib reset link. It works once and expires in {$expiryMinutes} minutes.",
    'eyebrow' => $isStaffAccount ? 'PASSWORD RESET' : 'PIN RESET',
    'title' => 'Reset your ' . $secretWord,
    'intro' => "{$greeting}, we received a request to reset the {$secretWord} for your SmartLib account. Tap the button below to choose a new one.",
    'button_label' => 'Reset ' . $secretWord,
    'button_url' => $resetUrl,
    'note' => "This link works once and expires in {$expiryMinutes} minutes.",
    'details' => [
        'ID NUMBER' => (string)($user['user_number'] ?? ''),
        'LINK VALID FOR' => "{$expiryMinutes} minutes",
    ],
    'fallback_label' => 'Button not working? Copy this link into your browser:',
    'footer' => "Didn’t ask for this? You can safely ignore this email — your {$secretWord} stays the same.",
];
$html = smartlib_auth_email_html($emailOptions);
$text = smartlib_auth_email_text($emailOptions);

$tokenId = (int)($tokenInfo['token_id'] ?? 0);
// Answer first, then send (see pin_reset_send_reply()). A failed send is
// only logged: telling the visitor "could not be sent" would reveal that
// the ID is registered.
pin_reset_send_reply($genericSuccess);
$sent = auth_send_multipart_email($email, $subject, $html, $text);
if (!$sent) {
    pin_reset_log('send_failed token_id=' . $tokenId . ' user_number=' . substr($identifier, 0, 40));
    exit;
}

pin_reset_log('sent token_id=' . $tokenId . ' to=' . auth_mask_email($email) . ' user_number=' . substr($identifier, 0, 40));
exit;





