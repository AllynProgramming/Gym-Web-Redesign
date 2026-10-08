<?php
// api/update-workout.php
// Update an existing workout session and its associated exercises.

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'You are not logged in. Please log in again.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    echo json_encode(['success' => false, 'error' => 'Malformed request.']);
    exit;
}

$workoutId = isset($input['workout_id']) ? (int) $input['workout_id'] : 0;
if ($workoutId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid workout ID.']);
    exit;
}

$userId = getUserId();

if (!verifyOwnership($conn, 'workout_sessions', $workoutId)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'You do not have permission to edit this workout.']);
    exit;
}

$planName = trim($input['plan_name'] ?? '');
$sessionDate = trim($input['session_date'] ?? '');
$durationMinutes = isset($input['duration_minutes']) ? (int) $input['duration_minutes'] : null;

$exercisesInput = is_array($input['exercises'] ?? null) ? $input['exercises'] : [];

if (empty($sessionDate)) {
    echo json_encode(['success' => false, 'error' => 'Session date is required.']);
    exit;
}

$d = DateTime::createFromFormat('Y-m-d', $sessionDate);
if (!$d || $d->format('Y-m-d') !== $sessionDate) {
    echo json_encode(['success' => false, 'error' => 'That date does not look valid.']);
    exit;
}

$exercises = [];
foreach ($exercisesInput as $ex) {
    $name = trim($ex['name'] ?? '');
    $notes = trim($ex['notes'] ?? '');
    $setsInput = is_array($ex['sets'] ?? null) ? $ex['sets'] : [];

    $sets = [];
    foreach ($setsInput as $set) {
        $w = $set['weight'] ?? '';
        $r = $set['reps'] ?? '';

        if ($w === '' && $r === '') {
            continue;
        }

        if ($w === '' || $r === '' || !is_numeric($w) || (float) $w < 0) {
            echo json_encode(['success' => false, 'error' => "Every set for \"$name\" needs a valid weight and reps."]); 
            exit;
        }

        if (!ctype_digit((string) $r) || (int) $r < 1) {
            echo json_encode(['success' => false, 'error' => "Reps must be a whole number of at least 1 (check \"$name\")."]);
            exit;
        }

        $sets[] = [
            'weight' => (float) $w,
            'reps' => (int) $r,
            'is_warmup' => !empty($set['is_warmup']) ? 1 : 0,
        ];
    }

    if ($name === '' && empty($sets)) {
        continue;
    }

    if ($name === '') {
        echo json_encode(['success' => false, 'error' => 'Every exercise needs a name.']);
        exit;
    }

    if (empty($sets)) {
        echo json_encode(['success' => false, 'error' => "Add at least one set for \"$name\", or remove it."]); 
        exit;
    }

    $exercises[] = ['name' => $name, 'notes' => $notes, 'sets' => $sets];
}

if (empty($exercises)) {
    echo json_encode(['success' => false, 'error' => 'Add at least one exercise before saving.']);
    exit;
}

$workoutPlanId = null;
if ($planName !== '') {
    $stmt = $conn->prepare("SELECT id FROM workout_plans WHERE user_id = ? AND plan_name = ? LIMIT 1");
    $stmt->bind_param("is", $userId, $planName);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $workoutPlanId = $row['id'];
    }
    $stmt->close();

    if ($workoutPlanId === null) {
        $stmt = $conn->prepare("INSERT INTO workout_plans (user_id, plan_name) VALUES (?, ?)");
        $stmt->bind_param("is", $userId, $planName);
        $stmt->execute();
        $workoutPlanId = $conn->insert_id;
        $stmt->close();
    }
}

// Simple duplicate check: only prevent if editing to a date that already has a workout with the SAME plan
if ($workoutPlanId !== null) {
    $stmt = $conn->prepare("SELECT id FROM workout_sessions WHERE user_id = ? AND workout_plan_id = ? AND session_date = ? AND id != ?");
    $stmt->bind_param("iisi", $userId, $workoutPlanId, $sessionDate, $workoutId);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    
    if ($result->num_rows > 0) {
        echo json_encode(['success' => false, 'error' => 'A workout with this plan already exists on this date.']);
        exit;
    }
}

$conn->begin_transaction();

$stmt = $conn->prepare("UPDATE workout_sessions SET workout_plan_id = ?, session_date = ?, duration_minutes = ? WHERE id = ?");
$stmt->bind_param("isii", $workoutPlanId, $sessionDate, $durationMinutes, $workoutId);
if (!$stmt->execute()) {
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => 'Could not update the workout session.']);
    $stmt->close();
    exit;
}
$stmt->close();

$stmt = $conn->prepare("DELETE FROM exercises WHERE session_id = ?");
$stmt->bind_param("i", $workoutId);
$stmt->execute();
$stmt->close();

$stmt = $conn->prepare(
    "INSERT INTO exercises (session_id, exercise_name, weight, reps, sets, notes, is_warmup)\n    VALUES (?, ?, ?, ?, 1, ?, ?)"
);

foreach ($exercises as $ex) {
    foreach ($ex['sets'] as $set) {
        $stmt->bind_param(
            "isdisi",
            $workoutId,
            $ex['name'],
            $set['weight'],
            $set['reps'],
            $ex['notes'],
            $set['is_warmup']
        );
        if (!$stmt->execute()) {
            $conn->rollback();
            echo json_encode(['success' => false, 'error' => 'Could not save exercise sets.']);
            $stmt->close();
            exit;
        }
    }
}
$stmt->close();

$conn->commit();

echo json_encode(['success' => true, 'workoutId' => $workoutId]);
exit;