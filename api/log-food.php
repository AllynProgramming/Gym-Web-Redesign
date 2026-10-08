<?php
// api/log-food.php
// Logs a food entry for a given meal/date. Accepts either an existing food_id,
// or a `new_food` object (name + per-100g macros) which gets created as a custom
// food (owned by this user) and then logged in the same request.

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'You are not logged in. Please log in again.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$userId = getUserId();

$meal = $input['meal'] ?? '';
$grams = (float) ($input['grams'] ?? 0);
$logDate = trim($input['log_date'] ?? '');

if (!in_array($meal, ['breakfast', 'lunch', 'dinner', 'snack'], true)) {
    echo json_encode(['success' => false, 'error' => 'Invalid meal.']);
    exit;
}

if ($grams <= 0) {
    echo json_encode(['success' => false, 'error' => 'Enter a valid amount in grams.']);
    exit;
}

$d = DateTime::createFromFormat('Y-m-d', $logDate);
if (!$d || $d->format('Y-m-d') !== $logDate) {
    echo json_encode(['success' => false, 'error' => 'Invalid date.']);
    exit;
}

$foodId = isset($input['food_id']) ? (int) $input['food_id'] : null;

// --- Case 1: a brand-new custom food was supplied ---
if (!$foodId && !empty($input['new_food']) && is_array($input['new_food'])) {
    $nf = $input['new_food'];
    $name = trim($nf['name'] ?? '');

    if ($name === '') {
        echo json_encode(['success' => false, 'error' => 'Enter a name for the custom food.']);
        exit;
    }

    $cal = (float) ($nf['calories_per_100g'] ?? 0);
    $protein = (float) ($nf['protein_per_100g'] ?? 0);
    $carbs = (float) ($nf['carbs_per_100g'] ?? 0);
    $fat = (float) ($nf['fat_per_100g'] ?? 0);

    $stmt = $conn->prepare("
        INSERT INTO foods (name, calories_per_100g, protein_per_100g, carbs_per_100g, fat_per_100g, created_by_user_id)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("sddddi", $name, $cal, $protein, $carbs, $fat, $userId);
    $stmt->execute();
    $foodId = $conn->insert_id;
    $stmt->close();
}

if (!$foodId) {
    echo json_encode(['success' => false, 'error' => 'Pick a food, or add it as a custom food.']);
    exit;
}

// --- Look up the food's per-100g macros (must be a shared food or one of this user's own) ---
$stmt = $conn->prepare("
    SELECT calories_per_100g, protein_per_100g, carbs_per_100g, fat_per_100g
    FROM foods
    WHERE id = ? AND (created_by_user_id IS NULL OR created_by_user_id = ?)
");
$stmt->bind_param("ii", $foodId, $userId);
$stmt->execute();
$food = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$food) {
    echo json_encode(['success' => false, 'error' => 'That food could not be found.']);
    exit;
}

$factor = $grams / 100;
$calories = round($food['calories_per_100g'] * $factor, 2);
$protein = round($food['protein_per_100g'] * $factor, 2);
$carbs = round($food['carbs_per_100g'] * $factor, 2);
$fat = round($food['fat_per_100g'] * $factor, 2);

$stmt = $conn->prepare("
    INSERT INTO nutrition_logs (user_id, food_id, log_date, meal, grams, calories, protein, carbs, fat)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
");
$stmt->bind_param("iissddddd", $userId, $foodId, $logDate, $meal, $grams, $calories, $protein, $carbs, $fat);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Could not log that food. Please try again.']);
}
$stmt->close();
exit;
?>
