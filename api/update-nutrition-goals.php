<?php
// api/update-nutrition-goals.php
// Upserts this user's daily nutrition targets.

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

$calories = (int) ($input['calories'] ?? 0);
$protein = (int) ($input['protein'] ?? 0);
$carbs = (int) ($input['carbs'] ?? 0);
$fat = (int) ($input['fat'] ?? 0);

if ($calories <= 0 || $protein < 0 || $carbs < 0 || $fat < 0) {
    echo json_encode(['success' => false, 'error' => 'Enter valid, non-negative goal values.']);
    exit;
}

$stmt = $conn->prepare("
    INSERT INTO nutrition_goals (user_id, calories, protein, carbs, fat)
    VALUES (?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE calories = ?, protein = ?, carbs = ?, fat = ?
");
$stmt->bind_param(
    "iiiiiiiii",
    $userId, $calories, $protein, $carbs, $fat,
    $calories, $protein, $carbs, $fat
);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Could not save your goals. Please try again.']);
}
$stmt->close();
exit;
?>
