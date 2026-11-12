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

/**
 * Code 39 barcode as a PNG file (same encoding as makeBarcodeSvg() in
 * filters.js), for the email only: inline SVG is stripped by Gmail and
 * Outlook. Drawn at 2x (narrow bar 4px, wide 10px, 112px tall) so it stays
 * crisp on high-density screens when shown at half size. Returns
 * [path, width, height] of a temp file the caller deletes, or null if GD is
 * missing or anything fails -- the email then shows the code as text only.
 */
function receipt_barcode_png(string $value): ?array {
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagepng')) {
        return null;
    }
    static $patterns = [
        '0' => 'nnnwwnwnn', '1' => 'wnnwnnnnw', '2' => 'nnwwnnnnw', '3' => 'wnwwnnnnn',
        '4' => 'nnnwwnnnw', '5' => 'wnnwwnnnn', '6' => 'nnwwwnnnn', '7' => 'nnnwnnwnw',
        '8' => 'wnnwnnwnn', '9' => 'nnwwnnwnn', 'A' => 'wnnnnwnnw', 'B' => 'nnwnnwnnw',
        'C' => 'wnwnnwnnn', 'D' => 'nnnnwwnnw', 'E' => 'wnnnwwnnn', 'F' => 'nnwnwwnnn',
        'G' => 'nnnnnwwnw', 'H' => 'wnnnnwwnn', 'I' => 'nnwnnwwnn', 'J' => 'nnnnwwwnn',
        'K' => 'wnnnnnnww', 'L' => 'nnwnnnnww', 'M' => 'wnwnnnnwn', 'N' => 'nnnnwnnww',
        'O' => 'wnnnwnnwn', 'P' => 'nnwnwnnwn', 'Q' => 'nnnnnnwww', 'R' => 'wnnnnnwwn',
        'S' => 'nnwnnnwwn', 'T' => 'nnnnwnwwn', 'U' => 'wwnnnnnnw', 'V' => 'nwwnnnnnw',
        'W' => 'wwwnnnnnn', 'X' => 'nwnnwnnnw', 'Y' => 'wwnnwnnnn', 'Z' => 'nwwnwnnnn',
        '-' => 'nwnnnnwnw', '.' => 'wwnnnnwnn', ' ' => 'nwwnnnwnn', '$' => 'nwnwnwnnn',
        '/' => 'nwnwnnnwn', '+' => 'nwnnnwnwn', '%' => 'nnnwnwnwn', '*' => 'nwnnwnwnn',
    ];
    $normalized = trim((string)preg_replace('/[^0-9A-Z\-. $\/+%]/', '', strtoupper($value)));
    if ($normalized === '') {
        return null;
    }
    $encoded = '*' . $normalized . '*';
    $narrow = 4; $wide = 10; $gap = 4; $quiet = 24; $height = 112;

    $width = $quiet * 2;
    foreach (str_split($encoded) as $ch) {
        foreach (str_split($patterns[$ch] ?? $patterns['-']) as $unit) {
            $width += $unit === 'w' ? $wide : $narrow;
        }
        $width += $gap;
    }

    $img = @imagecreatetruecolor($width, $height);
    if (!$img) {
        return null;
    }
    imagefilledrectangle($img, 0, 0, $width - 1, $height - 1, imagecolorallocate($img, 255, 255, 255));
    $ink = imagecolorallocate($img, 0x10, 0x2b, 0x22);
    $x = $quiet;
    foreach (str_split($encoded) as $ch) {
        foreach (str_split($patterns[$ch] ?? $patterns['-']) as $i => $unit) {
            $w = $unit === 'w' ? $wide : $narrow;
            if ($i % 2 === 0) {
                imagefilledrectangle($img, $x, 0, $x + $w - 1, $height - 1, $ink);
            }
            $x += $w;
        }
        $x += $gap;
    }

    $path = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'smartlib-barcode-' . bin2hex(random_bytes(6)) . '.png';
    $ok = @imagepng($img, $path);
    imagedestroy($img);
    return $ok ? [$path, $width, $height] : null;
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

// Brand colours for this template. Email clients only get tables, solid
// colours, plain borders and background-color; the one gradient (header)
// sits on a solid background-color, so clients that drop background-image
// show the same flat green as before. The body's faint corner wash works the
// same way over white. No flexbox, absolute positioning, backdrop-filter,
// masks, text-clip or gradient rings: Outlook drops them. box-shadow and
// border-radius are decoration only (Outlook shows square, unshadowed).
$brand = '#164436';       // header, inner card border, due-date callout
$brandLight = '#1e5e4a';  // header gradient end

// Section tones, same as the in-app receipt (Kiosk Polish v197). All four
// clear 4.5:1 on the white body at the 11px used here.
$toneStudent = '#127350';
$toneBook = '#1f5f8c';
$toneLoan = '#6f3fa8';
$toneVerify = '#48655d';

$inlineImages = [];

// Same logo badge as the 2FA email (login_handler.php): the embedded school
// logo when AUTH_EMAIL_EMBED_IMAGES is on, otherwise an "SL" text badge.
// The ring is #738f86, i.e. 40% white over the header green, written as a
// solid colour because Outlook ignores rgba() borders.
$logoBadgeHtml = '<div style="display:inline-block;margin:0 auto 8px;width:42px;height:42px;line-height:42px;border-radius:50%;border:2px solid #738f86;background:#ffffff;color:' . $brand . ';font-weight:700;">SL</div>';
$logoPath = __DIR__ . '/assets/images/stjude_logo.jpg';
if (AUTH_EMAIL_EMBED_IMAGES && is_file($logoPath) && is_readable($logoPath)) {
    $inlineImages[] = ['cid' => 'smartlib_logo', 'path' => $logoPath, 'mime' => 'image/jpeg'];
    $logoBadgeHtml = '<img src="cid:smartlib_logo" alt="SmartLib" style="display:block;margin:0 auto 8px;width:52px;height:52px;border-radius:50%;border:2px solid #738f86;background:#ffffff;object-fit:cover;">';
}

// Vertical gap. Margins on tables are unreliable in Outlook; a sized block is not.
$gap = static function (int $px): string {
    return '<div style="height:' . $px . 'px;line-height:' . $px . 'px;font-size:1px;">&nbsp;</div>';
};

// A solid 1px (or 2px) rule as a table cell: bgcolor + background-color, so
// it shows in every client. $width in px.
$ruleCell = static function (int $width, int $height, string $color): string {
    return '<td width="' . $width . '" height="' . $height . '" bgcolor="' . $color . '" style="width:' . $width . 'px;height:' . $height . 'px;line-height:' . $height . 'px;font-size:1px;background-color:' . $color . ';">&nbsp;</td>';
};

// Centred section label: rule, uppercase text, rule (a 3-column table).
// $heading is a fixed string, not user data.
$flankLabel = static function (string $heading, string $color) use ($ruleCell): string {
    $line = '<td style="vertical-align:middle;"><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>' . $ruleCell(36, 1, $color) . '</tr></table></td>';
    return '<table role="presentation" align="center" cellpadding="0" cellspacing="0" border="0" style="margin:0 auto;"><tr>'
        . $line
        . '<td style="vertical-align:middle;padding:0 10px;font-size:11px;line-height:16px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;white-space:nowrap;color:' . $color . ';">' . $heading . '</td>'
        . $line
        . '</tr></table>';
};

// Centred bold line with a short solid rule under it. $value must already be
// HTML-escaped.
$hero = static function (string $value, string $ruleColor) use ($ruleCell, $gap): string {
    return '<div style="text-align:center;font-size:18px;line-height:24px;font-weight:700;color:#102822;">' . $value . '</div>'
        . $gap(5)
        . '<table role="presentation" align="center" cellpadding="0" cellspacing="0" border="0" style="margin:0 auto;"><tr>' . $ruleCell(36, 2, $ruleColor) . '</tr></table>';
};

// Centred strip of label-over-value cells, divided by a hairline on every
// cell but the first. Each field is [label, escaped value, nowrap?]; codes
// and dates are nowrap so they are never split, names and authors may wrap.
$strip = static function (array $fields): string {
    $cells = '';
    foreach ($fields as $i => [$label, $value, $nowrap]) {
        $cells .= '<td align="center" style="padding:0 14px;text-align:center;vertical-align:top;' . ($i > 0 ? 'border-left:1px solid #e1e8e4;' : '') . '">'
            . '<div style="font-size:11px;line-height:15px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;white-space:nowrap;color:#48655d;">' . $label . '</div>'
            . '<div style="font-size:14px;line-height:20px;font-weight:700;color:#102822;' . ($nowrap ? 'white-space:nowrap;' : '') . '">' . $value . '</div>'
            . '</td>';
    }
    return '<table role="presentation" align="center" cellpadding="0" cellspacing="0" border="0" style="margin:0 auto;"><tr>' . $cells . '</tr></table>';
};

// Barcode: a PNG of the transaction ID (see receipt_barcode_png), with the
// code underneath as a caption so it is still readable if images are off.
$barcodeFile = receipt_barcode_png($transactionId);
$barcodeImg = '';
if ($barcodeFile !== null) {
    [$barcodePath, $barcodeW, $barcodeH] = $barcodeFile;
    $inlineImages[] = ['cid' => 'receipt_barcode', 'path' => $barcodePath, 'mime' => 'image/png'];
    $shownW = min(intdiv($barcodeW, 2), 500);
    $shownH = (int)round($barcodeH * $shownW / $barcodeW);
    $barcodeImg = '<img src="cid:receipt_barcode" width="' . $shownW . '" height="' . $shownH . '" alt="Barcode ' . $hTxn . '" style="display:block;margin:0 auto;width:100%;max-width:' . $shownW . 'px;height:auto;border:0;">';
}
$barcodeTray = '<div style="background:#ffffff;border:1px dashed #b9d3c9;border-radius:8px;padding:10px;text-align:center;">'
    . $barcodeImg
    . '<div style="margin-top:' . ($barcodeImg !== '' ? '6' : '0') . 'px;font-family:Consolas,Menlo,monospace;font-size:13px;letter-spacing:1px;color:#102822;font-weight:600;">' . $hTxn . '</div>'
    . '</div>';

$studentSection = $flankLabel('Student Information', $toneStudent) . $gap(6)
    . $hero($hName, $toneStudent) . $gap(10)
    . $strip([['ID Number', $hId, true], ['Course', $hCourse, false]]);
$bookSection = $flankLabel('Book Information', $toneBook) . $gap(6)
    . $hero($hTitle, $toneBook) . $gap(10)
    // Author gets its own row: three no-wrap columns side by side are wider
    // than a phone screen, and email cannot rely on media queries.
    . $strip([['Author', $hAuthor, false]]) . $gap(8)
    . $strip([['Accession No.', $hAccession, true], ['ISBN', $hIsbn, true]]);
$loanSection = $flankLabel('Loan Details', $toneLoan) . $gap(8)
    . $strip([['Borrowed', $hBorrowed, true]]);
$verifySection = $flankLabel('Verification', $toneVerify) . $gap(8) . $barcodeTray;

// Due date: its own solid block (bgcolor too, for old Outlook). Mint
// #9ff0c8 on the header green measures about 8:1; the white label about 11:1.
$dueCallout = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="' . $brand . '" style="background-color:' . $brand . ';border-radius:10px;">'
    . '<tr><td align="center" style="padding:14px 16px;">'
    . '<div style="font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#ffffff;">Due Date</div>'
    . '<div style="margin-top:4px;font-size:24px;font-weight:800;color:#9ff0c8;">' . $hDue . '</div>'
    . '</td></tr></table>';

$g12 = $gap(12);
$g20 = $gap(20);

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
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:630px;border-collapse:separate;">
          <tr>
            <td bgcolor="#ffffff" style="background-color:#ffffff;border:1px solid #d8e4df;border-radius:16px;padding:4px;box-shadow:0 6px 18px rgba(16,40,34,0.08);">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#ffffff;border:1px solid {$brand};border-radius:12px;overflow:hidden;border-collapse:separate;">
                <tr>
                  <td bgcolor="{$brand}" style="background-color:{$brand};background-image:linear-gradient(135deg, {$brand} 0%, {$brandLight} 100%);padding:20px 24px;color:#ffffff;text-align:center;">
                    {$logoBadgeHtml}
                    <div style="font-size:18px;font-weight:700;letter-spacing:.3px;color:#ffffff;">SmartLib Borrowing Receipt</div>
                    <div style="font-size:12px;margin-top:4px;color:#cfe9df;">{$hLibrary} &middot; Issued {$hIssued}</div>
                  </td>
                </tr>
                <tr>
                  <td bgcolor="#ffffff" style="background-color:#ffffff;background-image:radial-gradient(70% 50% at 100% 0%, rgba(63,207,142,0.06) 0%, rgba(63,207,142,0) 70%), radial-gradient(70% 50% at 0% 100%, rgba(23,105,79,0.05) 0%, rgba(23,105,79,0) 70%);padding:24px 24px 22px;">
                    {$studentSection}
                    {$g20}
                    {$bookSection}
                    {$g20}
                    {$loanSection}
                    {$g12}
                    {$dueCallout}
                    {$g20}
                    {$verifySection}
                    {$g20}
                    <div style="background:#eef6f2;border-radius:8px;padding:12px 14px;font-size:13px;line-height:1.6;font-style:italic;text-align:center;color:#2c4a42;">Please return this book on or before the due date. Late returns may incur fines.</div>
                  </td>
                </tr>
              </table>
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
    . "DUE DATE: {$dueDate}\n\n"
    . "VERIFICATION\n"
    . "Transaction ID: {$transactionId}\n\n"
    . "Please return this book on or before the due date. Late returns may incur fines.";

$sent = auth_send_multipart_email($email, $subject, $htmlBody, $textBody, $inlineImages);
if ($barcodeFile !== null) {
    @unlink($barcodeFile[0]);
}

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
