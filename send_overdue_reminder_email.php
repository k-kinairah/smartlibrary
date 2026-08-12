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
    return trim((string)(preg_replace('/\s+/', ' ', $value) ?? ''));
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

function safe_query(mysqli $conn, string $sql) {
    try {
        return $conn->query($sql);
    } catch (Throwable $e) {
        return false;
    }
}

function ensure_overdue_reminder_log_table(mysqli $conn): bool {
    $sql = "
        CREATE TABLE IF NOT EXISTS overdue_reminder_logs (
            reminder_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            record_id INT NOT NULL,
            user_id INT NOT NULL,
            email_to VARCHAR(190) NOT NULL,
            sent_by_user_id INT NULL,
            sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (reminder_id),
            KEY idx_overdue_record_sent (record_id, sent_at),
            KEY idx_overdue_user_sent (user_id, sent_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    return (bool)safe_query($conn, $sql);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['status' => 'error', 'message' => 'Invalid request method.'], 405);
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$role = strtolower(trim((string)($_SESSION['role'] ?? '')));
if ($userId <= 0 || !in_array($role, ['admin', 'librarian'], true)) {
    respond(['status' => 'error', 'message' => 'Unauthorized request.'], 403);
}

if (!ensure_overdue_reminder_log_table($conn)) {
    respond(['status' => 'error', 'message' => 'Reminder logging is not available right now.'], 500);
}

$raw = file_get_contents('php://input');
$payload = json_decode((string)$raw, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$recordId = (int)($payload['record_id'] ?? 0);
if ($recordId <= 0) {
    respond(['status' => 'error', 'message' => 'Missing overdue record.'], 422);
}

$stmt = $conn->prepare(
    "SELECT
        br.record_id,
        br.user_id,
        br.status,
        br.date_borrowed,
        br.due_date,
        br.date_returned,
        COALESCE(u.first_name, '') AS first_name,
        COALESCE(u.last_name, '') AS last_name,
        COALESCE(u.user_number, '') AS user_number,
        COALESCE(u.email, '') AS email,
        COALESCE(p.program_name, 'General') AS program_name,
        COALESCE(b.title, 'Unknown Book') AS title,
        COALESCE(b.author, 'Unknown Author') AS author,
        COALESCE(b.isbn, 'N/A') AS isbn,
        COALESCE(NULLIF(TRIM(bc.accession_no), ''), CONCAT('Copy #', bc.copy_id)) AS accession_no
     FROM borrow_records br
     LEFT JOIN library_users u ON br.user_id = u.user_id
     LEFT JOIN programs p ON u.program_id = p.program_id
     LEFT JOIN book_copies bc ON br.copy_id = bc.copy_id
     LEFT JOIN books b ON bc.book_id = b.book_id
     WHERE br.record_id = ?
     LIMIT 1"
);

if (!$stmt) {
    respond(['status' => 'error', 'message' => 'Unable to initialize reminder sending.'], 500);
}

$stmt->bind_param('i', $recordId);
$stmt->execute();
$res = $stmt->get_result();
$row = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$row) {
    respond(['status' => 'error', 'message' => 'Overdue record not found.'], 404);
}

$status = strtolower(trim((string)($row['status'] ?? '')));
$dueRaw = trim((string)($row['due_date'] ?? ''));
$dateReturned = trim((string)($row['date_returned'] ?? ''));

$isOverdue = false;
if ($status === 'overdue') {
    $isOverdue = true;
} elseif ($status === 'borrowed' && $dateReturned === '' && $dueRaw !== '') {
    $dueTs = strtotime($dueRaw);
    if ($dueTs !== false && strtotime(date('Y-m-d')) > strtotime(date('Y-m-d', $dueTs))) {
        $isOverdue = true;
    }
}

if (!$isOverdue) {
    respond(['status' => 'error', 'message' => 'This record is not overdue anymore.'], 409);
}

$email = trim((string)($row['email'] ?? ''));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond([
        'status' => 'error',
        'message' => 'Borrower has no valid email on file.'
    ], 422);
}

# 24-hour cooldown per overdue record
$cooldownStmt = $conn->prepare(
    "SELECT sent_at
     FROM overdue_reminder_logs
     WHERE record_id = ?
     ORDER BY sent_at DESC
     LIMIT 1"
);
if (!$cooldownStmt) {
    respond(['status' => 'error', 'message' => 'Unable to verify reminder cooldown.'], 500);
}
$cooldownStmt->bind_param('i', $recordId);
$cooldownStmt->execute();
$cooldownRes = $cooldownStmt->get_result();
$lastSentRow = $cooldownRes ? $cooldownRes->fetch_assoc() : null;
$cooldownStmt->close();

if ($lastSentRow && !empty($lastSentRow['sent_at'])) {
    $lastSentTs = strtotime((string)$lastSentRow['sent_at']);
    if ($lastSentTs !== false) {
        $nextAllowedTs = $lastSentTs + 86400;
        if (time() < $nextAllowedTs) {
            respond([
                'status' => 'cooldown',
                'message' => 'Reminder already sent. You can send again after ' . date('M d, Y g:i A', $nextAllowedTs) . '.',
                'next_allowed_at' => date('Y-m-d H:i:s', $nextAllowedTs)
            ], 429);
        }
    }
}

$borrowerName = esc_text(trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? '')));
if ($borrowerName === '') {
    $borrowerName = 'Library User';
}
$studentId = esc_text((string)($row['user_number'] ?? 'N/A'));
$programName = esc_text((string)($row['program_name'] ?? 'General'));
$title = esc_text((string)($row['title'] ?? 'Book'));
$author = esc_text((string)($row['author'] ?? 'Unknown Author'));
$isbn = esc_text((string)($row['isbn'] ?? 'N/A'));
$accession = esc_text((string)($row['accession_no'] ?? 'N/A'));
$borrowedDate = esc_text((string)($row['date_borrowed'] ?? 'N/A'));
$dueDate = esc_text($dueRaw !== '' ? $dueRaw : 'N/A');

$daysLate = '';
$dueTs = strtotime($dueRaw);
if ($dueTs !== false) {
    $todayMid = strtotime(date('Y-m-d'));
    $dueMid = strtotime(date('Y-m-d', $dueTs));
    $diffDays = (int)floor(($todayMid - $dueMid) / 86400);
    if ($diffDays > 0) {
        $daysLate = (string)$diffDays;
    }
}

$library = 'PHINMA-SJCDC Library';
$issuedAt = date('n/j/Y, g:i:s A');
$subject = 'SmartLib Overdue Reminder - ' . $title;
if (function_exists('mb_encode_mimeheader')) {
    $subject = mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n");
}

$lateText = $daysLate !== '' ? ($daysLate . ' day' . ((int)$daysLate === 1 ? '' : 's') . ' overdue') : 'overdue';

$htmlBody = <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>SmartLib Overdue Reminder</title>
</head>
<body style="margin:0;padding:0;background:#eef3f8;font-family:Arial,Helvetica,sans-serif;color:#1e293b;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#eef3f8;padding:24px 12px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:660px;background:#ffffff;border:1px solid #dbe4ee;border-radius:14px;overflow:hidden;">
          <tr>
            <td style="background:#0f5132;padding:18px 24px;color:#ffffff;">
              <div style="font-size:18px;font-weight:700;letter-spacing:.3px;">SmartLib Overdue Reminder</div>
              <div style="font-size:12px;opacity:.9;margin-top:4px;">{$library} | Sent: {$issuedAt}</div>
            </td>
          </tr>
          <tr>
            <td style="padding:24px;">
              <p style="margin:0 0 12px;font-size:15px;line-height:1.6;color:#475569;">Hello <strong>{$borrowerName}</strong>, this is a reminder that your borrowed book is currently overdue.</p>
              <div style="margin:0 0 18px;background:#fff4f4;border:1px solid #f3c6c6;border-radius:10px;padding:12px 14px;color:#9f1239;font-size:14px;font-weight:700;">Status: {$lateText}</div>

              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">
                <tr>
                  <td colspan="2" style="padding:0 0 10px;font-size:16px;font-weight:700;color:#0f172a;">Borrower Information</td>
                </tr>
                <tr>
                  <td style="padding:9px 8px;border-top:1px solid #e2e8f0;font-size:14px;color:#64748b;width:38%;">Name</td>
                  <td style="padding:9px 8px;border-top:1px solid #e2e8f0;font-size:14px;color:#0f172a;font-weight:600;">{$borrowerName}</td>
                </tr>
                <tr>
                  <td style="padding:9px 8px;border-top:1px solid #e2e8f0;font-size:14px;color:#64748b;">ID Number</td>
                  <td style="padding:9px 8px;border-top:1px solid #e2e8f0;font-size:14px;color:#0f172a;font-weight:600;">{$studentId}</td>
                </tr>
                <tr>
                  <td style="padding:9px 8px;border-top:1px solid #e2e8f0;font-size:14px;color:#64748b;">Program</td>
                  <td style="padding:9px 8px;border-top:1px solid #e2e8f0;font-size:14px;color:#0f172a;font-weight:600;">{$programName}</td>
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
                  <td style="padding:9px 8px;border-top:1px solid #e2e8f0;font-size:14px;color:#64748b;">Borrowed Date</td>
                  <td style="padding:9px 8px;border-top:1px solid #e2e8f0;font-size:14px;color:#0f172a;font-weight:600;">{$borrowedDate}</td>
                </tr>
                <tr>
                  <td style="padding:9px 8px;border-top:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0;font-size:14px;color:#64748b;">Due Date</td>
                  <td style="padding:9px 8px;border-top:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0;font-size:14px;color:#0f172a;font-weight:600;">{$dueDate}</td>
                </tr>
              </table>

              <p style="margin:18px 0 0;font-size:14px;line-height:1.6;color:#475569;">Please return this book as soon as possible to avoid additional penalties.</p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;

$textBody = "SMARTLIB OVERDUE REMINDER\n"
    . "{$library}\n"
    . "Sent: {$issuedAt}\n\n"
    . "Hello {$borrowerName}, your borrowed book is currently {$lateText}.\n\n"
    . "Borrower Information\n"
    . "Name: {$borrowerName}\n"
    . "ID Number: {$studentId}\n"
    . "Program: {$programName}\n\n"
    . "Book Information\n"
    . "Title: {$title}\n"
    . "Author: {$author}\n"
    . "Accession Number: {$accession}\n"
    . "ISBN: {$isbn}\n"
    . "Borrowed Date: {$borrowedDate}\n"
    . "Due Date: {$dueDate}\n"
    . "Please return this book as soon as possible.";

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
        'message' => 'Email send failed on server. Configure SMTP/sendmail in XAMPP to enable delivery.'
    ], 500);
}

$logStmt = $conn->prepare(
    "INSERT INTO overdue_reminder_logs (record_id, user_id, email_to, sent_by_user_id, sent_at)
     VALUES (?, ?, ?, ?, NOW())"
);
if ($logStmt) {
    $borrowUserId = (int)($row['user_id'] ?? 0);
    $senderId = $userId > 0 ? $userId : null;
    if ($senderId === null) {
        $senderId = 0;
    }
    $logStmt->bind_param('iisi', $recordId, $borrowUserId, $email, $senderId);
    $logStmt->execute();
    $logStmt->close();
}

respond([
    'status' => 'success',
    'message' => 'Reminder sent to ' . mask_email($email)
]);
?>
