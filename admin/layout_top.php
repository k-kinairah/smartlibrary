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
        'kiosk' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="7.4" y="3.5" width="9.2" height="17" rx="2"></rect><circle cx="12" cy="17.3" r="0.8" fill="currentColor" stroke="none"></circle></svg>'
    ];

    return $icons[$key] ?? '';
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
        <a class="nav-link <?= $currentPage === 'reports.php' ? 'active' : '' ?>" href="reports.php"><span class="nav-icon" aria-hidden="true"><?= admin_icon('reports') ?></span><span>Reports</span></a>
        <a class="nav-link <?= $currentPage === 'manage_books.php' ? 'active' : '' ?>" href="manage_books.php"><span class="nav-icon" aria-hidden="true"><?= admin_icon('books') ?></span><span>Books</span></a>
        <a class="nav-link <?= $currentPage === 'manage_users.php' ? 'active' : '' ?>" href="manage_users.php"><span class="nav-icon" aria-hidden="true"><?= admin_icon('users') ?></span><span>Users</span></a>
        <a class="nav-link <?= $currentPage === 'archived_history.php' ? 'active' : '' ?>" href="archived_history.php"><span class="nav-icon" aria-hidden="true"><?= admin_icon('archives') ?></span><span>Archives</span></a>
        <a class="nav-link <?= $currentPage === 'export_center.php' ? 'active' : '' ?>" href="export_center.php"><span class="nav-icon" aria-hidden="true"><?= admin_icon('export') ?></span><span>Data Export</span></a>

        <div class="sidebar-footer">
            <a class="nav-link nav-link-kiosk" href="../index.php"><span class="nav-icon" aria-hidden="true"><?= admin_icon('kiosk') ?></span><span>Go back to kiosk</span></a>
            <a href="../logout.php" class="logout-btn"><span class="nav-icon" aria-hidden="true"><?= admin_icon('logout') ?></span><span>Logout</span></a>
        </div>
    </aside>

    <main class="main-content">
