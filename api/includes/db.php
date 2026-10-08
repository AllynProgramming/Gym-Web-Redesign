<?php
// includes/db.php
// Database connection file with security settings

$servername = getenv('DB_HOST') ?: 'sql213.infinityfree.com';
$username = getenv('DB_USERNAME') ?: 'if0_42502418';
$password = getenv('DB_PASSWORD') ?: 'zu7c1QR0vxxO';
$database = getenv('DB_NAME') ?: 'if0_42502418_gym_tracker';
$port = getenv('DB_PORT') ?: 3306;

// Create connection
$conn = new mysqli($servername, $username, $password, $database, $port);

// Check connection
if ($conn->connect_error) {
    http_response_code(500);
    die(json_encode(['error' => 'Database connection failed: ' . $conn->connect_error]));
}

// Set charset to UTF-8 (prevent SQL injection via character encoding)
$conn->set_charset('utf8mb4');

// Set timezone
date_default_timezone_set('UTC');
?>
