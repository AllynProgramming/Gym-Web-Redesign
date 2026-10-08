<?php
// api/respond-friend.php
// Accept or decline an incoming friend request.

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
$action = $input['action'] ?? '';

if (!in_array($action, ['accept', 'decline'], true) || $friendshipId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid request.']);
    exit;
}

$userId = getUserId();

// The request must be addressed TO me and still pending
$stmt = $conn->prepare("SELECT id FROM friendships WHERE id = ? AND friend_id = ? AND status = 'pending'");
$stmt->bind_param("ii", $friendshipId, $userId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    echo json_encode(['success' => false, 'error' => 'That request no longer exists.']);
    exit;
}

if ($action === 'accept') {
    $stmt = $conn->prepare("UPDATE friendships SET status = 'accepted' WHERE id = ?");
} else {
    $stmt = $conn->prepare("DELETE FROM friendships WHERE id = ?");
}
$stmt->bind_param("i", $friendshipId);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Could not update that request. Please try again.']);
}
$stmt->close();
exit;
?>
