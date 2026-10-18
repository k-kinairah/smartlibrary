<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentRole = strtolower(trim((string)($_SESSION['role'] ?? '')));
if (!isset($_SESSION['user_id']) || !in_array($currentRole, ['librarian', 'admin'], true)) {
    header("Location: ../index.php");
    exit;
}

$currentPage = basename($_SERVER['PHP_SELF']);
$roleLabel = $currentRole === 'admin' ? 'Administrator' : 'Librarian';

function admin_icon(string $key): string {
    $icons = [
        'dashboard' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="4" width="6" height="6" rx="1.2"></rect><rect x="14" y="4" width="6" height="6" rx="1.2"></rect><rect x="4" y="14" width="6" height="6" rx="1.2"></rect><rect x="14" y="14" width="6" height="6" rx="1.2"></rect></svg>',
        'books' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 4.8 6.5 12 10 19.2 6.5 12 3Z"></path><path d="M4.8 11 12 14.5 19.2 11"></path><path d="M4.8 15.5 12 19 19.2 15.5"></path></svg>',
        'borrow' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 5v14"></path><path d="M10 4v15"></path><path d="M14 6v13"></path><path d="M18 4.5 20 19"></path></svg>',
        'reports' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 19.2h14"></path><path d="M8 16v-4.2"></path><path d="M12 16V7.6"></path><path d="M16 16v-6.8"></path></svg>',
        'export' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v10"></path><path d="m8 10 4 4 4-4"></path><path d="M5 19h14"></path><path d="M6 6h12"></path></svg>',
        'users' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8.4" r="2.9"></circle><path d="M5.3 18.8c0-3.2 2.9-5.3 6.7-5.3s6.7 2.1 6.7 5.3"></path></svg>',
        'archives' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8.4A2.4 2.4 0 0 1 6.4 6h3l1.7 1.7H17.6A2.4 2.4 0 0 1 20 10.1v7.5A2.4 2.4 0 0 1 17.6 20H6.4A2.4 2.4 0 0 1 4 17.6V8.4Z"></path></svg>',
        'logout' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 5.5H6.8A1.8 1.8 0 0 0 5 7.3v9.4a1.8 1.8 0 0 0 1.8 1.8H10"></path><path d="M13 8.2 17.8 12 13 15.8"></path><path d="M9.8 12h8"></path></svg>',
        'kiosk' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="7.4" y="3.5" width="9.2" height="17" rx="2"></rect><circle cx="12" cy="17.3" r="0.8" fill="currentColor" stroke="none"></circle></svg>',
        'graduation-cap' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 9.5 12 5l9 4.5-9 4.5-9-4.5Z"></path><path d="M7 11.5v4c0 1.4 2.2 2.5 5 2.5s5-1.1 5-2.5v-4"></path><path d="M20 9.5v5"></path></svg>',
        'id-badge' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="6" y="4" width="12" height="16" rx="2"></rect><circle cx="12" cy="10" r="2.2"></circle><path d="M8.5 16.5c0-1.8 1.6-2.8 3.5-2.8s3.5 1 3.5 2.8"></path></svg>',
        'swap' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8h13"></path><path d="m14 4 4 4-4 4"></path><path d="M20 16H7"></path><path d="m10 12-4 4 4 4"></path></svg>',
        'layers' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 4 7.5 12 12l8-4.5L12 3Z"></path><path d="M4 12.5 12 17l8-4.5"></path></svg>',
        'list' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 6h11"></path><path d="M8 12h11"></path><path d="M8 18h11"></path><circle cx="4.2" cy="6" r="1" fill="currentColor" stroke="none"></circle><circle cx="4.2" cy="12" r="1" fill="currentColor" stroke="none"></circle><circle cx="4.2" cy="18" r="1" fill="currentColor" stroke="none"></circle></svg>',
        'book-open' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 6.5c-1.8-1.3-4.2-1.8-6.5-1.3v11.8c2.3-.5 4.7 0 6.5 1.3"></path><path d="M12 6.5c1.8-1.3 4.2-1.8 6.5-1.3v11.8c-2.3-.5-4.7 0-6.5 1.3"></path><path d="M12 6.5v11.8"></path></svg>',
        'check-circle' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.2"></circle><path d="m8.3 12.3 2.5 2.5 5-5.2"></path></svg>',
        'clock' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.2"></circle><path d="M12 7.5V12l3.2 2"></path></svg>',
        'alert-triangle' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4.5 21 19H3L12 4.5Z"></path><path d="M12 10v4"></path><circle cx="12" cy="16.8" r="0.9" fill="currentColor" stroke="none"></circle></svg>',
        'box' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3.5 8 12 4l8.5 4-8.5 4-8.5-4Z"></path><path d="M3.5 8v8L12 20l8.5-4V8"></path><path d="M12 12v8"></path></svg>',
        'plus-circle' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.2"></circle><path d="M12 8.2v7.6"></path><path d="M8.2 12h7.6"></path></svg>',
        'user-check' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9.5" cy="8.2" r="2.9"></circle><path d="M4 18.5c0-3 2.5-5 5.5-5s5.5 2 5.5 5"></path><path d="m15.5 11.5 1.8 1.8 3-3.2"></path></svg>',
        'user-x' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9.5" cy="8.2" r="2.9"></circle><path d="M4 18.5c0-3 2.5-5 5.5-5s5.5 2 5.5 5"></path><path d="m15 9.5 4 4"></path><path d="m19 9.5-4 4"></path></svg>',
        'shield' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.5 19 6v5.5c0 4.3-3 7.4-7 8.5-4-1.1-7-4.2-7-8.5V6l7-2.5Z"></path><path d="m8.7 12 2.3 2.3 4.3-4.6"></path></svg>',
        'activity' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3.5 12h4l2-5.5 4 11 2-5.5h4.5"></path></svg>',
        'edit' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14.2 5.3 18.7 9.8 8.4 20H4v-4.4L14.2 5.3Z"></path><path d="m12.6 6.9 3.5 3.5"></path></svg>',
        'trash' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 7h14"></path><path d="M9.5 7V5.2a1.2 1.2 0 0 1 1.2-1.2h2.6a1.2 1.2 0 0 1 1.2 1.2V7"></path><path d="M7.5 7 8.3 19a1.6 1.6 0 0 0 1.6 1.5h4.2a1.6 1.6 0 0 0 1.6-1.5L16.5 7"></path><path d="M10.3 11v6"></path><path d="M13.7 11v6"></path></svg>',
        'currency' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.2"></circle><path d="M12 7.5v9"></path><path d="M14.8 9.3c-.5-.8-1.5-1.2-2.8-1.2-1.8 0-3 .9-3 2.1 0 1.1.9 1.6 2.6 2 1.9.4 3.2 1 3.2 2.2 0 1.2-1.3 2.1-3 2.1-1.4 0-2.5-.5-3-1.3"></path></svg>',
        'search' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.3"></circle><path d="m19.5 19.5-4.2-4.2"></path></svg>',
        'arrow-left' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 12H5"></path><path d="m11 6-6 6 6 6"></path></svg>',
        'sparkles' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3c.8 3.7 2.3 5.4 6 6.2-3.7.8-5.2 2.5-6 6.2-.8-3.7-2.3-5.4-6-6.2 3.7-.8 5.2-2.5 6-6.2Z"></path><path d="M18.5 15.5c.3 1.3.9 1.9 2.2 2.2-1.3.3-1.9.9-2.2 2.2-.3-1.3-.9-1.9-2.2-2.2 1.3-.3 1.9-.9 2.2-2.2Z"></path></svg>'
    ];

    return $icons[$key] ?? '';
}

/**
 * Icon + accent color metadata for stat cards / filter chips across the
 * admin pages, keyed by the chip/card title text. Centralizing this here
 * (rather than duplicating it per-page) keeps the icon+color story
 * consistent everywhere the same metric name appears (e.g. "Returned"
 * or "Missing" always get the same icon and accent regardless of which
 * page renders the chip). Accent is one of: blue (neutral count, the
 * default), green (positive/healthy signal), gold (needs attention),
 * red (reserved for "missing" only, per established convention).
 */
function stat_chip_meta(string $title): array {
    $key = strtolower(trim($title));
    $map = [
        'total books' => ['icon' => 'books', 'accent' => 'blue'],
        'total students' => ['icon' => 'graduation-cap', 'accent' => 'blue'],
        'total faculty' => ['icon' => 'id-badge', 'accent' => 'blue'],
        'total borrows' => ['icon' => 'swap', 'accent' => 'blue'],
        'programs' => ['icon' => 'layers', 'accent' => 'blue'],
        'total records' => ['icon' => 'list', 'accent' => 'blue'],
        'current loans' => ['icon' => 'book-open', 'accent' => 'blue'],
        'borrowed' => ['icon' => 'book-open', 'accent' => 'blue'],
        'returned' => ['icon' => 'check-circle', 'accent' => 'green'],
        'available' => ['icon' => 'check-circle', 'accent' => 'green'],
        'overdue' => ['icon' => 'clock', 'accent' => 'gold'],
        'missing' => ['icon' => 'alert-triangle', 'accent' => 'red'],
        'active fine' => ['icon' => 'currency', 'accent' => 'gold'],
        'no copies' => ['icon' => 'box', 'accent' => 'blue'],
        'newly added' => ['icon' => 'plus-circle', 'accent' => 'green'],
        'total users' => ['icon' => 'users', 'accent' => 'blue'],
        'active' => ['icon' => 'user-check', 'accent' => 'green'],
        'inactive' => ['icon' => 'user-x', 'accent' => 'blue'],
        'students' => ['icon' => 'graduation-cap', 'accent' => 'blue'],
        'faculty' => ['icon' => 'id-badge', 'accent' => 'blue'],
        'librarians' => ['icon' => 'shield', 'accent' => 'blue'],
        'total logged actions' => ['icon' => 'activity', 'accent' => 'blue'],
        'added' => ['icon' => 'plus-circle', 'accent' => 'green'],
        'updated' => ['icon' => 'edit', 'accent' => 'blue'],
        'deleted' => ['icon' => 'trash', 'accent' => 'blue']
    ];

    return $map[$key] ?? ['icon' => 'list', 'accent' => 'blue'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>
        (function () {
            try {
                var key = 'smartlib-admin-theme';
                var storedTheme = localStorage.getItem(key);
                var resolvedTheme = (storedTheme === 'light' || storedTheme === 'dark') ? storedTheme : 'dark';
                document.documentElement.setAttribute('data-theme', resolvedTheme);
            } catch (error) {
                document.documentElement.setAttribute('data-theme', 'dark');
            }
        }());
    </script>
    <title>SmartLib Admin</title>
    <link rel="icon" href="../assets/images/phinma-icon.png" type="image/png">
    <?php $adminCssVer = @filemtime(__DIR__ . '/../assets/css/admin.css') ?: time(); ?>
    <link rel="stylesheet" href="../assets/css/admin.css?v=<?= $adminCssVer ?>">
</head>
<body>
<div class="admin-container">
    <aside class="sidebar glass-card">
        <div class="sidebar-header">
            <img src="../assets/images/stjude_logo.jpg" class="sidebar-logo" alt="St. Jude logo">
            <h2>SmartLib</h2>
            <p><?= htmlspecialchars($roleLabel) ?></p>
            <div class="theme-toggle-wrap">
                <button
                    type="button"
                    id="adminThemeToggle"
                    class="theme-toggle-btn"
                    aria-label="Switch theme"
                    aria-pressed="false"
                >
                    <span class="theme-toggle-copy">
                        <span class="theme-toggle-label">Appearance</span>
                        <span class="theme-toggle-state">Dark mode</span>
                    </span>
                    <span class="theme-toggle-switch" aria-hidden="true">
                        <span class="theme-toggle-knob"></span>
                    </span>
                </button>
            </div>
        </div>

        <a class="nav-link <?= $currentPage === 'dashboard.php' ? 'active' : '' ?>" href="dashboard.php"><span class="nav-icon" aria-hidden="true"><?= admin_icon('dashboard') ?></span><span>Dashboard</span></a>
        <a class="nav-link <?= $currentPage === 'borrow_records.php' ? 'active' : '' ?>" href="borrow_records.php"><span class="nav-icon" aria-hidden="true"><?= admin_icon('borrow') ?></span><span>Borrow Records</span></a>
        <a class="nav-link <?= $currentPage === 'manage_books.php' ? 'active' : '' ?>" href="manage_books.php"><span class="nav-icon" aria-hidden="true"><?= admin_icon('books') ?></span><span>Books</span></a>
        <a class="nav-link <?= $currentPage === 'manage_users.php' ? 'active' : '' ?>" href="manage_users.php"><span class="nav-icon" aria-hidden="true"><?= admin_icon('users') ?></span><span>Users</span></a>
        <a class="nav-link <?= $currentPage === 'reports.php' ? 'active' : '' ?>" href="reports.php"><span class="nav-icon" aria-hidden="true"><?= admin_icon('reports') ?></span><span>Reports</span></a>
        <a class="nav-link <?= $currentPage === 'archived_history.php' ? 'active' : '' ?>" href="archived_history.php"><span class="nav-icon" aria-hidden="true"><?= admin_icon('archives') ?></span><span>Archives</span></a>
        <a class="nav-link <?= $currentPage === 'export_center.php' ? 'active' : '' ?>" href="export_center.php"><span class="nav-icon" aria-hidden="true"><?= admin_icon('export') ?></span><span>Data Export</span></a>

        <div class="sidebar-footer">
            <a class="nav-link nav-link-kiosk" href="../index.php"><span class="nav-icon" aria-hidden="true"><?= admin_icon('kiosk') ?></span><span>Go back to kiosk</span></a>
            <a href="../logout.php" class="logout-btn"><span class="nav-icon" aria-hidden="true"><?= admin_icon('logout') ?></span><span>Logout</span></a>
        </div>
    </aside>

    <main class="main-content">
