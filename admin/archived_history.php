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
require_once '../config/session_guard.php';
if (!smartlib_active_staff_session($conn)) {
    header('Location: ../index.php');
    exit;
}
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

/**
 * The detail fields that matter most for each kind of entry, shown first in
 * the Details column (up to 4). Without this, entries whose details include
 * an ISBN/author only ever showed those, and the rest fell back to their
 * first 4 stored fields -- which hid the borrower and fine on returns, the
 * copy counts on book edits, "PIN changed" on user edits, and so on. This
 * reads existing rows as they are; nothing stored is rewritten.
 */
function meta_summary_priority_fields(string $action, string $entity): array {
    $map = [
        'returned|borrow_record' => ['borrower', 'borrower_number', 'fine', 'accession_no'],
        'missing|borrow_record' => ['borrower', 'borrower_number', 'accession_no', 'copy_status'],
        'updated|book' => ['copies_added', 'copies_removed', 'copy_count_after_update', 'isbn'],
        'updated|user' => ['user_number', 'role', 'pin_changed', 'program_id'],
        'deleted|user' => ['user_number', 'loans_deleted', 'copies_reset_to_available', 'role'],
        'retrieved|book' => ['retrieved_from_activity_id', 'isbn', 'author'],
    ];
    return $map[$action . '|' . $entity] ?? [];
}

function meta_field_label(string $key): string {
    $labels = [
        'borrower_number' => 'Borrower ID',
        'copy_count_after_update' => 'Copies after',
        'copies_reset_to_available' => 'Copies reset',
        'retrieved_from_activity_id' => 'Retrieved from',
        'pin_changed' => 'PIN changed',
        'program_id' => 'Program',
        'copy_status' => 'Copy marked',
        'accession_no' => 'Accession',
        'isbn' => 'ISBN',
    ];
    return $labels[$key] ?? ucfirst(str_replace('_', ' ', $key));
}

function meta_program_name(int $programId): string {
    static $names = null;
    if ($names === null) {
        $names = [];
        global $conn;
        $res = ($conn instanceof mysqli) ? safe_query($conn, 'SELECT program_id, program_name FROM programs') : false;
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $names[(int)$row['program_id']] = (string)$row['program_name'];
            }
        }
    }
    return $names[$programId] ?? ('#' . $programId);
}

function meta_field_value(string $key, $value): string {
    if ($key === 'program_id') {
        return ($value === null || (int)$value <= 0) ? 'None' : meta_program_name((int)$value);
    }
    if ($key === 'fine') {
        return 'PHP ' . number_format((float)$value, 2);
    }
    if ($key === 'retrieved_from_activity_id') {
        return 'entry #' . (int)$value;
    }
    if (is_bool($value)) {
        return $value ? 'Yes' : 'No';
    }
    if (is_array($value)) {
        $flat = array_filter(array_map(fn($v) => is_scalar($v) ? (string)$v : '', $value), fn($v) => $v !== '');
        return empty($flat) ? 'none' : implode(', ', $flat);
    }
    if ($value === null || $value === '') {
        return '-';
    }
    return (string)$value;
}

function meta_summary(?string $json, string $action = '', string $entity = ''): string {
    $decoded = parse_meta_json($json);
    if (empty($decoded)) {
        $raw = trim((string)$json);
        return $raw === '' ? '-' : $raw;
    }

    $priority = meta_summary_priority_fields($action, $entity);
    if (!empty($priority)) {
        $parts = [];
        foreach ($priority as $key) {
            if (array_key_exists($key, $decoded)) {
                $parts[] = meta_field_label($key) . ': ' . meta_field_value($key, $decoded[$key]);
            }
        }
        if (!empty($parts)) {
            return implode(' | ', $parts);
        }
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

// Every action type belongs to exactly one stat card, so Total always equals
// the sum of the cards, and clicking a card lists exactly the rows it counts.
// "Updated" is the catch-all: any action type added later lands there too.
const ARCHIVE_CARD_GROUPS = [
    'added' => ['added', 'bulk_imported', 'retrieved'],
    'returned' => ['returned'],
    'missing' => ['missing'],
    'deleted' => ['deleted'],
    'updated' => ['updated', 'status_changed', 'cover_refetched', 'cover_uploaded', 'cover_cleared'],
];
const ARCHIVE_ROW_LIMIT = 350;

function archive_card_for_action(string $actionType): string {
    foreach (ARCHIVE_CARD_GROUPS as $card => $types) {
        if (in_array($actionType, $types, true)) {
            return $card;
        }
    }
    return 'updated';
}

function archive_sql_in(mysqli $conn, array $values): string {
    return implode(', ', array_map(fn($v) => "'" . $conn->real_escape_string((string)$v) . "'", $values));
}

// Strict YYYY-MM-DD check; anything else is reported instead of silently
// matching nothing.
function archive_valid_date(string $value): bool {
    $dt = DateTime::createFromFormat('!Y-m-d', $value);
    return $dt !== false && $dt->format('Y-m-d') === $value;
}

$dateError = '';
foreach (['start' => $dateFrom, 'end' => $dateTo] as $which => $value) {
    if ($value !== '' && !archive_valid_date($value)) {
        $dateError = $dateFilter !== ''
            ? '"' . $value . '" is not a valid date. Pick a date, or press Reset.'
            : '"' . $value . '" is not a valid ' . $which . ' date (use YYYY-MM-DD). Fix the date range, or press Reset.';
        break;
    }
}
if ($dateError === '' && $dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
    $dateError = 'The start date (' . $dateFrom . ') is after the end date (' . $dateTo . '). Swap them, or press Reset.';
}

$where = ['1=1'];

if (isset(ARCHIVE_CARD_GROUPS[$action])) {
    if ($action === 'updated') {
        // Catch-all card: everything not claimed by another card.
        $others = [];
        foreach (ARCHIVE_CARD_GROUPS as $card => $types) {
            if ($card !== 'updated') {
                $others = array_merge($others, $types);
            }
        }
        $where[] = 'a.action_type NOT IN (' . archive_sql_in($conn, $others) . ')';
    } else {
        $where[] = 'a.action_type IN (' . archive_sql_in($conn, ARCHIVE_CARD_GROUPS[$action]) . ')';
    }
} elseif (in_array($action, smartlib_admin_audit_actions(), true)) {
    // A single action type, e.g. ?action=status_changed (no card of its own).
    $where[] = "a.action_type = '" . $conn->real_escape_string($action) . "'";
}

if ($search !== '') {
    // % and _ are literal characters here, not wildcards.
    $safeSearch = $conn->real_escape_string(addcslashes($search, '\\%_'));
    // "By" shows the account's current name when the entry is linked to an
    // account, else the name stored at the time -- search the same thing.
    $where[] = "(
        a.entity_title LIKE '%{$safeSearch}%'
        OR a.entity_type LIKE '%{$safeSearch}%'
        OR a.metadata_json LIKE '%{$safeSearch}%'
        OR (CASE WHEN TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) <> ''
                 THEN TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) COLLATE utf8mb4_unicode_ci
                 ELSE a.actor_name END) LIKE '%{$safeSearch}%'
        OR COALESCE(u.user_number, '') LIKE '%{$safeSearch}%'
    )";
}

if ($dateError === '') {
    if ($dateFrom !== '') {
        $where[] = "DATE(a.created_at) >= '" . $conn->real_escape_string($dateFrom) . "'";
    }
    if ($dateTo !== '') {
        $where[] = "DATE(a.created_at) <= '" . $conn->real_escape_string($dateTo) . "'";
    }
}

$whereSql = implode(' AND ', $where);

$cardCounts = array_fill_keys(array_keys(ARCHIVE_CARD_GROUPS), 0);
$totalLogs = 0;
$matchingTotal = 0;
$rows = [];
$existingBookIds = [];

if ($tableReady) {
    $resTypes = safe_query($conn, 'SELECT action_type, COUNT(*) AS total FROM admin_activity_logs GROUP BY action_type');
    if ($resTypes) {
        while ($r = $resTypes->fetch_assoc()) {
            $n = (int)($r['total'] ?? 0);
            $cardCounts[archive_card_for_action(strtolower((string)$r['action_type']))] += $n;
            $totalLogs += $n;
        }
    }

    if ($dateError === '') {
        $fromSql = "FROM admin_activity_logs a LEFT JOIN library_users u ON u.user_id = a.actor_user_id WHERE {$whereSql}";

        $resMatching = safe_query($conn, "SELECT COUNT(*) AS total {$fromSql}");
        if ($resMatching && ($r = $resMatching->fetch_assoc())) {
            $matchingTotal = (int)($r['total'] ?? 0);
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
            {$fromSql}
            ORDER BY a.created_at DESC, a.activity_id DESC
            LIMIT " . ARCHIVE_ROW_LIMIT
        );

        if ($historyRes && $historyRes->num_rows > 0) {
            while ($row = $historyRes->fetch_assoc()) {
                $rows[] = $row;
            }
        }
    }

    // Which "added book" rows still point at a book that exists, so the
    // Open link is only shown when it leads somewhere.
    $addedBookIds = [];
    foreach ($rows as $row) {
        if (strtolower((string)$row['action_type']) === 'added' && strtolower((string)$row['entity_type']) === 'book' && (int)$row['entity_id'] > 0) {
            $addedBookIds[] = (int)$row['entity_id'];
        }
    }
    if (!empty($addedBookIds)) {
        $resBooks = safe_query($conn, 'SELECT book_id FROM books WHERE book_id IN (' . implode(',', array_unique($addedBookIds)) . ')');
        if ($resBooks) {
            while ($r = $resBooks->fetch_assoc()) {
                $existingBookIds[(int)$r['book_id']] = true;
            }
        }
    }
}

$isTruncated = $matchingTotal > count($rows);

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
    ['action' => 'added', 'title' => 'Added', 'count' => $cardCounts['added']],
    ['action' => 'updated', 'title' => 'Updated', 'count' => $cardCounts['updated']],
    ['action' => 'returned', 'title' => 'Returned', 'count' => $cardCounts['returned']],
    ['action' => 'missing', 'title' => 'Missing', 'count' => $cardCounts['missing']],
    ['action' => 'deleted', 'title' => 'Deleted', 'count' => $cardCounts['deleted']]
];
$archiveFlash = pull_archive_flash();

require 'layout_top.php';
?>

<div class="page-top archive-top-row">
    <h1 class="page-heading"><span class="page-icon" aria-hidden="true"><?= admin_icon('archives') ?></span>Audit Log</h1>
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
    <form method="GET" class="filters-inline borrow-filters archive-filters">
        <div class="search-field-wrap">
            <span class="filters-search-icon" aria-hidden="true"><?= admin_icon('search') ?></span>
            <input type="text" name="search" placeholder="Search item, admin, action details" value="<?= htmlspecialchars($search) ?>">
        </div>

        <input type="hidden" name="action" value="<?= htmlspecialchars($action) ?>">


        <input type="date" name="date" value="<?= htmlspecialchars($dateFilter) ?>">

        <button type="submit" class="btn-primary">Apply</button>
        <a href="archived_history.php" class="filter-reset-btn">Reset</a>
    </form>
</section>

<?php if ($dateError !== ''): ?>
    <section class="panel glass-card borrow-flash borrow-flash-error archive-filter-notice" role="alert">
        <?= htmlspecialchars($dateError) ?>
    </section>
<?php elseif ($isTruncated): ?>
    <section class="panel glass-card borrow-flash borrow-flash-info archive-filter-notice" role="status">
        Showing the newest <?= number_format(count($rows)) ?> of <?= number_format($matchingTotal) ?> matching entries. Narrow your search or pick a date to see the rest.
    </section>
<?php endif; ?>

<section class="panel glass-card data-table-panel">
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
                            $metaText = meta_summary((string)($row['metadata_json'] ?? ''), $actionVal, $entityType);
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
                            $bookGone = $isAddedBook && $entityId > 0 && !isset($existingBookIds[$entityId]);
                            if ($isAddedBook && !$bookGone) {
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
                            <td><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $entityType))) ?></td>
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
                                        <button type="submit" class="archive-action-btn">
                                            <span class="archive-action-icon" aria-hidden="true"><?= admin_icon('archive-restore') ?></span>
                                            Retrieve
                                        </button>
                                    </form>
                                <?php elseif ($canRetrieveBook && $restoredBookId > 0): ?>
                                    <span class="archive-retrieved"><span class="archive-action-icon" aria-hidden="true"><?= admin_icon('check-circle') ?></span>Retrieved #<?= $restoredBookId ?></span>
                                <?php elseif ($bookGone): ?>
                                    <span class="archive-action-muted" title="This book has since been deleted">Book deleted</span>
                                <?php elseif ($isAddedBook && $viewHref !== ''): ?>
                                    <a href="<?= htmlspecialchars($viewHref) ?>" class="archive-action-btn archive-action-btn-view">
                                        <span class="archive-action-icon" aria-hidden="true"><?= admin_icon('book-open') ?></span>
                                        Open
                                    </a>
                                <?php else: ?>
                                    <span class="archive-action-muted" aria-label="No action available">&mdash;</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7"><?= $dateError !== '' ? 'Fix the date filter above to see entries.' : 'No archived history found for the selected filters.' ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<div class="overlay-modal" id="archiveRetrieveConfirmModal" aria-hidden="true">
    <div class="overlay-card glass-card archive-confirm-card confirm-modal-card" role="dialog" aria-modal="true" aria-labelledby="archiveRetrieveConfirmTitle" aria-describedby="archiveRetrieveConfirmText">
        <button type="button" class="icon-close archive-confirm-cancel confirm-modal-close" aria-label="Cancel">&times;</button>
        <div class="archive-confirm-header confirm-modal-header">
            <span class="archive-confirm-icon confirm-modal-icon is-positive" aria-hidden="true">i</span>
            <div>
                <h2 id="archiveRetrieveConfirmTitle">Retrieve Book</h2>
                <p id="archiveRetrieveConfirmText">Retrieve this deleted book into Books?</p>
            </div>
        </div>
        <p class="archive-confirm-note confirm-modal-note">This restores the book as a new active record and logs the action in Archives.</p>
        <div class="archive-confirm-actions confirm-modal-actions">
            <button type="button" class="archive-confirm-submit confirm-modal-confirm is-positive">Retrieve</button>
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
