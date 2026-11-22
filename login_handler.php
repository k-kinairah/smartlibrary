<?php
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
ob_start();

$GLOBALS['__smartlib_json_sent'] = false;
register_shutdown_function(static function (): void {
    if (!empty($GLOBALS['__smartlib_json_sent'])) {
        return;
    }

    $err = error_get_last();
    if (!is_array($err)) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
    if (!in_array((int)($err['type'] ?? 0), $fatalTypes, true)) {
        return;
    }

    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Fatal server error during sign-in. Check PHP error logs.'
    ], JSON_UNESCAPED_UNICODE);
});

session_start();
require 'config/db_connect.php';
require 'config/auth_security.php';
require 'config/csrf.php';
require_once 'config/library_card_helpers.php';
require_once 'config/auth_email_template.php';

header('Content-Type: application/json; charset=utf-8');

function respond_json(array $payload, int $statusCode = 200): void {
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    $GLOBALS['__smartlib_json_sent'] = true;
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        $json = '{"status":"error","message":"Unable to encode server response."}';
        $statusCode = 500;
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    http_response_code($statusCode);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Length: ' . strlen($json));
        header('Connection: close');
    }

    echo $json;

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        @flush();
    }

    exit;
}


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_json(['status' => 'error', 'message' => 'Invalid request method.'], 405);
}

if (!smartlib_csrf_verify('signin_csrf', $_POST['csrf_token'] ?? '')) {
    respond_json(['status' => 'error', 'message' => 'Your session expired. Refresh the page and try again.'], 403);
}

// Same normalization as signup, reprint and the door scanner: IDs are stored
// without dashes, but the card prints new-format IDs with them, so a patron
// typing their ID exactly as printed must still match.
$identifier = library_card_normalize_id((string)($_POST['identifier'] ?? ''));
$password = trim((string)($_POST['password'] ?? ''));
$clientIp = auth_client_ip();
// The role comes only from library_users.role for this ID. A posted
// account_type (from the old Account Type dropdown) is ignored.

// Checked against when the ID doesn't exist (or has no PIN yet), so those
// cases take about as long as a wrong PIN and response time doesn't reveal
// which IDs exist. Same bcrypt cost (10) as the stored PINs; it matches
// nothing (a random secret).
const SMARTLIB_DUMMY_PIN_HASH = '$2y$10$w6V8b/CFpVbXan6nzJwkF.ApK.jFFHQlS8LYmjpXr5Wjr7LXALPmq';

if ($identifier === '' || $password === '') {
    respond_json(['status' => 'error', 'message' => 'Please provide your ID and PIN.'], 422);
}

if (!auth_ensure_login_attempts_table($conn) || !auth_ensure_2fa_table($conn)) {
    respond_json(['status' => 'error', 'message' => 'Authentication services are unavailable right now.'], 500);
}

$lockStatus = auth_login_lock_status($conn, $identifier, $clientIp);
if (!empty($lockStatus['locked'])) {
    $retryAfter = (int)($lockStatus['retry_after_seconds'] ?? 0);
    respond_json([
        'status' => 'error',
        'message' => 'Too many failed sign-in attempts. Try again in ' . auth_human_time_left($retryAfter) . '.',
        'retry_after_seconds' => $retryAfter
    ], 429);
}

$stmt = $conn->prepare(
    "SELECT user_id, user_number, first_name, last_name, password, role, status, email
     FROM library_users
     WHERE user_number = ?
     LIMIT 1"
);

if (!$stmt) {
    respond_json(['status' => 'error', 'message' => 'Unable to sign in right now.'], 500);
}

$stmt->bind_param('s', $identifier);
$stmt->execute();
$result = $stmt->get_result();
$user = $result ? $result->fetch_assoc() : null;
$stmt->close();

if (!$user) {
    password_verify($password, SMARTLIB_DUMMY_PIN_HASH);
    auth_record_login_attempt($conn, $identifier, 'unknown', $clientIp, false, 'user_not_found');
    respond_json(['status' => 'error', 'message' => 'Invalid credentials.'], 401);
}

$rawRole = strtolower(trim((string)($user['role'] ?? 'student')));
$normalizedRole = in_array($rawRole, ['admin', 'librarian'], true) ? 'librarian' : $rawRole;
$status = strtolower(trim((string)($user['status'] ?? 'active')));

// Hashed PINs only. The old plaintext-compare fallback (with upgrade-on-login)
// was removed for launch; password_verify() returns false for anything that
// isn't a valid hash, so a NULL/plaintext column simply can't sign in and the
// user has to go through reset_pin.php. An empty PIN still runs one bcrypt
// check (the dummy) so it takes as long as a wrong PIN.
$storedPassword = (string)($user['password'] ?? '');
$isValid = $storedPassword !== ''
    ? password_verify($password, $storedPassword)
    : (password_verify($password, SMARTLIB_DUMMY_PIN_HASH) && false);

if (!$isValid) {
    auth_record_login_attempt($conn, $identifier, $normalizedRole, $clientIp, false, 'invalid_password');
    respond_json(['status' => 'error', 'message' => 'Invalid credentials.'], 401);
}

if ($status !== '' && $status !== 'active') {
    auth_record_login_attempt($conn, $identifier, $normalizedRole, $clientIp, false, 'inactive_account');
    respond_json(['status' => 'error', 'message' => 'This account is inactive. Please contact the librarian.'], 403);
}

// Only these roles can use the kiosk sign-in (same set the old dropdown
// allowed). Anything else gets the generic message, never its role.
if (!in_array($normalizedRole, ['student', 'faculty', 'librarian'], true)) {
    auth_record_login_attempt($conn, $identifier, $normalizedRole, $clientIp, false, 'role_not_allowed');
    respond_json(['status' => 'error', 'message' => 'Invalid credentials.'], 401);
}

if ($normalizedRole === 'librarian') {
    $userId = (int)($user['user_id'] ?? 0);
    $userEmail = trim((string)($user['email'] ?? ''));

    if ($userId <= 0) {
        respond_json(['status' => 'error', 'message' => 'Unable to start 2FA verification.'], 500);
    }

    if ($userEmail === '' || !filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
        respond_json([
            'status' => 'error',
            'message' => 'This account has no valid email for its verification code. Please contact the system administrator.'
        ], 422);
    }

    $challenge = auth_create_2fa_challenge(
        $conn,
        $userId,
        (string)($user['user_number'] ?? ''),
        $normalizedRole,
        $userEmail,
        $clientIp
    );

    if (!$challenge) {
        respond_json(['status' => 'error', 'message' => 'Unable to generate 2FA code right now.'], 500);
    }

    $code = (string)($challenge['code'] ?? '');
    $masked = auth_mask_email($userEmail);
    $expiryMinutes = AUTH_2FA_CODE_TTL_MINUTES;
    $subject = 'SMARTLIB 2FA CODE';
    $emailOptions = [
        'variant' => 'code',
        'preheader' => "Your SmartLib sign-in code is {$code}. It expires in {$expiryMinutes} minutes.",
        'eyebrow' => 'SIGN-IN VERIFICATION',
        'title' => 'Your sign-in code',
        'intro' => 'Enter this code to finish signing in to SmartLib.',
        'code' => $code,
        'expiry' => "Expires in {$expiryMinutes} minutes",
        'security' => 'Never share this code. Library staff will never ask you for it.',
        'sent_to' => 'Sent to ' . $masked,
        'footer' => "Didn’t try to sign in? You can safely ignore this email — nobody can sign in without this code.",
    ];
    $html = smartlib_auth_email_html($emailOptions);
    $text = smartlib_auth_email_text($emailOptions);
$_SESSION['pending_2fa'] = [
        'challenge_id' => (int)($challenge['challenge_id'] ?? 0),
        'user_id' => $userId,
        'identifier' => $identifier,
        'account_type' => $normalizedRole,
        'user' => [
            'user_id' => $userId,
            'user_number' => (string)($user['user_number'] ?? ''),
            'first_name' => (string)($user['first_name'] ?? ''),
            'last_name' => (string)($user['last_name'] ?? ''),
            'role_raw' => $rawRole,
            'role_normalized' => $normalizedRole
        ],
        'created_at' => time()
    ];

    $clientMessage = 'Verification code sent to ' . $masked . '. Enter the code to continue.';
    if (smartlib_dev_show_codes_on_screen($clientIp)) {
        // Local-dev fallback, OFF unless config/mail_config.php sets
        // SMARTLIB_DEV_SHOW_CODES_ON_SCREEN = true AND the request comes from
        // this same machine. The localhost check alone is not enough on a
        // host: a proxy on the same server makes every visitor 127.0.0.1,
        // which would print every user's 2FA code on screen.
        $clientMessage .= " [Local dev -- email not yet configured, your code is: {$code}]";
    }

    $clientPayload = [
        'status' => '2fa_required',
        'message' => $clientMessage,
        'masked_email' => $masked,
        'expires_minutes' => AUTH_2FA_CODE_TTL_MINUTES
    ];

    register_shutdown_function(static function () use ($userEmail, $subject, $html, $text): void {
        @set_time_limit(45);
        auth_send_multipart_email($userEmail, $subject, $html, $text);
    });

    respond_json($clientPayload);
}

auth_finalize_session_login($user, $normalizedRole);
auth_clear_failed_login_attempts($conn, $identifier, $clientIp);
auth_record_login_attempt($conn, $identifier, $normalizedRole, $clientIp, true, 'login_success');

respond_json([
    'status' => 'success',
    'role' => $normalizedRole
]);









