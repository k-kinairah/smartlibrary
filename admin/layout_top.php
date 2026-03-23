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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
        </div>

        <a class="nav-link <?= $currentPage === 'dashboard.php' ? 'active' : '' ?>" href="dashboard.php">Dashboard</a>
        <a class="nav-link <?= $currentPage === 'manage_books.php' ? 'active' : '' ?>" href="manage_books.php">Manage Books</a>
        <a class="nav-link <?= $currentPage === 'manage_users.php' ? 'active' : '' ?>" href="manage_users.php">Manage Users</a>
        <a class="nav-link <?= $currentPage === 'borrow_records.php' ? 'active' : '' ?>" href="borrow_records.php">Borrow Records</a>
        <a class="nav-link" href="../index.php">Go back to kiosk</a>

        <a href="../logout.php" class="logout-btn">Logout</a>
    </aside>

    <main class="main-content">
