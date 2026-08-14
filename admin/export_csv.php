<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentRole = strtolower(trim((string)($_SESSION['role'] ?? '')));
if (!isset($_SESSION['user_id']) || !in_array($currentRole, ['librarian', 'admin'], true)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

require '../config/db_connect.php';
require_once '../config/borrow_fine_rules.php';

function export_csv_fail(string $message, int $statusCode = 404): void {
    http_response_code($statusCode);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

function export_csv_filename(string $type): string {
    $safe = preg_replace('/[^a-z0-9_\-]/i', '_', $type) ?: 'export';
    return 'smartlib_' . strtolower($safe) . '_' . date('Ymd_His') . '.csv';
}

function export_csv_send(mysqli $conn, string $type, array $headers, string $sql): void {
    $res = $conn->query($sql);
    if (!$res) {
        export_csv_fail('Unable to build export.', 500);
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . export_csv_filename($type) . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    if (!$out) {
        exit;
    }

    fputcsv($out, $headers);
    while ($row = $res->fetch_assoc()) {
        $line = [];
        foreach ($headers as $key) {
            $line[] = (string)($row[$key] ?? '');
        }
        fputcsv($out, $line);
    }

    fclose($out);
    exit;
}

$type = strtolower(trim((string)($_GET['type'] ?? '')));
smartlib_ensure_borrow_renewal_columns($conn);
sync_overdue_status_and_fines($conn);

$exports = [
    'books' => [
        'headers' => ['book_id', 'isbn', 'title', 'author', 'publisher', 'year_published', 'category', 'program', 'location', 'call_number', 'copy_count', 'available_copies', 'borrowed_copies', 'lost_copies', 'created_at'],
        'sql' => "SELECT
                    b.book_id,
                    COALESCE(b.isbn, '') AS isbn,
                    COALESCE(b.title, '') AS title,
                    COALESCE(b.author, '') AS author,
                    COALESCE(b.publisher, '') AS publisher,
                    COALESCE(b.year_published, '') AS year_published,
                    COALESCE(c.category_name, '') AS category,
                    COALESCE(p.program_name, '') AS program,
                    TRIM(CONCAT(COALESCE(ll.section, ''), CASE WHEN ll.shelf_code IS NULL OR ll.shelf_code = '' THEN '' ELSE CONCAT(' / ', ll.shelf_code) END)) AS location,
                    COALESCE(b.call_number, '') AS call_number,
                    COUNT(bc.copy_id) AS copy_count,
                    SUM(CASE WHEN bc.status = 'available' THEN 1 ELSE 0 END) AS available_copies,
                    SUM(CASE WHEN bc.status = 'borrowed' THEN 1 ELSE 0 END) AS borrowed_copies,
                    SUM(CASE WHEN bc.status = 'lost' THEN 1 ELSE 0 END) AS lost_copies,
                    COALESCE(DATE_FORMAT(b.created_at, '%Y-%m-%d %H:%i:%s'), '') AS created_at
                 FROM books b
                 LEFT JOIN categories c ON c.category_id = b.category_id
                 LEFT JOIN programs p ON p.program_id = b.program_id
                 LEFT JOIN library_locations ll ON ll.location_id = b.location_id
                 LEFT JOIN book_copies bc ON bc.book_id = b.book_id
                 GROUP BY b.book_id
                 ORDER BY b.title ASC, b.book_id ASC"
    ],
    'copies' => [
        'headers' => ['copy_id', 'book_id', 'accession_no', 'isbn', 'title', 'author', 'copy_status', 'created_at'],
        'sql' => "SELECT
                    bc.copy_id,
                    bc.book_id,
                    COALESCE(bc.accession_no, '') AS accession_no,
                    COALESCE(bc.isbn, b.isbn, '') AS isbn,
                    COALESCE(b.title, '') AS title,
                    COALESCE(b.author, '') AS author,
                    COALESCE(bc.status, '') AS copy_status,
                    COALESCE(DATE_FORMAT(bc.created_at, '%Y-%m-%d %H:%i:%s'), '') AS created_at
                 FROM book_copies bc
                 LEFT JOIN books b ON b.book_id = bc.book_id
                 ORDER BY b.title ASC, bc.accession_no ASC, bc.copy_id ASC"
    ],
    'users' => [
        'headers' => ['user_id', 'user_number', 'first_name', 'last_name', 'email', 'program', 'role', 'status', 'active_loans', 'total_fines', 'created_at'],
        'sql' => "SELECT
                    u.user_id,
                    COALESCE(u.user_number, '') AS user_number,
                    COALESCE(u.first_name, '') AS first_name,
                    COALESCE(u.last_name, '') AS last_name,
                    COALESCE(u.email, '') AS email,
                    COALESCE(p.program_name, '') AS program,
                    COALESCE(u.role, '') AS role,
                    COALESCE(u.status, '') AS status,
                    SUM(CASE WHEN br.status IN ('borrowed', 'overdue', 'missing') THEN 1 ELSE 0 END) AS active_loans,
                    COALESCE(SUM(br.fine), 0) AS total_fines,
                    COALESCE(DATE_FORMAT(u.created_at, '%Y-%m-%d %H:%i:%s'), '') AS created_at
                 FROM library_users u
                 LEFT JOIN programs p ON p.program_id = u.program_id
                 LEFT JOIN borrow_records br ON br.user_id = u.user_id
                 GROUP BY u.user_id
                 ORDER BY u.role ASC, u.last_name ASC, u.first_name ASC, u.user_id ASC"
    ],
    'borrow_records' => [
        'headers' => ['record_id', 'borrower_id', 'borrower_number', 'borrower_name', 'role', 'book_id', 'title', 'accession_no', 'date_borrowed', 'due_date', 'date_returned', 'status', 'fine', 'renew_count', 'last_renewed_at', 'record_created_at'],
        'sql' => "SELECT
                    br.record_id,
                    br.user_id AS borrower_id,
                    COALESCE(u.user_number, '') AS borrower_number,
                    TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS borrower_name,
                    COALESCE(u.role, '') AS role,
                    COALESCE(b.book_id, 0) AS book_id,
                    COALESCE(b.title, '') AS title,
                    COALESCE(bc.accession_no, '') AS accession_no,
                    COALESCE(br.date_borrowed, '') AS date_borrowed,
                    COALESCE(br.due_date, '') AS due_date,
                    COALESCE(br.date_returned, '') AS date_returned,
                    COALESCE(br.status, '') AS status,
                    COALESCE(br.fine, 0) AS fine,
                    COALESCE(br.renew_count, 0) AS renew_count,
                    COALESCE(DATE_FORMAT(br.last_renewed_at, '%Y-%m-%d %H:%i:%s'), '') AS last_renewed_at,
                    COALESCE(DATE_FORMAT(br.created_at, '%Y-%m-%d %H:%i:%s'), '') AS record_created_at
                 FROM borrow_records br
                 LEFT JOIN library_users u ON u.user_id = br.user_id
                 LEFT JOIN book_copies bc ON bc.copy_id = br.copy_id
                 LEFT JOIN books b ON b.book_id = bc.book_id
                 ORDER BY br.created_at DESC, br.record_id DESC"
    ],
    'overdue_missing' => [
        'headers' => ['record_id', 'borrower_number', 'borrower_name', 'role', 'title', 'accession_no', 'date_borrowed', 'due_date', 'status', 'fine', 'days_late'],
        'sql' => "SELECT
                    br.record_id,
                    COALESCE(u.user_number, '') AS borrower_number,
                    TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS borrower_name,
                    COALESCE(u.role, '') AS role,
                    COALESCE(b.title, '') AS title,
                    COALESCE(bc.accession_no, '') AS accession_no,
                    COALESCE(br.date_borrowed, '') AS date_borrowed,
                    COALESCE(br.due_date, '') AS due_date,
                    COALESCE(br.status, '') AS status,
                    COALESCE(br.fine, 0) AS fine,
                    CASE WHEN br.status = 'overdue' AND br.due_date IS NOT NULL THEN GREATEST(DATEDIFF(CURDATE(), br.due_date), 0) ELSE 0 END AS days_late
                 FROM borrow_records br
                 LEFT JOIN library_users u ON u.user_id = br.user_id
                 LEFT JOIN book_copies bc ON bc.copy_id = br.copy_id
                 LEFT JOIN books b ON b.book_id = bc.book_id
                 WHERE br.status IN ('overdue', 'missing')
                 ORDER BY br.status ASC, br.due_date ASC, br.record_id DESC"
    ]
];

if (!isset($exports[$type])) {
    export_csv_fail('Unknown export type.', 404);
}

$export = $exports[$type];
export_csv_send($conn, $type, $export['headers'], $export['sql']);