<?php
// nutrition.php
// Daily food log: breakfast/lunch/dinner/snack entries, totals vs. goals, and a food picker
// that auto-calculates macros from grams (using the local `foods` table).

require_once __DIR__ . '/api/includes/db.php';
require_once __DIR__ . '/api/includes/auth.php';

requireLogin();

$userId = getUserId();
$user = getUserInfo($conn, $userId);

$logDate = $_GET['date'] ?? date('Y-m-d');
$d = DateTime::createFromFormat('Y-m-d', $logDate);
if (!$d || $d->format('Y-m-d') !== $logDate) {
    $logDate = date('Y-m-d');
}

$prevDate = (new DateTime($logDate))->modify('-1 day')->format('Y-m-d');
$nextDate = (new DateTime($logDate))->modify('+1 day')->format('Y-m-d');
$isToday = $logDate === date('Y-m-d');

// This user's goals (create a default row on the fly if they've never set one)
$stmt = $conn->prepare("SELECT calories, protein, carbs, fat, height_cm, weight_kg, age, sex, activity_level, goal_type, target_weight_kg FROM nutrition_goals WHERE user_id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$goals = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$goals) {
    $goals = [
        'calories' => 2000, 'protein' => 150, 'carbs' => 200, 'fat' => 65,
        'height_cm' => null, 'weight_kg' => null, 'age' => null, 'sex' => null,
        'activity_level' => 'moderate', 'goal_type' => 'maintain', 'target_weight_kg' => null,
    ];
}

// Every logged entry for this day, with the food's name
$stmt = $conn->prepare("
    SELECT nl.id, nl.meal, nl.grams, nl.calories, nl.protein, nl.carbs, nl.fat, f.name AS food_name
    FROM nutrition_logs nl
    JOIN foods f ON f.id = nl.food_id
    WHERE nl.user_id = ? AND nl.log_date = ?
    ORDER BY nl.id ASC
");
$stmt->bind_param("is", $userId, $logDate);
$stmt->execute();
$entries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$meals = ['breakfast' => [], 'lunch' => [], 'dinner' => [], 'snack' => []];
$totals = ['calories' => 0, 'protein' => 0, 'carbs' => 0, 'fat' => 0];

foreach ($entries as $e) {
    $meals[$e['meal']][] = $e;
    $totals['calories'] += $e['calories'];
    $totals['protein'] += $e['protein'];
    $totals['carbs'] += $e['carbs'];
    $totals['fat'] += $e['fat'];
}

$mealLabels = ['breakfast' => 'Breakfast', 'lunch' => 'Lunch', 'dinner' => 'Dinner', 'snack' => 'Snacks'];

// Every food this user can pick from: shared/common foods + their own custom ones
$stmt = $conn->prepare("
    SELECT id, name, calories_per_100g, protein_per_100g, carbs_per_100g, fat_per_100g
    FROM foods
    WHERE created_by_user_id IS NULL OR created_by_user_id = ?
    ORDER BY name
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$allFoods = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

function pct($value, $goal) {
    if ($goal <= 0) return 0;
    return max(0, min(100, round(($value / $goal) * 100)));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nutrition - Personal GymTracker </title>
    <style>
        :root {
            color-scheme: dark;
            --bg-dark: #05030a;
            --panel: rgba(15, 8, 28, 0.95);
            --panel-2: rgba(20, 12, 40, 0.98);
            --text-main: #f6f7ff;
            --text-muted: #adb2d4;
            --border: rgba(151, 109, 222, 0.22);
            --cal: #a755ff;
            --protein: #4fd6ac;
            --carbs: #ffb454;
            --fat: #ff7ab8;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            background:
                radial-gradient(circle at top left, rgba(120, 81, 169, 0.18), transparent 20%),
                radial-gradient(circle at bottom right, rgba(120, 81, 169, 0.12), transparent 18%),
                var(--bg-dark);
            color: var(--text-main);
        }

        /* ---------- Navbar ---------- */
        .navbar {
            background: rgba(5, 5, 15, 0.96);
            border-bottom: 1px solid rgba(151, 109, 222, 0.2);
            padding: 22px 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            position: sticky;
            top: 0;
            z-index: 10;
            backdrop-filter: blur(16px);
        }

        .navbar h1 { font-size: 1.9rem; letter-spacing: 0.03em; }

        .nav-toggle {
            display: none;
            align-items: center;
            justify-content: center;
            width: 46px;
            height: 46px;
            border: 1px solid rgba(151, 109, 222, 0.3);
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.06);
            color: #fff;
            cursor: pointer;
        }

        .barbell-icon { display: inline-flex; align-items: center; gap: 4px; }
        .barbell-icon .bar { width: 18px; height: 4px; border-radius: 999px; background: linear-gradient(90deg, #fff, #c284ff); box-shadow: 0 0 12px rgba(194, 132, 255, 0.3); }
        .barbell-icon .plate { width: 8px; height: 12px; border-radius: 999px; background: linear-gradient(135deg, #a755ff, #7a3ecf); border: 1px solid rgba(255, 255, 255, 0.28); box-shadow: inset 0 0 4px rgba(255, 255, 255, 0.2); }

        .navbar-right { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; justify-content: flex-end; }

        .navbar-right a {
            color: var(--text-main);
            text-decoration: none;
            padding: 10px 16px;
            border-radius: 999px;
            transition: background 0.3s ease;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.08);
            font-weight: 600;
            font-size: 0.92rem;
        }

        .navbar-right a:hover { background: rgba(120, 81, 169, 0.18); }

        /* ---------- Layout ---------- */
        .container { max-width: 720px; margin: 0 auto; padding: 32px 24px 60px; }

        .date-nav {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 14px;
            margin-bottom: 22px;
        }

        .date-nav a {
            width: 38px; height: 38px;
            display: grid; place-items: center;
            border-radius: 50%;
            background: var(--panel);
            border: 1px solid var(--border);
            color: var(--text-main);
            text-decoration: none;
            font-size: 1.1rem;
        }
        .date-nav a:hover { background: rgba(151, 109, 222, 0.18); }

        .date-nav .date-label { font-size: 1.15rem; font-weight: 700; min-width: 160px; text-align: center; }
        .date-nav .today-tag { font-size: 0.75rem; color: var(--text-muted); display: block; font-weight: 400; }

        .panel {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 22px;
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: 0 16px 34px rgba(0, 0, 0, 0.2);
        }

        .panel-title { font-size: 1.05rem; font-weight: 700; margin-bottom: 4px; color: #fff; }

        .message {
            padding: 11px 14px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.88rem;
            margin-bottom: 16px;
            display: none;
        }
        .message.show { display: block; }
        .message.success { background: rgba(151, 109, 222, 0.14); border: 1px solid rgba(151, 109, 222, 0.3); color: #e7d6ff; }
        .message.error { background: rgba(255, 94, 94, 0.16); border: 1px solid rgba(255, 94, 94, 0.24); color: #ffd7d7; }

        /* ---------- Goal progress bars ---------- */
        .goal-row { margin-bottom: 16px; }
        .goal-row:last-child { margin-bottom: 0; }

        .goal-head { display: flex; justify-content: space-between; font-size: 0.88rem; margin-bottom: 6px; }
        .goal-head strong { color: #fff; }
        .goal-head span { color: var(--text-muted); }

        .goal-bar-track { height: 10px; border-radius: 999px; background: rgba(255, 255, 255, 0.06); overflow: hidden; }
        .goal-bar-fill { height: 100%; border-radius: 999px; transition: width 0.4s ease; }

        .goal-row.calories .goal-bar-fill { background: var(--cal); }
        .goal-row.protein .goal-bar-fill { background: var(--protein); }
        .goal-row.carbs .goal-bar-fill { background: var(--carbs); }
        .goal-row.fat .goal-bar-fill { background: var(--fat); }

        .edit-goals-toggle {
            font-size: 0.82rem;
            color: var(--text-muted);
            background: none;
            border: none;
            cursor: pointer;
            text-decoration: underline;
            margin-top: 14px;
        }
        .edit-goals-toggle:hover { color: var(--text-main); }

        .goals-form { display: none; margin-top: 16px; padding-top: 16px; border-top: 1px solid rgba(151, 109, 222, 0.15); }
        .goals-form.show { display: block; }

        .goals-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 12px; }
        .goals-grid .form-group { display: grid; gap: 5px; }
        .goals-grid label { font-size: 0.78rem; color: var(--text-muted); font-weight: 600; }

        input, select {
            width: 100%;
            padding: 10px 12px;
            min-height: 42px;
            border-radius: 10px;
            border: 1px solid rgba(151, 109, 222, 0.22);
            background: rgba(255, 255, 255, 0.05);
            color: var(--text-main);
            font-size: 0.9rem;
            font-family: inherit;
        }
        input:focus, select:focus { outline: none; border-color: rgba(155, 106, 240, 0.8); box-shadow: 0 0 0 3px rgba(155, 106, 240, 0.16); }
        select option { background: #14092b; color: #fff; }

        .btn-small {
            padding: 9px 18px;
            min-height: 38px;
            border: none;
            border-radius: 999px;
            background: linear-gradient(135deg, #a755ff 0%, #7d3fd0 55%, #632a9f 100%);
            color: #fff;
            font-weight: 700;
            font-size: 0.85rem;
            cursor: pointer;
        }
        .btn-small:disabled { opacity: 0.6; cursor: not-allowed; }

        /* ---------- Meal sections ---------- */
        .meal-panel { margin-bottom: 16px; }
        .meal-head { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 12px; }
        .meal-head h4 { font-size: 1rem; }
        .meal-head span { color: var(--text-muted); font-size: 0.85rem; }

        .food-entry {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            background: var(--panel-2);
            border: 1px solid var(--border);
            border-radius: 12px;
            margin-bottom: 8px;
        }
        .food-entry-info strong { display: block; font-size: 0.9rem; }
        .food-entry-info span { color: var(--text-muted); font-size: 0.78rem; }
        .food-entry-macros { text-align: right; font-size: 0.8rem; color: var(--text-muted); flex-shrink: 0; }
        .food-entry-macros strong { color: #d8b8ff; }

        .remove-entry-btn {
            background: rgba(255, 94, 94, 0.1);
            border: 1px solid rgba(255, 94, 94, 0.28);
            color: #ffb3b3;
            width: 26px; height: 26px;
            border-radius: 50%;
            font-size: 0.9rem;
            cursor: pointer;
            flex-shrink: 0;
        }
        .remove-entry-btn:hover { background: rgba(255, 94, 94, 0.2); }

        .add-food-btn {
            width: 100%;
            padding: 10px 0;
            min-height: 40px;
            background: rgba(151, 109, 222, 0.1);
            border: 1px dashed rgba(155, 106, 240, 0.4);
            color: #d8b8ff;
            border-radius: 12px;
            font-weight: 700;
            font-size: 0.85rem;
            cursor: pointer;
        }
        .add-food-btn:hover { background: rgba(151, 109, 222, 0.18); }

        .empty-note { color: var(--text-muted); font-size: 0.85rem; padding: 4px 0 10px; }

        /* ---------- Add-food modal ---------- */
        .modal-backdrop {
            display: none;
            position: fixed; inset: 0;
            background: rgba(5, 3, 10, 0.72);
            backdrop-filter: blur(6px);
            z-index: 50;
            align-items: center; justify-content: center;
            padding: 20px;
        }
        .modal-backdrop.is-open { display: flex; }

        .modal-panel {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 22px;
            max-width: 420px; width: 100%;
            max-height: 88vh;
            overflow-y: auto;
            padding: 24px;
        }

        .modal-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        .modal-head h3 { font-size: 1.1rem; }
        .modal-close {
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: var(--text-main);
            width: 30px; height: 30px;
            border-radius: 50%;
            font-size: 1rem;
            cursor: pointer;
        }

        .macro-preview {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
            margin: 14px 0;
            padding: 12px;
            background: var(--panel-2);
            border-radius: 12px;
            border: 1px solid var(--border);
        }
        .macro-preview div { text-align: center; }
        .macro-preview strong { display: block; font-size: 1rem; }
        .macro-preview span { font-size: 0.72rem; color: var(--text-muted); }

        .custom-food-toggle {
            font-size: 0.8rem;
            color: var(--text-muted);
            background: none; border: none;
            text-decoration: underline;
            cursor: pointer;
            margin-top: 10px;
        }

        .custom-food-fields { display: none; margin-top: 12px; padding-top: 12px; border-top: 1px solid rgba(151, 109, 222, 0.15); }
        .custom-food-fields.show { display: block; }

        .hidden { display: none; }

        @media (max-width: 640px) {
            .container { padding: 20px 16px 40px; }
            .navbar { padding: 16px 20px; }
            .nav-toggle { display: inline-flex; }
            .goals-grid { grid-template-columns: repeat(2, 1fr); }

            .navbar-right {
                display: none;
                position: absolute;
                top: calc(100% + 10px);
                right: 20px; left: 20px;
                flex-direction: column;
                align-items: stretch;
                padding: 14px;
                background: rgba(5, 5, 15, 0.98);
                border: 1px solid rgba(151, 109, 222, 0.24);
                border-radius: 18px;
                box-shadow: 0 16px 32px rgba(0, 0, 0, 0.24);
            }
            .navbar-right.is-open { display: flex; }
            .navbar-right a { width: 100%; text-align: center; justify-content: center; }
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <h1>Personal GymTracker </h1>
        <button class="nav-toggle" id="navToggle" aria-label="Toggle navigation" type="button">
            <span class="barbell-icon" aria-hidden="true">
                <span class="plate"></span><span class="bar"></span><span class="plate"></span>
            </span>
        </button>
        <div class="navbar-right" id="navMenu">
            <a href="dashboard.php">Dashboard</a>
            <a href="friends.php">Friends</a>
            <a href="profile.php">Profile</a>
            <a href="api/logout.php">Logout</a>
        </div>
    </nav>

    <div class="container">
        <div class="date-nav">
            <a href="nutrition.php?date=<?php echo $prevDate; ?>">‹</a>
            <div class="date-label">
                <?php echo date('l, M j', strtotime($logDate)); ?>
                <?php if ($isToday): ?><span class="today-tag">Today</span><?php endif; ?>
            </div>
            <a href="nutrition.php?date=<?php echo $nextDate; ?>">›</a>
        </div>

        <div class="message" id="pageMessage"></div>

        <!-- Goals + totals -->
        <div class="panel">
            <p class="panel-title">Today's totals</p>

            <?php
                $goalRows = [
                    'calories' => ['label' => 'Calories', 'unit' => 'kcal'],
                    'protein' => ['label' => 'Protein', 'unit' => 'g'],
                    'carbs' => ['label' => 'Carbs', 'unit' => 'g'],
                    'fat' => ['label' => 'Fat', 'unit' => 'g'],
                ];
            ?>
            <?php foreach ($goalRows as $key => $meta): ?>
                <div class="goal-row <?php echo $key; ?>">
                    <div class="goal-head">
                        <strong><?php echo $meta['label']; ?></strong>
                        <span><?php echo round($totals[$key]); ?> / <?php echo $goals[$key]; ?> <?php echo $meta['unit']; ?></span>
                    </div>
                    <div class="goal-bar-track">
                        <div class="goal-bar-fill" style="width: <?php echo pct($totals[$key], $goals[$key]); ?>%;"></div>
                    </div>
                </div>
            <?php endforeach; ?>

            <button type="button" class="edit-goals-toggle" id="editGoalsToggle">Edit daily goals</button>

            <form class="goals-form" id="goalsForm">
                <div class="goals-grid">
                    <div class="form-group">
                        <label>Calories</label>
                        <input type="number" id="goal_calories" value="<?php echo $goals['calories']; ?>" min="0">
                    </div>
                    <div class="form-group">
                        <label>Protein (g)</label>
                        <input type="number" id="goal_protein" value="<?php echo $goals['protein']; ?>" min="0">
                    </div>
                    <div class="form-group">
                        <label>Carbs (g)</label>
                        <input type="number" id="goal_carbs" value="<?php echo $goals['carbs']; ?>" min="0">
                    </div>
                    <div class="form-group">
                        <label>Fat (g)</label>
                        <input type="number" id="goal_fat" value="<?php echo $goals['fat']; ?>" min="0">
                    </div>
                </div>
                <button type="submit" class="btn-small" id="saveGoalsBtn">Save goals</button>
            </form>
        </div>

        <!-- Body profile -> auto-calculated targets -->
        <div class="panel">
            <p class="panel-title">Body profile</p>
            <p class="empty-note" style="padding:0 0 16px;">Fill this in once to auto-calculate your daily targets above. These are general estimates (Mifflin-St Jeor formula) — not medical advice; adjust the goals manually above if you know better numbers for you.</p>

            <div class="message" id="bodyProfileMessage"></div>

            <form id="bodyProfileForm">
                <div class="goals-grid" style="grid-template-columns: repeat(3, 1fr); margin-bottom:12px;">
                    <div class="form-group">
                        <label>Height (cm)</label>
                        <input type="number" id="bp_height" min="100" max="250" value="<?php echo htmlspecialchars($goals['height_cm'] ?? ''); ?>" placeholder="175">
                    </div>
                    <div class="form-group">
                        <label>Weight (kg)</label>
                        <input type="number" id="bp_weight" min="30" max="300" step="0.1" value="<?php echo htmlspecialchars($goals['weight_kg'] ?? ''); ?>" placeholder="75">
                    </div>
                    <div class="form-group">
                        <label>Age</label>
                        <input type="number" id="bp_age" min="13" max="100" value="<?php echo htmlspecialchars($goals['age'] ?? ''); ?>" placeholder="25">
                    </div>
                </div>

                <div class="goals-grid" style="grid-template-columns: repeat(3, 1fr); margin-bottom:12px;">
                    <div class="form-group">
                        <label>Sex</label>
                        <select id="bp_sex">
                            <option value="">Select…</option>
                            <option value="male" <?php echo ($goals['sex'] ?? '') === 'male' ? 'selected' : ''; ?>>Male</option>
                            <option value="female" <?php echo ($goals['sex'] ?? '') === 'female' ? 'selected' : ''; ?>>Female</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Activity level</label>
                        <select id="bp_activity">
                            <?php
                                $activityOptions = [
                                    'sedentary' => 'Sedentary (little/no exercise)',
                                    'light' => 'Light (1-3 days/week)',
                                    'moderate' => 'Moderate (3-5 days/week)',
                                    'active' => 'Active (6-7 days/week)',
                                    'very_active' => 'Very active (physical job + training)',
                                ];
                                $currentActivity = $goals['activity_level'] ?? 'moderate';
                                foreach ($activityOptions as $val => $label):
                            ?>
                                <option value="<?php echo $val; ?>" <?php echo $currentActivity === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Goal</label>
                        <select id="bp_goal">
                            <?php $currentGoal = $goals['goal_type'] ?? 'maintain'; ?>
                            <option value="cut" <?php echo $currentGoal === 'cut' ? 'selected' : ''; ?>>Cutting (lose fat)</option>
                            <option value="maintain" <?php echo $currentGoal === 'maintain' ? 'selected' : ''; ?>>Maintaining</option>
                            <option value="bulk" <?php echo $currentGoal === 'bulk' ? 'selected' : ''; ?>>Bulking (gain muscle)</option>
                        </select>
                    </div>
                </div>

                <div class="form-group" style="max-width: 200px; margin-bottom:14px;">
                    <label>Target weight (kg) — optional</label>
                    <input type="number" id="bp_target_weight" min="30" max="300" step="0.1" value="<?php echo htmlspecialchars($goals['target_weight_kg'] ?? ''); ?>" placeholder="e.g. 80">
                </div>

                <button type="submit" class="btn-small" id="calculateGoalsBtn">Calculate my targets</button>
            </form>
        </div>

        <!-- Meals -->
        <?php foreach ($mealLabels as $mealKey => $mealLabel): ?>
            <div class="panel meal-panel">
                <div class="meal-head">
                    <h4><?php echo $mealLabel; ?></h4>
                    <span><?php echo round(array_sum(array_column($meals[$mealKey], 'calories'))); ?> kcal</span>
                </div>

                <div id="mealList_<?php echo $mealKey; ?>">
                    <?php if (empty($meals[$mealKey])): ?>
                        <p class="empty-note">Nothing logged yet.</p>
                    <?php else: ?>
                        <?php foreach ($meals[$mealKey] as $entry): ?>
                            <div class="food-entry" data-log-id="<?php echo $entry['id']; ?>">
                                <div class="food-entry-info">
                                    <strong><?php echo htmlspecialchars($entry['food_name']); ?></strong>
                                    <span><?php echo $entry['grams']; ?>g</span>
                                </div>
                                <div class="food-entry-macros">
                                    <strong><?php echo round($entry['calories']); ?> kcal</strong><br>
                                    P <?php echo round($entry['protein']); ?>g · C <?php echo round($entry['carbs']); ?>g · F <?php echo round($entry['fat']); ?>g
                                </div>
                                <button type="button" class="remove-entry-btn" data-log-id="<?php echo $entry['id']; ?>" aria-label="Remove">×</button>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <button type="button" class="add-food-btn" data-meal="<?php echo $mealKey; ?>">+ Add food to <?php echo strtolower($mealLabel); ?></button>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Add food modal -->
    <div class="modal-backdrop" id="addFoodBackdrop">
        <div class="modal-panel">
            <div class="modal-head">
                <h3 id="addFoodMealLabel">Add food</h3>
                <button type="button" class="modal-close" id="closeAddFoodModal">×</button>
            </div>

            <div class="message" id="modalMessage"></div>

            <form id="addFoodForm">
                <input type="hidden" id="addFoodMeal" value="">

                <div class="form-group" style="margin-bottom:12px;">
                    <label for="foodSelect">Food</label>
                    <input type="text" id="foodSelect" list="foodOptions" placeholder="Start typing… e.g. Chicken breast" autocomplete="off">
                    <datalist id="foodOptions">
                        <?php foreach ($allFoods as $f): ?>
                            <option value="<?php echo htmlspecialchars($f['name']); ?>" data-id="<?php echo $f['id']; ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <div class="form-group" style="margin-bottom:12px;">
                    <label for="foodGrams">Amount (grams)</label>
                    <input type="number" id="foodGrams" min="0" step="1" placeholder="100" value="100">
                </div>

                <div class="macro-preview" id="macroPreview">
                    <div><strong id="prevCal">—</strong><span>kcal</span></div>
                    <div><strong id="prevProtein">—</strong><span>protein</span></div>
                    <div><strong id="prevCarbs">—</strong><span>carbs</span></div>
                    <div><strong id="prevFat">—</strong><span>fat</span></div>
                </div>

                <button type="button" class="custom-food-toggle" id="customFoodToggle">Can't find it? Add a custom food</button>

                <div class="custom-food-fields" id="customFoodFields">
                    <div class="form-group" style="margin-bottom:10px;">
                        <label>Food name</label>
                        <input type="text" id="customName" placeholder="e.g. Mom's chili">
                    </div>
                    <div class="goals-grid" style="margin-bottom:6px;">
                        <div class="form-group">
                            <label>Cal /100g</label>
                            <input type="number" id="customCal" min="0" step="0.1">
                        </div>
                        <div class="form-group">
                            <label>Protein /100g</label>
                            <input type="number" id="customProtein" min="0" step="0.1">
                        </div>
                        <div class="form-group">
                            <label>Carbs /100g</label>
                            <input type="number" id="customCarbs" min="0" step="0.1">
                        </div>
                        <div class="form-group">
                            <label>Fat /100g</label>
                            <input type="number" id="customFat" min="0" step="0.1">
                        </div>
                    </div>
                    <p class="empty-note" style="padding:0;">Saved once — you can reuse it next time just by searching its name.</p>
                </div>

                <button type="submit" class="btn-small" id="saveFoodBtn" style="width:100%; margin-top:16px;">Log it</button>
            </form>
        </div>
    </div>

    <script>
        const navToggle = document.getElementById('navToggle');
        const navMenu = document.getElementById('navMenu');
        if (navToggle && navMenu) {
            navToggle.addEventListener('click', () => navMenu.classList.toggle('is-open'));
            document.addEventListener('click', (e) => {
                if (!navToggle.contains(e.target) && !navMenu.contains(e.target)) navMenu.classList.remove('is-open');
            });
        }

        // All foods (id + per-100g macros) available to the food picker
        const FOODS = <?php echo json_encode($allFoods); ?>;
        const foodByName = {};
        FOODS.forEach(f => { foodByName[f.name.toLowerCase()] = f; });

        const pageMessage = document.getElementById('pageMessage');
        function showMessage(el, text, type) {
            el.textContent = text;
            el.className = 'message show ' + type;
        }

        // ---------- Goals editing ----------
        const goalsForm = document.getElementById('goalsForm');
        document.getElementById('editGoalsToggle').addEventListener('click', () => {
            goalsForm.classList.toggle('show');
        });

        goalsForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const btn = document.getElementById('saveGoalsBtn');
            btn.disabled = true;

            fetch('api/update-nutrition-goals.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    calories: document.getElementById('goal_calories').value,
                    protein: document.getElementById('goal_protein').value,
                    carbs: document.getElementById('goal_carbs').value,
                    fat: document.getElementById('goal_fat').value,
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showMessage(pageMessage, 'Goals updated!', 'success');
                    setTimeout(() => location.reload(), 700);
                } else {
                    showMessage(pageMessage, data.error || 'Could not save goals.', 'error');
                    btn.disabled = false;
                }
            })
            .catch(() => { showMessage(pageMessage, 'Could not reach the server.', 'error'); btn.disabled = false; });
        });

        // ---------- Body profile -> auto-calculate targets ----------
        const bodyProfileForm = document.getElementById('bodyProfileForm');
        const bodyProfileMessage = document.getElementById('bodyProfileMessage');

        bodyProfileForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const btn = document.getElementById('calculateGoalsBtn');
            btn.disabled = true;

            fetch('api/calculate-nutrition-goals.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    height_cm: document.getElementById('bp_height').value,
                    weight_kg: document.getElementById('bp_weight').value,
                    age: document.getElementById('bp_age').value,
                    sex: document.getElementById('bp_sex').value,
                    activity_level: document.getElementById('bp_activity').value,
                    goal_type: document.getElementById('bp_goal').value,
                    target_weight_kg: document.getElementById('bp_target_weight').value,
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showMessage(bodyProfileMessage, `Targets calculated: ${data.targets.calories} kcal, ${data.targets.protein}g protein, ${data.targets.carbs}g carbs, ${data.targets.fat}g fat.`, 'success');
                    setTimeout(() => location.reload(), 1200);
                } else {
                    showMessage(bodyProfileMessage, data.error || 'Could not calculate targets.', 'error');
                    btn.disabled = false;
                }
            })
            .catch(() => { showMessage(bodyProfileMessage, 'Could not reach the server.', 'error'); btn.disabled = false; });
        });

        // ---------- Add food modal ----------
        const addFoodBackdrop = document.getElementById('addFoodBackdrop');
        const addFoodMealInput = document.getElementById('addFoodMeal');
        const addFoodMealLabel = document.getElementById('addFoodMealLabel');
        const foodSelect = document.getElementById('foodSelect');
        const foodGrams = document.getElementById('foodGrams');
        const modalMessage = document.getElementById('modalMessage');
        const customFoodFields = document.getElementById('customFoodFields');

        const mealLabels = { breakfast: 'Breakfast', lunch: 'Lunch', dinner: 'Dinner', snack: 'Snacks' };

        document.querySelectorAll('.add-food-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                addFoodMealInput.value = btn.dataset.meal;
                addFoodMealLabel.textContent = 'Add food to ' + mealLabels[btn.dataset.meal];
                document.getElementById('addFoodForm').reset();
                foodGrams.value = 100;
                customFoodFields.classList.remove('show');
                modalMessage.className = 'message';
                updatePreview();
                addFoodBackdrop.classList.add('is-open');
                setTimeout(() => foodSelect.focus(), 50);
            });
        });

        document.getElementById('closeAddFoodModal').addEventListener('click', () => addFoodBackdrop.classList.remove('is-open'));
        addFoodBackdrop.addEventListener('click', (e) => { if (e.target === addFoodBackdrop) addFoodBackdrop.classList.remove('is-open'); });

        document.getElementById('customFoodToggle').addEventListener('click', () => {
            customFoodFields.classList.toggle('show');
        });

        function updatePreview() {
            const food = foodByName[foodSelect.value.trim().toLowerCase()];
            const grams = parseFloat(foodGrams.value) || 0;

            if (!food) {
                document.getElementById('prevCal').textContent = '—';
                document.getElementById('prevProtein').textContent = '—';
                document.getElementById('prevCarbs').textContent = '—';
                document.getElementById('prevFat').textContent = '—';
                return;
            }

            const factor = grams / 100;
            document.getElementById('prevCal').textContent = Math.round(food.calories_per_100g * factor);
            document.getElementById('prevProtein').textContent = Math.round(food.protein_per_100g * factor) + 'g';
            document.getElementById('prevCarbs').textContent = Math.round(food.carbs_per_100g * factor) + 'g';
            document.getElementById('prevFat').textContent = Math.round(food.fat_per_100g * factor) + 'g';
        }

        foodSelect.addEventListener('input', updatePreview);
        foodGrams.addEventListener('input', updatePreview);

        // ---------- Submit: log a food (existing or newly-defined custom food) ----------
        document.getElementById('addFoodForm').addEventListener('submit', function (e) {
            e.preventDefault();
            const btn = document.getElementById('saveFoodBtn');
            const grams = parseFloat(foodGrams.value);

            if (!grams || grams <= 0) {
                showMessage(modalMessage, 'Enter a valid amount in grams.', 'error');
                return;
            }

            const existingFood = foodByName[foodSelect.value.trim().toLowerCase()];
            const payload = {
                meal: addFoodMealInput.value,
                grams: grams,
                log_date: <?php echo json_encode($logDate); ?>,
            };

            if (existingFood) {
                payload.food_id = existingFood.id;
            } else if (customFoodFields.classList.contains('show')) {
                const name = document.getElementById('customName').value.trim();
                if (!name) {
                    showMessage(modalMessage, 'Enter a name for the custom food.', 'error');
                    return;
                }
                payload.new_food = {
                    name: name,
                    calories_per_100g: document.getElementById('customCal').value || 0,
                    protein_per_100g: document.getElementById('customProtein').value || 0,
                    carbs_per_100g: document.getElementById('customCarbs').value || 0,
                    fat_per_100g: document.getElementById('customFat').value || 0,
                };
            } else {
                showMessage(modalMessage, "Pick a food from the list, or add it as a custom food below.", 'error');
                return;
            }

            btn.disabled = true;
            fetch('api/log-food.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    showMessage(modalMessage, data.error || 'Could not log that food.', 'error');
                    btn.disabled = false;
                }
            })
            .catch(() => { showMessage(modalMessage, 'Could not reach the server.', 'error'); btn.disabled = false; });
        });

        // ---------- Remove a logged entry ----------
        document.querySelectorAll('.remove-entry-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                if (!confirm('Remove this food entry?')) return;
                btn.disabled = true;

                fetch('api/delete-food-log.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ log_id: btn.dataset.logId })
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        showMessage(pageMessage, data.error || 'Could not remove that entry.', 'error');
                        btn.disabled = false;
                    }
                })
                .catch(() => { showMessage(pageMessage, 'Could not reach the server.', 'error'); btn.disabled = false; });
            });
        });
    </script>
</body>
</html>