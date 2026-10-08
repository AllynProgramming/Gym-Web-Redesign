<?php
// api/add-friend.php
// Sends a friend request by username (creates a 'pending' row).

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'You are not logged in. Please log in again.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$username = trim($input['username'] ?? '');

if ($username === '') {
    echo json_encode(['success' => false, 'error' => 'Enter a username.']);
    exit;
}

$userId = getUserId();

// Find the target user
$stmt = $conn->prepare("SELECT id, username FROM users WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$target = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$target) {
    echo json_encode(['success' => false, 'error' => 'No user found with that username.']);
    exit;
}

if ((int) $target['id'] === (int) $userId) {
    echo json_encode(['success' => false, 'error' => "You can't add yourself as a friend."]);
    exit;
}

$targetId = $target['id'];

// Check for any existing relationship in either direction
$stmt = $conn->prepare("
    SELECT id, status FROM friendships
    WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)
    LIMIT 1
");
$stmt->bind_param("iiii", $userId, $targetId, $targetId, $userId);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($existing) {
    if ($existing['status'] === 'accepted') {
        echo json_encode(['success' => false, 'error' => 'You are already friends with that user.']);
    } elseif ($existing['status'] === 'pending') {
        echo json_encode(['success' => false, 'error' => 'A friend request is already pending with that user.']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Could not add that user.']);
    }
    exit;
}

$stmt = $conn->prepare("INSERT INTO friendships (user_id, friend_id, status) VALUES (?, ?, 'pending')");
$stmt->bind_param("ii", $userId, $targetId);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Could not send the request. Please try again.']);
}
$stmt->close();
exit;
?>
