<?php
session_start();
require 'config/db_connect.php';
require_once 'config/mail_sender.php';
require_once 'config/reminder_email_template.php';
require_once 'config/library_settings.php';

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

require_once 'config/session_guard.php';
$userId = smartlib_active_session_user_id($conn); // 0 if signed out, inactive or deleted
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
        COALESCE(u.role, '') AS role,
        -- department if set (faculty/staff), else program; same rule as the receipt
        COALESCE(NULLIF(d.department_name, ''), p.program_name, 'General') AS program_name,
        COALESCE(b.title, 'Unknown Book') AS title,
        COALESCE(b.author, 'Unknown Author') AS author,
        COALESCE(b.isbn, 'N/A') AS isbn,
        COALESCE(NULLIF(TRIM(bc.accession_no), ''), CONCAT('Copy #', bc.copy_id)) AS accession_no
     FROM borrow_records br
     LEFT JOIN library_users u ON br.user_id = u.user_id
     LEFT JOIN programs p ON u.program_id = p.program_id
     LEFT JOIN departments d ON d.department_id = u.department_id
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
// Same rule as checkout_book.php's course_label.
$affLabel = strtolower(trim((string)($row['role'] ?? ''))) === 'faculty' ? 'Department' : 'Course';

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

$subject = 'SmartLib Overdue Reminder - ' . $title;
if (function_exists('mb_encode_mimeheader')) {
    $subject = mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n");
}

// Raw values: the template escapes the HTML once (the old inline HTML did not
// escape names or titles at all) and uses them as-is in the text part. This
// endpoint builds its own MIME with no inline images, so the logo is the "SL" badge.
$reminder = [
    'kind' => 'overdue',
    'days' => $daysLate !== '' ? (int)$daysLate : 0,
    'name' => $borrowerName,
    'id' => $studentId,
    'aff_label' => $affLabel,
    'aff' => $programName,
    'title' => $title,
    'author' => $author,
    'accession' => $accession,
    'isbn' => $isbn,
    'borrowed_date' => $borrowedDate,
    'due_date' => $dueRaw,
    // Penalties are only mentioned while overdue fines are on (Settings).
    'fines_on' => smartlib_fines_enabled($conn),
];
$htmlBody = smartlib_reminder_email_html($reminder);
$textBody = smartlib_reminder_email_text($reminder);

$boundary = 'smartlib_' . bin2hex(random_bytes(8));
$headers = [];
$headers[] = 'MIME-Version: 1.0';
foreach (smartlib_mail_sender_headers('SmartLib Kiosk') as $senderHeader) {
    $headers[] = $senderHeader;
}
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

$sent = smartlib_mail_send($email, $subject, $message, $headers);

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
