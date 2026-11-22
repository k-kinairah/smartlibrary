<?php
session_start();
require 'config/db_connect.php';
require 'config/auth_security.php';
require_once 'config/library_card_helpers.php';

header('Content-Type: application/json; charset=utf-8');

function respond_json(array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
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

// Same ID normalization as login_handler.php (dashes as printed on the card).
$identifier = library_card_normalize_id((string)($_POST['identifier'] ?? ''));
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

// Cooldown and hourly cap are per ID; the IP only has a high abuse ceiling
// (see auth_reset_rate_limit_status()).
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
$expiryMinutes = AUTH_RESET_TOKEN_TTL_MINUTES;
$logoCid = 'smartlib_logo';
$logoPath = __DIR__ . '/assets/images/stjude_logo.jpg';
$inlineImages = [];
if (is_file($logoPath) && is_readable($logoPath)) {
    $inlineImages[] = ['cid' => $logoCid, 'path' => $logoPath, 'mime' => 'image/jpeg'];
    $logoBadgeHtml = '<img src="cid:' . $logoCid . '" alt="SMARTLIB" width="42" height="42" style="display:block;margin:0 auto 8px;width:42px;height:42px;border-radius:50%;background:#ffffff;padding:2px;">';
} else {
    $logoBadgeHtml = '<div style="display:inline-block;margin:0 auto 8px;width:42px;height:42px;line-height:42px;border-radius:50%;background:#ffffff;color:#0f5132;font-weight:700;">SL</div>';
}
$name = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));
if ($name === '') {
    $name = 'SmartLib User';
}

$subject = 'SMARTLIB PIN RESET LINK';
$html = <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>SMARTLIB PIN Reset</title>
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
              <div style="font-size:12px;opacity:.9;margin-top:4px;text-align:center;">PIN Reset Request</div>
            </td>
          </tr>
          <tr>
            <td style="padding:24px;text-align:center;">
              <h1 style="margin:0 0 10px;font-size:24px;line-height:1.25;color:#0f172a;">Reset your PIN/password</h1>
              <p style="margin:0 0 18px;font-size:15px;line-height:1.6;color:#475569;">Hi {$name}, use the secure button below to reset your SMARTLIB PIN/password.</p>
              <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 auto 18px;">
                <tr>
                  <td style="border-radius:10px;background:#0f5132;">
                    <a href="{$resetUrl}" style="display:inline-block;padding:12px 22px;color:#ffffff;text-decoration:none;font-weight:700;font-size:15px;">Reset PIN</a>
                  </td>
                </tr>
              </table>
              <p style="margin:0 0 8px;font-size:13px;line-height:1.6;color:#64748b;text-align:center;">If the button does not work, copy and paste this link into your browser:</p>
              <p style="margin:0 0 14px;font-size:13px;line-height:1.6;word-break:break-all;color:#0f5132;text-align:center;">{$resetUrl}</p>
              <p style="margin:0;font-size:14px;line-height:1.6;color:#475569;text-align:center;">This link expires in <strong>{$expiryMinutes} minutes</strong> and can only be used once.</p>
            </td>
          </tr>
          <tr>
            <td style="padding:0 24px 22px;font-size:12px;line-height:1.6;color:#64748b;text-align:center;">If you did not request this reset, you can safely ignore this message.</td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
$text = "SMARTLIB PIN Reset\n\n"
    . "Hi {$name},\n"
    . "Use this link to reset your PIN/password:\n{$resetUrl}\n\n"
    . "This link expires in {$expiryMinutes} minutes and can be used only once.";

$tokenId = (int)($tokenInfo['token_id'] ?? 0);
$sent = auth_send_multipart_email($email, $subject, $html, $text, $inlineImages);
if (!$sent) {
    pin_reset_log('send_failed token_id=' . $tokenId . ' user_number=' . substr($identifier, 0, 40));
    respond_json(['status' => 'error', 'message' => 'Reset email could not be sent. Check server mail configuration.'], 500);
}

pin_reset_log('sent token_id=' . $tokenId . ' to=' . auth_mask_email($email) . ' user_number=' . substr($identifier, 0, 40));

respond_json($genericSuccess);





