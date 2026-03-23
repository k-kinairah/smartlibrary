<?php
require 'config/db_connect.php';
header('Content-Type: application/json');

$first = trim($_POST['first'] ?? '');
$last = trim($_POST['last'] ?? '');
$identifier = trim($_POST['identifier'] ?? '');
$email = trim($_POST['email'] ?? '');
$course = trim($_POST['course'] ?? '');
$password = trim($_POST['password'] ?? '');

if ($first === '' || $last === '' || $identifier === '' || $password === '') {
    echo json_encode([
        'status' => 'error',
        'message' => 'Please fill in all required fields.'
    ]);
    exit;
}

$check = $conn->prepare('SELECT user_id FROM library_users WHERE user_number = ? LIMIT 1');
$check->bind_param('s', $identifier);
$check->execute();
$result = $check->get_result();

if ($result && $result->num_rows > 0) {
    echo json_encode([
        'status' => 'error',
        'message' => 'This ID is already registered.'
    ]);
    exit;
}

$programId = null;
if ($course !== '') {
    $prog = $conn->prepare('SELECT program_id FROM programs WHERE program_name = ? LIMIT 1');
    if ($prog) {
        $prog->bind_param('s', $course);
        $prog->execute();
        $progRes = $prog->get_result();
        if ($progRes && ($p = $progRes->fetch_assoc())) {
            $programId = (int)$p['program_id'];
        }
        $prog->close();
    }
}

$hashed = password_hash($password, PASSWORD_DEFAULT);

if ($programId !== null) {
    $stmt = $conn->prepare(
        "INSERT INTO library_users
        (user_number, first_name, last_name, email, program_id, role, password, status, created_at)
        VALUES (?, ?, ?, ?, ?, 'student', ?, 'active', NOW())"
    );

    $stmt->bind_param(
        'ssssis',
        $identifier,
        $first,
        $last,
        $email,
        $programId,
        $hashed
    );
} else {
    $stmt = $conn->prepare(
        "INSERT INTO library_users
        (user_number, first_name, last_name, email, program_id, role, password, status, created_at)
        VALUES (?, ?, ?, ?, NULL, 'student', ?, 'active', NOW())"
    );

    $stmt->bind_param(
        'sssss',
        $identifier,
        $first,
        $last,
        $email,
        $hashed
    );
}

if ($stmt && $stmt->execute()) {
    echo json_encode([
        'status' => 'success',
        'message' => 'Account created successfully!'
    ]);
} else {
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error.'
    ]);
}

if ($stmt) {
    $stmt->close();
}
