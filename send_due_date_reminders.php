<?php
require_once __DIR__ . '/config/db_connect.php';
require_once __DIR__ . '/config/auth_security.php';

date_default_timezone_set('Asia/Taipei');

$isCli = PHP_SAPI === 'cli';
$argvList = $_SERVER['argv'] ?? [];
$dryRun = in_array('--dry-run', $argvList, true);

if (!$isCli) {
    http_response_code(403);
    echo 'This script is CLI-only.';
    exit;
}

function out(string $message): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
    if (PHP_SAPI === 'cli') {
        echo $line . PHP_EOL;
        return;
    }

    echo htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "<br>\n";
}

function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function safe_query(mysqli $conn, string $sql) {
    try {
        return $conn->query($sql);
    } catch (Throwable $e) {
        return false;
    }
}

function ensure_due_soon_reminder_log_table(mysqli $conn): bool {
    $sql = "
        CREATE TABLE IF NOT EXISTS due_soon_reminder_logs (
            reminder_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            record_id INT NOT NULL,
            user_id INT NOT NULL,
            email_to VARCHAR(190) NOT NULL,
            days_before_due TINYINT UNSIGNED NOT NULL,
            due_date DATE NOT NULL,
            sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (reminder_id),
            UNIQUE KEY uniq_due_soon_record_due_day (record_id, due_date, days_before_due),
            KEY idx_due_soon_user_sent (user_id, sent_at),
            KEY idx_due_soon_due_date (due_date, sent_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    return (bool)safe_query($conn, $sql);
}

function due_soon_email_html(
    string $borrowerName,
    string $studentId,
    string $programName,
    string $title,
    string $author,
    string $isbn,
    string $accession,
    string $borrowedDate,
    string $dueDateLabel,
    int $daysBefore
): string {
    $library = 'PHINMA-SJCDC Library';
    $issuedAt = date('n/j/Y, g:i:s A');
    $dayWord = $daysBefore === 1 ? 'day' : 'days';
    $headline = "Due in {$daysBefore} {$dayWord}";

    return <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>SmartLib Due Date Reminder</title>
</head>
<body style="margin:0;padding:0;background:#eef3f8;font-family:Arial,Helvetica,sans-serif;color:#1e293b;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#eef3f8;padding:24px 12px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:660px;background:#ffffff;border:1px solid #dbe4ee;border-radius:14px;overflow:hidden;">
          <tr>
            <td style="background:#0b5ed7;padding:18px 24px;color:#ffffff;">
              <div style="font-size:18px;font-weight:700;letter-spacing:.3px;">SmartLib Due Date Reminder</div>
              <div style="font-size:12px;opacity:.9;margin-top:4px;">{$library} | Sent: {$issuedAt}</div>
            </td>
          </tr>
          <tr>
            <td style="padding:24px;">
              <p style="margin:0 0 12px;font-size:15px;line-height:1.6;color:#475569;">Hello <strong>{$borrowerName}</strong>, this is a reminder that one of your borrowed books will be due soon.</p>
              <div style="margin:0 0 18px;background:#eef6ff;border:1px solid #bfdbfe;border-radius:10px;padding:12px 14px;color:#1d4ed8;font-size:14px;font-weight:700;">{$headline}</div>

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
                  <td style="padding:9px 8px;border-top:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0;font-size:14px;color:#0f172a;font-weight:600;">{$dueDateLabel}</td>
                </tr>
              </table>

              <p style="margin:18px 0 0;font-size:14px;line-height:1.6;color:#475569;">Please return or renew this book on or before the due date to avoid overdue penalties.</p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
}

function due_soon_email_text(
    string $borrowerName,
    string $studentId,
    string $programName,
    string $title,
    string $author,
    string $isbn,
    string $accession,
    string $borrowedDate,
    string $dueDateLabel,
    int $daysBefore
): string {
    $library = 'PHINMA-SJCDC Library';
    $issuedAt = date('n/j/Y, g:i:s A');
    $dayWord = $daysBefore === 1 ? 'day' : 'days';

    return "SMARTLIB DUE DATE REMINDER\n"
        . "{$library}\n"
        . "Sent: {$issuedAt}\n\n"
        . "Hello {$borrowerName}, your borrowed book is due in {$daysBefore} {$dayWord}.\n\n"
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
        . "Due Date: {$dueDateLabel}\n\n"
        . "Please return or renew this book on or before the due date to avoid overdue penalties.";
}

if (!$ensure = ensure_due_soon_reminder_log_table($conn)) {
    out('Failed to ensure due_soon_reminder_logs table.');
    exit(1);
}

$query = "
    SELECT
        br.record_id,
        br.user_id,
        DATE(br.due_date) AS due_date_only,
        br.date_borrowed,
        DATEDIFF(DATE(br.due_date), CURDATE()) AS days_before_due,
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
    WHERE br.due_date IS NOT NULL
      AND br.date_returned IS NULL
      AND br.status IN ('borrowed', 'overdue')
      AND DATEDIFF(DATE(br.due_date), CURDATE()) IN (1, 2, 3)
    ORDER BY DATE(br.due_date) ASC, br.record_id ASC
";

$res = safe_query($conn, $query);
if (!$res) {
    out('Failed to read due-soon records from borrow_records.');
    exit(1);
}

$checkStmt = $conn->prepare(
    "SELECT reminder_id
     FROM due_soon_reminder_logs
     WHERE record_id = ?
       AND due_date = ?
       AND days_before_due = ?
     LIMIT 1"
);

$insertStmt = $conn->prepare(
    "INSERT INTO due_soon_reminder_logs
        (record_id, user_id, email_to, days_before_due, due_date, sent_at)
     VALUES (?, ?, ?, ?, ?, NOW())"
);

if (!$checkStmt || !$insertStmt) {
    out('Failed to prepare reminder log statements.');
    exit(1);
}

$totalCandidates = 0;
$alreadySentCount = 0;
$invalidEmailCount = 0;
$attemptedCount = 0;
$sentCount = 0;
$failedCount = 0;

while ($row = $res->fetch_assoc()) {
    $totalCandidates++;

    $recordId = (int)($row['record_id'] ?? 0);
    $userId = (int)($row['user_id'] ?? 0);
    $email = trim((string)($row['email'] ?? ''));
    $daysBefore = (int)($row['days_before_due'] ?? 0);
    $dueDateRaw = trim((string)($row['due_date_only'] ?? ''));

    if ($recordId <= 0 || $userId <= 0 || $dueDateRaw === '' || !in_array($daysBefore, [1, 2, 3], true)) {
        continue;
    }

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $invalidEmailCount++;
        out("Skip record {$recordId}: invalid or missing email.");
        continue;
    }

    $checkStmt->bind_param('isi', $recordId, $dueDateRaw, $daysBefore);
    $checkStmt->execute();
    $checkRes = $checkStmt->get_result();
    $alreadyLogged = $checkRes && $checkRes->fetch_assoc();

    if ($alreadyLogged) {
        $alreadySentCount++;
        out("Skip record {$recordId}: {$daysBefore}-day reminder already sent for due date {$dueDateRaw}.");
        continue;
    }

    $borrowerName = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
    if ($borrowerName === '') {
        $borrowerName = 'Library User';
    }

    $studentId = trim((string)($row['user_number'] ?? 'N/A'));
    if ($studentId === '') {
        $studentId = 'N/A';
    }

    $programName = trim((string)($row['program_name'] ?? 'General'));
    if ($programName === '') {
        $programName = 'General';
    }

    $title = trim((string)($row['title'] ?? 'Book'));
    $author = trim((string)($row['author'] ?? 'Unknown Author'));
    $isbn = trim((string)($row['isbn'] ?? 'N/A'));
    $accession = trim((string)($row['accession_no'] ?? 'N/A'));
    $borrowedDate = trim((string)($row['date_borrowed'] ?? 'N/A'));
    $dueDateLabel = $dueDateRaw;
    $dueTs = strtotime($dueDateRaw);
    if ($dueTs !== false) {
        $dueDateLabel = date('F j, Y', $dueTs);
    }

    $dayWord = $daysBefore === 1 ? 'day' : 'days';
    $subject = "SmartLib Due Date Reminder ({$daysBefore} {$dayWord} left) - {$title}";
    $htmlBody = due_soon_email_html(
        h($borrowerName),
        h($studentId),
        h($programName),
        h($title),
        h($author),
        h($isbn),
        h($accession),
        h($borrowedDate),
        h($dueDateLabel),
        $daysBefore
    );
    $textBody = due_soon_email_text(
        $borrowerName,
        $studentId,
        $programName,
        $title,
        $author,
        $isbn,
        $accession,
        $borrowedDate,
        $dueDateLabel,
        $daysBefore
    );

    $attemptedCount++;

    if ($dryRun) {
        out("DRY RUN: would send {$daysBefore}-day reminder to {$email} (record {$recordId}).");
        continue;
    }

    $sent = auth_send_multipart_email($email, $subject, $htmlBody, $textBody);
    if (!$sent) {
        $failedCount++;
        out("Failed sending reminder for record {$recordId} to {$email}.");
        continue;
    }

    $insertStmt->bind_param('iisis', $recordId, $userId, $email, $daysBefore, $dueDateRaw);
    $ok = $insertStmt->execute();
    if (!$ok) {
        $errno = (int)$conn->errno;
        if ($errno === 1062) {
            $alreadySentCount++;
            out("Skip record {$recordId}: duplicate reminder log detected.");
            continue;
        }

        $failedCount++;
        out("Reminder sent but log insert failed for record {$recordId}. MySQL errno: {$errno}.");
        continue;
    }

    $sentCount++;
    out("Reminder sent for record {$recordId} ({$daysBefore} day/s before due).");
}

$checkStmt->close();
$insertStmt->close();

if ($dryRun) {
    out('Dry run complete.');
}

out("Summary: candidates={$totalCandidates}, attempted={$attemptedCount}, sent={$sentCount}, already_sent={$alreadySentCount}, invalid_email={$invalidEmailCount}, failed={$failedCount}.");
exit(0);
?>
