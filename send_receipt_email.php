<?php
session_start();
require 'config/db_connect.php';

header('Content-Type: application/json; charset=utf-8');

function respond(array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function esc_text(string $value): string {
    return trim(preg_replace('/\s+/', ' ', $value));
}

function mask_email(string $email): string {
    $parts = explode('@', $email, 2);
    if (count($parts) !== 2) {
        return $email;
    }

    $name = $parts[0];
    $domain = $parts[1];

    if (strlen($name) <= 2) {
        return substr($name, 0, 1) . '*' . '@' . $domain;
    }

    return substr($name, 0, 2) . str_repeat('*', max(2, strlen($name) - 3)) . substr($name, -1) . '@' . $domain;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['status' => 'error', 'message' => 'Invalid request method.'], 405);
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    respond(['status' => 'error', 'message' => 'Please sign in first.'], 401);
}

$raw = file_get_contents('php://input');
$payload = json_decode((string)$raw, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$receipt = $payload['receipt'] ?? null;
if (!is_array($receipt)) {
    respond(['status' => 'error', 'message' => 'Missing receipt payload.'], 422);
}

$userStmt = $conn->prepare(
    "SELECT COALESCE(user_number, '') AS user_number,
            COALESCE(first_name, '') AS first_name,
            COALESCE(last_name, '') AS last_name,
            COALESCE(email, '') AS email
     FROM library_users
     WHERE user_id = ?
     LIMIT 1"
);

if (!$userStmt) {
    respond(['status' => 'error', 'message' => 'Unable to initialize email sending.'], 500);
}

$userStmt->bind_param('i', $userId);
$userStmt->execute();
$userRes = $userStmt->get_result();
$user = $userRes ? $userRes->fetch_assoc() : null;
$userStmt->close();

if (!$user) {
    respond(['status' => 'error', 'message' => 'User account not found.'], 404);
}

$email = trim((string)($user['email'] ?? ''));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond([
        'status' => 'error',
        'message' => 'No valid email found on your account. Please update your email in library_users first.'
    ], 422);
}

$studentName = esc_text((string)($receipt['userName'] ?? trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))));
$studentId = esc_text((string)($receipt['userId'] ?? ($user['user_number'] ?? '')));
$course = esc_text((string)($receipt['course'] ?? 'N/A'));
$title = esc_text((string)($receipt['title'] ?? 'Book'));
$author = esc_text((string)($receipt['author'] ?? 'Unknown Author'));
$isbn = esc_text((string)($receipt['isbn'] ?? 'N/A'));
$accession = esc_text((string)($receipt['accession'] ?? 'N/A'));
$borrowedDate = esc_text((string)($receipt['borrowedDate'] ?? 'N/A'));
$dueDate = esc_text((string)($receipt['dueDate'] ?? 'N/A'));
$library = esc_text((string)($receipt['library'] ?? 'PHINMA-SJCDC Library'));
$issuedAt = esc_text((string)($receipt['issuedAt'] ?? date('n/j/Y, g:i:s A')));

$subject = 'SmartLib Receipt - ' . $title;
if (function_exists('mb_encode_mimeheader')) {
    $subject = mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n");
}

$htmlBody = <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>SmartLib Receipt</title>
</head>
<body style="margin:0;padding:0;background:#eef3f8;font-family:Arial,Helvetica,sans-serif;color:#1e293b;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#eef3f8;padding:24px 12px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:660px;background:#ffffff;border:1px solid #dbe4ee;border-radius:14px;overflow:hidden;">
          <tr>
            <td style="background:#0f5132;padding:18px 24px;color:#ffffff;">
              <div style="font-size:18px;font-weight:700;letter-spacing:.3px;">SmartLib Borrowing Receipt</div>
              <div style="font-size:12px;opacity:.9;margin-top:4px;">{$library} | Issued: {$issuedAt}</div>
            </td>
          </tr>
          <tr>
            <td style="padding:24px;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">
                <tr>
                  <td colspan="2" style="padding:0 0 10px;font-size:16px;font-weight:700;color:#0f172a;">Student Information</td>
                </tr>
                <tr>
                  <td style="padding:9px 8px;border-top:1px solid #e2e8f0;font-size:14px;color:#64748b;width:38%;">Name</td>
                  <td style="padding:9px 8px;border-top:1px solid #e2e8f0;font-size:14px;color:#0f172a;font-weight:600;">{$studentName}</td>
                </tr>
                <tr>
                  <td style="padding:9px 8px;border-top:1px solid #e2e8f0;font-size:14px;color:#64748b;">ID Number</td>
                  <td style="padding:9px 8px;border-top:1px solid #e2e8f0;font-size:14px;color:#0f172a;font-weight:600;">{$studentId}</td>
                </tr>
                <tr>
                  <td style="padding:9px 8px;border-top:1px solid #e2e8f0;font-size:14px;color:#64748b;">Course</td>
                  <td style="padding:9px 8px;border-top:1px solid #e2e8f0;font-size:14px;color:#0f172a;font-weight:600;">{$course}</td>
                </tr>

                <tr>
                  <td colspan="2" style="padding:18px 0 10px;font-size:16px;font-weight:700;color:#0f172a;">Book Information</td>
                </tr>
                <tr>
                  <td style="padding:9px 8px;border-top:1px solid #e2e8f0;font-size:14px;color:#64748b;">Title</td>
                  <td style="padding:9px 8px;border-top:1px solid #e2e8f0;font-size:14px;color:#0f172a;font-weight:600;">{$title}</td>
                </tr>
                <tr>
                  <td style="padding:9px 8px;border-top:1px solid #e2e8f0;font-size:14px;color:#64748b;">Author</td>
                  <td style="padding:9px 8px;border-top:1px solid #e2e8f0;font-size:14px;color:#0f172a;font-weight:600;">{$author}</td>
                </tr>
                <tr>
                  <td style="padding:9px 8px;border-top:1px solid #e2e8f0;font-size:14px;color:#64748b;">Accession Number</td>
                  <td style="padding:9px 8px;border-top:1px solid #e2e8f0;font-size:14px;color:#0f172a;font-weight:600;">{$accession}</td>
                </tr>
                <tr>
                  <td style="padding:9px 8px;border-top:1px solid #e2e8f0;font-size:14px;color:#64748b;">ISBN</td>
                  <td style="padding:9px 8px;border-top:1px solid #e2e8f0;font-size:14px;color:#0f172a;font-weight:600;">{$isbn}</td>
                </tr>
                <tr>
                  <td style="padding:9px 8px;border-top:1px solid #e2e8f0;font-size:14px;color:#64748b;">Borrowed</td>
                  <td style="padding:9px 8px;border-top:1px solid #e2e8f0;font-size:14px;color:#0f172a;font-weight:600;">{$borrowedDate}</td>
                </tr>
                <tr>
                  <td style="padding:9px 8px;border-top:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0;font-size:14px;color:#64748b;">Due Date</td>
                  <td style="padding:9px 8px;border-top:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0;font-size:14px;color:#0f172a;font-weight:600;">{$dueDate}</td>
                </tr>
              </table>

              <p style="margin:18px 0 0;font-size:14px;line-height:1.6;color:#475569;">Please return this book on or before the due date. Late returns may incur fines.</p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;

$textBody = "SMARTLIB RECEIPT\n"
    . "{$library}\n"
    . "Issued: {$issuedAt}\n\n"
    . "Student Information\n"
    . "Name: {$studentName}\n"
    . "ID Number: {$studentId}\n"
    . "Course: {$course}\n\n"
    . "Book Information\n"
    . "Title: {$title}\n"
    . "Author: {$author}\n"
    . "Accession Number: {$accession}\n"
    . "ISBN: {$isbn}\n"
    . "Borrowed: {$borrowedDate}\n"
    . "Due Date: {$dueDate}\n"
    . "Please return this book on or before the due date.";

$boundary = 'smartlib_' . bin2hex(random_bytes(8));
$headers = [];
$headers[] = 'MIME-Version: 1.0';
$headers[] = 'From: SmartLib Kiosk <no-reply@smartlib.local>';
$headers[] = 'Reply-To: no-reply@smartlib.local';
$headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

$message = "--{$boundary}\r\n"
    . "Content-Type: text/plain; charset=UTF-8\r\n"
    . "Content-Transfer-Encoding: 8bit\r\n\r\n"
    . $textBody . "\r\n\r\n"
    . "--{$boundary}\r\n"
    . "Content-Type: text/html; charset=UTF-8\r\n"
    . "Content-Transfer-Encoding: 8bit\r\n\r\n"
    . $htmlBody . "\r\n\r\n"
    . "--{$boundary}--";

$sent = @mail($email, $subject, $message, implode("\r\n", $headers));

if (!$sent) {
    respond([
        'status' => 'error',
        'message' => 'Email send failed on server. Configure SMTP/sendmail in XAMPP to enable Gmail delivery.'
    ], 500);
}

respond([
    'status' => 'success',
    'message' => 'Receipt sent to ' . mask_email($email)
]);
?>



