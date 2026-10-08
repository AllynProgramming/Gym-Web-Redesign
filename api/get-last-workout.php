<?php
// api/get-last-workout.php
// Fetch the user's most recent workout to enable the "Duplicate last workout" feature

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'You are not logged in.']);
    exit;
}

$userId = getUserId();

// Fetch the most recent workout session (excluding today if same plan)
$stmt = $conn->prepare("
    SELECT ws.id, ws.session_date, ws.duration_minutes, wp.plan_name
    FROM workout_sessions ws
    LEFT JOIN workout_plans wp ON ws.workout_plan_id = wp.id
    WHERE ws.user_id = ?
    ORDER BY ws.session_date DESC, ws.id DESC
    LIMIT 1
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$sessionRow = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$sessionRow) {
    echo json_encode(['success' => false, 'error' => 'No previous workouts found.']);
    exit;
}

$workoutId = $sessionRow['id'];
$exerciseData = [];

// Fetch all exercises for that workout
$stmt = $conn->prepare("
    SELECT exercise_name, weight, reps, notes, is_warmup
    FROM exercises
    WHERE session_id = ?
    ORDER BY id ASC
");
$stmt->bind_param("i", $workoutId);
$stmt->execute();
$exerciseRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Group by exercise name + notes
$currentKey = null;
$exercises = [];
foreach ($exerciseRows as $row) {
    $rowKey = $row['exercise_name'] . '|' . $row['notes'];
    if ($currentKey !== $rowKey) {
        $exercises[] = [
            'name' => $row['exercise_name'],
            'notes' => $row['notes'],
            'sets' => [],
        ];
        $currentKey = $rowKey;
    }

    $lastIndex = count($exercises) - 1;
    $exercises[$lastIndex]['sets'][] = [
        'weight' => $row['weight'],
        'reps' => $row['reps'],
        'is_warmup' => (bool) $row['is_warmup'],
    ];
}

echo json_encode([
    'success' => true,
    'plan_name' => $sessionRow['plan_name'] ?? '',
    'session_date' => $sessionRow['session_date'],
    'duration_minutes' => $sessionRow['duration_minutes'],
    'exercises' => $exercises,
]);
exit;
?>