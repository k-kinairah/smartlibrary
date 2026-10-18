<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentRole = strtolower(trim((string)($_SESSION['role'] ?? '')));
if (!isset($_SESSION['user_id']) || !in_array($currentRole, ['librarian', 'admin'], true)) {
    header('Location: ../index.php');
    exit;
}
require '../config/db_connect.php';
require_once '../config/borrow_fine_rules.php';
require_once '../config/admin_audit.php';

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
                "SELECT
                    br.record_id,
                    br.copy_id,
                    br.user_id,
                    br.status,
                    br.due_date,
                    bc.accession_no,
                    b.book_id,
                    b.title AS book_title,
                    CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) AS borrower_name,
                    u.user_number AS borrower_number
                 FROM borrow_records br
                 LEFT JOIN book_copies bc ON bc.copy_id = br.copy_id
                 LEFT JOIN books b ON b.book_id = bc.book_id
                 LEFT JOIN library_users u ON u.user_id = br.user_id
                 WHERE br.record_id = ?
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
                $dueDate = (string)($record['due_date'] ?? '');
                $returnFine = smartlib_overdue_fine_amount($dueDate, $returnDate);

                $updateRecord = $conn->prepare(
                    "UPDATE borrow_records
                     SET status = 'returned',
                         date_returned = ?,
                         fine = ?
                     WHERE record_id = ?"
                );

                if (!$updateRecord) {
                    throw new RuntimeException('Unable to update borrow record.');
                }

                $updateRecord->bind_param('sdi', $returnDate, $returnFine, $recordId);
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

                smartlib_admin_audit_log($conn, 'returned', 'borrow_record', $recordId, (string)($record['book_title'] ?? 'Borrow Record #' . $recordId), [
                    'record_id' => $recordId,
                    'copy_id' => $copyId,
                    'book_id' => (int)($record['book_id'] ?? 0),
                    'accession_no' => (string)($record['accession_no'] ?? ''),
                    'borrower' => trim((string)($record['borrower_name'] ?? '')),
                    'borrower_number' => (string)($record['borrower_number'] ?? ''),
                    'previous_status' => $currentStatus,
                    'new_status' => 'returned',
                    'fine' => $returnFine
                ]);

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

                smartlib_admin_audit_log($conn, 'missing', 'borrow_record', $recordId, (string)($record['book_title'] ?? 'Borrow Record #' . $recordId), [
                    'record_id' => $recordId,
                    'copy_id' => $copyId,
                    'book_id' => (int)($record['book_id'] ?? 0),
                    'accession_no' => (string)($record['accession_no'] ?? ''),
                    'borrower' => trim((string)($record['borrower_name'] ?? '')),
                    'borrower_number' => (string)($record['borrower_number'] ?? ''),
                    'previous_status' => $currentStatus,
                    'new_status' => 'missing',
                    'copy_status' => 'lost'
                ]);
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

sync_overdue_status_and_fines($conn);

$flash = $_SESSION['borrow_flash'] ?? null;
unset($_SESSION['borrow_flash']);

$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');
$dateFilter = trim($_GET['date'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');

if ($dateFilter !== '') {
    $dateFrom = $dateFilter;
    $dateTo = $dateFilter;
} elseif ($dateFrom !== '' && $dateFrom === $dateTo) {
    $dateFilter = $dateFrom;
}

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

$activeStatus = in_array($status, $allowedStatus, true) ? $status : '';
$baseFilterParams = [
    'search' => $search,
    'date' => $dateFilter,
    'date_from' => $dateFilter === '' ? $dateFrom : '',
    'date_to' => $dateFilter === '' ? $dateTo : ''
];

$buildBorrowRecordsUrl = static function (array $params): string {
    $clean = [];
    foreach ($params as $key => $value) {
        $text = trim((string)$value);
        if ($text !== '') {
            $clean[(string)$key] = $text;
        }
    }

    return 'borrow_records.php' . (!empty($clean) ? ('?' . http_build_query($clean)) : '');
};

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

$chipMetrics = [
    ['status' => '', 'title' => 'Total Records', 'count' => $totalRecords],
    ['status' => 'borrowed', 'title' => 'Borrowed', 'count' => $borrowedCount],
    ['status' => 'returned', 'title' => 'Returned', 'count' => $returnedCount],
    ['status' => 'overdue', 'title' => 'Overdue', 'count' => $overdueCount],
    ['status' => 'missing', 'title' => 'Missing', 'count' => $missingCount]
];

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
require 'layout_top.php';
?>

<div class="page-top">
    <h1 class="page-heading"><span class="page-icon" aria-hidden="true"><?= admin_icon('borrow') ?></span>Borrow Records</h1>
</div>

<?php if (is_array($flash) && !empty($flash['message'])): ?>
    <section class="panel glass-card borrow-flash borrow-flash-<?= htmlspecialchars((string)($flash['type'] ?? 'info')) ?>">
        <?= htmlspecialchars((string)$flash['message']) ?>
    </section>
<?php endif; ?>

<section class="stats-grid borrow-status-chips">
    <?php foreach ($chipMetrics as $chip): ?>
        <?php
            $chipStatus = (string)($chip['status'] ?? '');
            $chipParams = $baseFilterParams;
            if ($chipStatus !== '') {
                $chipParams['status'] = $chipStatus;
            }
            $isActiveChip = ($chipStatus === '' && $activeStatus === '') || ($chipStatus !== '' && $activeStatus === $chipStatus);
            $chipHref = $buildBorrowRecordsUrl($chipParams);
        ?>
        <?php $chipMeta = stat_chip_meta((string)($chip['title'] ?? '')); ?>
        <a href="<?= htmlspecialchars($chipHref) ?>" class="stat-card glass-card borrow-chip<?= $isActiveChip ? ' is-active' : '' ?>">
            <div class="metric-head-row">
                <span class="metric-icon-badge metric-icon-badge--<?= htmlspecialchars($chipMeta['accent']) ?>" aria-hidden="true"><?= admin_icon($chipMeta['icon']) ?></span>
                <h3><?= htmlspecialchars((string)($chip['title'] ?? '')) ?></h3>
            </div>
            <p class="value"><?= number_format((int)($chip['count'] ?? 0)) ?></p>
        </a>
    <?php endforeach; ?>
</section>

<section class="filters-bar-panel">
    <form method="GET" class="filters-inline borrow-filters">
        <input type="hidden" name="status" value="<?= htmlspecialchars($activeStatus) ?>">

        <div class="search-field-wrap">
            <span class="filters-search-icon" aria-hidden="true"><?= admin_icon('search') ?></span>
            <input type="text" name="search" placeholder="Search user, book, accession, record #" value="<?= htmlspecialchars($search) ?>">
        </div>

        <input type="date" name="date" value="<?= htmlspecialchars($dateFilter) ?>">

        <button type="submit" class="btn-primary">Apply</button>
        <a href="borrow_records.php" class="filter-reset-btn">Reset</a>
    </form>
</section>

<section class="panel glass-card data-table-panel">
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
                            $statusText = ucfirst($statusVal);
                            $statusTextClass = 'borrow-record-status-text borrow-record-status-muted';
                            if ($statusVal === 'borrowed') {
                                $statusTextClass = 'borrow-record-status-text borrow-record-status-borrowed';
                            } elseif ($statusVal === 'overdue') {
                                $statusTextClass = 'borrow-record-status-text borrow-record-status-overdue';
                            } elseif ($statusVal === 'missing') {
                                $statusTextClass = 'borrow-record-status-text borrow-record-status-missing';
                            } elseif ($statusVal === 'returned') {
                                $statusTextClass = 'borrow-record-status-text borrow-record-status-returned';
                            }
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
                                <a class="borrower-history-inline-link" href="borrower_history.php?user_id=<?= (int)($row['user_id'] ?? 0) ?>">History</a>
                            </td>
                            <td><?= htmlspecialchars($title) ?></td>
                            <td><?= htmlspecialchars((string)($row['date_borrowed'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($row['due_date'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($row['date_returned'] ?? '-')) ?></td>
                            <td><span class="<?= htmlspecialchars($statusTextClass) ?>"><?= htmlspecialchars($statusText) ?></span></td>
                            <td><?= htmlspecialchars($fine) ?></td>
                            <td>
                                <div class="borrow-action-row">
                                    <?php if ($canMarkReturned): ?>
                                        <form method="POST" class="row-action-form borrow-confirm-form" data-borrow-action="returned" data-book-title="<?= htmlspecialchars($title) ?>">
                                            <input type="hidden" name="record_action" value="mark_returned">
                                            <input type="hidden" name="record_id" value="<?= $rid ?>">
                                            <button type="submit" class="borrow-action-icon-btn returned" aria-label="Mark returned">
                                                <svg viewBox="0 0 24 24" aria-hidden="true">
                                                    <path d="M5 12.5 10 17l9-10"></path>
                                                </svg>
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($canMarkMissing): ?>
                                        <form method="POST" class="row-action-form borrow-confirm-form" data-borrow-action="missing" data-book-title="<?= htmlspecialchars($title) ?>">
                                            <input type="hidden" name="record_action" value="mark_missing">
                                            <input type="hidden" name="record_id" value="<?= $rid ?>">
                                            <button type="submit" class="borrow-action-icon-btn missing" aria-label="Mark missing">
                                                <svg viewBox="0 0 24 24" aria-hidden="true">
                                                    <path d="M7 7 17 17"></path>
                                                    <path d="M17 7 7 17"></path>
                                                </svg>
                                            </button>
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
                                    <div><strong>ISBN</strong><span><?= htmlspecialchars($isbn) ?></span></div>
                                    <div><strong>Accession No</strong><span><?= htmlspecialchars($accession) ?></span></div>
                                    <div><strong>User ID</strong><span><?= (int)($row['user_id'] ?? 0) ?></span></div>
                                    <div><strong>Copy ID</strong><span><?= (int)($row['copy_id'] ?? 0) ?></span></div>
                                    <div><strong>Created At</strong><span><?= htmlspecialchars((string)($row['created_at'] ?? '-')) ?></span></div>
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

<div class="overlay-modal" id="borrowActionConfirmModal" aria-hidden="true">
    <div class="overlay-card glass-card confirm-modal-card" role="dialog" aria-modal="true" aria-labelledby="borrowActionConfirmTitle" aria-describedby="borrowActionConfirmText">
        <button type="button" class="icon-close confirm-modal-close" id="borrowActionConfirmCancel" aria-label="Cancel">&times;</button>
        <div class="confirm-modal-header">
            <span class="confirm-modal-icon" id="borrowActionConfirmIcon" aria-hidden="true">!</span>
            <div>
                <h2 id="borrowActionConfirmTitle">Confirm Action</h2>
                <p id="borrowActionConfirmText">Apply this action?</p>
            </div>
        </div>
        <p class="confirm-modal-note" id="borrowActionConfirmNote"></p>
        <div class="confirm-modal-actions">
            <button type="button" class="confirm-modal-confirm" id="borrowActionConfirmSubmit">Confirm</button>
        </div>
    </div>
</div>

<script>
(function () {
    const modal = document.getElementById('borrowActionConfirmModal');
    const iconEl = document.getElementById('borrowActionConfirmIcon');
    const titleEl = document.getElementById('borrowActionConfirmTitle');
    const textEl = document.getElementById('borrowActionConfirmText');
    const noteEl = document.getElementById('borrowActionConfirmNote');
    const cancelBtn = document.getElementById('borrowActionConfirmCancel');
    const submitBtn = document.getElementById('borrowActionConfirmSubmit');
    if (!modal || !iconEl || !titleEl || !textEl || !noteEl || !cancelBtn || !submitBtn) {
        return;
    }

    const TONES = ['is-positive', 'is-warning', 'is-danger'];
    const COPY = {
        returned: {
            title: 'Mark as Returned',
            icon: String.fromCharCode(0x2713),
            note: 'The book copy will be marked available again.',
            confirmLabel: 'Mark Returned',
            tone: 'is-positive'
        },
        missing: {
            title: 'Mark as Missing',
            icon: '!',
            note: 'This flags the copy as missing until it is resolved manually.',
            confirmLabel: 'Mark Missing',
            tone: 'is-warning'
        }
    };

    let pendingForm = null;

    function setTone(el, tone) {
        TONES.forEach(function (t) { el.classList.remove(t); });
        el.classList.add(tone);
    }

    function closeModal() {
        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');
        pendingForm = null;
    }

    document.querySelectorAll('.borrow-confirm-form').forEach(function (formEl) {
        formEl.addEventListener('submit', function (event) {
            event.preventDefault();

            const action = formEl.dataset.borrowAction === 'missing' ? 'missing' : 'returned';
            const copy = COPY[action];
            const rawTitle = (formEl.dataset.bookTitle || '').trim();
            const label = rawTitle !== '' ? '"' + rawTitle + '"' : 'this record';

            pendingForm = formEl;
            titleEl.textContent = copy.title;
            textEl.textContent = copy.title + ' for ' + label + '?';
            noteEl.textContent = copy.note;
            iconEl.textContent = copy.icon;
            setTone(iconEl, copy.tone);
            submitBtn.textContent = copy.confirmLabel;
            setTone(submitBtn, copy.tone);

            modal.classList.add('show');
            modal.setAttribute('aria-hidden', 'false');
            submitBtn.focus();
        });
    });

    cancelBtn.addEventListener('click', closeModal);

    submitBtn.addEventListener('click', function () {
        if (!pendingForm) {
            closeModal();
            return;
        }

        const formToSubmit = pendingForm;
        pendingForm = null;
        closeModal();
        formToSubmit.submit();
    });

    window.addEventListener('click', function (event) {
        if (event.target === modal) {
            closeModal();
        }
    });

    window.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modal.classList.contains('show')) {
            closeModal();
        }
    });
}());
</script>

<?php require 'layout_bottom.php'; ?>









