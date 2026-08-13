<?php require 'layout_top.php'; ?>
<?php
require '../config/db_connect.php';
require_once '../config/admin_audit.php';

function safe_query(mysqli $conn, string $sql) {
    try {
        return $conn->query($sql);
    } catch (Throwable $e) {
        return false;
    }
}

function ensure_admin_activity_log_table(mysqli $conn): bool {
    return smartlib_admin_audit_ensure_table($conn);
}

function action_badge_class(string $action): string {
    if (in_array($action, ['added', 'returned', 'retrieved'], true)) {
        return 'status-active';
    }
    if (in_array($action, ['deleted', 'missing'], true)) {
        return 'status-inactive';
    }
    return 'status-pending';
}

function action_label(string $action): string {
    return smartlib_admin_audit_action_label($action);
}
function parse_meta_json(?string $json): array {
    $raw = trim((string)$json);
    if ($raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function meta_summary(?string $json): string {
    $decoded = parse_meta_json($json);
    if (empty($decoded)) {
        $raw = trim((string)$json);
        return $raw === '' ? '-' : $raw;
    }

    $parts = [];

    if (!empty($decoded['isbn'])) {
        $parts[] = 'ISBN: ' . (string)$decoded['isbn'];
    }

    if (!empty($decoded['author'])) {
        $parts[] = 'Author: ' . (string)$decoded['author'];
    }

    if (!empty($decoded['accession_no_saved'])) {
        $parts[] = 'Accession: ' . (string)$decoded['accession_no_saved'];
    } elseif (!empty($decoded['accession_no_requested'])) {
        $parts[] = 'Accession: ' . (string)$decoded['accession_no_requested'];
    }

    if (isset($decoded['requested_copies']) || isset($decoded['inserted_copies'])) {
        $requested = (int)($decoded['requested_copies'] ?? 0);
        $inserted = (int)($decoded['inserted_copies'] ?? 0);
        $parts[] = 'Copies: ' . $inserted . ' / ' . $requested;
    }

    if (isset($decoded['total_copies_before_delete'])) {
        $parts[] = 'Copies Before Delete: ' . (int)$decoded['total_copies_before_delete'];
    }

    if (!empty($decoded['year_published'])) {
        $parts[] = 'Year: ' . (string)$decoded['year_published'];
    }

    if (!empty($decoded['restored_book_id'])) {
        $parts[] = 'Retrieved as Book #' . (int)$decoded['restored_book_id'];
    }

    if (empty($parts)) {
        foreach ($decoded as $key => $value) {
            if (is_scalar($value)) {
                $parts[] = ucfirst(str_replace('_', ' ', (string)$key)) . ': ' . (string)$value;
            }
            if (count($parts) >= 4) {
                break;
            }
        }
    }

    return empty($parts) ? '-' : implode(' | ', $parts);
}

function sql_nullable_string(mysqli $conn, ?string $value): string {
    $text = trim((string)$value);
    if ($text === '') {
        return 'NULL';
    }
    return "'" . $conn->real_escape_string($text) . "'";
}

function sql_nullable_int($value): string {
    $num = (int)$value;
    return $num > 0 ? (string)$num : 'NULL';
}

function valid_fk_id(mysqli $conn, string $table, string $column, $value): int {
    $id = (int)$value;
    if ($id <= 0) {
        return 0;
    }

    $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $safeColumn = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
    if ($safeTable === '' || $safeColumn === '') {
        return 0;
    }

    $res = safe_query($conn, "SELECT {$safeColumn} FROM {$safeTable} WHERE {$safeColumn} = {$id} LIMIT 1");
    if ($res && $res->num_rows > 0) {
        return $id;
    }

    return 0;
}

function set_archive_flash(string $type, string $message): void {
    $_SESSION['archive_flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

function pull_archive_flash(): array {
    $flash = $_SESSION['archive_flash'] ?? ['type' => '', 'message' => ''];
    unset($_SESSION['archive_flash']);

    if (!is_array($flash)) {
        return ['type' => '', 'message' => ''];
    }

    return [
        'type' => (string)($flash['type'] ?? ''),
        'message' => (string)($flash['message'] ?? '')
    ];
}

function archive_redirect(): void {
    header('Location: archived_history.php');
    exit;
}

function archive_csrf_token(): string {
    if (empty($_SESSION['archive_csrf']) || !is_string($_SESSION['archive_csrf'])) {
        $_SESSION['archive_csrf'] = bin2hex(random_bytes(32));
    }

    return (string)$_SESSION['archive_csrf'];
}

$archiveCsrfToken = archive_csrf_token();

$tableReady = ensure_admin_activity_log_table($conn);

if ($tableReady) {
    safe_query($conn, "DELETE FROM admin_activity_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 180 DAY)");
}

if ($tableReady && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = trim((string)($_POST['csrf_token'] ?? ''));
    if ($postedToken === '' || !hash_equals($archiveCsrfToken, $postedToken)) {
        set_archive_flash('error', 'Invalid request token. Refresh and try again.');
        archive_redirect();
    }

    $archiveAction = strtolower(trim((string)($_POST['archive_action'] ?? '')));

    if ($archiveAction === 'retrieve_book') {
        $activityId = (int)($_POST['activity_id'] ?? 0);

        if ($activityId <= 0) {
            set_archive_flash('error', 'Invalid archive entry selected.');
            archive_redirect();
        }

        $logRes = safe_query(
            $conn,
            "
            SELECT activity_id, action_type, entity_type, entity_id, entity_title, metadata_json
            FROM admin_activity_logs
            WHERE activity_id = {$activityId}
            LIMIT 1
            "
        );

        if (!$logRes || $logRes->num_rows === 0) {
            set_archive_flash('error', 'Archive entry was not found.');
            archive_redirect();
        }

        $logRow = $logRes->fetch_assoc();
        $actionType = strtolower(trim((string)($logRow['action_type'] ?? '')));
        $entityType = strtolower(trim((string)($logRow['entity_type'] ?? '')));

        if ($actionType !== 'deleted' || $entityType !== 'book') {
            set_archive_flash('info', 'Only deleted books can be retrieved from archives.');
            archive_redirect();
        }

        $meta = parse_meta_json((string)($logRow['metadata_json'] ?? ''));
        $alreadyRestored = (int)($meta['restored_book_id'] ?? 0);
        if ($alreadyRestored > 0) {
            set_archive_flash('info', 'This archive entry was already retrieved as Book #' . $alreadyRestored . '.');
            archive_redirect();
        }

        $title = trim((string)($logRow['entity_title'] ?? ''));
        if ($title === '') {
            $title = trim((string)($meta['title'] ?? ''));
        }

        if ($title === '') {
            set_archive_flash('error', 'Unable to retrieve this book because the archived title is missing.');
            archive_redirect();
        }

        $isbn = trim((string)($meta['isbn'] ?? ''));
        $author = trim((string)($meta['author'] ?? ''));
        $publisher = trim((string)($meta['publisher'] ?? ''));
        $yearPublished = trim((string)($meta['year_published'] ?? ''));
        $callNumber = trim((string)($meta['call_number'] ?? ''));
        $description = trim((string)($meta['description'] ?? ''));
        $legacyLocation = trim((string)($meta['location'] ?? ''));

        $categoryId = valid_fk_id($conn, 'categories', 'category_id', $meta['category_id'] ?? 0);
        $programId = valid_fk_id($conn, 'programs', 'program_id', $meta['program_id'] ?? 0);
        $locationId = valid_fk_id($conn, 'library_locations', 'location_id', $meta['location_id'] ?? 0);

        $actorUserId = (int)($_SESSION['user_id'] ?? 0);
        $actorName = trim((string)($_SESSION['name'] ?? ''));
        if ($actorName === '') {
            $actorName = trim((string)($_SESSION['full_name'] ?? ''));
        }
        if ($actorName === '') {
            $actorName = trim((string)($_SESSION['username'] ?? ''));
        }
        if ($actorName === '') {
            $actorName = 'System Admin';
        }

        $conn->begin_transaction();

        try {
            $insertSql = "
                INSERT INTO books (
                    isbn, title, author, publisher, year_published,
                    category_id, program_id, location_id,
                    call_number, description, location, created_at
                ) VALUES (
                    " . sql_nullable_string($conn, $isbn) . ",
                    " . sql_nullable_string($conn, $title) . ",
                    " . sql_nullable_string($conn, $author) . ",
                    " . sql_nullable_string($conn, $publisher) . ",
                    " . sql_nullable_string($conn, $yearPublished) . ",
                    " . sql_nullable_int($categoryId) . ",
                    " . sql_nullable_int($programId) . ",
                    " . sql_nullable_int($locationId) . ",
                    " . sql_nullable_string($conn, $callNumber) . ",
                    " . sql_nullable_string($conn, $description) . ",
                    " . sql_nullable_string($conn, $legacyLocation) . ",
                    NOW()
                )
            ";

            if (!safe_query($conn, $insertSql)) {
                throw new RuntimeException('Failed to recreate archived book.');
            }

            $newBookId = (int)$conn->insert_id;
            if ($newBookId <= 0) {
                throw new RuntimeException('Retrieved book ID was not generated.');
            }

            $meta['restored_book_id'] = $newBookId;
            $meta['restored_at'] = date('Y-m-d H:i:s');
            if ($actorUserId > 0) {
                $meta['restored_by_user_id'] = $actorUserId;
            }
            $meta['restored_by_name'] = $actorName;

            $updatedMeta = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $updateSql = "UPDATE admin_activity_logs SET metadata_json = " . sql_nullable_string($conn, $updatedMeta) . " WHERE activity_id = {$activityId} LIMIT 1";
            if (!safe_query($conn, $updateSql)) {
                throw new RuntimeException('Failed to update archive entry metadata.');
            }

            $restoreLogMeta = [
                'retrieved_from_activity_id' => $activityId,
                'source_deleted_entity_id' => (int)($logRow['entity_id'] ?? 0),
                'isbn' => $isbn,
                'author' => $author
            ];
            $restoreLogMetaJson = json_encode($restoreLogMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $insertLogSql = "
                INSERT INTO admin_activity_logs (
                    actor_user_id, actor_name, action_type, entity_type,
                    entity_id, entity_title, metadata_json, created_at
                ) VALUES (
                    " . sql_nullable_int($actorUserId) . ",
                    " . sql_nullable_string($conn, $actorName) . ",
                    'retrieved',
                    'book',
                    {$newBookId},
                    " . sql_nullable_string($conn, $title) . ",
                    " . sql_nullable_string($conn, $restoreLogMetaJson) . ",
                    NOW()
                )
            ";
            safe_query($conn, $insertLogSql);

            $conn->commit();
            set_archive_flash('success', 'Book retrieved successfully as Book #' . $newBookId . '.');
            archive_redirect();
        } catch (Throwable $e) {
            $conn->rollback();
            set_archive_flash('error', 'Retrieve failed. ' . $e->getMessage());
            archive_redirect();
        }
    }
}

$search = trim((string)($_GET['search'] ?? ''));
$action = strtolower(trim((string)($_GET['action'] ?? '')));
$dateFilter = trim((string)($_GET['date'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));

if ($dateFilter !== '') {
    $dateFrom = $dateFilter;
    $dateTo = $dateFilter;
} elseif ($dateFrom !== '' && $dateFrom === $dateTo) {
    $dateFilter = $dateFrom;
}

$where = ['1=1'];

if (in_array($action, smartlib_admin_audit_actions(), true)) {
    $safeAction = $conn->real_escape_string($action);
    $where[] = "a.action_type = '{$safeAction}'";
}

if ($search !== '') {
    $safeSearch = $conn->real_escape_string($search);
    $where[] = "(
        a.entity_title LIKE '%{$safeSearch}%'
        OR a.entity_type LIKE '%{$safeSearch}%'
        OR a.actor_name LIKE '%{$safeSearch}%'
        OR a.metadata_json LIKE '%{$safeSearch}%'
    )";
}

if ($dateFrom !== '') {
    $safeFrom = $conn->real_escape_string($dateFrom);
    $where[] = "DATE(a.created_at) >= '{$safeFrom}'";
}

if ($dateTo !== '') {
    $safeTo = $conn->real_escape_string($dateTo);
    $where[] = "DATE(a.created_at) <= '{$safeTo}'";
}

$whereSql = implode(' AND ', $where);

$totalLogs = 0;
$totalAdded = 0;
$totalDeleted = 0;
$totalUpdated = 0;
$totalReturned = 0;
$totalMissing = 0;
$rows = [];

if ($tableReady) {
    $resTotal = safe_query($conn, 'SELECT COUNT(*) AS total FROM admin_activity_logs');
    if ($resTotal && ($r = $resTotal->fetch_assoc())) {
        $totalLogs = (int)($r['total'] ?? 0);
    }

    $resAdded = safe_query($conn, "SELECT COUNT(*) AS total FROM admin_activity_logs WHERE action_type = 'added'");
    if ($resAdded && ($r = $resAdded->fetch_assoc())) {
        $totalAdded = (int)($r['total'] ?? 0);
    }

    $resDeleted = safe_query($conn, "SELECT COUNT(*) AS total FROM admin_activity_logs WHERE action_type = 'deleted'");
    if ($resDeleted && ($r = $resDeleted->fetch_assoc())) {
        $totalDeleted = (int)($r['total'] ?? 0);
    }

    $resUpdated = safe_query($conn, "SELECT COUNT(*) AS total FROM admin_activity_logs WHERE action_type IN ('updated', 'status_changed')");
    if ($resUpdated && ($r = $resUpdated->fetch_assoc())) {
        $totalUpdated = (int)($r['total'] ?? 0);
    }

    $resReturned = safe_query($conn, "SELECT COUNT(*) AS total FROM admin_activity_logs WHERE action_type = 'returned'");
    if ($resReturned && ($r = $resReturned->fetch_assoc())) {
        $totalReturned = (int)($r['total'] ?? 0);
    }

    $resMissing = safe_query($conn, "SELECT COUNT(*) AS total FROM admin_activity_logs WHERE action_type = 'missing'");
    if ($resMissing && ($r = $resMissing->fetch_assoc())) {
        $totalMissing = (int)($r['total'] ?? 0);
    }

    $historyRes = safe_query(
        $conn,
        "
        SELECT
            a.activity_id,
            a.action_type,
            a.entity_type,
            a.entity_id,
            a.entity_title,
            a.metadata_json,
            a.actor_name,
            a.created_at,
            u.user_number,
            CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) AS user_full_name
        FROM admin_activity_logs a
        LEFT JOIN library_users u ON u.user_id = a.actor_user_id
        WHERE {$whereSql}
        ORDER BY a.created_at DESC, a.activity_id DESC
        LIMIT 350
        "
    );

    if ($historyRes && $historyRes->num_rows > 0) {
        while ($row = $historyRes->fetch_assoc()) {
            $rows[] = $row;
        }
    }
}

$buildArchiveUrl = static function (array $params): string {
    $clean = [];
    foreach ($params as $key => $value) {
        $text = trim((string)$value);
        if ($text !== '') {
            $clean[(string)$key] = $text;
        }
    }

    return 'archived_history.php' . (!empty($clean) ? ('?' . http_build_query($clean)) : '');
};

$baseFilterParams = [
    'search' => $search,
    'date' => $dateFilter
];

$chipMetrics = [
    ['action' => '', 'title' => 'Total Logged Actions', 'count' => $totalLogs],
    ['action' => 'added', 'title' => 'Added', 'count' => $totalAdded],
    ['action' => 'updated', 'title' => 'Updated', 'count' => $totalUpdated],
    ['action' => 'returned', 'title' => 'Returned', 'count' => $totalReturned],
    ['action' => 'missing', 'title' => 'Missing', 'count' => $totalMissing],
    ['action' => 'deleted', 'title' => 'Deleted', 'count' => $totalDeleted]
];
$archiveFlash = pull_archive_flash();
?>

<div class="page-top archive-top-row">
    <h1>Audit Log</h1>
    <p class="archive-inline-note" title="Audit records older than 180 days are automatically removed.">
        <span class="archive-inline-note-icon" aria-hidden="true">i</span>
        <span>Audit retention: 180 days</span>
    </p>
</div>

<?php if ($archiveFlash['message'] !== ''): ?>
    <?php
        $flashType = strtolower(trim((string)$archiveFlash['type']));
        if (!in_array($flashType, ['success', 'error', 'info'], true)) {
            $flashType = 'info';
        }
    ?>
    <section class="panel glass-card borrow-flash borrow-flash-<?= htmlspecialchars($flashType) ?>">
        <?= htmlspecialchars((string)$archiveFlash['message']) ?>
    </section>
<?php endif; ?>

<section class="stats-grid borrow-status-chips">
    <?php foreach ($chipMetrics as $chip): ?>
        <?php
            $chipAction = (string)($chip['action'] ?? '');
            $isActiveChip = ($chipAction === '' && $action === '') || ($chipAction !== '' && $action === $chipAction);
            $chipParams = $baseFilterParams;
            if ($chipAction !== '') {
                $chipParams['action'] = $chipAction;
            }
            if ($isActiveChip && $chipAction !== '') {
                $chipParams['action'] = '';
            }
            $chipHref = $buildArchiveUrl($chipParams);
        ?>
        <a href="<?= htmlspecialchars($chipHref) ?>" class="stat-card glass-card borrow-chip<?= $isActiveChip ? ' is-active' : '' ?>">
            <h3><?= htmlspecialchars((string)($chip['title'] ?? '')) ?></h3>
            <p class="value"><?= number_format((int)($chip['count'] ?? 0)) ?></p>
        </a>
    <?php endforeach; ?>
</section>

<section class="panel glass-card">
    <form method="GET" class="filters-inline borrow-filters archive-filters">
        <input type="text" name="search" placeholder="Search item, admin, action details" value="<?= htmlspecialchars($search) ?>">

        <input type="hidden" name="action" value="<?= htmlspecialchars($action) ?>">


        <input type="date" name="date" value="<?= htmlspecialchars($dateFilter) ?>">

        <button type="submit" class="btn-primary">Apply</button>
        <a href="archived_history.php" class="archive-reset-btn filter-reset-btn">Reset</a>
    </form>
</section>

<section class="panel glass-card">
    <div class="table-wrap">
        <table class="data-table archive-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Action</th>
                    <th>Entity</th>
                    <th>Item</th>
                    <th>Details</th>
                    <th>By</th>
                    <th>Options</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$tableReady): ?>
                    <tr>
                        <td colspan="7">Archive log table is not ready yet. Reload this page in a few seconds.</td>
                    </tr>
                <?php elseif (!empty($rows)): ?>
                    <?php foreach ($rows as $row): ?>
                        <?php
                            $actionVal = strtolower(trim((string)($row['action_type'] ?? '')));
                            $entityType = strtolower(trim((string)($row['entity_type'] ?? 'book')));
                            $entityId = (int)($row['entity_id'] ?? 0);
                            $title = trim((string)($row['entity_title'] ?? 'Untitled Item'));
                            $metaText = meta_summary((string)($row['metadata_json'] ?? ''));
                            $metaData = parse_meta_json((string)($row['metadata_json'] ?? ''));

                            $resolvedName = trim((string)($row['user_full_name'] ?? ''));
                            if ($resolvedName === '') {
                                $resolvedName = trim((string)($row['actor_name'] ?? 'System Admin'));
                            }
                            $userNumber = trim((string)($row['user_number'] ?? ''));

                            $canRetrieveBook = $actionVal === 'deleted' && $entityType === 'book';
                            $isAddedBook = $actionVal === 'added' && $entityType === 'book';
                            $restoredBookId = (int)($metaData['restored_book_id'] ?? 0);
                            $viewHref = '';
                            if ($isAddedBook) {
                                if ($entityId > 0) {
                                    $viewHref = 'manage_books.php?edit=' . $entityId;
                                } elseif ($title !== '') {
                                    $viewHref = 'manage_books.php?search=' . urlencode($title);
                                }
                            }
                        ?>
                        <tr>
                            <td><?= htmlspecialchars((string)($row['created_at'] ?? '-')) ?></td>
                            <td>
                                <span class="archive-action-text archive-action-<?= htmlspecialchars($actionVal) ?>">
                                    <?= htmlspecialchars(action_label($actionVal)) ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars(ucfirst($entityType)) ?></td>
                            <td>
                                <?= htmlspecialchars($title) ?>
                                <?php if ($entityId > 0): ?>
                                    <small class="archive-meta">#<?= $entityId ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($metaText) ?></td>
                            <td>
                                <?= htmlspecialchars($resolvedName !== '' ? $resolvedName : 'System Admin') ?>
                                <?php if ($userNumber !== ''): ?>
                                    <small class="archive-meta"><?= htmlspecialchars($userNumber) ?></small>
                                <?php endif; ?>
                            </td>
                            <td class="archive-action-cell">
                                <?php if ($canRetrieveBook && $restoredBookId <= 0): ?>
                                    <form method="POST" class="archive-action-form archive-retrieve-form" data-archive-title="<?= htmlspecialchars($title) ?>">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($archiveCsrfToken) ?>">
                                        <input type="hidden" name="archive_action" value="retrieve_book">
                                        <input type="hidden" name="activity_id" value="<?= (int)($row['activity_id'] ?? 0) ?>">
                                        <button type="submit" class="archive-action-btn">Retrieve</button>
                                    </form>
                                <?php elseif ($canRetrieveBook && $restoredBookId > 0): ?>
                                    <span class="archive-retrieved">Retrieved #<?= $restoredBookId ?></span>
                                <?php elseif ($isAddedBook && $viewHref !== ''): ?>
                                    <a href="<?= htmlspecialchars($viewHref) ?>" class="archive-action-btn archive-action-btn-view">Open</a>
                                <?php else: ?>
                                    <span class="archive-action-muted">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7">No archived history found for the selected filters.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<div class="overlay-modal" id="archiveRetrieveConfirmModal" aria-hidden="true">
    <div class="overlay-card glass-card archive-confirm-card" role="dialog" aria-modal="true" aria-labelledby="archiveRetrieveConfirmTitle" aria-describedby="archiveRetrieveConfirmText">
        <div class="archive-confirm-header">
            <span class="archive-confirm-icon" aria-hidden="true">i</span>
            <div>
                <h2 id="archiveRetrieveConfirmTitle">Retrieve Book</h2>
                <p id="archiveRetrieveConfirmText">Retrieve this deleted book into Books?</p>
            </div>
        </div>
        <p class="archive-confirm-note">This restores the book as a new active record and logs the action in Archives.</p>
        <div class="archive-confirm-actions">
            <button type="button" class="archive-confirm-cancel">Cancel</button>
            <button type="button" class="archive-confirm-submit">Retrieve</button>
        </div>
    </div>
</div>

<script>
const archiveRetrieveModal = document.getElementById('archiveRetrieveConfirmModal');
const archiveRetrieveText = document.getElementById('archiveRetrieveConfirmText');
const archiveRetrieveCancel = document.querySelector('.archive-confirm-cancel');
const archiveRetrieveSubmit = document.querySelector('.archive-confirm-submit');
let pendingArchiveRetrieveForm = null;

function openArchiveRetrieveConfirm(formEl) {
    if (!archiveRetrieveModal || !archiveRetrieveText || !archiveRetrieveSubmit) return;

    pendingArchiveRetrieveForm = formEl;
    const rawTitle = (formEl && formEl.dataset && formEl.dataset.archiveTitle) ? formEl.dataset.archiveTitle.trim() : '';
    const label = rawTitle !== '' ? '"' + rawTitle + '"' : 'this deleted book';
    archiveRetrieveText.textContent = 'Retrieve ' + label + ' into Books?';

    archiveRetrieveModal.classList.add('show');
    archiveRetrieveModal.setAttribute('aria-hidden', 'false');
    archiveRetrieveSubmit.focus();
}

function closeArchiveRetrieveConfirm() {
    if (!archiveRetrieveModal) return;
    archiveRetrieveModal.classList.remove('show');
    archiveRetrieveModal.setAttribute('aria-hidden', 'true');
    pendingArchiveRetrieveForm = null;
}

document.querySelectorAll('.archive-retrieve-form').forEach(function (formEl) {
    formEl.addEventListener('submit', function (event) {
        event.preventDefault();
        openArchiveRetrieveConfirm(formEl);
    });
});

if (archiveRetrieveCancel) {
    archiveRetrieveCancel.addEventListener('click', function () {
        closeArchiveRetrieveConfirm();
    });
}

if (archiveRetrieveSubmit) {
    archiveRetrieveSubmit.addEventListener('click', function () {
        if (!pendingArchiveRetrieveForm) {
            closeArchiveRetrieveConfirm();
            return;
        }

        const formToSubmit = pendingArchiveRetrieveForm;
        pendingArchiveRetrieveForm = null;
        archiveRetrieveModal.classList.remove('show');
        archiveRetrieveModal.setAttribute('aria-hidden', 'true');
        formToSubmit.submit();
    });
}

window.addEventListener('click', function (event) {
    if (event.target === archiveRetrieveModal) {
        closeArchiveRetrieveConfirm();
    }
});

window.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && archiveRetrieveModal && archiveRetrieveModal.classList.contains('show')) {
        closeArchiveRetrieveConfirm();
    }
});
</script>

<?php require 'layout_bottom.php'; ?>


