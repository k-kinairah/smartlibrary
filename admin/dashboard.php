<?php require 'layout_top.php'; ?>
<?php
require '../config/db_connect.php';

function safe_query(mysqli $conn, string $sql) {
    try {
        return $conn->query($sql);
    } catch (Throwable $e) {
        return false;
    }
}

function safe_scalar_int(mysqli $conn, string $sql, int $fallback = 0): int {
    $res = safe_query($conn, $sql);
    if (!$res) {
        return $fallback;
    }

    $row = $res->fetch_row();
    return $row ? (int)$row[0] : $fallback;
}

$totalBooks = safe_scalar_int($conn, 'SELECT COUNT(*) FROM books');
$totalStudents = safe_scalar_int($conn, "SELECT COUNT(*) FROM library_users WHERE role = 'student'");
$totalBorrows = safe_scalar_int($conn, 'SELECT COUNT(*) FROM borrow_records');
$pendingRequests = safe_scalar_int($conn, 'SELECT COUNT(*) FROM notifications WHERE is_read = 0');

$mostBorrowedLabels = [];
$mostBorrowedValues = [];
$mostBorrowedRes = safe_query(
    $conn,
    "
    SELECT
        b.title,
        COUNT(br.record_id) AS borrow_count
    FROM books b
    LEFT JOIN book_copies bc ON bc.book_id = b.book_id
    LEFT JOIN borrow_records br ON br.copy_id = bc.copy_id
    GROUP BY b.book_id, b.title
    ORDER BY borrow_count DESC, b.title ASC
    LIMIT 10
    "
);

if ($mostBorrowedRes && $mostBorrowedRes->num_rows > 0) {
    while ($row = $mostBorrowedRes->fetch_assoc()) {
        $mostBorrowedLabels[] = (string)($row['title'] ?? 'Untitled');
        $mostBorrowedValues[] = (int)($row['borrow_count'] ?? 0);
    }
}

$trendMap = [];
$trendRes = safe_query(
    $conn,
    "
    SELECT
        DATE_FORMAT(COALESCE(date_borrowed, created_at), '%Y-%m') AS ym,
        COUNT(*) AS total
    FROM borrow_records
    WHERE COALESCE(date_borrowed, created_at) >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
    GROUP BY ym
    ORDER BY ym ASC
    "
);

if ($trendRes && $trendRes->num_rows > 0) {
    while ($row = $trendRes->fetch_assoc()) {
        $trendMap[(string)$row['ym']] = (int)$row['total'];
    }
}

$trendLabels = [];
$trendValues = [];
$now = new DateTime('first day of this month');
for ($i = 5; $i >= 0; $i--) {
    $d = (clone $now)->modify("-$i month");
    $ym = $d->format('Y-m');
    $trendLabels[] = $d->format('M Y');
    $trendValues[] = (int)($trendMap[$ym] ?? 0);
}

$categoryLabels = [];
$categoryValues = [];
$categoryRes = safe_query(
    $conn,
    "
    SELECT
        COALESCE(c.category_name, 'Uncategorized') AS category_name,
        COUNT(*) AS total
    FROM books b
    LEFT JOIN categories c ON b.category_id = c.category_id
    GROUP BY COALESCE(c.category_name, 'Uncategorized')
    ORDER BY total DESC, category_name ASC
    "
);

if ($categoryRes && $categoryRes->num_rows > 0) {
    while ($row = $categoryRes->fetch_assoc()) {
        $count = (int)($row['total'] ?? 0);
        if ($count <= 0) {
            continue;
        }
        $categoryLabels[] = (string)($row['category_name'] ?? 'Uncategorized');
        $categoryValues[] = $count;
    }
}

if (empty($categoryLabels)) {
    $categoryLabels = ['No Data'];
    $categoryValues = [1];
}

$recentRows = [];
$recentBorrows = safe_query(
    $conn,
    "
    SELECT
        CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) AS borrower,
        COALESCE(b.title, 'Unknown Book') AS title,
        COALESCE(br.date_borrowed, DATE(br.created_at)) AS borrowed_date,
        COALESCE(br.status, 'borrowed') AS status
    FROM borrow_records br
    LEFT JOIN library_users u ON br.user_id = u.user_id
    LEFT JOIN book_copies bc ON br.copy_id = bc.copy_id
    LEFT JOIN books b ON bc.book_id = b.book_id
    ORDER BY COALESCE(br.date_borrowed, DATE(br.created_at)) DESC
    LIMIT 8
    "
);

if ($recentBorrows && $recentBorrows->num_rows > 0) {
    while ($row = $recentBorrows->fetch_assoc()) {
        $recentRows[] = $row;
    }
}

$chartPayload = [
    'mostBorrowed' => [
        'labels' => $mostBorrowedLabels,
        'values' => $mostBorrowedValues
    ],
    'monthlyTrend' => [
        'labels' => $trendLabels,
        'values' => $trendValues
    ],
    'categoryDistribution' => [
        'labels' => $categoryLabels,
        'values' => $categoryValues
    ]
];

$chartJson = json_encode($chartPayload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS);
?>

<div class="page-top dashboard-top">
    <div>
        <h1>Dashboard</h1>
        <p class="page-subtitle">Live system overview for books, borrowing activity, and category balance.</p>
    </div>
    <div class="welcome">Welcome, <?= htmlspecialchars($_SESSION['name'] ?? 'Librarian') ?></div>
</div>

<section class="stats-grid dashboard-metrics">
    <article class="stat-card glass-card metric-card">
        <div class="metric-label">Total Books</div>
        <div class="metric-value"><?= number_format($totalBooks) ?></div>
        <div class="metric-meta">Catalog titles in collection</div>
    </article>

    <article class="stat-card glass-card metric-card">
        <div class="metric-label">Total Students</div>
        <div class="metric-value"><?= number_format($totalStudents) ?></div>
        <div class="metric-meta">Registered student accounts</div>
    </article>

    <article class="stat-card glass-card metric-card">
        <div class="metric-label">Total Borrows</div>
        <div class="metric-value"><?= number_format($totalBorrows) ?></div>
        <div class="metric-meta">All-time borrow transactions</div>
    </article>

    <article class="stat-card glass-card metric-card">
        <div class="metric-label">Pending Requests</div>
        <div class="metric-value warn"><?= number_format($pendingRequests) ?></div>
        <div class="metric-meta">Unread notifications queue</div>
    </article>
</section>

<section class="dashboard-chart-grid">
    <article class="panel glass-card chart-panel">
        <div class="panel-head">
            <div>
                <h2>Most Borrowed Books</h2>
                <p class="panel-sub">Top 10 books by borrow count</p>
            </div>
        </div>
        <div id="most-borrowed-chart" class="chart-shell"></div>
    </article>

    <article class="panel glass-card chart-panel">
        <div class="panel-head">
            <div>
                <h2>Monthly Borrowing Trends</h2>
                <p class="panel-sub">Borrow activity over the last 6 months</p>
            </div>
        </div>
        <div id="monthly-trend-chart" class="chart-shell"></div>
    </article>
</section>

<section class="panel glass-card chart-panel category-panel">
    <div class="panel-head">
        <div>
            <h2>Book Category Distribution</h2>
            <p class="panel-sub">Collection balance grouped by category</p>
        </div>
    </div>

    <div class="category-layout">
        <div id="category-donut" class="donut-wrap"></div>
        <div id="category-legend" class="donut-legend"></div>
    </div>
</section>

<section class="panel glass-card">
    <div class="panel-head">
        <h2>Recent Borrow Activity</h2>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Book</th>
                    <th>Borrow Date</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($recentRows)): ?>
                    <?php foreach ($recentRows as $row): ?>
                        <?php $status = (string)($row['status'] ?? 'borrowed'); ?>
                        <tr>
                            <td><?= htmlspecialchars(trim((string)($row['borrower'] ?? 'Unknown User'))) ?></td>
                            <td><?= htmlspecialchars((string)($row['title'] ?? 'Unknown Book')) ?></td>
                            <td><?= htmlspecialchars((string)($row['borrowed_date'] ?? '-')) ?></td>
                            <td>
                                <span class="badge status-<?= htmlspecialchars($status) ?>">
                                    <?= htmlspecialchars(ucfirst($status)) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="4">No recent activity yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<script id="dashboard-data" type="application/json"><?= $chartJson ?: '{}' ?></script>
<?php $dashJsVer = @filemtime(__DIR__ . '/../assets/javascript/admin_dashboard.js') ?: time(); ?>
<script src="../assets/javascript/admin_dashboard.js?v=<?= $dashJsVer ?>"></script>

<?php require 'layout_bottom.php'; ?>

