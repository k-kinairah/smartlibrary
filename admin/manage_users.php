<?php require 'layout_top.php'; ?>
<?php
require '../config/db_connect.php';

$flash = ['type' => '', 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_user'])) {
    $firstName = trim((string)($_POST['first_name'] ?? ''));
    $lastName = trim((string)($_POST['last_name'] ?? ''));
    $userNumber = trim((string)($_POST['user_number'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $pin = trim((string)($_POST['pin'] ?? ''));
    $role = strtolower(trim((string)($_POST['role'] ?? 'student')));
    $programIdRaw = trim((string)($_POST['program_id'] ?? ''));
    $programId = ctype_digit($programIdRaw) ? (int)$programIdRaw : null;

    $allowedRoles = ['student', 'faculty', 'librarian', 'admin'];
    if (!in_array($role, $allowedRoles, true)) {
        $role = 'student';
    }

    if ($firstName === '' || $lastName === '' || $userNumber === '' || $pin === '') {
        $flash = ['type' => 'error', 'message' => 'First name, last name, ID number, and PIN are required.'];
    } elseif (!preg_match('/^\d{4,}$/', $pin)) {
        $flash = ['type' => 'error', 'message' => 'PIN must be at least 4 digits.'];
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

        if ($flash['message'] === '' && $email !== '') {
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
                 VALUES (?, ?, ?, NULLIF(?, ''), ?, NULLIF(?, 0), ?, 'active', NOW())"
            );

            if ($insert) {
                $insert->bind_param('sssssis', $userNumber, $firstName, $lastName, $email, $pinHash, $programId, $role);
                $ok = $insert->execute();
                $insert->close();

                if ($ok) {
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

if (isset($_GET['toggle'])) {
    $userId = intval($_GET['toggle']);
    $conn->query("UPDATE library_users SET status = IF(status='active','inactive','active') WHERE user_id = $userId");
    header('Location: manage_users.php');
    exit;
}

$search = trim($_GET['search'] ?? '');
$roleFilter = trim($_GET['role'] ?? '');

$where = 'WHERE 1=1';
if ($search !== '') {
    $safe = $conn->real_escape_string($search);
    $where .= " AND (first_name LIKE '%$safe%' OR last_name LIKE '%$safe%' OR user_number LIKE '%$safe%')";
}
if ($roleFilter !== '') {
    $safeRole = $conn->real_escape_string($roleFilter);
    $where .= " AND role = '$safeRole'";
}

$users = $conn->query("SELECT * FROM library_users $where ORDER BY created_at DESC");
$programs = $conn->query("SELECT program_id, program_name FROM programs ORDER BY program_name ASC");
?>

<div class="page-top">
    <h1>Manage Users</h1>
</div>

<?php if (!empty($flash['message'])): ?>
    <section class="panel glass-card borrow-flash borrow-flash-<?= htmlspecialchars($flash['type'] === 'success' ? 'success' : 'error') ?>">
        <?= htmlspecialchars($flash['message']) ?>
    </section>
<?php endif; ?>

<section class="panel glass-card">
    <div class="panel-head">
        <h2>Add User</h2>
    </div>

    <form method="POST" class="form-grid user-create-grid">
        <input type="text" name="first_name" placeholder="First Name" required>
        <input type="text" name="last_name" placeholder="Last Name" required>
        <input type="text" name="user_number" placeholder="Student/Faculty/Librarian ID" required>
        <input type="email" name="email" placeholder="Email (optional)">
        <input type="password" name="pin" placeholder="PIN (at least 4 digits)" required>

        <select name="program_id">
            <option value="">No Program</option>
            <?php if ($programs): ?>
                <?php while ($p = $programs->fetch_assoc()): ?>
                    <option value="<?= intval($p['program_id']) ?>"><?= htmlspecialchars($p['program_name']) ?></option>
                <?php endwhile; ?>
            <?php endif; ?>
        </select>

        <select name="role" required>
            <option value="student">Student</option>
            <option value="faculty">Faculty</option>
            <option value="librarian">Librarian</option>
            <option value="admin">Admin</option>
        </select>

        <button type="submit" name="create_user" class="btn-primary form-submit">Create User</button>
    </form>
</section>

<section class="panel glass-card">
    <form method="GET" class="filters-inline">
        <input type="text" name="search" placeholder="Search name or ID" value="<?= htmlspecialchars($search) ?>">
        <select name="role">
            <option value="">All Roles</option>
            <option value="student" <?= $roleFilter === 'student' ? 'selected' : '' ?>>Student</option>
            <option value="faculty" <?= $roleFilter === 'faculty' ? 'selected' : '' ?>>Faculty</option>
            <option value="librarian" <?= $roleFilter === 'librarian' ? 'selected' : '' ?>>Librarian</option>
            <option value="admin" <?= $roleFilter === 'admin' ? 'selected' : '' ?>>Admin</option>
        </select>
        <button type="submit" class="btn-primary">Filter</button>
    </form>
</section>

<section class="panel glass-card">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>User Number</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($users && $users->num_rows > 0): ?>
                    <?php while ($u = $users->fetch_assoc()): ?>
                        <tr>
                            <td><?= intval($u['user_id']) ?></td>
                            <td><?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?></td>
                            <td><?= htmlspecialchars($u['user_number']) ?></td>
                            <td><span class="badge role-<?= htmlspecialchars($u['role']) ?>"><?= htmlspecialchars(ucfirst($u['role'])) ?></span></td>
                            <td><span class="badge status-<?= htmlspecialchars($u['status']) ?>"><?= htmlspecialchars(ucfirst($u['status'])) ?></span></td>
                            <td>
                                <a class="btn-status <?= $u['status'] === 'active' ? 'deactivate' : 'activate' ?>" href="?toggle=<?= intval($u['user_id']) ?>">
                                    <?= $u['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
                                </a>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="6">No users found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require 'layout_bottom.php'; ?>

