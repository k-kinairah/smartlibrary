<?php require 'layout_top.php'; ?>
<?php
require '../config/db_connect.php';
require_once '../config/borrow_fine_rules.php';

function borrower_history_qres(mysqli $conn, string $sql) {
    try {
        return $conn->query($sql);
    } catch (Throwable $e) {
        return false;
    }
}

function borrower_history_status_class(string $status): string {
    $status = strtolower(trim($status));
    if (in_array($status, ['borrowed', 'returned', 'overdue', 'missing'], true)) {
        return 'borrow-record-status-text borrow-record-status-' . $status;
    }
    return 'borrow-record-status-text borrow-record-status-muted';
}

function borrower_history_format_date(?string $raw): string {
    $raw = trim((string)$raw);
    if ($raw === '') {
        return '-';
    }

    $ts = strtotime($raw);
    return $ts ? date('M d, Y', $ts) : $raw;
}

$userId = (int)($_GET['user_id'] ?? 0);
$user = null;
$records = [];
$activeRecords = [];
$returnedRecords = [];
$missingRecords = [];
$totals = [
    'all_records' => 0,
    'active_records' => 0,
    'returned_records' => 0,
    'overdue_records' => 0,
    'missing_records' => 0,
    'total_fine' => 0.0,
    'unpaid_active_fine' => 0.0
];

if ($userId > 0) {
    sync_overdue_status_and_fines($conn, $userId);

    $userStmt = $conn->prepare(
        "SELECT u.user_id, u.user_number, u.first_name, u.last_name, u.email, u.role, u.status, p.program_name
         FROM library_users u
         LEFT JOIN programs p ON p.program_id = u.program_id
         WHERE u.user_id = ?
         LIMIT 1"
    );
    if ($userStmt) {
        $userStmt->bind_param('i', $userId);
        $userStmt->execute();
        $userRes = $userStmt->get_result();
        $user = $userRes ? $userRes->fetch_assoc() : null;
        $userStmt->close();
    }

    $summaryRes = borrower_history_qres(
        $conn,
        "SELECT
            COUNT(*) AS all_records,
            SUM(CASE WHEN status IN ('borrowed', 'overdue') THEN 1 ELSE 0 END) AS active_records,
            SUM(CASE WHEN status = 'returned' THEN 1 ELSE 0 END) AS returned_records,
            SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) AS overdue_records,
            SUM(CASE WHEN status = 'missing' THEN 1 ELSE 0 END) AS missing_records,
            COALESCE(SUM(fine), 0) AS total_fine,
            COALESCE(SUM(CASE WHEN status IN ('borrowed', 'overdue', 'missing') THEN fine ELSE 0 END), 0) AS unpaid_active_fine
         FROM borrow_records
         WHERE user_id = " . (int)$userId
    );
    if ($summaryRes && ($summary = $summaryRes->fetch_assoc())) {
        foreach ($totals as $key => $defaultValue) {
            $totals[$key] = is_float($defaultValue) ? (float)($summary[$key] ?? 0) : (int)($summary[$key] ?? 0);
        }
    }

    $recordsRes = borrower_history_qres(
        $conn,
        "SELECT
            br.record_id,
            br.copy_id,
            br.date_borrowed,
            br.due_date,
            br.date_returned,
            br.status,
            br.fine,
            br.created_at,
            b.book_id,
            COALESCE(b.title, 'Unknown Book') AS title,
            COALESCE(b.author, 'Unknown Author') AS author,
            COALESCE(b.isbn, 'N/A') AS isbn,
            COALESCE(bc.accession_no, 'N/A') AS accession_no,
            GREATEST(DATEDIFF(CURDATE(), br.due_date), 0) AS days_overdue
         FROM borrow_records br
         LEFT JOIN book_copies bc ON bc.copy_id = br.copy_id
         LEFT JOIN books b ON b.book_id = bc.book_id
         WHERE br.user_id = " . (int)$userId . "
         ORDER BY
            FIELD(br.status, 'overdue', 'borrowed', 'missing', 'returned'),
            COALESCE(br.date_borrowed, DATE(br.created_at)) DESC,
            br.record_id DESC
         LIMIT 500"
    );

    if ($recordsRes && $recordsRes->num_rows > 0) {
        while ($record = $recordsRes->fetch_assoc()) {
            $status = strtolower((string)($record['status'] ?? 'borrowed'));
            $records[] = $record;
            if (in_array($status, ['borrowed', 'overdue'], true)) {
                $activeRecords[] = $record;
            } elseif ($status === 'returned') {
                $returnedRecords[] = $record;
            } elseif ($status === 'missing') {
                $missingRecords[] = $record;
            }
        }
    }
}

$borrowerName = 'Borrower Not Found';
if (is_array($user)) {
    $borrowerName = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));
    if ($borrowerName === '') {
        $borrowerName = (string)($user['user_number'] ?? 'Borrower #' . $userId);
    }
}

$backHref = 'manage_users.php';
?>

<div class="page-top borrower-history-top">
    <div>
        <h1>Borrower History</h1>
        <p class="page-subtitle">Complete borrowing record, current loans, overdue items, missing books, and fine totals.</p>
    </div>
    <a href="<?= htmlspecialchars($backHref) ?>" class="filter-reset-btn">Back to Users</a>
</div>

<?php if ($userId <= 0 || !is_array($user)): ?>
    <section class="panel glass-card borrower-history-empty">
        <h2>Borrower not found</h2>
        <p>Select a valid borrower from Manage Users or Borrow Records.</p>
        <a href="manage_users.php" class="btn-primary borrower-history-cta">Open Manage Users</a>
    </section>
<?php else: ?>
    <section class="panel glass-card borrower-profile-panel">
        <div class="borrower-profile-main">
            <div class="borrower-avatar" aria-hidden="true"><?= htmlspecialchars(strtoupper(substr($borrowerName, 0, 1))) ?></div>
            <div>
                <h2><?= htmlspecialchars($borrowerName) ?></h2>
                <div class="borrower-profile-meta">
                    <span><?= htmlspecialchars((string)($user['user_number'] ?? '-')) ?></span>
                    <span><?= htmlspecialchars(ucfirst((string)($user['role'] ?? 'user'))) ?></span>
                    <span><?= htmlspecialchars((string)($user['program_name'] ?? 'General Collection')) ?></span>
                    <span><?= htmlspecialchars(ucfirst((string)($user['status'] ?? 'active'))) ?></span>
                </div>
                <p><?= htmlspecialchars((string)($user['email'] ?? 'No email recorded')) ?></p>
            </div>
        </div>
        <div class="borrower-profile-actions">
            <a class="panel-link-btn" href="borrow_records.php?search=<?= urlencode((string)($user['user_number'] ?? $borrowerName)) ?>">Open Records</a>
            <a class="panel-link-btn" href="manage_users.php?edit_user=<?= (int)$userId ?>">Edit User</a>
        </div>
    </section>

    <section class="stats-grid borrow-status-chips borrower-history-chips">
        <article class="stat-card glass-card borrow-chip"><h3>Total Records</h3><p class="value"><?= number_format((int)$totals['all_records']) ?></p></article>
        <article class="stat-card glass-card borrow-chip"><h3>Current Loans</h3><p class="value"><?= number_format((int)$totals['active_records']) ?></p></article>
        <article class="stat-card glass-card borrow-chip"><h3>Overdue</h3><p class="value"><?= number_format((int)$totals['overdue_records']) ?></p></article>
        <article class="stat-card glass-card borrow-chip"><h3>Missing</h3><p class="value"><?= number_format((int)$totals['missing_records']) ?></p></article>
        <article class="stat-card glass-card borrow-chip"><h3>Active Fine</h3><p class="value">PHP <?= number_format((float)$totals['unpaid_active_fine'], 2) ?></p></article>
    </section>

    <section class="dashboard-chart-grid borrower-history-grid">
        <article class="panel glass-card borrower-history-panel">
            <div class="panel-head"><h2>Current Loans</h2></div>
            <div class="table-wrap borrower-history-table-wrap">
                <table class="data-table borrower-history-table">
                    <thead><tr><th>Book</th><th>Due</th><th>Status</th><th>Fine</th></tr></thead>
                    <tbody>
                    <?php if (!empty($activeRecords)): ?>
                        <?php foreach ($activeRecords as $record): ?>
                            <?php $status = strtolower((string)($record['status'] ?? 'borrowed')); ?>
                            <tr>
                                <td><strong><?= htmlspecialchars((string)$record['title']) ?></strong><small><?= htmlspecialchars((string)$record['accession_no']) ?></small></td>
                                <td><?= htmlspecialchars(borrower_history_format_date((string)$record['due_date'])) ?></td>
                                <td><span class="<?= htmlspecialchars(borrower_history_status_class($status)) ?>"><?= htmlspecialchars(ucfirst($status)) ?></span></td>
                                <td>PHP <?= number_format((float)($record['fine'] ?? 0), 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="4" class="dashboard-empty-cell">No current borrowed books.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>

        <article class="panel glass-card borrower-history-panel">
            <div class="panel-head"><h2>Missing Books</h2></div>
            <div class="table-wrap borrower-history-table-wrap">
                <table class="data-table borrower-history-table">
                    <thead><tr><th>Book</th><th>Borrowed</th><th>Fine</th><th>Record</th></tr></thead>
                    <tbody>
                    <?php if (!empty($missingRecords)): ?>
                        <?php foreach ($missingRecords as $record): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars((string)$record['title']) ?></strong><small><?= htmlspecialchars((string)$record['accession_no']) ?></small></td>
                                <td><?= htmlspecialchars(borrower_history_format_date((string)$record['date_borrowed'])) ?></td>
                                <td>PHP <?= number_format((float)($record['fine'] ?? 0), 2) ?></td>
                                <td>#<?= (int)($record['record_id'] ?? 0) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="4" class="dashboard-empty-cell">No missing books for this borrower.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>
    </section>

    <section class="panel glass-card borrower-history-panel">
        <div class="panel-head"><h2>Full Borrow History</h2></div>
        <div class="table-wrap">
            <table class="data-table borrower-history-table borrower-history-full-table">
                <thead>
                    <tr><th>Record</th><th>Book</th><th>Borrowed</th><th>Due</th><th>Returned</th><th>Status</th><th>Fine</th></tr>
                </thead>
                <tbody>
                    <?php if (!empty($records)): ?>
                        <?php foreach ($records as $record): ?>
                            <?php $status = strtolower((string)($record['status'] ?? 'borrowed')); ?>
                            <tr>
                                <td>#<?= (int)($record['record_id'] ?? 0) ?></td>
                                <td><strong><?= htmlspecialchars((string)$record['title']) ?></strong><small><?= htmlspecialchars((string)$record['author']) ?> / <?= htmlspecialchars((string)$record['accession_no']) ?></small></td>
                                <td><?= htmlspecialchars(borrower_history_format_date((string)$record['date_borrowed'])) ?></td>
                                <td><?= htmlspecialchars(borrower_history_format_date((string)$record['due_date'])) ?></td>
                                <td><?= htmlspecialchars(borrower_history_format_date((string)$record['date_returned'])) ?></td>
                                <td><span class="<?= htmlspecialchars(borrower_history_status_class($status)) ?>"><?= htmlspecialchars(ucfirst($status)) ?></span></td>
                                <td>PHP <?= number_format((float)($record['fine'] ?? 0), 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="7" class="dashboard-empty-cell">No borrow history yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php require 'layout_bottom.php'; ?>
