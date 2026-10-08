<?php
// api/update-profile.php
// Updates first name, last name, and username for the logged-in user.

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'You are not logged in. Please log in again.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$firstName = trim($input['first_name'] ?? '');
$lastName = trim($input['last_name'] ?? '');
$username = trim($input['username'] ?? '');

if ($username === '') {
    echo json_encode(['success' => false, 'error' => 'Username cannot be empty.']);
    exit;
}

if (strlen($username) < 3 || strlen($username) > 50) {
    echo json_encode(['success' => false, 'error' => 'Username must be 3–50 characters.']);
    exit;
}

if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
    echo json_encode(['success' => false, 'error' => 'Username can only contain letters, numbers, and underscores.']);
    exit;
}

$userId = getUserId();

// Make sure no one ELSE already has this username
$stmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
$stmt->bind_param("si", $username, $userId);
$stmt->execute();
$taken = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($taken) {
    echo json_encode(['success' => false, 'error' => 'That username is already taken.']);
    exit;
}

$stmt = $conn->prepare("UPDATE users SET first_name = ?, last_name = ?, username = ? WHERE id = ?");
$stmt->bind_param("sssi", $firstName, $lastName, $username, $userId);

if ($stmt->execute()) {
    // Keep the session's username in sync so the navbar etc. reflect the change immediately
    $_SESSION['username'] = $username;
    echo json_encode(['success' => true, 'username' => $username]);
} else {
    echo json_encode(['success' => false, 'error' => 'Could not save changes. Please try again.']);
}
$stmt->close();
exit;
?>
