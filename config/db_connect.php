<?php
// Credentials live in the gitignored config/db_config.php
// (copy config/db_config.example.php to create it).
$dbConfigPath = __DIR__ . '/db_config.php';
if (!is_file($dbConfigPath)) {
    error_log('SmartLib DB config missing: copy config/db_config.example.php to config/db_config.php');
    http_response_code(500);
    die('Database connection failed. Please try again later.');
}
require $dbConfigPath;

// PHP 8.1+ mysqli throws on connect failure, so the old connect_error check
// never ran and the uncaught exception could print host/user details. Log the
// real reason; show visitors a generic message.
try {
    $conn = new mysqli($host, $user, $pass, $db);
} catch (mysqli_sql_exception $e) {
    error_log('SmartLib DB connection failed: ' . $e->getMessage());
    http_response_code(500);
    die('Database connection failed. Please try again later.');
}
$conn->set_charset("utf8mb4");
