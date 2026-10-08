<?php
// api/add-workout.php
// Saves a workout session. Each exercise now carries its own list of sets
// (weight + reps + warmup flag can differ set to set), so every set is stored
// as its own row in `exercises` (sets column is always 1 — one row = one set).

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

$userId = getUserId();

$planName = trim($input['plan_name'] ?? '');
$sessionDate = trim($input['session_date'] ?? '');
$durationMinutes = isset($input['duration_minutes']) ? (int) $input['duration_minutes'] : null;
$exercisesInput = is_array($input['exercises'] ?? null) ? $input['exercises'] : [];

// --- Validate the session date ---
if (empty($sessionDate)) {
    echo json_encode(['success' => false, 'error' => 'Session date is required.']);
    exit;
}

$d = DateTime::createFromFormat('Y-m-d', $sessionDate);
if (!$d || $d->format('Y-m-d') !== $sessionDate) {
    echo json_encode(['success' => false, 'error' => 'That date does not look valid.']);
    exit;
}

// --- Validate exercises + their sets ---
// Each exercise needs a name and at least one set with weight + reps.
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
            continue; // fully empty set row, skip
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
        continue; // fully empty exercise card, skip
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

// --- Resolve (or create) the workout plan ---
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

// --- Insert the workout session ---
$stmt = $conn->prepare("
    INSERT INTO workout_sessions (user_id, workout_plan_id, session_date, duration_minutes)
    VALUES (?, ?, ?, ?)
");
$stmt->bind_param("iisi", $userId, $workoutPlanId, $sessionDate, $durationMinutes);

if (!$stmt->execute()) {
    if ($conn->errno === 1062) {
        echo json_encode([
            'success' => false,
            'error' => 'You already logged a workout for this plan on this date. Pick a different date or plan.'
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Could not save the session. Please try again.']);
    }
    $stmt->close();
    exit;
}

$sessionId = $conn->insert_id;
$stmt->close();

// --- Insert one row per set ---
$stmt = $conn->prepare("
    INSERT INTO exercises (session_id, exercise_name, weight, reps, sets, notes, is_warmup)
    VALUES (?, ?, ?, ?, 1, ?, ?)
");

foreach ($exercises as $ex) {
    foreach ($ex['sets'] as $set) {
        $stmt->bind_param(
            "isdisi",
            $sessionId,
            $ex['name'],
            $set['weight'],
            $set['reps'],
            $ex['notes'],
            $set['is_warmup']
        );
        $stmt->execute();
    }
}
$stmt->close();

echo json_encode(['success' => true, 'sessionId' => $sessionId]);
exit;
?>