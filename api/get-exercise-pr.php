<?php
// api/get-exercise-pr.php
// Fetch the personal record (best weight×reps) for a given exercise

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$userId = getUserId();
$exerciseName = trim($_GET['exercise'] ?? '');

if (!$exerciseName) {
    echo json_encode(['success' => false, 'error' => 'No exercise name provided']);
    exit;
}

// Find the best weight×reps combo for this exercise (highest weight first, then highest reps)
$stmt = $conn->prepare("
    SELECT e.weight, e.reps
    FROM exercises e
    JOIN workout_sessions ws ON e.session_id = ws.id
    WHERE ws.user_id = ? AND e.exercise_name = ? AND e.is_warmup = 0
    ORDER BY CAST(e.weight AS DECIMAL(10,2)) DESC, CAST(e.reps AS INT) DESC
    LIMIT 1
");
$stmt->bind_param("is", $userId, $exerciseName);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($result) {
    echo json_encode([
        'success' => true,
        'exercise' => $exerciseName,
        'weight' => $result['weight'],
        'reps' => $result['reps'],
        'pr' => $result['weight'] . 'kg × ' . $result['reps']
    ]);
} else {
    echo json_encode([
        'success' => true,
        'exercise' => $exerciseName,
        'pr' => null,
        'message' => 'No history for this exercise yet'
    ]);
}
exit;
?>