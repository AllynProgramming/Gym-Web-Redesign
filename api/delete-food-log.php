<?php
// api/delete-food-log.php
// Removes one logged food entry (must belong to the requesting user).

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'You are not logged in. Please log in again.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$logId = (int) ($input['log_id'] ?? 0);

if ($logId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid request.']);
    exit;
}

$userId = getUserId();

$stmt = $conn->prepare("DELETE FROM nutrition_logs WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $logId, $userId);

if ($stmt->execute() && $stmt->affected_rows > 0) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'That entry could not be found.']);
}
$stmt->close();
exit;
?>
