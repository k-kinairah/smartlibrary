<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/borrow_fine_rules.php';

function fail_borrower_renewal_verify(string $message): void {
    throw new RuntimeException($message);
}

function ok_borrower_renewal_verify(string $message): void {
    echo "OK: {$message}" . PHP_EOL;
}

function fetch_json_with_session(string $url, string $sessionId, string $method = 'GET', array $payload = []): array {
    $body = http_build_query($payload);
    $headers = ['Cookie: PHPSESSID=' . $sessionId];
    if ($method === 'POST') {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $headers[] = 'Content-Length: ' . strlen($body);
    }

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $method === 'POST' ? $body : '',
            'ignore_errors' => true,
            'timeout' => 15
        ]
    ]);

    $raw = @file_get_contents($url, false, $context);
    $responseHeaders = isset($http_response_header) && is_array($http_response_header) ? $http_response_header : [];
    $statusLine = (string)($responseHeaders[0] ?? '');
    $json = json_decode((string)$raw, true);

    if (!is_array($json)) {
        fail_borrower_renewal_verify('Endpoint returned invalid JSON. Status: ' . $statusLine . ' Body: ' . substr((string)$raw, 0, 160));
    }

    return ['status_line' => $statusLine, 'payload' => $json];
}

$baseUrl = $argv[1] ?? 'http://localhost/SmartLib';
$stamp = date('YmdHis');
$sessionId = 'renewverify' . bin2hex(random_bytes(4));
$createdUserId = 0;
$createdBookId = 0;
$createdCopyId = 0;
$createdRecordId = 0;
$exitCode = 0;

try {
    smartlib_ensure_borrow_renewal_columns($conn);

    $userNumber = 'BRV' . $stamp;
    $email = strtolower($userNumber) . '@smartlib.test';
    $password = password_hash('1234', PASSWORD_DEFAULT);

    $stmt = $conn->prepare(
        "INSERT INTO library_users (user_number, first_name, last_name, email, password, role, status, created_at)
         VALUES (?, 'Renewal', 'Verifier', ?, ?, 'student', 'active', NOW())"
    );
    if (!$stmt) {
        fail_borrower_renewal_verify('Unable to prepare temporary user insert.');
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
    $_SESSION['name'] = 'Renewal Verifier';
    $_SESSION['last_auth_at'] = time();
    session_write_close();

    $isbn = 'BRV-' . $stamp;
    $title = 'Borrower Renewal Verification ' . $stamp;
    $year = date('Y');
    $stmt = $conn->prepare(
        "INSERT INTO books (isbn, title, author, publisher, year_published, created_at)
         VALUES (?, ?, 'SmartLib Test', 'SmartLib', ?, NOW())"
    );
    if (!$stmt) {
        fail_borrower_renewal_verify('Unable to prepare temporary book insert.');
    }
    $stmt->bind_param('sss', $isbn, $title, $year);
    $stmt->execute();
    $createdBookId = (int)$conn->insert_id;
    $stmt->close();

    $accession = 'BRV-COPY-' . $stamp;
    $stmt = $conn->prepare(
        "INSERT INTO book_copies (book_id, accession_no, isbn, status, created_at)
         VALUES (?, ?, ?, 'borrowed', NOW())"
    );
    if (!$stmt) {
        fail_borrower_renewal_verify('Unable to prepare temporary copy insert.');
    }
    $stmt->bind_param('iss', $createdBookId, $accession, $isbn);
    $stmt->execute();
    $createdCopyId = (int)$conn->insert_id;
    $stmt->close();

    $borrowedDate = (new DateTimeImmutable('today'))->modify('-2 days')->format('Y-m-d');
    $dueDate = (new DateTimeImmutable('today'))->modify('+3 days')->format('Y-m-d');
    $expectedDueDate = (new DateTimeImmutable($dueDate))->modify('+7 days')->format('Y-m-d');

    $stmt = $conn->prepare(
        "INSERT INTO borrow_records (user_id, copy_id, date_borrowed, due_date, status, created_at, fine, renew_count)
         VALUES (?, ?, ?, ?, 'borrowed', NOW(), 0, 0)"
    );
    if (!$stmt) {
        fail_borrower_renewal_verify('Unable to prepare temporary borrow insert.');
    }
    $stmt->bind_param('iiss', $createdUserId, $createdCopyId, $borrowedDate, $dueDate);
    $stmt->execute();
    $createdRecordId = (int)$conn->insert_id;
    $stmt->close();

    $accountUrl = rtrim($baseUrl, '/') . '/fetch_my_checked_out.php';
    $renewUrl = rtrim($baseUrl, '/') . '/renew_loan.php';

    $before = fetch_json_with_session($accountUrl, $sessionId);
    if (strpos($before['status_line'], '200') === false || ($before['payload']['status'] ?? '') !== 'success') {
        fail_borrower_renewal_verify('Account endpoint did not return success before renewal.');
    }

    $activeBooks = is_array($before['payload']['active_books'] ?? null) ? $before['payload']['active_books'] : [];
    $eligible = null;
    foreach ($activeBooks as $book) {
        if ((int)($book['record_id'] ?? 0) === $createdRecordId) {
            $eligible = $book;
            break;
        }
    }

    if (!$eligible || empty($eligible['can_renew'])) {
        fail_borrower_renewal_verify('Temporary active loan was not marked renewable.');
    }

    $renew = fetch_json_with_session($renewUrl, $sessionId, 'POST', ['record_id' => (string)$createdRecordId]);
    if (strpos($renew['status_line'], '200') === false || ($renew['payload']['status'] ?? '') !== 'success') {
        fail_borrower_renewal_verify('Renew endpoint did not return success. Status: ' . $renew['status_line']);
    }
    if (($renew['payload']['due_date'] ?? '') !== $expectedDueDate) {
        fail_borrower_renewal_verify('Renew endpoint returned the wrong new due date.');
    }

    $res = $conn->query("SELECT due_date, renew_count, last_renewed_at FROM borrow_records WHERE record_id = {$createdRecordId} LIMIT 1");
    $row = $res ? $res->fetch_assoc() : null;
    if (!$row) {
        fail_borrower_renewal_verify('Renewed record disappeared before database assertion.');
    }
    if ((string)($row['due_date'] ?? '') !== $expectedDueDate || (int)($row['renew_count'] ?? 0) !== 1 || trim((string)($row['last_renewed_at'] ?? '')) === '') {
        fail_borrower_renewal_verify('Renew endpoint did not persist due date, renew count, and timestamp.');
    }

    $after = fetch_json_with_session($accountUrl, $sessionId);
    $afterBooks = is_array($after['payload']['active_books'] ?? null) ? $after['payload']['active_books'] : [];
    foreach ($afterBooks as $book) {
        if ((int)($book['record_id'] ?? 0) === $createdRecordId && !empty($book['can_renew'])) {
            fail_borrower_renewal_verify('Renewed loan should not remain renewable after one renewal.');
        }
    }

    $second = fetch_json_with_session($renewUrl, $sessionId, 'POST', ['record_id' => (string)$createdRecordId]);
    if (strpos($second['status_line'], '422') === false || ($second['payload']['status'] ?? '') !== 'error') {
        fail_borrower_renewal_verify('Second renewal was not rejected as expected.');
    }

    ok_borrower_renewal_verify('account endpoint marks eligible active loans as renewable');
    ok_borrower_renewal_verify('renew endpoint extends due date once and blocks a second renewal');
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
