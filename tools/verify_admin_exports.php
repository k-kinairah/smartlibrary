<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function fail_admin_exports_verify(string $message): void {
    throw new RuntimeException($message);
}

function ok_admin_exports_verify(string $message): void {
    echo "OK: {$message}" . PHP_EOL;
}

function fetch_admin_export_url(string $url, string $sessionId): array {
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => 'Cookie: PHPSESSID=' . $sessionId,
            'ignore_errors' => true,
            'timeout' => 15
        ]
    ]);

    $body = @file_get_contents($url, false, $context);
    $headers = isset($http_response_header) && is_array($http_response_header) ? $http_response_header : [];
    return [
        'status_line' => (string)($headers[0] ?? ''),
        'headers' => $headers,
        'body' => $body === false ? '' : (string)$body
    ];
}

function header_contains(array $headers, string $needle): bool {
    foreach ($headers as $header) {
        if (stripos((string)$header, $needle) !== false) {
            return true;
        }
    }
    return false;
}

function csv_first_row(string $csv): array {
    $fh = fopen('php://temp', 'r+');
    if (!$fh) {
        fail_admin_exports_verify('Unable to open temp CSV stream.');
    }
    fwrite($fh, $csv);
    rewind($fh);
    $row = fgetcsv($fh);
    fclose($fh);
    return is_array($row) ? $row : [];
}

$baseUrl = $argv[1] ?? 'http://localhost/SmartLib';
$sessionId = 'exportverify' . bin2hex(random_bytes(4));
$exitCode = 0;

try {
    session_id($sessionId);
    session_start();
    $_SESSION['user_id'] = 1;
    $_SESSION['user_number'] = 'ADMIN001';
    $_SESSION['role'] = 'admin';
    $_SESSION['name'] = 'Export Verifier';
    $_SESSION['last_auth_at'] = time();
    session_write_close();

    $center = fetch_admin_export_url(rtrim($baseUrl, '/') . '/admin/export_center.php', $sessionId);
    if (strpos($center['status_line'], '200') === false) {
        fail_admin_exports_verify('Data Export did not return HTTP 200. Status: ' . $center['status_line']);
    }
    foreach (['Data Export', 'Books Summary', 'Book Copies', 'Borrowers &amp; Staff', 'Borrow Records', 'Overdue &amp; Missing'] as $fragment) {
        if (strpos($center['body'], $fragment) === false) {
            fail_admin_exports_verify('Data Export page missing expected text: ' . $fragment);
        }
    }
    ok_admin_exports_verify('data export page renders all export cards');

    $expectedHeaders = [
        'books' => ['book_id', 'isbn', 'title', 'author', 'publisher', 'year_published', 'category', 'program', 'location', 'call_number', 'copy_count', 'available_copies', 'borrowed_copies', 'lost_copies', 'created_at'],
        'copies' => ['copy_id', 'book_id', 'accession_no', 'isbn', 'title', 'author', 'copy_status', 'created_at'],
        'users' => ['user_id', 'user_number', 'first_name', 'last_name', 'email', 'program', 'role', 'status', 'active_loans', 'total_fines', 'created_at'],
        'borrow_records' => ['record_id', 'borrower_id', 'borrower_number', 'borrower_name', 'role', 'book_id', 'title', 'accession_no', 'date_borrowed', 'due_date', 'date_returned', 'status', 'fine', 'renew_count', 'last_renewed_at', 'record_created_at'],
        'overdue_missing' => ['record_id', 'borrower_number', 'borrower_name', 'role', 'title', 'accession_no', 'date_borrowed', 'due_date', 'status', 'fine', 'days_late']
    ];

    foreach ($expectedHeaders as $type => $headers) {
        $response = fetch_admin_export_url(rtrim($baseUrl, '/') . '/admin/export_csv.php?type=' . urlencode($type), $sessionId);
        if (strpos($response['status_line'], '200') === false) {
            fail_admin_exports_verify("{$type} export did not return HTTP 200. Status: " . $response['status_line']);
        }
        if (!header_contains($response['headers'], 'Content-Type: text/csv')) {
            fail_admin_exports_verify("{$type} export did not return CSV content type.");
        }
        if (!header_contains($response['headers'], 'smartlib_' . $type)) {
            fail_admin_exports_verify("{$type} export did not include expected filename.");
        }
        $actualHeaders = csv_first_row($response['body']);
        if ($actualHeaders !== $headers) {
            fail_admin_exports_verify("{$type} export headers did not match expected columns.");
        }
        if ($type === 'users' && in_array('password', $actualHeaders, true)) {
            fail_admin_exports_verify('Users export includes password column.');
        }
    }

    ok_admin_exports_verify('all CSV exports return expected headers and download metadata');
    ok_admin_exports_verify('users export excludes password data');
    $exitCode = 0;
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . PHP_EOL);
    $exitCode = 1;
} finally {
    $sessionFile = rtrim((string)session_save_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'sess_' . $sessionId;
    if (is_file($sessionFile)) {
        @unlink($sessionFile);
    }
}

exit($exitCode);