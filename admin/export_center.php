<?php require 'layout_top.php'; ?>
<?php
require '../config/db_connect.php';
require_once '../config/borrow_fine_rules.php';

function export_center_scalar(mysqli $conn, string $sql): int {
    try {
        $res = $conn->query($sql);
    } catch (Throwable $e) {
        return 0;
    }

    if (!$res) {
        return 0;
    }

    $row = $res->fetch_row();
    return $row ? (int)$row[0] : 0;
}

smartlib_ensure_borrow_renewal_columns($conn);
sync_overdue_status_and_fines($conn);

$exportCards = [
    [
        'type' => 'books',
        'title' => 'Books Summary',
        'description' => 'Catalog titles with ISBN, category, program, location, call number, and copy totals.',
        'count' => export_center_scalar($conn, 'SELECT COUNT(*) FROM books'),
        'count_label' => 'titles'
    ],
    [
        'type' => 'copies',
        'title' => 'Book Copies',
        'description' => 'Every physical copy with accession number, ISBN, title, author, and copy status.',
        'count' => export_center_scalar($conn, 'SELECT COUNT(*) FROM book_copies'),
        'count_label' => 'copies'
    ],
    [
        'type' => 'users',
        'title' => 'Borrowers & Staff',
        'description' => 'User directory without passwords, including program, role, status, active loans, and fine totals.',
        'count' => export_center_scalar($conn, 'SELECT COUNT(*) FROM library_users'),
        'count_label' => 'accounts'
    ],
    [
        'type' => 'borrow_records',
        'title' => 'Borrow Records',
        'description' => 'Full circulation history with borrower, book, dates, status, fine, and renewal details.',
        'count' => export_center_scalar($conn, 'SELECT COUNT(*) FROM borrow_records'),
        'count_label' => 'records'
    ],
    [
        'type' => 'overdue_missing',
        'title' => 'Overdue & Missing',
        'description' => 'Focused list of overdue and missing loans for follow-up and collection control.',
        'count' => export_center_scalar($conn, "SELECT COUNT(*) FROM borrow_records WHERE status IN ('overdue', 'missing')"),
        'count_label' => 'flagged'
    ]
];
?>

<div class="page-top export-center-top">
    <div>
        <h1>Export Center</h1>
        <p class="page-subtitle">Download clean CSV backups for catalog, borrowers, circulation, overdue loans, and missing books.</p>
    </div>
    <a href="reports.php" class="filter-reset-btn">Open Reports</a>
</div>

<section class="panel glass-card export-center-note">
    <div>
        <h2>Backup-ready CSV exports</h2>
        <p>Use these files for spreadsheet reporting, backup snapshots, and school library inspections. Password hashes are never included.</p>
    </div>
    <span>Generated on download</span>
</section>

<section class="reports-quick export-center-grid">
    <?php foreach ($exportCards as $card): ?>
        <article class="panel glass-card reports-quick-card export-card">
            <div class="reports-card-icon export-card-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24">
                    <path d="M12 4v10"></path>
                    <path d="m8 10 4 4 4-4"></path>
                    <path d="M5 19h14"></path>
                </svg>
            </div>
            <div class="reports-card-body export-card-body">
                <div class="export-card-title-row">
                    <h3><?= htmlspecialchars($card['title']) ?></h3>
                    <span><?= number_format((int)$card['count']) ?> <?= htmlspecialchars($card['count_label']) ?></span>
                </div>
                <p><?= htmlspecialchars($card['description']) ?></p>
                <div class="reports-card-actions">
                    <a class="reports-export-link export-download-btn" href="export_csv.php?type=<?= urlencode((string)$card['type']) ?>">Download CSV</a>
                </div>
            </div>
        </article>
    <?php endforeach; ?>
</section>

<section class="panel glass-card export-center-table-panel">
    <div class="panel-head"><h2>What Each Export Includes</h2></div>
    <div class="table-wrap">
        <table class="data-table export-center-table">
            <thead><tr><th>Export</th><th>Useful For</th><th>Sensitive Data</th></tr></thead>
            <tbody>
                <tr><td>Books Summary</td><td>Inventory checks, title lists, copy availability summaries</td><td>No passwords or borrower data</td></tr>
                <tr><td>Book Copies</td><td>Accession-number audits and copy status checking</td><td>No borrower data</td></tr>
                <tr><td>Borrowers & Staff</td><td>Account review, active-loan counts, user status checks</td><td>Password column excluded</td></tr>
                <tr><td>Borrow Records</td><td>Complete circulation reporting and fine reconciliation</td><td>Includes borrower identity needed for library operations</td></tr>
                <tr><td>Overdue & Missing</td><td>Follow-up lists for unresolved loans</td><td>Includes borrower identity needed for follow-up</td></tr>
            </tbody>
        </table>
    </div>
</section>

<?php require 'layout_bottom.php'; ?>