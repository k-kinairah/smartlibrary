<?php
require_once __DIR__ . '/../config/session_start.php'; // smartlib_session_start(): Secure cookie on HTTPS
if (session_status() === PHP_SESSION_NONE) {
    smartlib_session_start();
}

$currentRole = strtolower(trim((string)($_SESSION['role'] ?? '')));
if (!isset($_SESSION['user_id']) || !in_array($currentRole, ['librarian', 'admin'], true)) {
    header('Location: ../index.php');
    exit;
}
require '../config/db_connect.php';
require_once '../config/session_guard.php';
if (!smartlib_active_staff_session($conn)) {
    header('Location: ' . smartlib_staff_signed_out_location());
    exit;
}
require_once '../config/borrow_fine_rules.php';
require_once '../config/admin_audit.php';
require_once '../config/request_guard.php';
require_once '../config/request_input.php';
require_once '../config/db_query.php';
require_once __DIR__ . '/includes/list_pager.php';

const BORROW_RECORDS_PER_PAGE = 50;
const BORROW_RECORDS_STATUSES = ['borrowed', 'returned', 'overdue', 'missing'];

/**
 * The list's filters and page, read from $_GET ($prefix '') or from the
 * row forms' hidden return_* inputs ($prefix 'return_'). Strings only;
 * status from the whitelist; dates as YYYY-MM-DD (what the date input
 * sends); page at least 1 (the list clamps it to the last page).
 */
function borrow_records_filters(array $source, string $prefix): array {
    $text = static function (string $key) use ($source, $prefix): string {
        $value = $source[$prefix . $key] ?? '';
        return is_string($value) ? trim($value) : '';
    };
    $date = static function (string $key) use ($text): string {
        $value = $text($key);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
    };
    $status = $text('status');
    return [
        'search' => $text('search'),
        'status' => in_array($status, BORROW_RECORDS_STATUSES, true) ? $status : '',
        'date' => $date('date'),
        'date_from' => $date('date_from'),
        'date_to' => $date('date_to'),
        'page' => max(1, smartlib_request_int($source, $prefix . 'page', 1)),
    ];
}

/**
 * Every Borrow Records list URL. Known keys only (in the order given);
 * empty values and page 1 are left out; values are URL-encoded and the
 * result is always a relative borrow_records.php URL (no open redirect).
 */
function borrow_records_url(array $params): string {
    $allowed = ['search', 'status', 'date', 'date_from', 'date_to', 'page'];
    $clean = [];
    foreach ($params as $key => $value) {
        if (!in_array($key, $allowed, true) || is_array($value)) {
            continue;
        }
        $text = trim((string)$value);
        if ($key === 'page') {
            $number = (int)$text;
            if ($number <= 1) {
                continue;
            }
            $text = (string)$number;
        }
        if ($text !== '') {
            $clean[$key] = $text;
        }
    }

    return 'borrow_records.php' . (!empty($clean) ? ('?' . http_build_query($clean)) : '');
}

function qres(mysqli $conn, string $sql, array $params = []) {
    try {
        return smartlib_db_query($conn, $sql, $params);
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_action'])
    && !smartlib_request_form_ok([SMARTLIB_STAFF_CSRF_KEY])) {
    // Anti-forgery (Origin + staff token): nothing is changed; back to the
    // list with a message (a plain 403 would leave a blank page).
    set_borrow_flash('error', 'This page had expired, so nothing was changed. Please try again.');
    header('Location: borrow_records.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_action'])) {
    // Back to the page and filters the row was on (return_* inputs, same
    // whitelist as the list). A page that no longer exists after the
    // change shows the new last page (the list clamps it).
    $borrowReturnUrl = borrow_records_url(borrow_records_filters($_POST, 'return_'));
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
                    header('Location: ' . $borrowReturnUrl);
                    exit;
                }

                $returnDate = date('Y-m-d');
                $dueDate = (string)($record['due_date'] ?? '');
                // Fines off (Settings): store 0. On: the saved rate, counting
                // only days late from the day fines were switched on.
                $finesForReturn = smartlib_fines_settings($conn);
                $returnFine = $finesForReturn['enabled']
                    ? smartlib_overdue_fine_amount($dueDate, $returnDate, $finesForReturn['rate'], $finesForReturn['start_date'])
                    : 0.0;

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

                $returnAuditMeta = [
                    'record_id' => $recordId,
                    'copy_id' => $copyId,
                    'book_id' => (int)($record['book_id'] ?? 0),
                    'accession_no' => (string)($record['accession_no'] ?? ''),
                    'borrower' => trim((string)($record['borrower_name'] ?? '')),
                    'borrower_number' => (string)($record['borrower_number'] ?? ''),
                    'previous_status' => $currentStatus,
                    'new_status' => 'returned',
                ];
                if ($finesForReturn['enabled']) {
                    $returnAuditMeta['fine'] = $returnFine;
                }
                smartlib_admin_audit_log($conn, 'returned', 'borrow_record', $recordId, (string)($record['book_title'] ?? 'Borrow Record #' . $recordId), $returnAuditMeta);

                $conn->commit();
                set_borrow_flash('success', 'Book return has been recorded successfully.');
            }

            if ($action === 'mark_missing') {
                if ($currentStatus === 'missing') {
                    $conn->rollback();
                    set_borrow_flash('info', 'This record is already marked as missing.');
                    header('Location: ' . $borrowReturnUrl);
                    exit;
                }

                if ($currentStatus === 'returned') {
                    $conn->rollback();
                    set_borrow_flash('error', 'Returned records cannot be marked as missing.');
                    header('Location: ' . $borrowReturnUrl);
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

    header('Location: ' . $borrowReturnUrl);
    exit;
}

sync_overdue_status_and_fines($conn);
$finesOn = smartlib_fines_enabled($conn);

$flash = $_SESSION['borrow_flash'] ?? null;
unset($_SESSION['borrow_flash']);

$borrowFilters = borrow_records_filters($_GET, '');
$search = $borrowFilters['search'];
$status = $borrowFilters['status'];
$dateFilter = $borrowFilters['date'];
$dateFrom = $borrowFilters['date_from'];
$dateTo = $borrowFilters['date_to'];
$borrowPage = $borrowFilters['page'];

if ($dateFilter !== '') {
    $dateFrom = $dateFilter;
    $dateTo = $dateFilter;
} elseif ($dateFrom !== '' && $dateFrom === $dateTo) {
    $dateFilter = $dateFrom;
}

// Prepared: the WHERE has ? placeholders, $whereParams their values in
// order (security review SR-16); a typed % or _ is literal (SR-21).
$where = ['1=1'];
$whereParams = [];

if ($search !== '') {
    $like = '%' . smartlib_like_escape($search) . '%';
    $where[] = "(
        br.record_id LIKE ? ESCAPE '\\\\'
        OR u.user_number LIKE ? ESCAPE '\\\\'
        OR u.first_name LIKE ? ESCAPE '\\\\'
        OR u.last_name LIKE ? ESCAPE '\\\\'
        OR b.title LIKE ? ESCAPE '\\\\'
        OR bc.accession_no LIKE ? ESCAPE '\\\\'
    )";
    array_push($whereParams, $like, $like, $like, $like, $like, $like);
}

$allowedStatus = BORROW_RECORDS_STATUSES;
if (in_array($status, $allowedStatus, true)) {
    $where[] = "br.status = ?";
    $whereParams[] = $status;
}

if ($dateFrom !== '') {
    $where[] = "COALESCE(br.date_borrowed, DATE(br.created_at)) >= ?";
    $whereParams[] = $dateFrom;
}

if ($dateTo !== '') {
    $where[] = "COALESCE(br.date_borrowed, DATE(br.created_at)) <= ?";
    $whereParams[] = $dateTo;
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
    return borrow_records_url($params);
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

// Paging: the filtered total uses the same joins and WHERE as the list.
// The status tiles above still count every record.
$filteredRecordsTotal = 0;
$resFiltered = qres(
    $conn,
    "
    SELECT COUNT(*) AS total
    FROM borrow_records br
    LEFT JOIN library_users u ON br.user_id = u.user_id
    LEFT JOIN book_copies bc ON br.copy_id = bc.copy_id
    LEFT JOIN books b ON bc.book_id = b.book_id
    WHERE $whereSql
    ",
    $whereParams
);
if ($resFiltered && ($r = $resFiltered->fetch_assoc())) {
    $filteredRecordsTotal = (int)($r['total'] ?? 0);
}
$borrowTotalPages = max(1, (int)ceil($filteredRecordsTotal / BORROW_RECORDS_PER_PAGE));
$borrowPage = min($borrowPage, $borrowTotalPages);
$borrowPageOffset = ($borrowPage - 1) * BORROW_RECORDS_PER_PAGE;
$borrowListParams = $baseFilterParams + ['status' => $activeStatus, 'page' => $borrowPage];

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
    LIMIT " . (int)$borrowPageOffset . ', ' . (int)BORROW_RECORDS_PER_PAGE . "
    ",
    $whereParams
);
require 'layout_top.php';
?>

<div class="page-top">
    <h1 class="page-heading"><span class="page-icon" aria-hidden="true"><?= admin_icon('borrow') ?></span>Borrow Records</h1>
</div>

<?php // One-shot result (session flash after the redirect): shared toast. ?>
<?php if (is_array($flash)) { smartlib_admin_toast_render((string)($flash['type'] ?? 'info'), (string)($flash['message'] ?? '')); } ?>

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
        <?php
            // Rich rows (Prompt 37). "Today" is the same one the overdue sync uses
            // (config/borrow_fine_rules.php: new DateTimeImmutable('today') in the
            // app timezone set by config/db_connect.php); whole dates are compared.
            $rrToday = new DateTimeImmutable('today');
            $rrYear = $rrToday->format('Y');
            $rrDate = static function ($value) {
                $ymd = substr(trim((string)$value), 0, 10);
                $d = preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd) ? DateTimeImmutable::createFromFormat('!Y-m-d', $ymd) : false;
                return $d ?: null;
            };
            $rrShortDate = static function (?DateTimeImmutable $d) use ($rrYear): string {
                if ($d === null) {
                    return '-';
                }
                return $d->format('Y') === $rrYear ? $d->format('M j') : $d->format('M j, Y');
            };
            $rrInitials = static function (string $first, string $last, string $fallback): string {
                $letters = '';
                foreach ([$first, $last] as $part) {
                    $part = trim($part);
                    if ($part !== '') {
                        $letters .= mb_strtoupper(mb_substr($part, 0, 1, 'UTF-8'), 'UTF-8');
                    }
                }
                if ($letters === '') {
                    $letters = mb_strtoupper(mb_substr(trim($fallback), 0, 2, 'UTF-8'), 'UTF-8');
                }
                return $letters !== '' ? $letters : '?';
            };
        ?>
        <table class="data-table borrow-table rr-table rr-borrow<?= $finesOn ? '' : ' borrow-table-no-fine' ?>">
            <thead>
                <tr>
                    <th>Record</th>
                    <th>User</th>
                    <th>Book</th>
                    <th>Dates</th>
                    <th>Status</th>
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

                            // Row accent: Returned ok, Borrowed warn, Overdue / Missing bad.
                            $rowTone = $statusVal === 'returned' ? 'ok' : (in_array($statusVal, ['overdue', 'missing'], true) ? 'bad' : 'warn');
                            // Relative date badge.
                            $rowBorrowed = $rrDate($row['date_borrowed'] ?? null);
                            $rowDue = $rrDate($row['due_date'] ?? null);
                            $rowReturned = $rrDate($row['date_returned'] ?? null);
                            if ($statusVal === 'returned') {
                                $dueBadgeTone = 'mint';
                                $dueBadgeText = $rowReturned !== null ? 'Returned ' . $rrShortDate($rowReturned) : 'Returned';
                            } elseif ($rowDue === null) {
                                $dueBadgeTone = 'soft';
                                $dueBadgeText = 'No due date';
                            } else {
                                $daysLeft = (int)$rrToday->diff($rowDue)->format('%r%a');
                                if ($daysLeft < 0) {
                                    $dueBadgeTone = 'red';
                                    $dueBadgeText = abs($daysLeft) . (abs($daysLeft) === 1 ? ' day late' : ' days late');
                                } elseif ($daysLeft === 0) {
                                    $dueBadgeTone = 'gold';
                                    $dueBadgeText = 'Due today';
                                } else {
                                    $dueBadgeTone = 'gold';
                                    $dueBadgeText = 'Due in ' . $daysLeft . ($daysLeft === 1 ? ' day' : ' days');
                                }
                            }
                        ?>
                        <tr class="borrow-row rr-row" data-detail="detail-<?= $rid ?>" data-tone="<?= $rowTone ?>">
                            <td class="rr-c-rec"><span class="rr-id">#<?= $rid ?></span></td>
                            <td class="rr-c-user">
                                <div class="rr-person">
                                    <span class="rr-avatar" aria-hidden="true"><?= htmlspecialchars($rrInitials((string)($row['first_name'] ?? ''), (string)($row['last_name'] ?? ''), $userNo)) ?></span>
                                    <div class="rr-stack">
                                        <div class="rr-title"><?= htmlspecialchars($borrower) ?></div>
                                        <div class="rr-meta"><?= htmlspecialchars($userNo) ?> · <a class="borrower-history-inline-link" href="borrower_history.php?user_id=<?= (int)($row['user_id'] ?? 0) ?>">History</a></div>
                                    </div>
                                </div>
                            </td>
                            <td class="rr-c-book">
                                <div class="rr-stack">
                                    <div class="rr-title"><?= htmlspecialchars($title) ?></div>
                                    <div class="rr-meta">Accession <b><?= htmlspecialchars($accession) ?></b></div>
                                </div>
                            </td>
                            <td class="rr-c-dates">
                                <div class="rr-due">
                                    <span class="rr-chip <?= $dueBadgeTone ?>"><span class="rr-chip-text"><?= htmlspecialchars($dueBadgeText) ?></span></span>
                                    <small><?= htmlspecialchars($rrShortDate($rowBorrowed)) ?> &rarr; <?= htmlspecialchars($rrShortDate($rowDue)) ?></small>
                                    <?php if ($finesOn): ?><small class="rr-fine">Fine <?= htmlspecialchars($fine) ?></small><?php endif; ?>
                                </div>
                            </td>
                            <td class="rr-c-status"><span class="<?= htmlspecialchars($statusTextClass) ?>"><?= htmlspecialchars($statusText) ?></span></td>
                            <td class="rr-c-act">
                                <div class="borrow-action-row rr-actions rr-actions-stack">
                                    <?php if ($canMarkReturned): ?>
                                        <form method="POST" class="row-action-form borrow-confirm-form" data-borrow-action="returned" data-book-title="<?= htmlspecialchars($title) ?>">
                                            <input type="hidden" name="record_action" value="mark_returned">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(smartlib_staff_csrf_token()) ?>">
                                            <input type="hidden" name="record_id" value="<?= $rid ?>">
                                            <?php foreach ($borrowListParams as $returnKey => $returnValue): ?>
                                                <input type="hidden" name="return_<?= htmlspecialchars($returnKey) ?>" value="<?= htmlspecialchars((string)$returnValue) ?>">
                                            <?php endforeach; ?>
                                            <button type="submit" class="borrow-action-icon-btn returned" aria-label="Mark returned">
                                                <span class="borrow-action-icon" aria-hidden="true"><?= admin_icon('check-circle') ?></span>
                                                <span class="borrow-action-label">Returned</span>
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($canMarkMissing): ?>
                                        <form method="POST" class="row-action-form borrow-confirm-form" data-borrow-action="missing" data-book-title="<?= htmlspecialchars($title) ?>">
                                            <input type="hidden" name="record_action" value="mark_missing">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(smartlib_staff_csrf_token()) ?>">
                                            <input type="hidden" name="record_id" value="<?= $rid ?>">
                                            <?php foreach ($borrowListParams as $returnKey => $returnValue): ?>
                                                <input type="hidden" name="return_<?= htmlspecialchars($returnKey) ?>" value="<?= htmlspecialchars((string)$returnValue) ?>">
                                            <?php endforeach; ?>
                                            <button type="submit" class="borrow-action-icon-btn missing" aria-label="Mark missing">
                                                <span class="borrow-action-icon" aria-hidden="true"><?= admin_icon('alert-triangle') ?></span>
                                                <span class="borrow-action-label">Missing</span>
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if (!$canMarkReturned && !$canMarkMissing): ?>
                                        <span class="borrow-action-muted" aria-label="No action available">&mdash;</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <tr id="detail-<?= $rid ?>" class="borrow-detail-row" hidden>
                            <td colspan="6">
                                <div class="rr-detail-panel">
                                <div class="borrow-detail-grid">
                                    <div><strong>ISBN</strong><span><?= htmlspecialchars($isbn) ?></span></div>
                                    <div><strong>Accession No</strong><span><?= htmlspecialchars($accession) ?></span></div>
                                    <div><strong>User ID</strong><span><?= (int)($row['user_id'] ?? 0) ?></span></div>
                                    <div><strong>Copy ID</strong><span><?= (int)($row['copy_id'] ?? 0) ?></span></div>
                                    <div><strong>Created At</strong><span><?= htmlspecialchars((string)($row['created_at'] ?? '-')) ?></span></div>
                                </div>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6">No borrow records found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php smartlib_list_pager_render($borrowPage, $borrowTotalPages, $filteredRecordsTotal, BORROW_RECORDS_PER_PAGE, static function (int $pagerPage) use ($borrowListParams): string { return borrow_records_url(array_merge($borrowListParams, ['page' => $pagerPage])); }, 'records'); ?>
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
