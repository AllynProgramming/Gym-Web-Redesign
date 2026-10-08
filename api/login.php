<?php
// api/login.php
// Secure login endpoint

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
$password = $_POST['password'] ?? '';

// Validate input
if (empty($username) || empty($password)) {
    header('Location: ../index.php?error=invalid_input');
    exit;
}

// Prepared statement to prevent SQL injection
$stmt = $conn->prepare("SELECT id, username, password_hash FROM users WHERE username = ?");

if (!$stmt) {
    die(json_encode(['error' => 'Database error: ' . $conn->error]));
}

$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

// Check if user exists
if ($result->num_rows !== 1) {
    header('Location: ../index.php?error=invalid_login');
    $stmt->close();
    exit;
}

$user = $result->fetch_assoc();

// Verify password using bcrypt
if (!password_verify($password, $user['password_hash'])) {
    header('Location: ../index.php?error=invalid_login');
    $stmt->close();
    exit;
}

// Password is correct - create session
$_SESSION['userId'] = $user['id'];
$_SESSION['username'] = $user['username'];
$_SESSION['login_time'] = time();

// Regenerate session ID to prevent session fixation attacks
session_regenerate_id(true);

$stmt->close();

// Redirect to dashboard
header('Location: ../dashboard.php');
exit;
?>
