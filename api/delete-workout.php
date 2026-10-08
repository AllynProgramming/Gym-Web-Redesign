<?php
// api/delete-workout.php
// Deletes a workout session and all its exercises

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'You are not logged in.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$workoutId = $data['workout_id'] ?? null;

if (!$workoutId || !is_numeric($workoutId)) {
    echo json_encode(['success' => false, 'error' => 'Invalid workout ID']);
    exit;
}

$userId = getUserId();

// Verify ownership - user can only delete their own workouts
$stmt = $conn->prepare("SELECT id FROM workout_sessions WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $workoutId, $userId);
$stmt->execute();
$result = $stmt->get_result();
$stmt->close();

if ($result->num_rows === 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'You do not have permission to delete this workout']);
    exit;
}

// Delete all exercises in the session (cascade handled by DB, but explicit delete for safety)
$stmt = $conn->prepare("DELETE FROM exercises WHERE session_id = ?");
$stmt->bind_param("i", $workoutId);
$stmt->execute();
$stmt->close();

// Delete the workout session
$stmt = $conn->prepare("DELETE FROM workout_sessions WHERE id = ?");
$stmt->bind_param("i", $workoutId);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Workout deleted successfully']);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not delete workout']);
}

$stmt->close();
?>
