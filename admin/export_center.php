<?php require 'layout_top.php'; ?>
<?php
require_once '../config/db_connect.php';
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
        'icon' => 'book-open',
        'title' => 'Books Summary',
        'description' => 'Catalog titles with ISBN, category, program, location, call number, and copy totals.',
        'count' => export_center_scalar($conn, 'SELECT COUNT(*) FROM books'),
        'count_label' => 'titles'
    ],
    [
        'type' => 'copies',
        'icon' => 'layers',
        'title' => 'Book Copies',
        'description' => 'Every physical copy with accession number, ISBN, title, author, and copy status.',
        'count' => export_center_scalar($conn, 'SELECT COUNT(*) FROM book_copies'),
        'count_label' => 'copies'
    ],
    [
        'type' => 'users',
        'icon' => 'people',
        'title' => 'Borrowers & Staff',
        'description' => 'User directory without passwords, including program, role, status, active loans, and fine totals.',
        'count' => export_center_scalar($conn, 'SELECT COUNT(*) FROM library_users'),
        'count_label' => 'accounts'
    ],
    [
        'type' => 'borrow_records',
        'icon' => 'swap',
        'title' => 'Borrow Records',
        'description' => 'Full circulation history with borrower, book, dates, status, fine, and renewal details.',
        'count' => export_center_scalar($conn, 'SELECT COUNT(*) FROM borrow_records'),
        'count_label' => 'records'
    ],
    [
        'type' => 'overdue_missing',
        'icon' => 'alert-triangle',
        'title' => 'Overdue & Missing',
        'description' => 'Focused list of overdue and missing loans for follow-up and collection control.',
        'count' => export_center_scalar($conn, "SELECT COUNT(*) FROM borrow_records WHERE status IN ('overdue', 'missing')"),
        'count_label' => 'flagged'
    ]
];
?>

<div class="page-top export-center-top">
    <div>
        <h1 class="page-heading"><span class="page-icon" aria-hidden="true"><?= admin_icon('export') ?></span>Data Export</h1>
        <p class="page-subtitle">Reports are for summaries. Exports are raw CSV backups for catalog, borrowers, circulation, overdue loans, and missing books.</p>
    </div>
    <a href="reports.php" class="filter-reset-btn">Open Reports</a>
</div>

<section class="panel glass-card export-center-note data-table-panel">
    <div>
        <h2>CSV data exports</h2>
        <p>Use these raw CSV files for backup snapshots, spreadsheet audits, migration prep, and school library inspections. Password hashes are never included.</p>
    </div>
    <span>Generated on download</span>
</section>

<section class="reports-quick export-center-grid">
    <?php foreach ($exportCards as $card): ?>
        <article class="panel glass-card reports-quick-card export-card data-table-panel">
            <div class="reports-card-icon export-card-icon" aria-hidden="true"><?= admin_icon((string)$card['icon']) ?></div>
            <div class="reports-card-body export-card-body">
                <div class="export-card-title-row">
                    <h3><?= htmlspecialchars($card['title']) ?></h3>
                    <span><?= number_format((int)$card['count']) ?> <?= htmlspecialchars($card['count_label']) ?></span>
                </div>
                <p><?= htmlspecialchars($card['description']) ?></p>
                <div class="reports-card-actions">
                    <a class="reports-export-link export-download-btn" href="export_csv.php?type=<?= urlencode((string)$card['type']) ?>"><?= admin_icon('export') ?><span>Download CSV</span></a>
                </div>
            </div>
        </article>
    <?php endforeach; ?>
</section>

<section class="panel glass-card export-center-table-panel data-table-panel">
    <div class="panel-head"><h2>What Each Export Includes</h2></div>
    <div class="table-wrap">
        <table class="data-table export-center-table">
            <thead><tr><th>Export</th><th>Useful For</th><th>Sensitive Data</th></tr></thead>
            <tbody>
                <?php
                // Sensitivity tiers: "none" = no personal data at all; "personal" = names,
                // ID numbers or emails (never passwords) -- handle like a class list.
                $includesRows = [
                    ['icon' => 'book-open', 'name' => 'Books Summary', 'use' => 'Inventory checks, title lists, copy availability summaries', 'tier' => 'none', 'note' => 'No passwords or borrower data'],
                    ['icon' => 'layers', 'name' => 'Book Copies', 'use' => 'Accession-number audits and copy status checking', 'tier' => 'none', 'note' => 'No borrower data'],
                    ['icon' => 'people', 'name' => 'Borrowers & Staff', 'use' => 'Account review, active-loan counts, user status checks', 'tier' => 'personal', 'note' => 'Names, ID numbers and emails; password column excluded'],
                    ['icon' => 'swap', 'name' => 'Borrow Records', 'use' => 'Complete circulation reporting and fine reconciliation', 'tier' => 'personal', 'note' => 'Includes borrower identity needed for library operations'],
                    ['icon' => 'alert-triangle', 'name' => 'Overdue & Missing', 'use' => 'Follow-up lists for unresolved loans', 'tier' => 'personal', 'note' => 'Includes borrower identity needed for follow-up'],
                ];
                ?>
                <?php foreach ($includesRows as $row): ?>
                    <tr>
                        <td><span class="export-include-name"><span class="export-include-icon" aria-hidden="true"><?= admin_icon($row['icon']) ?></span><?= htmlspecialchars($row['name']) ?></span></td>
                        <td><?= htmlspecialchars($row['use']) ?></td>
                        <td>
                            <span class="export-sensitivity">
                                <span class="export-sensitivity-badge export-sensitivity-<?= $row['tier'] ?>"><?= $row['tier'] === 'none' ? 'None' : 'Personal data' ?></span>
                                <span class="export-sensitivity-note"><?= htmlspecialchars($row['note']) ?></span>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require 'layout_bottom.php'; ?>