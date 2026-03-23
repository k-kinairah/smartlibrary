<?php require 'layout_top.php'; ?>
<?php
require '../config/db_connect.php';

function qres(mysqli $conn, string $sql) {
    try {
        return $conn->query($sql);
    } catch (Throwable $e) {
        return false;
    }
}

function set_borrow_flash(string $type, string $message): void {
    $_SESSION['borrow_flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_action'])) {
    $action = trim((string)($_POST['record_action'] ?? ''));
    $recordId = (int)($_POST['record_id'] ?? 0);
    $allowedActions = ['mark_returned', 'mark_missing'];

    if ($recordId > 0 && in_array($action, $allowedActions, true)) {
        try {
            $conn->begin_transaction();

            $recordStmt = $conn->prepare(
                "SELECT record_id, copy_id, status
                 FROM borrow_records
                 WHERE record_id = ?
                 LIMIT 1
                 FOR UPDATE"
            );

            if (!$recordStmt) {
                throw new RuntimeException('Unable to load borrow record.');
            }

            $recordStmt->bind_param('i', $recordId);
            $recordStmt->execute();
            $recordRes = $recordStmt->get_result();
            $record = $recordRes ? $recordRes->fetch_assoc() : null;
            $recordStmt->close();

            if (!$record) {
                throw new RuntimeException('Borrow record not found.');
            }

            $currentStatus = strtolower(trim((string)($record['status'] ?? 'borrowed')));
            $copyId = (int)($record['copy_id'] ?? 0);

            if ($copyId <= 0) {
                throw new RuntimeException('Missing copy reference for this borrow record.');
            }

            if ($action === 'mark_returned') {
                if ($currentStatus === 'returned') {
                    $conn->rollback();
                    set_borrow_flash('info', 'This record is already marked as returned.');
                    header('Location: borrow_records.php');
                    exit;
                }

                $returnDate = date('Y-m-d');

                $updateRecord = $conn->prepare(
                    "UPDATE borrow_records
                     SET status = 'returned',
                         date_returned = ?
                     WHERE record_id = ?"
                );

                if (!$updateRecord) {
                    throw new RuntimeException('Unable to update borrow record.');
                }

                $updateRecord->bind_param('si', $returnDate, $recordId);
                $updateRecord->execute();
                $updateRecord->close();

                $updateCopy = $conn->prepare(
                    "UPDATE book_copies
                     SET status = 'available'
                     WHERE copy_id = ?"
                );

                if (!$updateCopy) {
                    throw new RuntimeException('Unable to update book copy status.');
                }

                $updateCopy->bind_param('i', $copyId);
                $updateCopy->execute();
                $updateCopy->close();

                $conn->commit();
                set_borrow_flash('success', 'Book return has been recorded successfully.');
            }

            if ($action === 'mark_missing') {
                if ($currentStatus === 'missing') {
                    $conn->rollback();
                    set_borrow_flash('info', 'This record is already marked as missing.');
                    header('Location: borrow_records.php');
                    exit;
                }

                if ($currentStatus === 'returned') {
                    $conn->rollback();
                    set_borrow_flash('error', 'Returned records cannot be marked as missing.');
                    header('Location: borrow_records.php');
                    exit;
                }

                $updateRecord = $conn->prepare(
                    "UPDATE borrow_records
                     SET status = 'missing',
                         date_returned = NULL
                     WHERE record_id = ?"
                );

                if (!$updateRecord) {
                    throw new RuntimeException('Unable to update borrow record.');
                }

                $updateRecord->bind_param('i', $recordId);
                $updateRecord->execute();
                $updateRecord->close();

                $updateCopy = $conn->prepare(
                    "UPDATE book_copies
                     SET status = 'lost'
                     WHERE copy_id = ?"
                );

                if (!$updateCopy) {
                    throw new RuntimeException('Unable to update book copy status.');
                }

                $updateCopy->bind_param('i', $copyId);
                $updateCopy->execute();
                $updateCopy->close();

                $conn->commit();
                set_borrow_flash('success', 'Record has been marked as missing and copy status set to lost.');
            }
        } catch (Throwable $e) {
            try {
                $conn->rollback();
            } catch (Throwable $rollbackError) {
                // Ignore rollback errors.
            }

            set_borrow_flash('error', 'Action failed. Please try again.');
        }
    } else {
        set_borrow_flash('error', 'Invalid request.');
    }

    header('Location: borrow_records.php');
    exit;
}

$flash = $_SESSION['borrow_flash'] ?? null;
unset($_SESSION['borrow_flash']);

$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');

$where = ['1=1'];

if ($search !== '') {
    $safe = $conn->real_escape_string($search);
    $where[] = "(
        br.record_id LIKE '%$safe%'
        OR u.user_number LIKE '%$safe%'
        OR u.first_name LIKE '%$safe%'
        OR u.last_name LIKE '%$safe%'
        OR b.title LIKE '%$safe%'
        OR bc.accession_no LIKE '%$safe%'
    )";
}

$allowedStatus = ['borrowed', 'returned', 'overdue', 'missing'];
if (in_array($status, $allowedStatus, true)) {
    $safeStatus = $conn->real_escape_string($status);
    $where[] = "br.status = '$safeStatus'";
}

if ($dateFrom !== '') {
    $safeFrom = $conn->real_escape_string($dateFrom);
    $where[] = "COALESCE(br.date_borrowed, DATE(br.created_at)) >= '$safeFrom'";
}

if ($dateTo !== '') {
    $safeTo = $conn->real_escape_string($dateTo);
    $where[] = "COALESCE(br.date_borrowed, DATE(br.created_at)) <= '$safeTo'";
}

$whereSql = implode(' AND ', $where);

$totalRecords = 0;
$borrowedCount = 0;
$returnedCount = 0;
$overdueCount = 0;
$missingCount = 0;

$resTotal = qres($conn, 'SELECT COUNT(*) AS total FROM borrow_records');
if ($resTotal && ($r = $resTotal->fetch_assoc())) {
    $totalRecords = (int)($r['total'] ?? 0);
}

$resBorrowed = qres($conn, "SELECT COUNT(*) AS total FROM borrow_records WHERE status='borrowed'");
if ($resBorrowed && ($r = $resBorrowed->fetch_assoc())) {
    $borrowedCount = (int)($r['total'] ?? 0);
}

$resReturned = qres($conn, "SELECT COUNT(*) AS total FROM borrow_records WHERE status='returned'");
if ($resReturned && ($r = $resReturned->fetch_assoc())) {
    $returnedCount = (int)($r['total'] ?? 0);
}

$resOverdue = qres($conn, "SELECT COUNT(*) AS total FROM borrow_records WHERE status='overdue'");
if ($resOverdue && ($r = $resOverdue->fetch_assoc())) {
    $overdueCount = (int)($r['total'] ?? 0);
}

$resMissing = qres($conn, "SELECT COUNT(*) AS total FROM borrow_records WHERE status='missing'");
if ($resMissing && ($r = $resMissing->fetch_assoc())) {
    $missingCount = (int)($r['total'] ?? 0);
}

$records = qres(
    $conn,
    "
    SELECT
        br.record_id,
        br.user_id,
        br.copy_id,
        br.date_borrowed,
        br.due_date,
        br.date_returned,
        br.fine,
        br.status,
        br.created_at,
        u.user_number,
        u.first_name,
        u.last_name,
        b.title,
        b.isbn,
        bc.accession_no
    FROM borrow_records br
    LEFT JOIN library_users u ON br.user_id = u.user_id
    LEFT JOIN book_copies bc ON br.copy_id = bc.copy_id
    LEFT JOIN books b ON bc.book_id = b.book_id
    WHERE $whereSql
    ORDER BY COALESCE(br.date_borrowed, DATE(br.created_at)) DESC, br.record_id DESC
    LIMIT 250
    "
);
?>

<div class="page-top">
    <h1>Borrow Records</h1>
</div>

<?php if (is_array($flash) && !empty($flash['message'])): ?>
    <section class="panel glass-card borrow-flash borrow-flash-<?= htmlspecialchars((string)($flash['type'] ?? 'info')) ?>">
        <?= htmlspecialchars((string)$flash['message']) ?>
    </section>
<?php endif; ?>

<section class="stats-grid">
    <article class="stat-card glass-card">
        <h3>Total Records</h3>
        <p class="value"><?= number_format($totalRecords) ?></p>
    </article>
    <article class="stat-card glass-card">
        <h3>Borrowed</h3>
        <p class="value"><?= number_format($borrowedCount) ?></p>
    </article>
    <article class="stat-card glass-card">
        <h3>Returned</h3>
        <p class="value"><?= number_format($returnedCount) ?></p>
    </article>
    <article class="stat-card glass-card">
        <h3>Overdue</h3>
        <p class="value"><?= number_format($overdueCount) ?></p>
    </article>
    <article class="stat-card glass-card">
        <h3>Missing</h3>
        <p class="value"><?= number_format($missingCount) ?></p>
    </article>
</section>

<section class="panel glass-card">
    <form method="GET" class="filters-inline borrow-filters">
        <input type="text" name="search" placeholder="Search user, book, accession, record #" value="<?= htmlspecialchars($search) ?>">

        <select name="status">
            <option value="">All Status</option>
            <option value="borrowed" <?= $status === 'borrowed' ? 'selected' : '' ?>>Borrowed</option>
            <option value="returned" <?= $status === 'returned' ? 'selected' : '' ?>>Returned</option>
            <option value="overdue" <?= $status === 'overdue' ? 'selected' : '' ?>>Overdue</option>
            <option value="missing" <?= $status === 'missing' ? 'selected' : '' ?>>Missing</option>
        </select>

        <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
        <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">

        <button type="submit" class="btn-primary">Apply</button>
        <a href="borrow_records.php" class="btn-status activate">Reset</a>
    </form>
</section>

<section class="panel glass-card">
    <div class="table-wrap">
        <table class="data-table borrow-table">
            <thead>
                <tr>
                    <th>Record #</th>
                    <th>User</th>
                    <th>Book</th>
                    <th>Borrowed</th>
                    <th>Due</th>
                    <th>Returned</th>
                    <th>Status</th>
                    <th>Fine</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($records && $records->num_rows > 0): ?>
                    <?php while ($row = $records->fetch_assoc()): ?>
                        <?php
                            $rid = (int)($row['record_id'] ?? 0);
                            $statusVal = strtolower((string)($row['status'] ?? 'borrowed'));
                            $borrower = trim(((string)($row['first_name'] ?? '')) . ' ' . ((string)($row['last_name'] ?? '')));
                            if ($borrower === '') {
                                $borrower = 'Unknown User';
                            }
                            $userNo = (string)($row['user_number'] ?? '-');
                            $title = (string)($row['title'] ?? 'Unknown Book');
                            $isbn = (string)($row['isbn'] ?? 'N/A');
                            $accession = (string)($row['accession_no'] ?? 'N/A');
                            $fine = number_format((float)($row['fine'] ?? 0), 2);
                            $canMarkReturned = in_array($statusVal, ['borrowed', 'overdue', 'missing'], true);
                            $canMarkMissing = in_array($statusVal, ['borrowed', 'overdue'], true);
                        ?>
                        <tr class="borrow-row" data-detail="detail-<?= $rid ?>">
                            <td>#<?= $rid ?></td>
                            <td>
                                <div><?= htmlspecialchars($borrower) ?></div>
                                <small><?= htmlspecialchars($userNo) ?></small>
                            </td>
                            <td><?= htmlspecialchars($title) ?></td>
                            <td><?= htmlspecialchars((string)($row['date_borrowed'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($row['due_date'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($row['date_returned'] ?? '-')) ?></td>
                            <td><span class="badge status-<?= htmlspecialchars($statusVal) ?>"><?= htmlspecialchars(ucfirst($statusVal)) ?></span></td>
                            <td><?= htmlspecialchars($fine) ?></td>
                            <td>
                                <div class="borrow-action-row">
                                    <?php if ($canMarkReturned): ?>
                                        <form method="POST" class="row-action-form">
                                            <input type="hidden" name="record_action" value="mark_returned">
                                            <input type="hidden" name="record_id" value="<?= $rid ?>">
                                            <button type="submit" class="borrow-action-btn">Mark Returned</button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($canMarkMissing): ?>
                                        <form method="POST" class="row-action-form">
                                            <input type="hidden" name="record_action" value="mark_missing">
                                            <input type="hidden" name="record_id" value="<?= $rid ?>">
                                            <button type="submit" class="borrow-action-btn missing">Mark Missing</button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if (!$canMarkReturned && !$canMarkMissing): ?>
                                        <span class="borrow-action-muted">-</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <tr id="detail-<?= $rid ?>" class="borrow-detail-row" hidden>
                            <td colspan="9">
                                <div class="borrow-detail-grid">
                                    <div><strong>ISBN:</strong> <?= htmlspecialchars($isbn) ?></div>
                                    <div><strong>Accession No:</strong> <?= htmlspecialchars($accession) ?></div>
                                    <div><strong>User ID:</strong> <?= (int)($row['user_id'] ?? 0) ?></div>
                                    <div><strong>Copy ID:</strong> <?= (int)($row['copy_id'] ?? 0) ?></div>
                                    <div><strong>Created At:</strong> <?= htmlspecialchars((string)($row['created_at'] ?? '-')) ?></div>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="9">No borrow records found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.borrow-row').forEach(function (row) {
        row.addEventListener('click', function (e) {
            if (e.target.closest('.row-action-form')) {
                return;
            }

            const id = row.getAttribute('data-detail');
            const detail = document.getElementById(id);
            if (!detail) return;
            detail.hidden = !detail.hidden;
            row.classList.toggle('expanded', !detail.hidden);
        });
    });
});
</script>

<?php require 'layout_bottom.php'; ?>
