<?php
// register_handler.php
require 'config/db_connect.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status'=>'error','message'=>'Invalid request']);
    exit;
}

$account_type = $_POST['account_type'] ?? 'student'; // 'student' or 'faculty'
$first_name = trim($_POST['first_name'] ?? '');
$last_name = trim($_POST['last_name'] ?? '');
$identifier = trim($_POST['identifier'] ?? ''); // student_number or employee_id
$email = trim($_POST['email'] ?? '');
$password_plain = trim($_POST['password'] ?? ''); // could be 4-digit PIN for kiosk

if ($identifier === '' || $password_plain === '') {
    echo json_encode(['status'=>'error','message'=>'Missing required fields']);
    exit;
}

// hash password
$hash = password_hash($password_plain, PASSWORD_DEFAULT);

if ($account_type === 'student') {
    // optional: add course, year
    $course = trim($_POST['course'] ?? '');
    $sql = "INSERT INTO students (student_number, first_name, last_name, course, email, password, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())";
    if ($stmt = mysqli_prepare($conn, $sql)) {
        mysqli_stmt_bind_param($stmt, "ssssss", $identifier, $first_name, $last_name, $course, $email, $hash);
        if (mysqli_stmt_execute($stmt)) {
            echo json_encode(['status'=>'success','message'=>'Student account created']);
            exit;
        } else {
            echo json_encode(['status'=>'error','message'=>'Could not create account (duplicate?)']);
            exit;
        }
    }
} else { // faculty
    $sql = "INSERT INTO faculty (employee_id, first_name, last_name, course, email, password) VALUES (?, ?, ?, ?, ?, ?)";
    if ($stmt = mysqli_prepare($conn, $sql)) {
        $course = trim($_POST['course'] ?? '');
        mysqli_stmt_bind_param($stmt, "ssssss", $identifier, $first_name, $last_name, $course, $email, $hash);
        if (mysqli_stmt_execute($stmt)) {
            echo json_encode(['status'=>'success','message'=>'Faculty account created']);
            exit;
        } else {
            echo json_encode(['status'=>'error','message'=>'Could not create account (duplicate?)']);
            exit;
        }
    }
}

echo json_encode(['status'=>'error','message'=>'Unknown error']);
