<?php
session_start();
require 'config/db_connect.php';

header('Content-Type: application/json');

function respond(array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['status' => 'error', 'message' => 'Invalid request method.'], 405);
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$userRole = strtolower(trim((string)($_SESSION['role'] ?? '')));
if ($userId <= 0) {
    respond(['status' => 'error', 'message' => 'Please sign in first before checking out a book.'], 401);
}

$loanPolicyByRole = [
    'student' => ['days' => 7, 'max_borrows' => 3],
    'faculty' => ['days' => 30, 'max_borrows' => 5],
    'librarian' => ['days' => 7, 'max_borrows' => 5],
    'admin' => ['days' => 7, 'max_borrows' => 5]
];

if (!array_key_exists($userRole, $loanPolicyByRole)) {
    respond(['status' => 'error', 'message' => 'This account type is not allowed to check out from kiosk.'], 403);
}

$loanPolicy = $loanPolicyByRole[$userRole];
$loanDays = (int)($loanPolicy['days'] ?? 7);
$maxBorrows = (int)($loanPolicy['max_borrows'] ?? 3);

$bookId = (int)($_POST['book_id'] ?? 0);
if ($bookId <= 0) {
    respond(['status' => 'error', 'message' => 'Invalid book selection.'], 422);
}

$activeBorrowStmt = $conn->prepare(
    "SELECT COUNT(*) AS active_borrows
     FROM borrow_records
     WHERE user_id = ? AND status IN ('borrowed', 'overdue', 'missing')"
);

if ($activeBorrowStmt) {
    $activeBorrowStmt->bind_param('i', $userId);
    $activeBorrowStmt->execute();
    $activeBorrowRes = $activeBorrowStmt->get_result();
    $activeBorrowRow = $activeBorrowRes ? $activeBorrowRes->fetch_assoc() : null;
    $activeBorrowStmt->close();

    $activeBorrows = (int)($activeBorrowRow['active_borrows'] ?? 0);
    if ($activeBorrows >= $maxBorrows) {
        respond([
            'status' => 'error',
            'message' => "Borrow limit reached. {$userRole} accounts can only borrow up to {$maxBorrows} books at a time."
        ], 422);
    }
}

try {
    $conn->begin_transaction();

    $copyStmt = $conn->prepare(
        "SELECT copy_id, accession_no, isbn
         FROM book_copies
         WHERE book_id = ? AND status = 'available'
         ORDER BY copy_id ASC
         LIMIT 1
         FOR UPDATE"
    );

    if (!$copyStmt) {
        throw new RuntimeException('Unable to initialize checkout.');
    }

    $copyStmt->bind_param('i', $bookId);
    $copyStmt->execute();
    $copyRes = $copyStmt->get_result();
    $copy = $copyRes ? $copyRes->fetch_assoc() : null;
    $copyStmt->close();

    if (!$copy) {
        $conn->rollback();
        respond(['status' => 'error', 'message' => 'No available copies left for this book.']);
    }

    $copyId = (int)($copy['copy_id'] ?? 0);
    $accessionNo = (string)($copy['accession_no'] ?? 'N/A');
    $copyIsbn = (string)($copy['isbn'] ?? 'N/A');

    $updateCopy = $conn->prepare("UPDATE book_copies SET status = 'borrowed' WHERE copy_id = ? AND status = 'available'");
    if (!$updateCopy) {
        throw new RuntimeException('Unable to update copy status.');
    }

    $updateCopy->bind_param('i', $copyId);
    $updateCopy->execute();
    $updatedRows = (int)$updateCopy->affected_rows;
    $updateCopy->close();

    if ($updatedRows !== 1) {
        $conn->rollback();
        respond(['status' => 'error', 'message' => 'Checkout conflict detected. Please try again.']);
    }

    $borrowedDateDb = date('Y-m-d');
    $dueDateDb = date('Y-m-d', strtotime("+{$loanDays} days"));

    $recordId = 0;

    $insertBorrow = $conn->prepare(
        "INSERT INTO borrow_records (user_id, copy_id, date_borrowed, due_date, status, created_at, fine)
         VALUES (?, ?, ?, ?, 'borrowed', NOW(), 0)"
    );

    if ($insertBorrow) {
        $insertBorrow->bind_param('iiss', $userId, $copyId, $borrowedDateDb, $dueDateDb);
        $insertBorrow->execute();
        $recordId = (int)$insertBorrow->insert_id;
        $insertBorrow->close();
    } else {
        $insertBorrowFallback = $conn->prepare(
            "INSERT INTO borrow_records (user_id, copy_id, date_borrowed, due_date, status, created_at)
             VALUES (?, ?, ?, ?, 'borrowed', NOW())"
        );

        if (!$insertBorrowFallback) {
            throw new RuntimeException('Unable to create borrow record.');
        }

        $insertBorrowFallback->bind_param('iiss', $userId, $copyId, $borrowedDateDb, $dueDateDb);
        $insertBorrowFallback->execute();
        $recordId = (int)$insertBorrowFallback->insert_id;
        $insertBorrowFallback->close();
    }

    $countStmt = $conn->prepare(
        "SELECT
            COUNT(*) AS total_copies,
            SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) AS available_copies
         FROM book_copies
         WHERE book_id = ?"
    );

    $totalCopies = 0;
    $availableCopies = 0;

    if ($countStmt) {
        $countStmt->bind_param('i', $bookId);
        $countStmt->execute();
        $countRes = $countStmt->get_result();
        $count = $countRes ? $countRes->fetch_assoc() : null;
        $countStmt->close();

        if ($count) {
            $totalCopies = (int)($count['total_copies'] ?? 0);
            $availableCopies = (int)($count['available_copies'] ?? 0);
        }
    }

    $userName = (string)($_SESSION['name'] ?? 'Student User');
    $userNumber = (string)($_SESSION['user_number'] ?? '');
    $course = '';

    $userStmt = $conn->prepare(
        "SELECT CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) AS full_name,
                COALESCE(u.user_number, '') AS user_number,
                COALESCE(p.program_name, '') AS program_name
         FROM library_users u
         LEFT JOIN programs p ON p.program_id = u.program_id
         WHERE u.user_id = ?
         LIMIT 1"
    );

    if ($userStmt) {
        $userStmt->bind_param('i', $userId);
        $userStmt->execute();
        $userRes = $userStmt->get_result();
        $u = $userRes ? $userRes->fetch_assoc() : null;
        $userStmt->close();

        if ($u) {
            $fullName = trim((string)($u['full_name'] ?? ''));
            if ($fullName !== '') {
                $userName = $fullName;
            }

            $num = trim((string)($u['user_number'] ?? ''));
            if ($num !== '') {
                $userNumber = $num;
            }

            $course = trim((string)($u['program_name'] ?? ''));
        }
    }

    if ($userNumber === '') {
        $userNumber = (string)$userId;
    }

    $availabilityText = max(0, $availableCopies) . ' of ' . max(1, $totalCopies) . ' available';
    $transactionId = $userNumber . '-' . ($recordId > 0 ? $recordId : 'BORROW') . '-' . date('Ymd');

    $conn->commit();

    respond([
        'status' => 'success',
        'message' => 'Book checked out successfully.',
        'receipt' => [
            'record_id' => $recordId,
            'book_id' => $bookId,
            'copy_id' => $copyId,
            'accession_no' => $accessionNo,
            'isbn' => $copyIsbn,
            'user_name' => $userName,
            'user_number' => $userNumber,
            'course' => $course,
            'issued_at' => date('n/j/Y, g:i:s A'),
            'borrowed_date' => date('n/j/Y', strtotime($borrowedDateDb)),
            'due_date' => date('n/j/Y', strtotime($dueDateDb)),
            'loan_days' => $loanDays,
            'transaction_id' => $transactionId,
            'available_copies' => $availableCopies,
            'total_copies' => $totalCopies,
            'availability_text' => $availabilityText
        ]
    ]);
} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $rollbackError) {
        // Ignore rollback errors.
    }

    respond([
        'status' => 'error',
        'message' => 'Checkout failed. Please try again.'
    ], 500);
}



