<?php
session_start();
require 'config/db_connect.php';
require_once 'config/borrow_fine_rules.php';

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
    respond(['status' => 'error', 'message' => 'Please sign in first before renewing a book.'], 401);
}

$allowedBorrowerRoles = ['student', 'faculty', 'librarian', 'admin'];
if (!in_array($userRole, $allowedBorrowerRoles, true)) {
    respond(['status' => 'error', 'message' => 'This account type is not allowed to renew books.'], 403);
}

$recordId = (int)($_POST['record_id'] ?? 0);
if ($recordId <= 0) {
    respond(['status' => 'error', 'message' => 'Invalid loan selection.'], 422);
}

smartlib_ensure_borrow_renewal_columns($conn);
sync_overdue_status_and_fines($conn, $userId);

$policy = smartlib_loan_policy_for_role($userRole);
$loanDays = max(1, (int)($policy['days'] ?? 7));
$maxRenewals = max(0, (int)($policy['max_renewals'] ?? 1));

try {
    $conn->begin_transaction();

    $stmt = $conn->prepare(
        "SELECT br.record_id, br.user_id, br.due_date, br.date_returned, br.status, br.fine,
                COALESCE(br.renew_count, 0) AS renew_count,
                b.title
         FROM borrow_records br
         LEFT JOIN book_copies bc ON bc.copy_id = br.copy_id
         LEFT JOIN books b ON b.book_id = bc.book_id
         WHERE br.record_id = ? AND br.user_id = ?
         LIMIT 1
         FOR UPDATE"
    );

    if (!$stmt) {
        throw new RuntimeException('Unable to load loan.');
    }

    $stmt->bind_param('ii', $recordId, $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $record = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$record) {
        $conn->rollback();
        respond(['status' => 'error', 'message' => 'Loan not found for this account.'], 404);
    }

    $status = strtolower(trim((string)($record['status'] ?? '')));
    $dueDateRaw = (string)($record['due_date'] ?? '');
    $dateReturned = trim((string)($record['date_returned'] ?? ''));
    $fine = round((float)($record['fine'] ?? 0), 2);
    $renewCount = (int)($record['renew_count'] ?? 0);
    $today = new DateTimeImmutable('today');
    $dueDate = smartlib_parse_ymd($dueDateRaw);

    if ($status !== 'borrowed' || $dateReturned !== '') {
        $conn->rollback();
        respond(['status' => 'error', 'message' => 'Only active borrowed books can be renewed.'], 422);
    }

    if (!$dueDate) {
        $conn->rollback();
        respond(['status' => 'error', 'message' => 'This loan has no valid due date. Please ask the librarian for help.'], 422);
    }

    if ($dueDate < $today || $fine > 0) {
        $conn->rollback();
        respond(['status' => 'error', 'message' => 'Overdue loans or loans with fines cannot be renewed online.'], 422);
    }

    if ($renewCount >= $maxRenewals) {
        $conn->rollback();
        respond(['status' => 'error', 'message' => 'This loan has already used its renewal.'], 422);
    }

    $newDueDate = $dueDate->modify('+' . $loanDays . ' days')->format('Y-m-d');
    $update = $conn->prepare(
        "UPDATE borrow_records
         SET due_date = ?, renew_count = renew_count + 1, last_renewed_at = NOW(), fine = 0
         WHERE record_id = ? AND user_id = ? AND status = 'borrowed'"
    );

    if (!$update) {
        throw new RuntimeException('Unable to renew loan.');
    }

    $update->bind_param('sii', $newDueDate, $recordId, $userId);
    $update->execute();
    $updatedRows = (int)$update->affected_rows;
    $update->close();

    if ($updatedRows !== 1) {
        throw new RuntimeException('Renewal could not be saved.');
    }

    $conn->commit();

    respond([
        'status' => 'success',
        'message' => 'Loan renewed successfully.',
        'record_id' => $recordId,
        'title' => (string)($record['title'] ?? 'Book'),
        'due_date' => $newDueDate,
        'due_date_label' => date('M d, Y', strtotime($newDueDate)),
        'renew_count' => $renewCount + 1,
        'max_renewals' => $maxRenewals
    ]);
} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $rollbackError) {
        // Ignore rollback failures so the client still receives JSON.
    }

    respond(['status' => 'error', 'message' => 'Unable to renew this loan right now.'], 500);
}