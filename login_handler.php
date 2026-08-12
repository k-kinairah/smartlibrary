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

$identifier = trim((string)($_POST['identifier'] ?? ''));
$password = trim((string)($_POST['password'] ?? ''));
$accountType = strtolower(trim((string)($_POST['account_type'] ?? 'student')));
$clientIp = auth_client_ip();

if (!in_array($accountType, ['student', 'faculty', 'librarian'], true)) {
    $accountType = 'student';
}

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
    auth_record_login_attempt($conn, $identifier, $accountType, $clientIp, false, 'user_not_found');
    respond_json(['status' => 'error', 'message' => 'Invalid credentials.'], 401);
}

$storedPassword = (string)($user['password'] ?? '');
$isHashed = preg_match('/^\$2[aby]\$/', $storedPassword) === 1;

$isValid = false;
if ($isHashed) {
    $isValid = password_verify($password, $storedPassword);
} else {
    $isValid = hash_equals($storedPassword, $password);

    if ($isValid) {
        $newHash = password_hash($password, PASSWORD_DEFAULT);
        $up = $conn->prepare("UPDATE library_users SET password = ? WHERE user_id = ? LIMIT 1");
        if ($up) {
            $uid = (int)($user['user_id'] ?? 0);
            $up->bind_param('si', $newHash, $uid);
            $up->execute();
            $up->close();
        }
    }
}

if (!$isValid) {
    auth_record_login_attempt($conn, $identifier, $accountType, $clientIp, false, 'invalid_password');
    respond_json(['status' => 'error', 'message' => 'Invalid credentials.'], 401);
}

$rawRole = strtolower(trim((string)($user['role'] ?? 'student')));
$status = strtolower(trim((string)($user['status'] ?? 'active')));

if ($status !== '' && $status !== 'active') {
    auth_record_login_attempt($conn, $identifier, $accountType, $clientIp, false, 'inactive_account');
    respond_json(['status' => 'error', 'message' => 'This account is inactive. Please contact the librarian.'], 403);
}

$roleAllowed = false;
if ($accountType === 'student') {
    $roleAllowed = ($rawRole === 'student');
} elseif ($accountType === 'faculty') {
    $roleAllowed = ($rawRole === 'faculty');
} elseif ($accountType === 'librarian') {
    $roleAllowed = in_array($rawRole, ['librarian', 'admin'], true);
}

if (!$roleAllowed) {
    auth_record_login_attempt($conn, $identifier, $accountType, $clientIp, false, 'role_mismatch');

    $accountLabel = 'student';
    if ($accountType === 'faculty') {
        $accountLabel = 'faculty';
    } elseif ($accountType === 'librarian') {
        $accountLabel = 'librarian';
    }

    respond_json(['status' => 'error', 'message' => "This ID is not a {$accountLabel} account."], 403);
}

$normalizedRole = in_array($rawRole, ['admin', 'librarian'], true) ? 'librarian' : $rawRole;

if ($normalizedRole === 'librarian') {
    $userId = (int)($user['user_id'] ?? 0);
    $userEmail = trim((string)($user['email'] ?? ''));

    if ($userId <= 0) {
        respond_json(['status' => 'error', 'message' => 'Unable to start 2FA verification.'], 500);
    }

    if ($userEmail === '' || !filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
        respond_json([
            'status' => 'error',
            'message' => 'This librarian/admin account has no valid email for 2FA. Please update email in Users first.'
        ], 422);
    }

    $challenge = auth_create_2fa_challenge(
        $conn,
        $userId,
        (string)($user['user_number'] ?? ''),
        $accountType,
        $userEmail,
        $clientIp
    );

    if (!$challenge) {
        respond_json(['status' => 'error', 'message' => 'Unable to generate 2FA code right now.'], 500);
    }

    $code = (string)($challenge['code'] ?? '');
    $masked = auth_mask_email($userEmail);
    $expiryMinutes = AUTH_2FA_CODE_TTL_MINUTES;
    $logoBadgeHtml = '<div style="display:inline-block;margin:0 auto 8px;width:42px;height:42px;line-height:42px;border-radius:50%;background:#ffffff;color:#0f5132;font-weight:700;">SL</div>';
    $inlineImages = [];

    $logoPath = __DIR__ . '/assets/images/stjude_logo.jpg';
    if (AUTH_EMAIL_EMBED_IMAGES && is_file($logoPath) && is_readable($logoPath)) {
        $inlineImages[] = [
            'cid' => 'smartlib_logo',
            'path' => $logoPath,
            'mime' => 'image/jpeg'
        ];
        $logoBadgeHtml = '<img src="cid:smartlib_logo" alt="SmartLib" style="display:block;margin:0 auto 8px;width:52px;height:52px;border-radius:50%;border:2px solid rgba(255,255,255,0.75);background:#ffffff;object-fit:cover;">';
    }

    $subject = 'SMARTLIB 2FA CODE';
    $html = <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>SMARTLIB Sign-in Verification</title>
</head>
<body style="margin:0;padding:0;background:#eef3f8;font-family:Arial,Helvetica,sans-serif;color:#1e293b;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#eef3f8;padding:24px 12px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:620px;background:#ffffff;border:1px solid #dbe4ee;border-radius:14px;overflow:hidden;">
          <tr>
            <td style="background:#0f5132;padding:18px 24px;color:#ffffff;text-align:center;">
              {$logoBadgeHtml}
              <div style="font-size:18px;font-weight:700;letter-spacing:.6px;color:#ffd84d;">SMARTLIB</div>
              <div style="font-size:12px;opacity:.95;margin-top:4px;color:#ffe48a;">Sign-in Verification</div>
            </td>
          </tr>
          <tr>
            <td style="padding:24px;text-align:center;">
              <h1 style="margin:0 0 10px;font-size:24px;line-height:1.25;color:#0f172a;">Your one-time sign-in code</h1>
              <p style="margin:0 0 18px;font-size:15px;line-height:1.5;color:#475569;">Enter this code to complete your SMARTLIB sign-in.</p>
              <div style="background:#f8fafc;border:1px solid #cbd5e1;border-radius:10px;padding:16px;text-align:center;">
                <span style="display:inline-block;font-size:36px;letter-spacing:8px;font-weight:800;color:#b88700;">{$code}</span>
              </div>
              <p style="margin:16px 0 0;font-size:14px;line-height:1.5;color:#475569;">This code expires in <strong>{$expiryMinutes} minutes</strong>. For your safety, do not share it with anyone.</p>
            </td>
          </tr>
          <tr>
            <td style="padding:0 24px 22px;font-size:12px;line-height:1.6;color:#64748b;text-align:center;">Code sent to {$masked}. If you did not request this, you can ignore this email.</td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    $text = "SMARTLIB Sign-in Verification\n\n"
        . "Your one-time code is: {$code}\n"
        . "This code expires in {$expiryMinutes} minutes.";
$_SESSION['pending_2fa'] = [
        'challenge_id' => (int)($challenge['challenge_id'] ?? 0),
        'user_id' => $userId,
        'identifier' => $identifier,
        'account_type' => $accountType,
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

    $clientPayload = [
        'status' => '2fa_required',
        'message' => 'Verification code sent to ' . $masked . '. Enter the code to continue.',
        'masked_email' => $masked,
        'expires_minutes' => AUTH_2FA_CODE_TTL_MINUTES
    ];

    register_shutdown_function(static function () use ($userEmail, $subject, $html, $text, $inlineImages): void {
        @set_time_limit(45);
        auth_send_multipart_email($userEmail, $subject, $html, $text, $inlineImages);
    });

    respond_json($clientPayload);
}

auth_finalize_session_login($user, $normalizedRole);
auth_clear_failed_login_attempts($conn, $identifier, $clientIp);
auth_record_login_attempt($conn, $identifier, $accountType, $clientIp, true, 'login_success');

respond_json([
    'status' => 'success',
    'role' => $normalizedRole
]);









