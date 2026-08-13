<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/borrow_fine_rules.php';

function fail_borrower_history_verify(string $message): void {
    throw new RuntimeException($message);
}

function ok_borrower_history_verify(string $message): void {
    echo "OK: {$message}" . PHP_EOL;
}

function fetch_borrower_history(string $baseUrl, string $sessionId, int $userId): array {
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => 'Cookie: PHPSESSID=' . $sessionId,
            'ignore_errors' => true,
            'timeout' => 15
        ]
    ]);

    $url = rtrim($baseUrl, '/') . '/admin/borrower_history.php?user_id=' . $userId;
    $body = @file_get_contents($url, false, $context);
    $headers = isset($http_response_header) && is_array($http_response_header) ? $http_response_header : [];

    return [
        'status_line' => (string)($headers[0] ?? ''),
        'body' => $body === false ? '' : (string)$body
    ];
}

$baseUrl = $argv[1] ?? 'http://localhost/SmartLib';
$stamp = date('YmdHis');
$sessionId = 'historyverify' . bin2hex(random_bytes(4));
$createdUserId = 0;
$createdBookId = 0;
$createdCopyIds = [];
$createdRecordIds = [];

try {
    session_id($sessionId);
    session_start();
    $_SESSION['user_id'] = 1;
    $_SESSION['role'] = 'admin';
    $_SESSION['name'] = 'Borrower History Verifier';
    $_SESSION['last_auth_at'] = time();
    session_write_close();

    $userNumber = 'BHV' . $stamp;
    $email = strtolower($userNumber) . '@smartlib.test';
    $password = password_hash('1234', PASSWORD_DEFAULT);

    $stmt = $conn->prepare(
        "INSERT INTO library_users (user_number, first_name, last_name, email, password, role, status, created_at)
         VALUES (?, 'History', 'Verifier', ?, ?, 'student', 'active', NOW())"
    );
    if (!$stmt) {
        fail_borrower_history_verify('Unable to prepare temporary user insert.');
    }
    $stmt->bind_param('sss', $userNumber, $email, $password);
    $stmt->execute();
    $createdUserId = (int)$conn->insert_id;
    $stmt->close();

    $isbn = 'BHV-' . $stamp;
    $title = 'Borrower History Verification ' . $stamp;
    $stmt = $conn->prepare(
        "INSERT INTO books (isbn, title, author, publisher, year_published, created_at)
         VALUES (?, ?, 'SmartLib Test', 'SmartLib', ?, NOW())"
    );
    if (!$stmt) {
        fail_borrower_history_verify('Unable to prepare temporary book insert.');
    }
    $year = date('Y');
    $stmt->bind_param('sss', $isbn, $title, $year);
    $stmt->execute();
    $createdBookId = (int)$conn->insert_id;
    $stmt->close();

    $copyStmt = $conn->prepare(
        "INSERT INTO book_copies (book_id, accession_no, isbn, status, created_at)
         VALUES (?, ?, ?, ?, NOW())"
    );
    if (!$copyStmt) {
        fail_borrower_history_verify('Unable to prepare temporary copy insert.');
    }

    $recordStmt = $conn->prepare(
        "INSERT INTO borrow_records (user_id, copy_id, date_borrowed, due_date, date_returned, status, created_at, fine)
         VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)"
    );
    if (!$recordStmt) {
        fail_borrower_history_verify('Unable to prepare temporary borrow insert.');
    }

    $borrowedDate = (new DateTimeImmutable('today'))->modify('-20 days')->format('Y-m-d');
    $overdueDueDate = (new DateTimeImmutable('today'))->modify('-12 days')->format('Y-m-d');
    $currentDueDate = (new DateTimeImmutable('today'))->modify('+7 days')->format('Y-m-d');
    $returnedDate = (new DateTimeImmutable('today'))->modify('-2 days')->format('Y-m-d');
    $nullDate = null;

    $copyStatus = 'borrowed';
    $accession = 'BHV-OVERDUE-' . $stamp;
    $copyStmt->bind_param('isss', $createdBookId, $accession, $isbn, $copyStatus);
    $copyStmt->execute();
    $overdueCopyId = (int)$conn->insert_id;
    $createdCopyIds[] = $overdueCopyId;

    $borrowStatus = 'borrowed';
    $zeroFine = 0.0;
    $recordStmt->bind_param('iissssd', $createdUserId, $overdueCopyId, $borrowedDate, $overdueDueDate, $nullDate, $borrowStatus, $zeroFine);
    $recordStmt->execute();
    $overdueRecordId = (int)$conn->insert_id;
    $createdRecordIds[] = $overdueRecordId;

    $copyStatus = 'available';
    $accession = 'BHV-RETURNED-' . $stamp;
    $copyStmt->bind_param('isss', $createdBookId, $accession, $isbn, $copyStatus);
    $copyStmt->execute();
    $returnedCopyId = (int)$conn->insert_id;
    $createdCopyIds[] = $returnedCopyId;

    $returnedStatus = 'returned';
    $returnedFine = 25.0;
    $recordStmt->bind_param('iissssd', $createdUserId, $returnedCopyId, $borrowedDate, $currentDueDate, $returnedDate, $returnedStatus, $returnedFine);
    $recordStmt->execute();
    $createdRecordIds[] = (int)$conn->insert_id;

    $copyStatus = 'lost';
    $accession = 'BHV-MISSING-' . $stamp;
    $copyStmt->bind_param('isss', $createdBookId, $accession, $isbn, $copyStatus);
    $copyStmt->execute();
    $missingCopyId = (int)$conn->insert_id;
    $createdCopyIds[] = $missingCopyId;

    $missingStatus = 'missing';
    $missingFine = 0.0;
    $recordStmt->bind_param('iissssd', $createdUserId, $missingCopyId, $borrowedDate, $currentDueDate, $nullDate, $missingStatus, $missingFine);
    $recordStmt->execute();
    $createdRecordIds[] = (int)$conn->insert_id;

    $copyStmt->close();
    $recordStmt->close();

    $response = fetch_borrower_history($baseUrl, $sessionId, $createdUserId);
    if (strpos($response['status_line'], '200') === false) {
        fail_borrower_history_verify('Borrower history did not return HTTP 200. Status: ' . $response['status_line']);
    }

    $body = $response['body'];
    $expectedFragments = [
        'Borrower History',
        'History Verifier',
        htmlspecialchars($userNumber, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
        'Current Loans',
        'Missing Books',
        'Full Borrow History',
        'PHP '
    ];

    foreach ($expectedFragments as $fragment) {
        if (strpos($body, $fragment) === false) {
            fail_borrower_history_verify('Borrower history page is missing expected text: ' . $fragment);
        }
    }

    $fineRes = $conn->query("SELECT status, fine FROM borrow_records WHERE record_id = {$overdueRecordId} LIMIT 1");
    $fineRow = $fineRes ? $fineRes->fetch_assoc() : null;
    if (!$fineRow) {
        fail_borrower_history_verify('Temporary overdue history record disappeared before fine assertion.');
    }

    $syncedStatus = strtolower((string)($fineRow['status'] ?? ''));
    $syncedFine = (float)($fineRow['fine'] ?? 0);
    if ($syncedStatus !== 'overdue') {
        fail_borrower_history_verify('Borrower history did not sync the temporary record to overdue status.');
    }
    if ($syncedFine <= 0) {
        fail_borrower_history_verify('Borrower history did not calculate a positive overdue fine.');
    }
    if (strpos($body, 'PHP ' . number_format($syncedFine, 2)) === false) {
        fail_borrower_history_verify('Borrower history page does not show the synced overdue fine.');
    }

    ok_borrower_history_verify('borrower history page renders profile, summary, and record sections');
    ok_borrower_history_verify('borrower history syncs overdue status and fine for the selected borrower');
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . PHP_EOL);
    exit(1);
} finally {
    if (!empty($createdRecordIds)) {
        $ids = implode(',', array_map('intval', $createdRecordIds));
        $conn->query("DELETE FROM borrow_records WHERE record_id IN ({$ids})");
    }
    if (!empty($createdCopyIds)) {
        $ids = implode(',', array_map('intval', $createdCopyIds));
        $conn->query("DELETE FROM book_copies WHERE copy_id IN ({$ids})");
    }
    if ($createdBookId > 0) {
        $conn->query('DELETE FROM books WHERE book_id = ' . (int)$createdBookId);
    }
    if ($createdUserId > 0) {
        $conn->query('DELETE FROM library_users WHERE user_id = ' . (int)$createdUserId);
    }

    $sessionFile = rtrim((string)session_save_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'sess_' . $sessionId;
    if (is_file($sessionFile)) {
        @unlink($sessionFile);
    }
}
