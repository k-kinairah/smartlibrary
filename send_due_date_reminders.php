<?php
require_once __DIR__ . '/config/db_connect.php';
require_once __DIR__ . '/config/auth_security.php';
require_once __DIR__ . '/config/reminder_email_template.php';

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
    // Same rule as checkout_book.php's course_label.
    $affLabel = strtolower(trim((string)($row['role'] ?? ''))) === 'faculty' ? 'Department' : 'Course';

    $title = trim((string)($row['title'] ?? 'Book'));
    $author = trim((string)($row['author'] ?? 'Unknown Author'));
    $isbn = trim((string)($row['isbn'] ?? 'N/A'));
    $accession = trim((string)($row['accession_no'] ?? 'N/A'));
    $borrowedDate = trim((string)($row['date_borrowed'] ?? 'N/A'));

    $dayWord = $daysBefore === 1 ? 'day' : 'days';
    $subject = "SmartLib Due Date Reminder ({$daysBefore} {$dayWord} left) - {$title}";
    // Raw values: the template escapes the HTML once and uses them as-is in the text part.
    $reminder = [
        'kind' => 'due_soon',
        'days' => $daysBefore,
        'name' => $borrowerName,
        'id' => $studentId,
        'aff_label' => $affLabel,
        'aff' => $programName,
        'title' => $title,
        'author' => $author,
        'accession' => $accession,
        'isbn' => $isbn,
        'borrowed_date' => $borrowedDate,
        'due_date' => $dueDateRaw,
        'inline_logo' => true,
    ];
    $htmlBody = smartlib_reminder_email_html($reminder);
    $textBody = smartlib_reminder_email_text($reminder);
    $inlineImages = smartlib_reminder_email_inline_images($reminder); // empty unless AUTH_EMAIL_EMBED_IMAGES

    $attemptedCount++;

    if ($dryRun) {
        out("DRY RUN: would send {$daysBefore}-day reminder to {$email} (record {$recordId}).");
        continue;
    }

    $sent = auth_send_multipart_email($email, $subject, $htmlBody, $textBody, $inlineImages);
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
