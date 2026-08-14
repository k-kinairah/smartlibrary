<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/borrow_fine_rules.php';

function fail_overdue_verify(string $message): void {
    throw new RuntimeException($message);
}

function ok_overdue_verify(string $message): void {
    echo "OK: {$message}" . PHP_EOL;
}

function fetch_admin_dashboard(string $baseUrl, string $sessionId): array {
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => 'Cookie: PHPSESSID=' . $sessionId,
            'ignore_errors' => true,
            'timeout' => 15
        ]
    ]);

    $body = @file_get_contents(rtrim($baseUrl, '/') . '/admin/dashboard.php', false, $context);
    $headers = isset($http_response_header) && is_array($http_response_header) ? $http_response_header : [];

    return [
        'status_line' => (string)($headers[0] ?? ''),
        'body' => $body === false ? '' : (string)$body
    ];
}

$baseUrl = $argv[1] ?? 'http://localhost/SmartLib';
$stamp = date('YmdHis');
$sessionId = 'overdueverify' . bin2hex(random_bytes(4));
$createdUserId = 0;
$createdBookId = 0;
$createdCopyId = 0;
$createdRecordId = 0;
$exitCode = 0;

try {
    session_id($sessionId);
    session_start();
    $_SESSION['user_id'] = 1;
    $_SESSION['role'] = 'admin';
    $_SESSION['name'] = 'Overdue Dashboard Verifier';
    session_write_close();

    $userNumber = 'ODV' . $stamp;
    $email = strtolower($userNumber) . '@smartlib.test';
    $password = password_hash('1234', PASSWORD_DEFAULT);

    $stmt = $conn->prepare(
        "INSERT INTO library_users (user_number, first_name, last_name, email, password, role, status, created_at)
         VALUES (?, 'Overdue', 'Verifier', ?, ?, 'student', 'active', NOW())"
    );
    if (!$stmt) {
        fail_overdue_verify('Unable to prepare temporary user insert.');
    }
    $stmt->bind_param('sss', $userNumber, $email, $password);
    $stmt->execute();
    $createdUserId = (int)$conn->insert_id;
    $stmt->close();

    $isbn = 'ODV-' . $stamp;
    $title = 'Overdue Dashboard Verification ' . $stamp;
    $stmt = $conn->prepare(
        "INSERT INTO books (isbn, title, author, publisher, year_published, created_at)
         VALUES (?, ?, 'SmartLib Test', 'SmartLib', ?, NOW())"
    );
    if (!$stmt) {
        fail_overdue_verify('Unable to prepare temporary book insert.');
    }
    $year = date('Y');
    $stmt->bind_param('sss', $isbn, $title, $year);
    $stmt->execute();
    $createdBookId = (int)$conn->insert_id;
    $stmt->close();

    $accession = 'ODV-COPY-' . $stamp;
    $stmt = $conn->prepare(
        "INSERT INTO book_copies (book_id, accession_no, isbn, status, created_at)
         VALUES (?, ?, ?, 'borrowed', NOW())"
    );
    if (!$stmt) {
        fail_overdue_verify('Unable to prepare temporary copy insert.');
    }
    $stmt->bind_param('iss', $createdBookId, $accession, $isbn);
    $stmt->execute();
    $createdCopyId = (int)$conn->insert_id;
    $stmt->close();

    $borrowedDate = (new DateTimeImmutable('today'))->modify('-370 days')->format('Y-m-d');
    $dueDate = (new DateTimeImmutable('today'))->modify('-365 days')->format('Y-m-d');

    $stmt = $conn->prepare(
        "INSERT INTO borrow_records (user_id, copy_id, date_borrowed, due_date, status, created_at, fine)
         VALUES (?, ?, ?, ?, 'borrowed', NOW(), 0)"
    );
    if (!$stmt) {
        fail_overdue_verify('Unable to prepare temporary borrow insert.');
    }
    $stmt->bind_param('iiss', $createdUserId, $createdCopyId, $borrowedDate, $dueDate);
    $stmt->execute();
    $createdRecordId = (int)$conn->insert_id;
    $stmt->close();

    $response = fetch_admin_dashboard($baseUrl, $sessionId);
    if (strpos($response['status_line'], '200') === false) {
        fail_overdue_verify('Dashboard did not return HTTP 200. Status: ' . $response['status_line']);
    }

    $body = $response['body'];
    if (strpos($body, 'Overdue &amp; Fines') === false && strpos($body, 'Overdue & Fines') === false) {
        fail_overdue_verify('Dashboard does not show the overdue fines panel title.');
    }
    if (strpos($body, htmlspecialchars($title, ENT_QUOTES, 'UTF-8')) === false) {
        fail_overdue_verify('Dashboard does not list the temporary overdue book.');
    }

    $fineRes = $conn->query("SELECT status, fine FROM borrow_records WHERE record_id = {$createdRecordId} LIMIT 1");
    $fineRow = $fineRes ? $fineRes->fetch_assoc() : null;
    if (!$fineRow) {
        fail_overdue_verify('Temporary overdue record disappeared before fine assertion.');
    }

    $syncedStatus = strtolower((string)($fineRow['status'] ?? ''));
    $syncedFine = (float)($fineRow['fine'] ?? 0);
    if ($syncedStatus !== 'overdue') {
        fail_overdue_verify('Dashboard did not sync the temporary record to overdue status.');
    }
    if ($syncedFine <= 0) {
        fail_overdue_verify('Dashboard did not calculate a positive overdue fine.');
    }
    if (strpos($body, 'PHP ' . number_format($syncedFine, 2)) === false) {
        fail_overdue_verify('Dashboard does not show the synced overdue fine.');
    }

    ok_overdue_verify('dashboard renders overdue fine summary and overdue table');
    ok_overdue_verify('temporary overdue record appears with expected fine');
    $exitCode = 0;
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . PHP_EOL);
    $exitCode = 1;
} finally {
    if ($createdRecordId > 0) {
        $conn->query('DELETE FROM borrow_records WHERE record_id = ' . (int)$createdRecordId);
    }
    if ($createdCopyId > 0) {
        $conn->query('DELETE FROM book_copies WHERE copy_id = ' . (int)$createdCopyId);
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
