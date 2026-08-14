<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config/db_connect.php';

function fail_borrower_account_verify(string $message): void {
    throw new RuntimeException($message);
}

function ok_borrower_account_verify(string $message): void {
    echo "OK: {$message}" . PHP_EOL;
}

function fetch_account_payload(string $baseUrl, string $sessionId): array {
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => 'Cookie: PHPSESSID=' . $sessionId,
            'ignore_errors' => true,
            'timeout' => 15
        ]
    ]);

    $body = @file_get_contents(rtrim($baseUrl, '/') . '/fetch_my_checked_out.php', false, $context);
    $headers = isset($http_response_header) && is_array($http_response_header) ? $http_response_header : [];
    $statusLine = (string)($headers[0] ?? '');

    if ($body === false || strpos($statusLine, '200') === false) {
        fail_borrower_account_verify('Account endpoint did not return HTTP 200. Status: ' . $statusLine);
    }

    $payload = json_decode((string)$body, true);
    if (!is_array($payload)) {
        fail_borrower_account_verify('Account endpoint returned invalid JSON.');
    }

    return $payload;
}

$baseUrl = $argv[1] ?? 'http://localhost/SmartLib';
$stamp = date('YmdHis');
$sessionId = 'acctverify' . bin2hex(random_bytes(4));
$createdUserId = 0;
$createdBookId = 0;
$createdCopyIds = [];
$createdRecordIds = [];
$exitCode = 0;

try {
    $userNumber = 'BAV' . $stamp;
    $email = strtolower($userNumber) . '@smartlib.test';
    $password = password_hash('1234', PASSWORD_DEFAULT);

    $stmt = $conn->prepare(
        "INSERT INTO library_users (user_number, first_name, last_name, email, password, role, status, created_at)
         VALUES (?, 'Account', 'Verifier', ?, ?, 'student', 'active', NOW())"
    );
    if (!$stmt) {
        fail_borrower_account_verify('Unable to prepare temporary user insert.');
    }
    $stmt->bind_param('sss', $userNumber, $email, $password);
    $stmt->execute();
    $createdUserId = (int)$conn->insert_id;
    $stmt->close();

    session_id($sessionId);
    session_start();
    $_SESSION['user_id'] = $createdUserId;
    $_SESSION['user_number'] = $userNumber;
    $_SESSION['role'] = 'student';
    $_SESSION['name'] = 'Account Verifier';
    $_SESSION['last_auth_at'] = time();
    session_write_close();

    $isbn = 'BAV-' . $stamp;
    $title = 'Borrower Account Verification ' . $stamp;
    $stmt = $conn->prepare(
        "INSERT INTO books (isbn, title, author, publisher, year_published, created_at)
         VALUES (?, ?, 'SmartLib Test', 'SmartLib', ?, NOW())"
    );
    if (!$stmt) {
        fail_borrower_account_verify('Unable to prepare temporary book insert.');
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
        fail_borrower_account_verify('Unable to prepare temporary copy insert.');
    }

    $recordStmt = $conn->prepare(
        "INSERT INTO borrow_records (user_id, copy_id, date_borrowed, due_date, date_returned, status, created_at, fine)
         VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)"
    );
    if (!$recordStmt) {
        fail_borrower_account_verify('Unable to prepare temporary borrow insert.');
    }

    $borrowedDate = (new DateTimeImmutable('today'))->modify('-20 days')->format('Y-m-d');
    $overdueDueDate = (new DateTimeImmutable('today'))->modify('-11 days')->format('Y-m-d');
    $currentDueDate = (new DateTimeImmutable('today'))->modify('+5 days')->format('Y-m-d');
    $returnedDate = (new DateTimeImmutable('today'))->modify('-1 day')->format('Y-m-d');
    $nullDate = null;

    $fixtures = [
        ['OVERDUE', 'borrowed', 'borrowed', $overdueDueDate, $nullDate, 0.0],
        ['ACTIVE', 'borrowed', 'borrowed', $currentDueDate, $nullDate, 0.0],
        ['RETURNED', 'available', 'returned', $currentDueDate, $returnedDate, 15.0],
        ['MISSING', 'lost', 'missing', $currentDueDate, $nullDate, 0.0]
    ];

    foreach ($fixtures as [$suffix, $copyStatus, $recordStatus, $dueDate, $returnDate, $fine]) {
        $accession = 'BAV-' . $suffix . '-' . $stamp;
        $copyStmt->bind_param('isss', $createdBookId, $accession, $isbn, $copyStatus);
        $copyStmt->execute();
        $copyId = (int)$conn->insert_id;
        $createdCopyIds[] = $copyId;

        $recordStmt->bind_param('iissssd', $createdUserId, $copyId, $borrowedDate, $dueDate, $returnDate, $recordStatus, $fine);
        $recordStmt->execute();
        $createdRecordIds[] = (int)$conn->insert_id;
    }

    $copyStmt->close();
    $recordStmt->close();

    $payload = fetch_account_payload($baseUrl, $sessionId);
    if (($payload['status'] ?? '') !== 'success') {
        fail_borrower_account_verify('Account payload status is not success.');
    }

    foreach (['borrower', 'summary', 'books', 'active_books', 'returned_books', 'missing_books'] as $key) {
        if (!array_key_exists($key, $payload)) {
            fail_borrower_account_verify('Account payload is missing ' . $key . '.');
        }
    }

    $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
    if ((int)($summary['total_records'] ?? 0) < 4) {
        fail_borrower_account_verify('Account summary did not include all temporary records.');
    }
    if ((int)($summary['current_loans'] ?? 0) < 2) {
        fail_borrower_account_verify('Account summary did not count active loans.');
    }
    if ((int)($summary['returned_books'] ?? 0) < 1) {
        fail_borrower_account_verify('Account summary did not count returned books.');
    }
    if ((int)($summary['missing_books'] ?? 0) < 1) {
        fail_borrower_account_verify('Account summary did not count missing books.');
    }
    if ((int)($summary['overdue_books'] ?? 0) < 1) {
        fail_borrower_account_verify('Account summary did not count overdue books.');
    }
    if ((float)($summary['active_fines'] ?? 0) <= 0) {
        fail_borrower_account_verify('Account summary did not include active overdue fine.');
    }

    $activeBooks = is_array($payload['active_books'] ?? null) ? $payload['active_books'] : [];
    $returnedBooks = is_array($payload['returned_books'] ?? null) ? $payload['returned_books'] : [];
    $missingBooks = is_array($payload['missing_books'] ?? null) ? $payload['missing_books'] : [];

    if (count($activeBooks) < 2 || count($returnedBooks) < 1 || count($missingBooks) < 1) {
        fail_borrower_account_verify('Account payload did not split active, returned, and missing books correctly.');
    }

    $sawOverdue = false;
    foreach ($activeBooks as $book) {
        if (($book['status'] ?? '') === 'overdue' && (float)($book['fine'] ?? 0) > 0 && (int)($book['days_late'] ?? 0) > 0) {
            $sawOverdue = true;
            break;
        }
    }
    if (!$sawOverdue) {
        fail_borrower_account_verify('Account payload did not expose overdue status, fine, and days late.');
    }

    ok_borrower_account_verify('account endpoint returns borrower profile, summary, and split loan history');
    ok_borrower_account_verify('account endpoint syncs overdue status and active fines');
    $exitCode = 0;
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . PHP_EOL);
    $exitCode = 1;
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

exit($exitCode);
