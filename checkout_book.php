<?php
require_once __DIR__ . '/config/request_input.php'; // smartlib_request_string(): arrays in scalar params count as empty
require_once __DIR__ . '/config/session_start.php'; // smartlib_session_start(): Secure cookie on HTTPS
smartlib_session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0'); // per-patron response: never stored
header('Pragma: no-cache');
require 'config/db_connect.php';
require_once 'config/borrow_fine_rules.php';
require_once 'config/loan_policy.php';
require_once 'config/library_card_helpers.php';

header('Content-Type: application/json');

function respond(array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['status' => 'error', 'message' => 'Invalid request method.'], 405);
}

// Anti-forgery: Origin + kiosk token (config/request_guard.php).
require_once 'config/request_guard.php';
smartlib_request_guard([SMARTLIB_KIOSK_CSRF_KEY]);

require_once 'config/session_guard.php';
$userId = smartlib_active_session_user_id($conn); // 0 if signed out, inactive or deleted
$userRole = strtolower(trim((string)($_SESSION['role'] ?? '')));
if ($userId <= 0) {
    respond(smartlib_session_signed_out_payload('Please sign in first before borrowing a book.'), 401);
}

$allowedBorrowerRoles = ['student', 'faculty', 'librarian', 'admin'];
if (!in_array($userRole, $allowedBorrowerRoles, true)) {
    respond(['status' => 'error', 'message' => 'This account type is not allowed to borrow from the kiosk.'], 403);
}

$bookId = smartlib_request_int($_POST, 'book_id');
if ($bookId <= 0) {
    respond(['status' => 'error', 'message' => 'Invalid book selection.'], 422);
}

// Loan length follows the book's section (config/loan_policy.php); borrow
// limit still follows the role. Room-use-only books are refused here,
// before any copy is locked or any row is written.
$bookSection = smartlib_resolve_book_section($conn, $bookId);
$loanPolicy = smartlib_loan_policy_for_book($userRole, $bookSection);
if (!empty($loanPolicy['room_only'])) {
    respond(['status' => 'error', 'message' => 'This book is for room use only and cannot be borrowed.'], 403);
}
$loanDays = (int)($loanPolicy['days'] ?? 7);
$maxBorrows = (int)($loanPolicy['max_borrows'] ?? 3);

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
        // 'code' lets filters.js pick the warning pop-up without matching
        // the wording.
        $roleLabel = ucfirst(strtolower(trim((string)$userRole)));
        respond([
            'status' => 'error',
            'code' => 'borrow_limit',
            'message' => "{$roleLabel} accounts can borrow up to {$maxBorrows} books at a time. Return a book to borrow another."
        ], 422);
    }
}

// A checkout that loses an InnoDB deadlock or lock-wait timeout (for
// example against a librarian marking one of the same copies returned, whose
// single statement locks loan -> copy -> book -> user) is retried, up to two
// more times, instead of answering "Borrowing failed". Every attempt starts
// from scratch inside a new transaction.
for ($checkoutAttempt = 1; ; $checkoutAttempt++) {
    try {
        $conn->begin_transaction();

        // Borrower row first (security review 2026-11-24, SR-10): parallel
        // checkouts by one account queue here, so the count below sees every
        // loan the others have just made and the limit cannot be overshot.
        // Lock order here: library_users -> book_copies -> new borrow_records
        // row. toggle_favorite.php locks library_users only (same first
        // lock); renew_loan.php locks one borrow_records row with its copy
        // and book, never the user row, so neither can deadlock with this.
        // admin/borrow_records.php (mark returned / missing) locks loan ->
        // copy -> book -> user in one statement; the rare deadlock with it
        // is detected by InnoDB and retried by the loop around this block.
        $lockUser = $conn->prepare('SELECT user_id FROM library_users WHERE user_id = ? FOR UPDATE');
        if (!$lockUser) {
            throw new RuntimeException('Unable to initialize checkout.');
        }
        $lockUser->bind_param('i', $userId);
        $lockUser->execute();
        $lockUser->store_result();
        $lockUser->close();

        $lockedCount = $conn->prepare(
            "SELECT COUNT(*) AS active_borrows
             FROM borrow_records
             WHERE user_id = ? AND status IN ('borrowed', 'overdue', 'missing')"
        );
        if (!$lockedCount) {
            throw new RuntimeException('Unable to initialize checkout.');
        }
        $lockedCount->bind_param('i', $userId);
        $lockedCount->execute();
        $lockedCountRow = $lockedCount->get_result()->fetch_assoc();
        $lockedCount->close();
        if ((int)($lockedCountRow['active_borrows'] ?? 0) >= $maxBorrows) {
            $conn->rollback();
            $roleLabel = ucfirst(strtolower(trim((string)$userRole)));
            respond([
                'status' => 'error',
                'code' => 'borrow_limit',
                'message' => "{$roleLabel} accounts can borrow up to {$maxBorrows} books at a time. Return a book to borrow another."
            ], 422);
        }

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
            respond(['status' => 'error', 'message' => 'Borrowing conflict detected. Please try again.']);
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
        $courseLabel = 'Course';

        $userStmt = $conn->prepare(
            // program_name can now hold a faculty/staff department (department
            // first), else a Junior/Senior High student's level, else the program.
            "SELECT CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) AS full_name,
                    COALESCE(u.user_number, '') AS user_number,
                    COALESCE(NULLIF(d.department_name, ''), " . library_card_school_level_label_sql('u.school_level') . ", p.program_name, '') AS program_name,
                    COALESCE(u.role, '') AS role,
                    COALESCE(u.school_level, '') AS school_level
             FROM library_users u
             LEFT JOIN programs p ON p.program_id = u.program_id
             LEFT JOIN departments d ON d.department_id = u.department_id
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
                $userRoleLower = strtolower((string)($u['role'] ?? ''));
                $userLevel = (string)($u['school_level'] ?? '');
                if ($userRoleLower === 'faculty') {
                    $courseLabel = 'Department';
                } elseif ($userRoleLower === 'student' && ($userLevel === 'jhs' || $userLevel === 'shs')) {
                    // "Level: Junior High School" / "Level: Senior High School".
                    $courseLabel = 'Level';
                } else {
                    $courseLabel = 'Course';
                }
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
            'message' => 'Book borrowed successfully.',
            'receipt' => [
                'record_id' => $recordId,
                'book_id' => $bookId,
                'copy_id' => $copyId,
                'accession_no' => $accessionNo,
                'isbn' => $copyIsbn,
                'user_name' => $userName,
                'user_number' => $userNumber,
                'course' => $course,
                'course_label' => $courseLabel,
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

        if ($checkoutAttempt < 3 && $e instanceof mysqli_sql_exception && in_array((int)$e->getCode(), [1213, 1205], true)) {
            usleep(random_int(20000, 80000));
            continue;
        }
        error_log('SmartLib checkout failed for user ' . $userId . ': ' . get_class($e) . ': ' . $e->getMessage());

        respond([
            'status' => 'error',
            'message' => 'Borrowing failed. Please try again.'
        ], 500);
    }
}



