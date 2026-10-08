<?php
// api/calculate-nutrition-goals.php
// Computes daily calorie/macro targets from body stats + bulk/cut/maintain goal,
// then saves both the body profile and the resulting targets to nutrition_goals.
//
// Method: Mifflin-St Jeor for BMR, standard activity multipliers for TDEE,
// then a goal-based adjustment on top:
//   - Cut:      TDEE - 20%,  protein 2.2 g/kg (higher to help preserve muscle in a deficit)
//   - Bulk:     TDEE + 15%,  protein 1.8 g/kg
//   - Maintain: TDEE,        protein 2.0 g/kg
// Fat is fixed at 25% of total calories; carbs take the remaining calories.
// These are reasonable general-purpose defaults, not medical/clinical advice.

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

$heightCm = (float) ($input['height_cm'] ?? 0);
$weightKg = (float) ($input['weight_kg'] ?? 0);
$age = (int) ($input['age'] ?? 0);
$sex = $input['sex'] ?? '';
$activityLevel = $input['activity_level'] ?? 'moderate';
$goalType = $input['goal_type'] ?? 'maintain';
$targetWeightKg = isset($input['target_weight_kg']) && $input['target_weight_kg'] !== ''
    ? (float) $input['target_weight_kg']
    : null;

// --- Validation ---
if ($heightCm < 100 || $heightCm > 250) {
    echo json_encode(['success' => false, 'error' => 'Enter a height between 100–250 cm.']);
    exit;
}
if ($weightKg < 30 || $weightKg > 300) {
    echo json_encode(['success' => false, 'error' => 'Enter a weight between 30–300 kg.']);
    exit;
}
if ($age < 13 || $age > 100) {
    echo json_encode(['success' => false, 'error' => 'Enter an age between 13–100.']);
    exit;
}
if (!in_array($sex, ['male', 'female'], true)) {
    echo json_encode(['success' => false, 'error' => 'Select a sex (used only for the BMR formula).']);
    exit;
}

$activityMultipliers = [
    'sedentary' => 1.2,
    'light' => 1.375,
    'moderate' => 1.55,
    'active' => 1.725,
    'very_active' => 1.9,
];
if (!isset($activityMultipliers[$activityLevel])) {
    $activityLevel = 'moderate';
}

if (!in_array($goalType, ['bulk', 'cut', 'maintain'], true)) {
    $goalType = 'maintain';
}

// --- BMR (Mifflin-St Jeor) → TDEE ---
$bmr = (10 * $weightKg) + (6.25 * $heightCm) - (5 * $age) + ($sex === 'male' ? 5 : -161);
$tdee = $bmr * $activityMultipliers[$activityLevel];

// --- Goal-based calorie adjustment + protein target ---
switch ($goalType) {
    case 'cut':
        $calories = $tdee * 0.80;
        $proteinPerKg = 2.2;
        break;
    case 'bulk':
        $calories = $tdee * 1.15;
        $proteinPerKg = 1.8;
        break;
    default:
        $calories = $tdee;
        $proteinPerKg = 2.0;
}

$protein = $weightKg * $proteinPerKg;
$fatCalories = $calories * 0.25;
$fat = $fatCalories / 9;
$proteinCalories = $protein * 4;
$carbCalories = max(0, $calories - $proteinCalories - $fatCalories);
$carbs = $carbCalories / 4;

$calories = (int) round($calories);
$protein = (int) round($protein);
$carbs = (int) round($carbs);
$fat = (int) round($fat);

$stmt = $conn->prepare("
    INSERT INTO nutrition_goals
        (user_id, height_cm, weight_kg, age, sex, activity_level, goal_type, target_weight_kg, calories, protein, carbs, fat)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        height_cm = ?, weight_kg = ?, age = ?, sex = ?, activity_level = ?, goal_type = ?, target_weight_kg = ?,
        calories = ?, protein = ?, carbs = ?, fat = ?
");
$stmt->bind_param(
    "iddisssdiiii" . "ddisssdiiii",
    $userId, $heightCm, $weightKg, $age, $sex, $activityLevel, $goalType, $targetWeightKg, $calories, $protein, $carbs, $fat,
    $heightCm, $weightKg, $age, $sex, $activityLevel, $goalType, $targetWeightKg, $calories, $protein, $carbs, $fat
);

if ($stmt->execute()) {
    echo json_encode([
        'success' => true,
        'targets' => ['calories' => $calories, 'protein' => $protein, 'carbs' => $carbs, 'fat' => $fat],
    ]);
} else {
    echo json_encode(['success' => false, 'error' => 'Could not save your targets. Please try again.']);
}
$stmt->close();
exit;
?>
