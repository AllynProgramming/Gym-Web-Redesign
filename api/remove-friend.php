<?php
// api/remove-friend.php
// Removes an accepted friendship (either direction).

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'You are not logged in. Please log in again.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$friendshipId = (int) ($input['friendship_id'] ?? 0);

if ($friendshipId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid request.']);
    exit;
}

$userId = getUserId();

// Must be a party to this friendship to remove it
$stmt = $conn->prepare("SELECT id FROM friendships WHERE id = ? AND (user_id = ? OR friend_id = ?)");
$stmt->bind_param("iii", $friendshipId, $userId, $userId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    echo json_encode(['success' => false, 'error' => 'That friendship no longer exists.']);
    exit;
}

$stmt = $conn->prepare("DELETE FROM friendships WHERE id = ?");
$stmt->bind_param("i", $friendshipId);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Could not remove that friend. Please try again.']);
}
$stmt->close();
exit;
?>
