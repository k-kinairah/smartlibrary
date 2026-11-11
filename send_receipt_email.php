<?php
session_start();
require 'config/db_connect.php';
require_once 'config/auth_security.php'; // auth_send_multipart_email(), auth_mask_email(), AUTH_EMAIL_EMBED_IMAGES

header('Content-Type: application/json; charset=utf-8');

function respond(array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// Collapses whitespace (incl. CR/LF, so nothing can break out of the
// Subject header). Not an HTML escape -- see h() for that.
function esc_text(string $value): string {
    return trim(preg_replace('/\s+/', ' ', $value));
}

// Every value placed into the HTML body goes through this.
function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function receipt_date(?string $value, string $format): string {
    $ts = $value !== null && $value !== '' ? strtotime($value) : false;
    return $ts !== false ? date($format, $ts) : 'N/A';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['status' => 'error', 'message' => 'Invalid request method.'], 405);
}

require_once 'config/session_guard.php';
$userId = smartlib_active_session_user_id($conn); // 0 if signed out, inactive or deleted
if ($userId <= 0) {
    respond(['status' => 'error', 'message' => 'Please sign in first.'], 401);
}

$raw = file_get_contents('php://input');
$payload = json_decode((string)$raw, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

// The only thing taken from the browser is the transaction ID, and only to
// find the loan. checkout_book.php builds it as
// "<user_number>-<record_id>-<Ymd of date_borrowed>"; user numbers can
// themselves contain hyphens, so the record ID and date are read from the
// end. Everything shown in the email is then read from the database.
$receipt = $payload['receipt'] ?? null;
$transactionIdIn = is_array($receipt) ? trim((string)($receipt['transactionId'] ?? '')) : '';
if (!preg_match('/^.+-(\d{1,10})-(\d{8})$/', $transactionIdIn, $m)) {
    respond(['status' => 'error', 'message' => 'Missing or invalid receipt.'], 422);
}
$recordId = (int)$m[1];
$borrowedYmd = $m[2];

$loanStmt = $conn->prepare(
    "SELECT br.record_id, br.date_borrowed, br.due_date, br.created_at,
            COALESCE(bc.accession_no, '') AS accession_no,
            COALESCE(NULLIF(bc.isbn, ''), b.isbn, '') AS isbn,
            COALESCE(b.title, '') AS title,
            COALESCE(b.author, '') AS author,
            COALESCE(u.user_number, '') AS user_number,
            COALESCE(u.first_name, '') AS first_name,
            COALESCE(u.last_name, '') AS last_name,
            COALESCE(u.email, '') AS email,
            COALESCE(p.program_name, '') AS program_name
     FROM borrow_records br
     JOIN book_copies bc ON bc.copy_id = br.copy_id
     JOIN books b ON b.book_id = bc.book_id
     JOIN library_users u ON u.user_id = br.user_id
     LEFT JOIN programs p ON p.program_id = u.program_id
     WHERE br.record_id = ? AND br.user_id = ?
     LIMIT 1"
);
if (!$loanStmt) {
    respond(['status' => 'error', 'message' => 'Unable to initialize email sending.'], 500);
}
$loanStmt->bind_param('ii', $recordId, $userId);
$loanStmt->execute();
$loanRes = $loanStmt->get_result();
$loan = $loanRes ? $loanRes->fetch_assoc() : null;
$loanStmt->close();

// Same answer whether the record doesn't exist or belongs to someone else.
if (!$loan || receipt_date($loan['date_borrowed'] ?? null, 'Ymd') !== $borrowedYmd) {
    respond(['status' => 'error', 'message' => 'Receipt not found for your account.'], 404);
}

$email = trim((string)$loan['email']);
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond([
        'status' => 'error',
        'message' => 'No valid email found on your account. Please update your email in library_users first.'
    ], 422);
}

// Plain values (used in the text part and the subject).
$orNa = static fn(string $v): string => $v !== '' ? $v : 'N/A';
$userNumber = esc_text((string)$loan['user_number']);
$studentName = $orNa(esc_text(trim($loan['first_name'] . ' ' . $loan['last_name'])));
$studentId = $orNa($userNumber);
$course = $orNa(esc_text((string)$loan['program_name']));
$title = esc_text((string)$loan['title']) ?: 'Book';
$author = esc_text((string)$loan['author']) ?: 'Unknown Author';
$accession = $orNa(esc_text((string)$loan['accession_no']));
$isbn = $orNa(esc_text((string)$loan['isbn']));
$borrowedDate = receipt_date($loan['date_borrowed'], 'n/j/Y');
$dueDate = receipt_date($loan['due_date'], 'n/j/Y');
$issuedAt = receipt_date($loan['created_at'], 'n/j/Y, g:i:s A');
$transactionId = ($userNumber !== '' ? $userNumber : (string)$userId) . '-' . (int)$loan['record_id'] . '-' . $borrowedYmd;
$library = 'PHINMA-SJCDC Library';

// HTML-escaped copies -- the only versions interpolated into $htmlBody.
$hName = h($studentName);
$hId = h($studentId);
$hCourse = h($course);
$hTitle = h($title);
$hAuthor = h($author);
$hAccession = h($accession);
$hIsbn = h($isbn);
$hBorrowed = h($borrowedDate);
$hDue = h($dueDate);
$hIssued = h($issuedAt);
$hTxn = h($transactionId);
$hLibrary = h($library);

$subject = 'SmartLib Receipt - ' . $title;

// Same logo badge as the 2FA email (login_handler.php): the embedded school
// logo when AUTH_EMAIL_EMBED_IMAGES is on, otherwise an "SL" text badge.
$inlineImages = [];
$logoBadgeHtml = '<div style="display:inline-block;margin:0 auto 8px;width:42px;height:42px;line-height:42px;border-radius:50%;background:#ffffff;color:#164436;font-weight:700;">SL</div>';
$logoPath = __DIR__ . '/assets/images/stjude_logo.jpg';
if (AUTH_EMAIL_EMBED_IMAGES && is_file($logoPath) && is_readable($logoPath)) {
    $inlineImages[] = ['cid' => 'smartlib_logo', 'path' => $logoPath, 'mime' => 'image/jpeg'];
    $logoBadgeHtml = '<img src="cid:smartlib_logo" alt="SmartLib" style="display:block;margin:0 auto 8px;width:52px;height:52px;border-radius:50%;border:2px solid rgba(255,255,255,0.75);background:#ffffff;object-fit:cover;">';
}

// One label/value row; both arguments must already be HTML-escaped.
$row = static function (string $label, string $value, bool $first = false): string {
    $border = $first ? '' : 'border-top:1px solid #e3ece8;';
    return '<tr>'
        . '<td style="padding:9px 14px;' . $border . 'font-size:14px;color:#5a7a72;width:40%;">' . $label . '</td>'
        . '<td style="padding:9px 14px;' . $border . 'font-size:14px;color:#102822;font-weight:600;">' . $value . '</td>'
        . '</tr>';
};

// A titled, rounded section box; $rowsHtml must already be escaped.
$section = static function (string $heading, string $rowsHtml): string {
    return '<div style="margin:0 0 6px;font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#1f6f5c;">' . $heading . '</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:separate;border:1px solid #d6e4de;border-radius:10px;background:#f7fbf9;margin:0 0 18px;">'
        . $rowsHtml
        . '</table>';
};

$studentSection = $section('Student Information',
    $row('Name', $hName, true) . $row('ID Number', $hId) . $row('Course', $hCourse));
$bookSection = $section('Book Information',
    $row('Title', $hTitle, true) . $row('Author', $hAuthor) . $row('Accession Number', $hAccession) . $row('ISBN', $hIsbn));
$loanSection = $section('Loan Details',
    $row('Borrowed', $hBorrowed, true) . $row('Due Date', $hDue) . $row('Transaction ID', '<span style="font-family:Consolas,Menlo,monospace;letter-spacing:.5px;">' . $hTxn . '</span>'));

$htmlBody = <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>SmartLib Receipt</title>
</head>
<body style="margin:0;padding:0;background:#eef4f1;font-family:Arial,Helvetica,sans-serif;color:#1c3e36;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#eef4f1;padding:24px 12px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:620px;background:#ffffff;border:1px solid #d6e4de;border-radius:12px;overflow:hidden;box-shadow:0 6px 18px rgba(16,40,34,0.08);">
          <tr>
            <td style="background:#164436;padding:20px 24px;color:#ffffff;text-align:center;">
              {$logoBadgeHtml}
              <div style="font-size:18px;font-weight:700;letter-spacing:.3px;color:#ffffff;">SmartLib Borrowing Receipt</div>
              <div style="font-size:12px;margin-top:4px;color:#cfe9df;">{$hLibrary} &middot; Issued {$hIssued}</div>
            </td>
          </tr>
          <tr>
            <td style="padding:24px 24px 6px;">
              {$studentSection}
              {$bookSection}
              {$loanSection}
            </td>
          </tr>
          <tr>
            <td style="padding:0 24px 22px;">
              <div style="background:#eef6f2;border-radius:8px;padding:12px 14px;font-size:13px;line-height:1.6;color:#2c4a42;">Please return this book on or before the due date. Late returns may incur fines.</div>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;

$textBody = "SMARTLIB BORROWING RECEIPT\n"
    . "{$library}\n"
    . "Issued: {$issuedAt}\n\n"
    . "STUDENT INFORMATION\n"
    . "Name: {$studentName}\n"
    . "ID Number: {$studentId}\n"
    . "Course: {$course}\n\n"
    . "BOOK INFORMATION\n"
    . "Title: {$title}\n"
    . "Author: {$author}\n"
    . "Accession Number: {$accession}\n"
    . "ISBN: {$isbn}\n\n"
    . "LOAN DETAILS\n"
    . "Borrowed: {$borrowedDate}\n"
    . "Due Date: {$dueDate}\n"
    . "Transaction ID: {$transactionId}\n\n"
    . "Please return this book on or before the due date. Late returns may incur fines.";

$sent = auth_send_multipart_email($email, $subject, $htmlBody, $textBody, $inlineImages);

if (!$sent) {
    respond([
        'status' => 'error',
        'message' => 'Email send failed on server. Configure SMTP/sendmail in XAMPP to enable Gmail delivery.'
    ], 500);
}

respond([
    'status' => 'success',
    'message' => 'Receipt sent to ' . auth_mask_email($email)
]);
