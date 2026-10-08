<?php
// api/signup.php
// Secure signup endpoint

session_start();
header('Content-Type: application/json');

// Include database connection
require_once __DIR__ . '/includes/db.php';

// Check if request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Get POST data
$username = trim($_POST['username'] ?? '');
$email = trim($_POST['email'] ?? '');
$first_name = trim($_POST['first_name'] ?? '');
$last_name = trim($_POST['last_name'] ?? '');
$password = $_POST['password'] ?? '';
$password_confirm = $_POST['password_confirm'] ?? '';

// Validate all fields are filled
if (empty($username) || empty($email) || empty($password) || empty($password_confirm)) {
    header('Location: ../index.php?error=invalid_input');
    exit;
}

// Validate username (3-50 characters, alphanumeric + underscore)
if (strlen($username) < 3 || strlen($username) > 50) {
    header('Location: ../index.php?error=invalid_input');
    exit;
}

if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
    header('Location: ../index.php?error=invalid_input');
    exit;
}

// Validate email
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Location: ../index.php?error=invalid_input');
    exit;
}

// Validate password (minimum 8 characters)
if (strlen($password) < 8) {
    header('Location: ../index.php?error=invalid_input');
    exit;
}

// Check if passwords match
if ($password !== $password_confirm) {
    header('Location: ../index.php?error=password_mismatch');
    exit;
}

// Check if username or email already exists
$stmt = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");

if (!$stmt) {
    die(json_encode(['error' => 'Database error: ' . $conn->error]));
}

$stmt->bind_param("ss", $username, $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    header('Location: ../index.php?error=user_exists');
    $stmt->close();
    exit;
}

$stmt->close();

// Hash password using bcrypt
$password_hash = password_hash($password, PASSWORD_BCRYPT);

// Insert new user
$stmt = $conn->prepare("INSERT INTO users (username, email, password_hash, first_name, last_name) VALUES (?, ?, ?, ?, ?)");

if (!$stmt) {
    die(json_encode(['error' => 'Database error: ' . $conn->error]));
}

$stmt->bind_param("sssss", $username, $email, $password_hash, $first_name, $last_name);

if ($stmt->execute()) {
    // User created successfully
    $stmt->close();
    header('Location: ../index.php?signup_success=1');
    exit;
} else {
    // Database error
    header('Location: ../index.php?error=invalid_input');
    $stmt->close();
    exit;
}
?>
