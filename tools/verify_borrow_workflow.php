<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/borrow_fine_rules.php';

function fail_verify(string $message): void {
    throw new RuntimeException($message);
}

function ok_verify(string $message): void {
    echo "OK: {$message}" . PHP_EOL;
}

function scalar_value(mysqli $conn, string $sql): string {
    $res = $conn->query($sql);
    if (!$res) {
        fail_verify('Query failed: ' . $conn->error);
    }

    $row = $res->fetch_row();
    return $row ? (string)$row[0] : '';
}

function post_admin_action(string $baseUrl, string $sessionId, array $payload): array {
    $body = http_build_query($payload);
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", [
                'Content-Type: application/x-www-form-urlencoded',
                'Cookie: PHPSESSID=' . $sessionId,
                'Content-Length: ' . strlen($body)
            ]),
            'content' => $body,
            'ignore_errors' => true,
            'timeout' => 15
        ]
    ]);

    $response = @file_get_contents(rtrim($baseUrl, '/') . '/admin/borrow_records.php', false, $context);
    $headers = isset($http_response_header) && is_array($http_response_header) ? $http_response_header : [];
    $statusLine = $headers[0] ?? '';

    return [
        'status_line' => $statusLine,
        'headers' => $headers,
        'body' => $response === false ? '' : (string)$response
    ];
}

function assert_redirect(array $response, string $label): void {
    $statusLine = (string)($response['status_line'] ?? '');
    $headers = (array)($response['headers'] ?? []);
    $hasLocation = false;

    foreach ($headers as $header) {
        if (stripos((string)$header, 'Location: borrow_records.php') === 0) {
            $hasLocation = true;
            break;
        }
    }

    if (strpos($statusLine, '302') === false || !$hasLocation) {
        fail_verify($label . ' did not redirect back to borrow_records.php. Status: ' . $statusLine);
    }
}

$baseUrl = $argv[1] ?? 'http://localhost/SmartLib';
$stamp = date('YmdHis');
$sessionId = 'borrowverify' . bin2hex(random_bytes(4));

$createdUserId = 0;
$createdBookId = 0;
$createdCopyIds = [];
$createdRecordIds = [];
$exitCode = 0;

try {
    session_id($sessionId);
    session_start();
    $_SESSION['user_id'] = 1;
    $_SESSION['user_number'] = 'ADMIN001';
    $_SESSION['role'] = 'admin';
    $_SESSION['name'] = 'Borrow Workflow Verifier';
    $_SESSION['last_auth_at'] = time();
    session_write_close();

    $programId = (int)scalar_value($conn, 'SELECT COALESCE(MIN(program_id), 1) FROM programs');
    $categoryId = (int)scalar_value($conn, 'SELECT COALESCE(MIN(category_id), 1) FROM categories');
    $locationId = (int)scalar_value($conn, 'SELECT COALESCE(MIN(location_id), 0) FROM library_locations');

    $userNumber = 'CODX-BORROW-' . $stamp;
    $email = strtolower($userNumber) . '@example.test';
    $passwordHash = password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);

    $stmt = $conn->prepare(
        "INSERT INTO library_users (user_number, first_name, last_name, email, password, program_id, role, status, created_at)
         VALUES (?, 'Codex', 'Borrow Verify', ?, ?, ?, 'student', 'active', NOW())"
    );
    if (!$stmt) {
        fail_verify('Unable to prepare temporary user insert.');
    }
    $stmt->bind_param('sssi', $userNumber, $email, $passwordHash, $programId);
    $stmt->execute();
    $createdUserId = (int)$stmt->insert_id;
    $stmt->close();

    $isbn = 'CODX-BORROW-' . $stamp;
    $title = 'CODX Borrow Workflow Test ' . $stamp;
    $callNumber = 'QA 2026 B67';
    $stmt = $conn->prepare(
        "INSERT INTO books (isbn, title, author, publisher, year_published, category_id, program_id, location_id, call_number, created_at)
         VALUES (?, ?, 'Codex Workflow', 'SmartLib QA', '2026', ?, ?, NULLIF(?, 0), ?, NOW())"
    );
    if (!$stmt) {
        fail_verify('Unable to prepare temporary book insert.');
    }
    $stmt->bind_param('ssiiis', $isbn, $title, $categoryId, $programId, $locationId, $callNumber);
    $stmt->execute();
    $createdBookId = (int)$stmt->insert_id;
    $stmt->close();

    $copyStmt = $conn->prepare(
        "INSERT INTO book_copies (book_id, accession_no, isbn, status, created_at)
         VALUES (?, ?, ?, 'borrowed', NOW())"
    );
    if (!$copyStmt) {
        fail_verify('Unable to prepare temporary copy insert.');
    }

    $recordStmt = $conn->prepare(
        "INSERT INTO borrow_records (user_id, copy_id, date_borrowed, due_date, status, created_at, fine)
         VALUES (?, ?, ?, ?, ?, NOW(), 0)"
    );
    if (!$recordStmt) {
        fail_verify('Unable to prepare temporary borrow insert.');
    }

    $borrowedDate = date('Y-m-d', strtotime('-12 days'));
    $overdueDueDate = date('Y-m-d', strtotime('-5 days'));
    $normalDueDate = date('Y-m-d', strtotime('+7 days'));

    $returnAccession = 'CODX-RETURN-' . $stamp;
    $copyStmt->bind_param('iss', $createdBookId, $returnAccession, $isbn);
    $copyStmt->execute();
    $returnCopyId = (int)$copyStmt->insert_id;
    $createdCopyIds[] = $returnCopyId;

    $returnStatus = 'overdue';
    $recordStmt->bind_param('iisss', $createdUserId, $returnCopyId, $borrowedDate, $overdueDueDate, $returnStatus);
    $recordStmt->execute();
    $returnRecordId = (int)$recordStmt->insert_id;
    $createdRecordIds[] = $returnRecordId;

    $missingAccession = 'CODX-MISSING-' . $stamp;
    $copyStmt->bind_param('iss', $createdBookId, $missingAccession, $isbn);
    $copyStmt->execute();
    $missingCopyId = (int)$copyStmt->insert_id;
    $createdCopyIds[] = $missingCopyId;

    $missingStatus = 'borrowed';
    $recordStmt->bind_param('iisss', $createdUserId, $missingCopyId, $borrowedDate, $normalDueDate, $missingStatus);
    $recordStmt->execute();
    $missingRecordId = (int)$recordStmt->insert_id;
    $createdRecordIds[] = $missingRecordId;

    $copyStmt->close();
    $recordStmt->close();

    ok_verify('temporary borrow data created');

    $returnResponse = post_admin_action($baseUrl, $sessionId, [
        'record_action' => 'mark_returned',
        'record_id' => (string)$returnRecordId
    ]);
    assert_redirect($returnResponse, 'mark_returned');

    $res = $conn->query(
        "SELECT br.status, br.date_returned, br.fine, bc.status AS copy_status
         FROM borrow_records br
         JOIN book_copies bc ON bc.copy_id = br.copy_id
         WHERE br.record_id = {$returnRecordId}
         LIMIT 1"
    );
    $returned = $res ? $res->fetch_assoc() : null;
    if (!$returned) {
        fail_verify('Returned record disappeared before assertions.');
    }

    $expectedFine = smartlib_overdue_fine_amount($overdueDueDate, date('Y-m-d'));
    if (($returned['status'] ?? '') !== 'returned') {
        fail_verify('mark_returned did not set borrow record status to returned.');
    }
    if (($returned['copy_status'] ?? '') !== 'available') {
        fail_verify('mark_returned did not set copy status to available.');
    }
    if (trim((string)($returned['date_returned'] ?? '')) === '') {
        fail_verify('mark_returned did not set date_returned.');
    }
    if (abs((float)($returned['fine'] ?? 0) - $expectedFine) > 0.01) {
        fail_verify('mark_returned fine mismatch.');
    }
    ok_verify('mark_returned updates record, copy status, returned date, and fine');

    $missingResponse = post_admin_action($baseUrl, $sessionId, [
        'record_action' => 'mark_missing',
        'record_id' => (string)$missingRecordId
    ]);
    assert_redirect($missingResponse, 'mark_missing');

    $res = $conn->query(
        "SELECT br.status, br.date_returned, bc.status AS copy_status
         FROM borrow_records br
         JOIN book_copies bc ON bc.copy_id = br.copy_id
         WHERE br.record_id = {$missingRecordId}
         LIMIT 1"
    );
    $missing = $res ? $res->fetch_assoc() : null;
    if (!$missing) {
        fail_verify('Missing record disappeared before assertions.');
    }

    if (($missing['status'] ?? '') !== 'missing') {
        fail_verify('mark_missing did not set borrow record status to missing.');
    }
    if (($missing['copy_status'] ?? '') !== 'lost') {
        fail_verify('mark_missing did not set copy status to lost.');
    }
    if (trim((string)($missing['date_returned'] ?? '')) !== '') {
        fail_verify('mark_missing should leave date_returned empty.');
    }
    ok_verify('mark_missing updates record and copy status');

    echo 'Borrow workflow verification passed.' . PHP_EOL;
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
