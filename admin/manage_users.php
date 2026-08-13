<?php require 'layout_top.php'; ?>
<?php
require '../config/db_connect.php';
require_once '../config/admin_audit.php';

$buildManageUsersUrl = static function (array $params): string {
    $clean = [];
    foreach ($params as $key => $value) {
        $text = trim((string)$value);
        if ($text !== '') {
            $clean[(string)$key] = $text;
        }
    }

    return 'manage_users.php' . (!empty($clean) ? ('?' . http_build_query($clean)) : '');
};

$normalizeRoleFilter = static function (string $role): string {
    $role = strtolower(trim($role));
    if ($role === 'admin') {
        return 'librarian';
    }

    return in_array($role, ['', 'student', 'faculty', 'librarian'], true) ? $role : '';
};

$allowedRoles = ['student', 'faculty', 'librarian', 'admin'];
$allowedStatusValues = ['active', 'inactive'];

function manage_users_csrf_token(): string {
    if (empty($_SESSION['manage_users_csrf']) || !is_string($_SESSION['manage_users_csrf'])) {
        $_SESSION['manage_users_csrf'] = bin2hex(random_bytes(32));
    }

    return (string)$_SESSION['manage_users_csrf'];
}

$csrfToken = manage_users_csrf_token();

$flash = ['type' => '', 'message' => ''];
$openEditUserModal = false;
$editPinPopupMessage = '';
$editUserData = [
    'user_id' => 0,
    'first_name' => '',
    'last_name' => '',
    'user_number' => '',
    'email' => '',
    'program_id' => null,
    'role' => 'student',
    'status' => 'active'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_user'])) {
    $postedToken = trim((string)($_POST['csrf_token'] ?? ''));
    if ($postedToken === '' || !hash_equals($csrfToken, $postedToken)) {
        $flash = ['type' => 'error', 'message' => 'Invalid request token. Refresh and try again.'];
    } else {
        $firstName = trim((string)($_POST['first_name'] ?? ''));
    $lastName = trim((string)($_POST['last_name'] ?? ''));
    $userNumber = trim((string)($_POST['user_number'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $pin = trim((string)($_POST['pin'] ?? ''));
    $role = strtolower(trim((string)($_POST['role'] ?? 'student')));
    $programIdRaw = trim((string)($_POST['program_id'] ?? ''));
    $programId = ctype_digit($programIdRaw) ? (int)$programIdRaw : null;

    if (!in_array($role, $allowedRoles, true)) {
        $role = 'student';
    }

    if ($firstName === '' || $lastName === '' || $userNumber === '' || $email === '' || $pin === '') {
        $flash = ['type' => 'error', 'message' => 'First name, last name, ID number, email, and PIN are required.'];
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $flash = ['type' => 'error', 'message' => 'Please enter a valid email address.'];
    } elseif (in_array($role, ['student', 'faculty'], true) && !preg_match('/^\d{4,}$/', $pin)) {
        $flash = ['type' => 'error', 'message' => 'For Student/Faculty accounts, PIN must be at least 4 digits.'];
    } else {
        $dupStmt = $conn->prepare("SELECT user_id FROM library_users WHERE user_number = ? LIMIT 1");
        if ($dupStmt) {
            $dupStmt->bind_param('s', $userNumber);
            $dupStmt->execute();
            $dupRes = $dupStmt->get_result();
            $exists = $dupRes && $dupRes->num_rows > 0;
            $dupStmt->close();

            if ($exists) {
                $flash = ['type' => 'error', 'message' => 'That ID number already exists.'];
            }
        }

        if ($flash['message'] === '') {
            $dupEmailStmt = $conn->prepare("SELECT user_id FROM library_users WHERE email = ? LIMIT 1");
            if ($dupEmailStmt) {
                $dupEmailStmt->bind_param('s', $email);
                $dupEmailStmt->execute();
                $dupEmailRes = $dupEmailStmt->get_result();
                $emailExists = $dupEmailRes && $dupEmailRes->num_rows > 0;
                $dupEmailStmt->close();

                if ($emailExists) {
                    $flash = ['type' => 'error', 'message' => 'That email is already used by another account.'];
                }
            }
        }

        if ($flash['message'] === '') {
            $pinHash = password_hash($pin, PASSWORD_DEFAULT);

            $insert = $conn->prepare(
                "INSERT INTO library_users (user_number, first_name, last_name, email, password, program_id, role, status, created_at)
                 VALUES (?, ?, ?, ?, ?, NULLIF(?, 0), ?, 'active', NOW())"
            );

            if ($insert) {
                $insert->bind_param('sssssis', $userNumber, $firstName, $lastName, $email, $pinHash, $programId, $role);
                $ok = $insert->execute();
                $createdUserId = $ok ? (int)$conn->insert_id : 0;
                $insert->close();

                if ($ok) {
                    smartlib_admin_audit_log($conn, 'added', 'user', $createdUserId, trim($firstName . ' ' . $lastName), [
                        'user_number' => $userNumber,
                        'email' => $email,
                        'role' => $role,
                        'status' => 'active',
                        'program_id' => $programId
                    ]);
                    $flash = ['type' => 'success', 'message' => 'User account created successfully.'];
                } else {
                    $flash = ['type' => 'error', 'message' => 'Could not create user account. Please try again.'];
                }
            } else {
                $flash = ['type' => 'error', 'message' => 'User insert failed. Check your database columns.'];
            }
        }
    }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_user'])) {
    $postedToken = trim((string)($_POST['csrf_token'] ?? ''));
    if ($postedToken === '' || !hash_equals($csrfToken, $postedToken)) {
        $flash = ['type' => 'error', 'message' => 'Invalid request token. Refresh and try again.'];
    } else {
        $editUserId = (int)($_POST['edit_user_id'] ?? 0);
    $firstName = trim((string)($_POST['edit_first_name'] ?? ''));
    $lastName = trim((string)($_POST['edit_last_name'] ?? ''));
    $userNumber = trim((string)($_POST['edit_user_number'] ?? ''));
    $email = trim((string)($_POST['edit_email'] ?? ''));
    $programIdRaw = trim((string)($_POST['edit_program_id'] ?? ''));
    $programId = ctype_digit($programIdRaw) ? (int)$programIdRaw : null;
    $role = strtolower(trim((string)($_POST['edit_role'] ?? 'student')));
    $status = strtolower(trim((string)($_POST['edit_status'] ?? 'active')));
    $newPin = trim((string)($_POST['edit_pin'] ?? ''));

    $returnSearch = trim((string)($_POST['return_search'] ?? ''));
    $returnRole = $normalizeRoleFilter((string)($_POST['return_role'] ?? ''));
    $returnStatus = strtolower(trim((string)($_POST['return_status'] ?? '')));

    if ($editUserId <= 0) {
        $flash = ['type' => 'error', 'message' => 'Invalid user selected for editing.'];
    } elseif ($firstName === '' || $lastName === '' || $userNumber === '') {
        $flash = ['type' => 'error', 'message' => 'First name, last name, and ID number are required.'];
    } else {
        if (!in_array($role, $allowedRoles, true)) {
            $role = 'student';
        }
        if (!in_array($status, $allowedStatusValues, true)) {
            $status = 'active';
        }

        $dupStmt = $conn->prepare("SELECT user_id FROM library_users WHERE user_number = ? AND user_id <> ? LIMIT 1");
        if ($dupStmt) {
            $dupStmt->bind_param('si', $userNumber, $editUserId);
            $dupStmt->execute();
            $dupRes = $dupStmt->get_result();
            $exists = $dupRes && $dupRes->num_rows > 0;
            $dupStmt->close();

            if ($exists) {
                $flash = ['type' => 'error', 'message' => 'That ID number already exists.'];
            }
        }

        if ($flash['message'] === '' && $email !== '') {
            $dupEmailStmt = $conn->prepare("SELECT user_id FROM library_users WHERE email = ? AND user_id <> ? LIMIT 1");
            if ($dupEmailStmt) {
                $dupEmailStmt->bind_param('si', $email, $editUserId);
                $dupEmailStmt->execute();
                $dupEmailRes = $dupEmailStmt->get_result();
                $emailExists = $dupEmailRes && $dupEmailRes->num_rows > 0;
                $dupEmailStmt->close();

                if ($emailExists) {
                    $flash = ['type' => 'error', 'message' => 'That email is already used by another account.'];
                }
            }
        }

        if (
            $flash['message'] === ''
            && $newPin !== ''
            && in_array($role, ['student', 'faculty'], true)
            && !preg_match('/^\d{4,}$/', $newPin)
        ) {
            $editPinPopupMessage = 'For Student/Faculty accounts, new PIN must be at least 4 digits.';
            $flash = ['type' => 'error', 'message' => $editPinPopupMessage];
        }

        if ($flash['message'] === '') {
            $ok = false;
            if ($newPin !== '') {
                $pinHash = password_hash($newPin, PASSWORD_DEFAULT);
                $update = $conn->prepare(
                    "UPDATE library_users
                     SET first_name = ?, last_name = ?, user_number = ?, email = NULLIF(?, ''),
                         program_id = NULLIF(?, 0), role = ?, status = ?, password = ?
                     WHERE user_id = ? LIMIT 1"
                );
                if ($update) {
                    $update->bind_param('ssssisssi', $firstName, $lastName, $userNumber, $email, $programId, $role, $status, $pinHash, $editUserId);
                    $ok = $update->execute();
                    $update->close();
                }
            } else {
                $update = $conn->prepare(
                    "UPDATE library_users
                     SET first_name = ?, last_name = ?, user_number = ?, email = NULLIF(?, ''),
                         program_id = NULLIF(?, 0), role = ?, status = ?
                     WHERE user_id = ? LIMIT 1"
                );
                if ($update) {
                    $update->bind_param('ssssissi', $firstName, $lastName, $userNumber, $email, $programId, $role, $status, $editUserId);
                    $ok = $update->execute();
                    $update->close();
                }
            }

            if ($ok) {
                smartlib_admin_audit_log($conn, 'updated', 'user', $editUserId, trim($firstName . ' ' . $lastName), [
                    'user_number' => $userNumber,
                    'email' => $email,
                    'role' => $role,
                    'status' => $status,
                    'program_id' => $programId,
                    'pin_changed' => $newPin !== ''
                ]);

                $redirectParams = [
                    'search' => $returnSearch,
                    'role' => $returnRole,
                    'status' => in_array($returnStatus, $allowedStatusValues, true) ? $returnStatus : ''
                ];
                header('Location: ' . $buildManageUsersUrl($redirectParams));
                exit;
            }

            $flash = ['type' => 'error', 'message' => 'Could not update user. Please try again.'];
        }
    }

    $openEditUserModal = true;
    $editUserData = [
        'user_id' => $editUserId,
        'first_name' => $firstName,
        'last_name' => $lastName,
        'user_number' => $userNumber,
        'email' => $email,
        'program_id' => $programId,
        'role' => in_array($role, ['student', 'faculty', 'librarian'], true) ? $role : 'student',
        'status' => in_array($status, $allowedStatusValues, true) ? $status : 'active'
    ];
    }
}

$search = trim((string)($_GET['search'] ?? ''));
$roleFilter = $normalizeRoleFilter((string)($_GET['role'] ?? ''));
$statusFilter = strtolower(trim((string)($_GET['status'] ?? '')));

$allowedStatusFilters = array_merge([''], $allowedStatusValues);
if (!in_array($statusFilter, $allowedStatusFilters, true)) {
    $statusFilter = '';
}

$baseFilterParams = [
    'search' => $search,
    'role' => $roleFilter,
    'status' => $statusFilter
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_user_status'])) {
    $postedToken = trim((string)($_POST['csrf_token'] ?? ''));
    if ($postedToken !== '' && hash_equals($csrfToken, $postedToken)) {
        $userId = (int)($_POST['toggle_user_status'] ?? 0);
        if ($userId > 0) {
            $beforeUser = null;
            $beforeStmt = $conn->prepare('SELECT user_id, user_number, first_name, last_name, role, status FROM library_users WHERE user_id = ? LIMIT 1');
            if ($beforeStmt) {
                $beforeStmt->bind_param('i', $userId);
                $beforeStmt->execute();
                $beforeRes = $beforeStmt->get_result();
                $beforeUser = $beforeRes ? $beforeRes->fetch_assoc() : null;
                $beforeStmt->close();
            }

            $toggleStmt = $conn->prepare("UPDATE library_users SET status = IF(status='active','inactive','active') WHERE user_id = ? LIMIT 1");
            if ($toggleStmt) {
                $toggleStmt->bind_param('i', $userId);
                $toggleStmt->execute();
                $didToggle = $toggleStmt->affected_rows > 0;
                $toggleStmt->close();

                if ($didToggle && is_array($beforeUser)) {
                    $oldStatus = strtolower((string)($beforeUser['status'] ?? ''));
                    $newStatus = $oldStatus === 'active' ? 'inactive' : 'active';
                    $displayName = trim((string)($beforeUser['first_name'] ?? '') . ' ' . (string)($beforeUser['last_name'] ?? ''));
                    smartlib_admin_audit_log($conn, 'status_changed', 'user', $userId, $displayName, [
                        'user_number' => (string)($beforeUser['user_number'] ?? ''),
                        'role' => (string)($beforeUser['role'] ?? ''),
                        'previous_status' => $oldStatus,
                        'new_status' => $newStatus
                    ]);
                }
            }
        }
    }

    header('Location: ' . $buildManageUsersUrl($baseFilterParams));
    exit;
}

if (!$openEditUserModal && isset($_GET['edit_user'])) {
    $editId = (int)($_GET['edit_user'] ?? 0);
    if ($editId > 0) {
        $editRes = $conn->query("SELECT user_id, first_name, last_name, user_number, email, program_id, role, status FROM library_users WHERE user_id = {$editId} LIMIT 1");
        if ($editRes && ($editRow = $editRes->fetch_assoc())) {
            $dbRole = strtolower((string)($editRow['role'] ?? ''));
            if ($dbRole === 'admin') {
                $dbRole = 'librarian';
            }

            $openEditUserModal = true;
            $editUserData = [
                'user_id' => (int)($editRow['user_id'] ?? 0),
                'first_name' => (string)($editRow['first_name'] ?? ''),
                'last_name' => (string)($editRow['last_name'] ?? ''),
                'user_number' => (string)($editRow['user_number'] ?? ''),
                'email' => (string)($editRow['email'] ?? ''),
                'program_id' => isset($editRow['program_id']) ? (int)$editRow['program_id'] : null,
                'role' => in_array($dbRole, ['student', 'faculty', 'librarian'], true) ? $dbRole : 'student',
                'status' => in_array((string)($editRow['status'] ?? ''), $allowedStatusValues, true) ? (string)$editRow['status'] : 'active'
            ];
        }
    }
}

$whereParts = [];
if ($search !== '') {
    $safe = $conn->real_escape_string($search);
    $whereParts[] = "(first_name LIKE '%$safe%' OR last_name LIKE '%$safe%' OR user_number LIKE '%$safe%' OR email LIKE '%$safe%')";
}
if ($roleFilter !== '') {
    if ($roleFilter === 'librarian') {
        $whereParts[] = "role IN ('librarian', 'admin')";
    } else {
        $safeRole = $conn->real_escape_string($roleFilter);
        $whereParts[] = "role = '$safeRole'";
    }
}
if ($statusFilter !== '') {
    $safeStatus = $conn->real_escape_string($statusFilter);
    $whereParts[] = "status = '$safeStatus'";
}
$whereSql = !empty($whereParts) ? ('WHERE ' . implode(' AND ', $whereParts)) : '';

$users = $conn->query("SELECT * FROM library_users {$whereSql} ORDER BY created_at DESC");

$totalUsers = 0;
$activeUsers = 0;
$inactiveUsers = 0;
$studentUsers = 0;
$facultyUsers = 0;
$librarianUsers = 0;

$userCounts = $conn->query(
    "
    SELECT
        COUNT(*) AS total_users,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_users,
        SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) AS inactive_users,
        SUM(CASE WHEN role = 'student' THEN 1 ELSE 0 END) AS student_users,
        SUM(CASE WHEN role = 'faculty' THEN 1 ELSE 0 END) AS faculty_users,
        SUM(CASE WHEN role IN ('librarian', 'admin') THEN 1 ELSE 0 END) AS librarian_users
    FROM library_users
    "
);

if ($userCounts && ($countRow = $userCounts->fetch_assoc())) {
    $totalUsers = (int)($countRow['total_users'] ?? 0);
    $activeUsers = (int)($countRow['active_users'] ?? 0);
    $inactiveUsers = (int)($countRow['inactive_users'] ?? 0);
    $studentUsers = (int)($countRow['student_users'] ?? 0);
    $facultyUsers = (int)($countRow['faculty_users'] ?? 0);
    $librarianUsers = (int)($countRow['librarian_users'] ?? 0);
}

$statusChipMetrics = [
    ['status' => '', 'title' => 'Total Users', 'count' => $totalUsers],
    ['status' => 'active', 'title' => 'Active', 'count' => $activeUsers],
    ['status' => 'inactive', 'title' => 'Inactive', 'count' => $inactiveUsers]
];

$roleChipMetrics = [
    ['role' => 'student', 'title' => 'Students', 'count' => $studentUsers],
    ['role' => 'faculty', 'title' => 'Faculty', 'count' => $facultyUsers],
    ['role' => 'librarian', 'title' => 'Librarians', 'count' => $librarianUsers]
];

$allChipMetrics = [];
$chipBaseParams = ['search' => $search];

foreach ($statusChipMetrics as $chip) {
    $chipStatus = (string)($chip['status'] ?? '');
    $isActive = ($chipStatus === '') ? ($statusFilter === '') : ($statusFilter === $chipStatus);

    $targetParams = $chipBaseParams;
    if ($roleFilter !== '') {
        $targetParams['role'] = $roleFilter;
    }
    if ($chipStatus !== '' && !$isActive) {
        $targetParams['status'] = $chipStatus;
    }

    $allChipMetrics[] = [
        'title' => (string)($chip['title'] ?? ''),
        'count' => (int)($chip['count'] ?? 0),
        'href' => $buildManageUsersUrl($targetParams),
        'is_active' => $isActive
    ];
}

foreach ($roleChipMetrics as $chip) {
    $chipRole = (string)($chip['role'] ?? '');
    $isActive = ($roleFilter === $chipRole);

    $targetParams = $chipBaseParams;
    if ($statusFilter !== '') {
        $targetParams['status'] = $statusFilter;
    }
    if (!$isActive) {
        $targetParams['role'] = $chipRole;
    }

    $allChipMetrics[] = [
        'title' => (string)($chip['title'] ?? ''),
        'count' => (int)($chip['count'] ?? 0),
        'href' => $buildManageUsersUrl($targetParams),
        'is_active' => $isActive
    ];
}

$programs = [];
$programRes = $conn->query("SELECT program_id, program_name FROM programs ORDER BY program_name ASC");
if ($programRes) {
    while ($programRow = $programRes->fetch_assoc()) {
        $programs[] = $programRow;
    }
}

$openCreateUserModal = $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['create_user'])
    && ($flash['type'] ?? '') !== 'success';

if ($openEditUserModal) {
    $openCreateUserModal = false;
}
?>

<div class="page-top">
    <h1>Manage Users</h1>
    <button class="btn-primary" onclick="openUserModal()">+ Add User</button>
</div>

<?php if (!empty($flash['message'])): ?>
    <section class="panel glass-card borrow-flash borrow-flash-<?= htmlspecialchars($flash['type'] === 'success' ? 'success' : 'error') ?>">
        <?= htmlspecialchars($flash['message']) ?>
    </section>
<?php endif; ?>

<section class="stats-grid borrow-status-chips manage-users-chip-row">
    <?php foreach ($allChipMetrics as $chip): ?>
        <a href="<?= htmlspecialchars((string)($chip['href'] ?? '')) ?>" class="stat-card glass-card borrow-chip<?= !empty($chip['is_active']) ? ' is-active' : '' ?>">
            <h3><?= htmlspecialchars((string)($chip['title'] ?? '')) ?></h3>
            <p class="value"><?= number_format((int)($chip['count'] ?? 0)) ?></p>
        </a>
    <?php endforeach; ?>
</section>

<section class="panel glass-card">
    <form method="GET" class="filters-inline borrow-filters">
        <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
        <input type="hidden" name="role" value="<?= htmlspecialchars($roleFilter) ?>">
        <input type="text" name="search" placeholder="Search name, ID, email" value="<?= htmlspecialchars($search) ?>">
        <button type="submit" class="btn-primary">Apply</button>
        <a href="manage_users.php" class="filter-reset-btn">Reset</a>
    </form>
</section>

<section class="panel glass-card">
    <div class="table-wrap">
        <table class="data-table manage-users-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>User Number</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($users && $users->num_rows > 0): ?>
                    <?php while ($u = $users->fetch_assoc()): ?>
                        <?php

                            $editParams = $baseFilterParams;
                            $editParams['edit_user'] = (string)intval($u['user_id']);
                            $editHref = $buildManageUsersUrl($editParams);

                            $rowRole = strtolower((string)($u['role'] ?? ''));
                            $displayRole = $rowRole === 'admin' ? 'Librarian' : ucfirst((string)$rowRole);
                            $roleClassKey = $rowRole === 'admin' ? 'librarian' : $rowRole;
                            $roleTextClass = 'user-role-text';
                            if ($roleClassKey === 'student') {
                                $roleTextClass .= ' user-role-student';
                            } elseif ($roleClassKey === 'faculty') {
                                $roleTextClass .= ' user-role-faculty';
                            } elseif ($roleClassKey === 'librarian') {
                                $roleTextClass .= ' user-role-librarian';
                            }
                            $rowStatus = strtolower(trim((string)($u['status'] ?? 'inactive')));
                            $statusClass = $rowStatus === 'active'
                                ? 'user-status-text user-status-active'
                                : 'user-status-text user-status-inactive';
                            $statusLabel = ucfirst($rowStatus === '' ? 'inactive' : $rowStatus);
                        ?>
                        <tr>
                            <td><?= intval($u['user_id']) ?></td>
                            <td><?= htmlspecialchars((string)$u['first_name'] . ' ' . (string)$u['last_name']) ?></td>
                            <td><?= htmlspecialchars((string)$u['user_number']) ?></td>
                            <td class="user-meta-text"><?= htmlspecialchars((string)($u['email'] ?? '') !== '' ? (string)$u['email'] : '-') ?></td>
                            <td class="<?= htmlspecialchars($roleTextClass) ?>"><?= htmlspecialchars($displayRole) ?></td>
                            <td><span class="<?= htmlspecialchars($statusClass) ?>"><?= htmlspecialchars($statusLabel) ?></span></td>
                            <td class="user-actions-cell">
                                <a class="btn-status user-pin-btn user-action-icon-btn" href="<?= htmlspecialchars($editHref) ?>" aria-label="Edit user">
                                    <svg viewBox="0 0 24 24" aria-hidden="true">
                                        <path d="M4 20h4.2l9.7-9.7-4.2-4.2L4 15.8V20Z"></path>
                                        <path d="m12.8 7.2 4.2 4.2"></path>
                                    </svg>
                                </a>
                                <form method="POST" class="row-action-form">
                                    <input type="hidden" name="toggle_user_status" value="<?= intval($u['user_id']) ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                    <button type="submit" class="btn-status user-toggle-btn user-action-icon-btn <?= $u['status'] === 'active' ? 'deactivate' : 'activate' ?>" aria-label="<?= $u['status'] === 'active' ? 'Deactivate user' : 'Activate user' ?>">
                                        <svg viewBox="0 0 24 24" aria-hidden="true">
                                            <?php if ($u['status'] === 'active'): ?>
                                                <path d="M7 7 17 17"></path>
                                                <path d="M17 7 7 17"></path>
                                            <?php else: ?>
                                                <path d="M5 12.5 10 17l9-10"></path>
                                            <?php endif; ?>
                                        </svg>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="7">No users found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<div class="overlay-modal<?= $openCreateUserModal ? ' show' : '' ?>" id="userModal" aria-hidden="<?= $openCreateUserModal ? 'false' : 'true' ?>">
    <div class="overlay-card glass-card user-modal-card">
        <div class="panel-head">
            <h2>Add User</h2>
            <button class="icon-close" type="button" onclick="closeUserModal()">&times;</button>
        </div>

        <form method="POST" class="form-grid user-create-grid user-create-portrait">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="text" name="first_name" placeholder="First Name" required>
            <input type="text" name="last_name" placeholder="Last Name" required>
            <input type="text" name="user_number" placeholder="Student/Faculty/Librarian ID" required>
            <input type="email" name="email" placeholder="Email" required>
            <input type="password" name="pin" placeholder="PIN / Password" required>
            <small class="pin-rule-note" id="createPinHint">Student/Faculty: at least 4 digits. Librarian: secure password can include letters, numbers, and symbols.</small>

            <select name="program_id">
                <option value="">No Program</option>
                <?php foreach ($programs as $p): ?>
                    <option value="<?= intval($p['program_id']) ?>"><?= htmlspecialchars((string)$p['program_name']) ?></option>
                <?php endforeach; ?>
            </select>

            <select name="role" required>
                <option value="student">Student</option>
                <option value="faculty">Faculty</option>
                <option value="librarian">Librarian</option>
            </select>

            <button type="submit" name="create_user" class="btn-primary form-submit">Create User</button>
        </form>
    </div>
</div>

<div class="overlay-modal<?= $openEditUserModal ? ' show' : '' ?>" id="editUserModal" aria-hidden="<?= $openEditUserModal ? 'false' : 'true' ?>">
    <div class="overlay-card glass-card user-modal-card">
        <div class="panel-head">
            <h2>Edit User</h2>
            <button class="icon-close" type="button" onclick="closeEditUserModal()">&times;</button>
        </div>

        <form method="POST" id="editUserForm" class="form-grid user-create-grid user-create-portrait">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="edit_user_id" value="<?= intval($editUserData['user_id'] ?? 0) ?>">
            <input type="hidden" name="return_search" value="<?= htmlspecialchars($search) ?>">
            <input type="hidden" name="return_role" value="<?= htmlspecialchars($roleFilter) ?>">
            <input type="hidden" name="return_status" value="<?= htmlspecialchars($statusFilter) ?>">

            <input type="text" name="edit_first_name" placeholder="First Name" value="<?= htmlspecialchars((string)($editUserData['first_name'] ?? '')) ?>" required>
            <input type="text" name="edit_last_name" placeholder="Last Name" value="<?= htmlspecialchars((string)($editUserData['last_name'] ?? '')) ?>" required>
            <input type="text" name="edit_user_number" placeholder="Student/Faculty/Librarian ID" value="<?= htmlspecialchars((string)($editUserData['user_number'] ?? '')) ?>" required>
            <input type="email" name="edit_email" placeholder="Email (optional)" value="<?= htmlspecialchars((string)($editUserData['email'] ?? '')) ?>">
            <input type="password" name="edit_pin" placeholder="New PIN / Password (optional)" autocomplete="new-password">
            <small class="pin-rule-note" id="editPinHint">Leave blank to keep current PIN/password. Student/Faculty: at least 4 digits. Librarian: secure password can include letters, numbers, and symbols.</small>

            <select name="edit_program_id">
                <option value="">No Program</option>
                <?php foreach ($programs as $p): ?>
                    <?php $pid = (int)($p['program_id'] ?? 0); ?>
                    <option value="<?= $pid ?>" <?= (int)($editUserData['program_id'] ?? 0) === $pid ? 'selected' : '' ?>><?= htmlspecialchars((string)$p['program_name']) ?></option>
                <?php endforeach; ?>
            </select>

            <select name="edit_role" required>
                <option value="student" <?= (($editUserData['role'] ?? '') === 'student') ? 'selected' : '' ?>>Student</option>
                <option value="faculty" <?= (($editUserData['role'] ?? '') === 'faculty') ? 'selected' : '' ?>>Faculty</option>
                <option value="librarian" <?= (($editUserData['role'] ?? '') === 'librarian') ? 'selected' : '' ?>>Librarian</option>
            </select>

            <select name="edit_status" required>
                <option value="active" <?= (($editUserData['status'] ?? '') === 'active') ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= (($editUserData['status'] ?? '') === 'inactive') ? 'selected' : '' ?>>Inactive</option>
            </select>

            <button type="submit" name="update_user" class="btn-primary form-submit">Save Changes</button>
        </form>
    </div>
</div>

<script>
function openUserModal() {
    const modal = document.getElementById('userModal');
    if (!modal) return;
    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
}

function closeUserModal() {
    const modal = document.getElementById('userModal');
    if (!modal) return;
    modal.classList.remove('show');
    modal.setAttribute('aria-hidden', 'true');
}

function closeEditUserModal() {
    const modal = document.getElementById('editUserModal');
    if (!modal) return;
    modal.classList.remove('show');
    modal.setAttribute('aria-hidden', 'true');

    const url = new URL(window.location.href);
    if (url.searchParams.has('edit_user')) {
        url.searchParams.delete('edit_user');
        window.history.replaceState({}, document.title, url.toString());
    }
}

const createUserModal = document.getElementById('userModal');
const createUserForm = createUserModal ? createUserModal.querySelector('form[method="POST"]') : null;
const editUserForm = document.getElementById('editUserForm');

function roleNeedsNumericPin(roleValue) {
    const role = String(roleValue || '').toLowerCase();
    return role === 'student' || role === 'faculty';
}

function updatePinHint(hintEl, roleValue, isEditForm) {
    if (!hintEl) return;

    if (roleNeedsNumericPin(roleValue)) {
        hintEl.textContent = isEditForm
            ? 'Leave blank to keep current PIN. For Student/Faculty, new PIN must be at least 4 digits.'
            : 'For Student/Faculty accounts, PIN must be at least 4 digits.';
        hintEl.classList.add('is-strict');
    } else {
        hintEl.textContent = isEditForm
            ? 'Leave blank to keep current password. Librarian can use a secure password (letters, numbers, symbols).'
            : 'For Librarian accounts, use a secure password (letters, numbers, symbols).';
        hintEl.classList.remove('is-strict');
    }
}

function applyOptionalEmailValidation(formEl, selector) {
    if (!formEl) return;
    const emailInput = formEl.querySelector(selector);
    if (!emailInput) return;

    const updateMessage = function () {
        const value = emailInput.value.trim();
        emailInput.setCustomValidity('');
        if (value === '') {
            emailInput.setCustomValidity('');
            return '';
        }
        if (!value.includes('@')) {
            const msg = "Email must include '@' (example: name@domain.com).";
            emailInput.setCustomValidity(msg);
            return msg;
        }
        if (!emailInput.checkValidity()) {
            const msg = 'Use a valid email format like name@domain.com.';
            emailInput.setCustomValidity(msg);
            return msg;
        }
        emailInput.setCustomValidity('');
        return '';
    };

    emailInput.addEventListener('input', updateMessage);
    emailInput.addEventListener('blur', updateMessage);
    emailInput.addEventListener('invalid', function () {
        updateMessage();
    });
}

if (createUserForm) {
    const createRole = createUserForm.querySelector('select[name="role"]');
    const createPin = createUserForm.querySelector('input[name="pin"]');
    const createHint = document.getElementById('createPinHint');

    applyOptionalEmailValidation(createUserForm, 'input[name="email"]');

    if (createRole) {
        updatePinHint(createHint, createRole.value, false);
        createRole.addEventListener('change', function () {
            if (createPin) createPin.setCustomValidity('');
            updatePinHint(createHint, createRole.value, false);
        });
    }

    createUserForm.addEventListener('submit', function (e) {
        if (!createRole || !createPin) return;
        const pinValue = createPin.value.trim();
        createPin.setCustomValidity('');

        if (roleNeedsNumericPin(createRole.value) && !/^\d{4,}$/.test(pinValue)) {
            const msg = 'For Student/Faculty accounts, PIN must be at least 4 digits.';
            e.preventDefault();
            createPin.setCustomValidity(msg);
            createPin.reportValidity();
            window.alert(msg);
            createPin.focus();
            createPin.select();
        }
    });
}

if (editUserForm) {
    const editRole = editUserForm.querySelector('select[name="edit_role"]');
    const pinInput = editUserForm.querySelector('input[name="edit_pin"]');
    const editHint = document.getElementById('editPinHint');

    applyOptionalEmailValidation(editUserForm, 'input[name="edit_email"]');

    if (editRole) {
        updatePinHint(editHint, editRole.value, true);
        editRole.addEventListener('change', function () {
            if (pinInput) pinInput.setCustomValidity('');
            updatePinHint(editHint, editRole.value, true);
        });
    }

    editUserForm.addEventListener('submit', function (e) {
        if (!pinInput || !editRole) return;
        const newPin = pinInput.value.trim();
        pinInput.setCustomValidity('');

        if (newPin !== '' && roleNeedsNumericPin(editRole.value) && !/^\d{4,}$/.test(newPin)) {
            const msg = 'For Student/Faculty accounts, new PIN must be at least 4 digits.';
            e.preventDefault();
            pinInput.setCustomValidity(msg);
            pinInput.reportValidity();
            window.alert(msg);
            pinInput.focus();
            pinInput.select();
        }
    });
}

const editPinPopupMessage = <?= json_encode($editPinPopupMessage, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
if (editPinPopupMessage && document.getElementById('editUserModal')?.classList.contains('show')) {
    window.setTimeout(function () {
        window.alert(editPinPopupMessage);
        const pinInput = document.querySelector('#editUserModal input[name="edit_pin"]');
        if (pinInput) {
            pinInput.focus();
            pinInput.select();
        }
    }, 80);
}

window.addEventListener('click', function (e) {
    if (e.target && e.target.id === 'userModal') {
        closeUserModal();
    }
    if (e.target && e.target.id === 'editUserModal') {
        closeEditUserModal();
    }
});
</script>

<?php require 'layout_bottom.php'; ?>

















