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
$transactionId = esc_text((string)($receipt['transactionId'] ?? 'N/A'));
$library = esc_text((string)($receipt['library'] ?? 'PHINMA-SJCDC Library'));
$issuedAt = esc_text((string)($receipt['issuedAt'] ?? date('n/j/Y, g:i:s A')));

$subject = 'SmartLib Receipt - ' . $title;
if (function_exists('mb_encode_mimeheader')) {
    $subject = mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n");
}

$htmlBody = "\n<html>\n<head>\n  <meta charset=\"UTF-8\">\n  <title>SmartLib Receipt</title>\n</head>\n<body style=\"font-family:Arial,Helvetica,sans-serif;color:#20372c;\">\n  <h2 style=\"margin-bottom:4px;\">SmartLib Borrowing Receipt</h2>\n  <div style=\"color:#4f655c;margin-bottom:14px;\">{$library}<br>Issued: {$issuedAt}</div>\n\n  <table cellpadding=\"6\" cellspacing=\"0\" border=\"0\" style=\"border-collapse:collapse;width:100%;max-width:560px;\">\n    <tr><td colspan=\"2\" style=\"font-weight:700;padding-top:10px;\">Student Information</td></tr>\n    <tr><td>Name</td><td><strong>{$studentName}</strong></td></tr>\n    <tr><td>ID Number</td><td><strong>{$studentId}</strong></td></tr>\n    <tr><td>Course</td><td><strong>{$course}</strong></td></tr>\n\n    <tr><td colspan=\"2\" style=\"font-weight:700;padding-top:14px;\">Book Information</td></tr>\n    <tr><td>Title</td><td><strong>{$title}</strong></td></tr>\n    <tr><td>Author</td><td><strong>{$author}</strong></td></tr>\n    <tr><td>Accession Number</td><td><strong>{$accession}</strong></td></tr>\n    <tr><td>ISBN</td><td><strong>{$isbn}</strong></td></tr>\n    <tr><td>Borrowed</td><td><strong>{$borrowedDate}</strong></td></tr>\n    <tr><td>Due Date</td><td><strong>{$dueDate}</strong></td></tr>\n    <tr><td>Transaction ID</td><td><strong>{$transactionId}</strong></td></tr>\n  </table>\n\n  <p style=\"margin-top:16px;color:#4f655c;\">Please return this book on or before the due date. Late returns may incur fines.</p>\n</body>\n</html>\n";

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
    . "Transaction ID: {$transactionId}\n\n"
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
