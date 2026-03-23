<?php
session_start();
require 'config/db_connect.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
    exit;
}

$identifier = trim($_POST['identifier'] ?? '');
$password = trim($_POST['password'] ?? '');
$accountType = strtolower(trim($_POST['account_type'] ?? 'student'));

if (!in_array($accountType, ['student', 'faculty', 'librarian'], true)) {
    $accountType = 'student';
}

if ($identifier === '' || $password === '') {
    echo json_encode(['status' => 'error', 'message' => 'Please provide credentials']);
    exit;
}

$sql = "SELECT * FROM library_users WHERE user_number = ? LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $identifier);
$stmt->execute();
$result = $stmt->get_result();

if ($user = $result->fetch_assoc()) {
    $storedPassword = (string)($user['password'] ?? '');
    $isHashed = preg_match('/^\$2[aby]\$/', $storedPassword) === 1;

    $isValid = false;
    if ($isHashed) {
        $isValid = password_verify($password, $storedPassword);
    } else {
        // Backward compatibility for legacy plain-text rows.
        $isValid = hash_equals($storedPassword, $password);

        if ($isValid) {
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            $up = $conn->prepare("UPDATE library_users SET password = ? WHERE user_id = ?");
            if ($up) {
                $uid = (int)$user['user_id'];
                $up->bind_param('si', $newHash, $uid);
                $up->execute();
                $up->close();
            }
        }
    }

    if ($isValid) {
        $rawRole = strtolower(trim((string)($user['role'] ?? 'student')));
        $status = strtolower(trim((string)($user['status'] ?? 'active')));

        if ($status !== '' && $status !== 'active') {
            echo json_encode(['status' => 'error', 'message' => 'This account is inactive. Please contact the librarian.']);
            exit;
        }

        $roleAllowed = false;
        if ($accountType === 'student') {
            $roleAllowed = ($rawRole === 'student');
        } elseif ($accountType === 'faculty') {
            $roleAllowed = ($rawRole === 'faculty');
        } elseif ($accountType === 'librarian') {
            // Admin users are treated as librarian access on kiosk/admin login.
            $roleAllowed = in_array($rawRole, ['librarian', 'admin'], true);
        }

        if (!$roleAllowed) {
            $accountLabel = 'student';
            if ($accountType === 'faculty') {
                $accountLabel = 'faculty';
            } elseif ($accountType === 'librarian') {
                $accountLabel = 'librarian';
            }

            echo json_encode(['status' => 'error', 'message' => "This ID is not a {$accountLabel} account."]);
            exit;
        }

        $normalizedRole = in_array($rawRole, ['admin', 'librarian'], true) ? 'librarian' : $rawRole;

        $_SESSION['user_id'] = (int)$user['user_id'];
        $_SESSION['user_number'] = (string)$user['user_number'];
        $_SESSION['role'] = $normalizedRole;
        $_SESSION['name'] = trim(((string)$user['first_name']) . ' ' . ((string)$user['last_name']));

        echo json_encode([
            'status' => 'success',
            'role' => $normalizedRole
        ]);
        exit;
    }
}

echo json_encode(['status' => 'error', 'message' => 'Invalid credentials']);
exit;

