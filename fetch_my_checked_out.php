<?php
session_start();
require 'config/db_connect.php';
require_once 'config/borrow_fine_rules.php';

header('Content-Type: application/json');

function respond(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function my_books_cover_path(?string $coverFile): string {
    $coverFile = trim((string)$coverFile);
    if ($coverFile === '') {
        return 'assets/covers/default.jpg';
    }

    if (preg_match('/^(https?:)?\/\//i', $coverFile) || str_starts_with($coverFile, 'assets/')) {
        return $coverFile;
    }

    if (str_contains($coverFile, '/')) {
        return $coverFile;
    }

    return 'assets/covers/' . $coverFile;
}

function my_books_date(?string $raw): string {
    $raw = trim((string)$raw);
    if ($raw === '') {
        return '';
    }

    $ts = strtotime($raw);
    return $ts ? date('M d, Y', $ts) : $raw;
}

function my_books_days_until_due(?string $raw): ?int {
    $raw = trim((string)$raw);
    if ($raw === '') {
        return null;
    }

    $due = smartlib_parse_ymd($raw);
    $today = smartlib_parse_ymd((new DateTimeImmutable('today'))->format('Y-m-d'));
    if (!$due || !$today) {
        return null;
    }

    return (int)$today->diff($due)->format('%r%a');
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    respond(['status' => 'error', 'message' => 'Please sign in first.'], 401);
}

sync_overdue_status_and_fines($conn, $userId);

$userStmt = $conn->prepare(
    "SELECT u.user_id, u.user_number, u.first_name, u.last_name, u.role, p.program_name
     FROM library_users u
     LEFT JOIN programs p ON p.program_id = u.program_id
     WHERE u.user_id = ?
     LIMIT 1"
);

$borrower = [
    'name' => trim((string)($_SESSION['name'] ?? 'Library User')),
    'user_number' => (string)($_SESSION['user_number'] ?? ''),
    'role' => (string)($_SESSION['role'] ?? ''),
    'program' => ''
];

if ($userStmt) {
    $userStmt->bind_param('i', $userId);
    $userStmt->execute();
    $userRes = $userStmt->get_result();
    $user = $userRes ? $userRes->fetch_assoc() : null;
    $userStmt->close();

    if ($user) {
        $name = trim(((string)($user['first_name'] ?? '')) . ' ' . ((string)($user['last_name'] ?? '')));
        if ($name !== '') {
            $borrower['name'] = $name;
        }
        $borrower['user_number'] = (string)($user['user_number'] ?? $borrower['user_number']);
        $borrower['role'] = (string)($user['role'] ?? $borrower['role']);
        $borrower['program'] = (string)($user['program_name'] ?? '');
    }
}

$summary = [
    'total_records' => 0,
    'current_loans' => 0,
    'returned_books' => 0,
    'overdue_books' => 0,
    'missing_books' => 0,
    'total_fines' => 0.0,
    'active_fines' => 0.0
];

$summaryStmt = $conn->prepare(
    "SELECT
        COUNT(*) AS total_records,
        SUM(CASE WHEN status IN ('borrowed', 'overdue') THEN 1 ELSE 0 END) AS current_loans,
        SUM(CASE WHEN status = 'returned' THEN 1 ELSE 0 END) AS returned_books,
        SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) AS overdue_books,
        SUM(CASE WHEN status = 'missing' THEN 1 ELSE 0 END) AS missing_books,
        COALESCE(SUM(fine), 0) AS total_fines,
        COALESCE(SUM(CASE WHEN status IN ('borrowed', 'overdue', 'missing') THEN fine ELSE 0 END), 0) AS active_fines
     FROM borrow_records
     WHERE user_id = ?"
);

if ($summaryStmt) {
    $summaryStmt->bind_param('i', $userId);
    $summaryStmt->execute();
    $summaryRes = $summaryStmt->get_result();
    $summaryRow = $summaryRes ? $summaryRes->fetch_assoc() : null;
    $summaryStmt->close();

    if ($summaryRow) {
        foreach ($summary as $key => $defaultValue) {
            $summary[$key] = is_float($defaultValue)
                ? round((float)($summaryRow[$key] ?? 0), 2)
                : (int)($summaryRow[$key] ?? 0);
        }
    }
}

$sql = "
    SELECT
        br.record_id,
        br.date_borrowed,
        br.due_date,
        br.date_returned,
        br.status,
        br.fine,
        bc.accession_no,
        b.title,
        b.author,
        b.cover
    FROM borrow_records br
    LEFT JOIN book_copies bc ON bc.copy_id = br.copy_id
    LEFT JOIN books b ON b.book_id = bc.book_id
    WHERE br.user_id = ?
    ORDER BY
        CASE
            WHEN br.status = 'overdue' THEN 1
            WHEN br.status = 'borrowed' THEN 2
            WHEN br.status = 'missing' THEN 3
            WHEN br.status = 'returned' THEN 4
            ELSE 5
        END,
        br.created_at DESC,
        br.record_id DESC
    LIMIT 120
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    respond(['status' => 'error', 'message' => 'Unable to load records.'], 500);
}

$stmt->bind_param('i', $userId);
$stmt->execute();
$res = $stmt->get_result();

$books = [];
$active_books = [];
$returned_books = [];
$missing_books = [];

if ($res) {
    while ($row = $res->fetch_assoc()) {
        $status = strtolower((string)($row['status'] ?? 'borrowed'));
        $daysUntilDue = my_books_days_until_due((string)($row['due_date'] ?? ''));
        $daysLate = 0;
        if ($daysUntilDue !== null && $daysUntilDue < 0 && in_array($status, ['borrowed', 'overdue'], true)) {
            $daysLate = abs($daysUntilDue);
        }

        $item = [
            'record_id' => (int)($row['record_id'] ?? 0),
            'title' => (string)($row['title'] ?? 'Untitled'),
            'author' => (string)($row['author'] ?? 'Unknown Author'),
            'accession_no' => (string)($row['accession_no'] ?? 'N/A'),
            'date_borrowed' => (string)($row['date_borrowed'] ?? ''),
            'date_borrowed_label' => my_books_date((string)($row['date_borrowed'] ?? '')),
            'due_date' => (string)($row['due_date'] ?? ''),
            'due_date_label' => my_books_date((string)($row['due_date'] ?? '')),
            'date_returned' => (string)($row['date_returned'] ?? ''),
            'date_returned_label' => my_books_date((string)($row['date_returned'] ?? '')),
            'days_until_due' => $daysUntilDue,
            'days_late' => $daysLate,
            'status' => $status,
            'fine' => round((float)($row['fine'] ?? 0), 2),
            'cover' => my_books_cover_path((string)($row['cover'] ?? ''))
        ];

        $books[] = $item;
        if (in_array($status, ['borrowed', 'overdue'], true)) {
            $active_books[] = $item;
        } elseif ($status === 'returned') {
            $returned_books[] = $item;
        } elseif ($status === 'missing') {
            $missing_books[] = $item;
        }
    }
}

$stmt->close();

respond([
    'status' => 'success',
    'borrower' => $borrower,
    'summary' => $summary,
    'books' => $books,
    'active_books' => $active_books,
    'returned_books' => $returned_books,
    'missing_books' => $missing_books
]);
