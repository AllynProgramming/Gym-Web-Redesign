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

// This user's goals (default values if they've never set any)
$stmt = $conn->prepare("SELECT calories, protein, carbs, fat, height_cm, weight_kg, age, sex, activity_level, goal_type, target_weight_kg FROM nutrition_goals WHERE user_id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$goals = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$goals) {
    $goals = [
        'calories' => 2000,
        'protein' => 150,
        'carbs' => 200,
        'fat' => 65,
        'height_cm' => null,
        'weight_kg' => null,
        'age' => null,
        'sex' => null,
        'activity_level' => 'moderate',
        'goal_type' => 'maintain',
        'target_weight_kg' => null,
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

function pct($value, $goal)
{
    if ($goal <= 0)
        return 0;
    return max(0, min(100, round(($value / $goal) * 100)));
}

// ---- View data (display only) ----
$goalRows = [
    'calories' => ['label' => 'Calories', 'unit' => ' kcal', 'color' => 'var(--blue)'],
    'protein' => ['label' => 'Protein', 'unit' => ' g', 'color' => 'var(--green)'],
    'carbs' => ['label' => 'Carbs', 'unit' => ' g', 'color' => 'var(--yellow)'],
    'fat' => ['label' => 'Fat', 'unit' => ' g', 'color' => 'var(--red)'],
];
$activityOptions = [
    'sedentary' => 'Sedentary: little or no exercise',
    'light' => 'Light: 1 to 3 days a week',
    'moderate' => 'Moderate: 3 to 5 days a week',
    'active' => 'Active: 6 to 7 days a week',
    'very_active' => 'Very active: physical job and training',
];
$currentActivity = $goals['activity_level'] ?? 'moderate';
$currentGoal = $goals['goal_type'] ?? 'maintain';
function gt_e($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <script src="assets/theme.js"></script>
    <title>Nutrition | GymTrack</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@62..125,500..900&family=Newsreader:opsz,wght@6..72,400..600&display=swap"
        rel="stylesheet">
    <style>
        :root {
            --bg: #ECEEEA;
            --surface: #F7F8F5;
            --ink: #1D2024;
            --muted: #5B6168;
            --rule: #C9CEC9;
            --accent: #1F4FCC;
            --on-accent: #fff;
            --err: #B3261E;
            --red: #D3302B;
            --blue: #1F4FCC;
            --yellow: #EDBE2B;
            --green: #1F8A4D;
            --head: "Archivo", Arial, sans-serif;
            --body: "Newsreader", Georgia, serif;
            box-sizing: border-box
        }

        @media (prefers-color-scheme:dark) {
            :root {
                --bg: #16181B;
                --surface: #1E2125;
                --ink: #E8EAE6;
                --muted: #9AA0A6;
                --rule: #34383D;
                --accent: #6C93FF;
                --on-accent: #0F1216;
                --err: #FF8A80;
                --red: #E5524C;
                --blue: #6C93FF;
                --green: #3DB070
            }
        }

        *,
        *::before,
        *::after {
            box-sizing: inherit
        }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--ink);
            font: 400 1.125rem/1.55 var(--body);
            padding: env(safe-area-inset-top, 0px) 0 env(safe-area-inset-bottom, 0px)
        }

        :focus-visible {
            outline: 3px solid var(--accent);
            outline-offset: 3px
        }

        a {
            color: inherit
        }

        .wrap {
            max-width: 760px;
            margin: 0 auto;
            padding: 0 clamp(1.1rem, 4vw, 2rem)
        }

        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem 2rem;
            flex-wrap: wrap;
            padding: 1.2rem 0;
            border-bottom: 1px solid var(--rule)
        }

        .logo {
            font: 800 1.25rem var(--head);
            font-stretch: 112%;
            text-decoration: none
        }

        nav {
            display: flex;
            gap: .3rem 1.4rem;
            flex-wrap: wrap;
            font: 600 .95rem var(--head)
        }

        nav a {
            text-decoration: none;
            padding: .3rem 0
        }

        nav a:hover,
        .days a:hover {
            text-decoration: underline;
            text-underline-offset: 4px
        }

        .top {
            padding: 2rem 0 .6rem
        }

        h1 {
            font: 850 clamp(2rem, 6vw, 3.2rem)/1.02 var(--head);
            font-stretch: 118%;
            letter-spacing: -.025em;
            margin: 0 0 .6rem
        }

        .days {
            display: flex;
            gap: .4rem 1.4rem;
            flex-wrap: wrap;
            font: 600 .95rem var(--head)
        }

        .btn {
            display: inline-block;
            background: var(--accent);
            color: var(--on-accent);
            font: 700 1rem var(--head);
            padding: .75rem 1.3rem;
            border: 0;
            border-radius: 6px;
            text-decoration: none;
            cursor: pointer;
            min-height: 2.8rem
        }

        .btn.alt {
            background: transparent;
            color: var(--ink);
            box-shadow: inset 0 0 0 2px var(--ink)
        }

        .btn[disabled] {
            opacity: .6;
            cursor: wait
        }

        .msg {
            margin: 1rem 0 0;
            padding: .75rem 1rem;
            border-radius: 6px;
            font: 600 1rem var(--head);
            background: var(--surface);
            box-shadow: inset 0 0 0 1px var(--rule)
        }

        .msg.err {
            color: var(--err);
            box-shadow: inset 0 0 0 2px var(--err)
        }

        .msg[hidden] {
            display: none
        }

        .sec {
            padding: 1.8rem 0;
            border-top: 1px solid var(--rule);
            margin-top: 1.4rem
        }

        .sec>h2,
        .meal h2 {
            font: 750 1.2rem var(--head);
            margin: 0 0 .8rem
        }

        .mac {
            display: grid;
            grid-template-columns: 6rem 1fr 12rem;
            gap: 1rem;
            align-items: center;
            padding: .7rem 0;
            border-bottom: 1px solid var(--rule)
        }

        .mac span {
            font: 600 1rem var(--head)
        }

        .mac em {
            font-style: normal;
            text-align: right;
            font-variant-numeric: tabular-nums;
            color: var(--muted)
        }

        .mac em b {
            color: var(--ink);
            font-family: var(--head)
        }

        .track {
            height: 12px;
            background: var(--surface);
            border-radius: 6px;
            box-shadow: inset 0 0 0 1px var(--rule);
            overflow: hidden
        }

        .track i {
            display: block;
            height: 100%;
            background: var(--c);
            border-radius: 6px
        }

        .meal {
            padding: 1.5rem 0;
            border-top: 2px solid var(--ink);
            margin-top: 1.4rem
        }

        .meal .mh {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: 1rem
        }

        .meal .mh span {
            font: 600 .95rem var(--head);
            color: var(--muted);
            font-variant-numeric: tabular-nums
        }

        .meal ul {
            list-style: none;
            margin: 0 0 .8rem;
            padding: 0
        }

        .meal li {
            display: grid;
            grid-template-columns: 1fr auto 2.4rem;
            gap: .8rem;
            align-items: center;
            padding: .6rem 0;
            border-bottom: 1px solid var(--rule)
        }

        .meal li .n {
            font: 700 1rem var(--head)
        }

        .meal li .n small {
            display: block;
            font: 400 .95rem var(--body);
            color: var(--muted)
        }

        .meal li .m {
            text-align: right;
            font-variant-numeric: tabular-nums;
            font-size: .95rem;
            color: var(--muted)
        }

        .meal li .m b {
            color: var(--ink);
            font-family: var(--head);
            display: block
        }

        .x {
            border: 0;
            background: transparent;
            color: var(--muted);
            font: 700 1.3rem/1 var(--head);
            width: 2.4rem;
            height: 2.4rem;
            border-radius: 6px;
            cursor: pointer
        }

        .x:hover {
            color: var(--err);
            box-shadow: inset 0 0 0 1.5px var(--err)
        }

        .empty {
            color: var(--muted);
            margin: 0 0 .8rem
        }

        .addfood {
            width: 100%;
            background: transparent;
            color: var(--ink);
            font: 700 .95rem var(--head);
            border: 2px dashed var(--rule);
            border-radius: 6px;
            min-height: 2.8rem;
            cursor: pointer
        }

        .addfood:hover {
            border-color: var(--ink)
        }

        details {
            border-top: 1px solid var(--rule);
            padding: 1.1rem 0
        }

        details>summary {
            font: 750 1.1rem var(--head);
            cursor: pointer;
            list-style: none;
            display: flex;
            justify-content: space-between;
            gap: 1rem
        }

        details>summary::-webkit-details-marker {
            display: none
        }

        details>summary::after {
            content: "Show";
            font: 600 .9rem var(--head);
            color: var(--muted)
        }

        details[open]>summary::after {
            content: "Hide"
        }

        details .in {
            padding-top: 1rem
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 1rem
        }

        .grid3 {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin-bottom: 1rem
        }

        .field {
            display: grid;
            gap: .3rem;
            align-content: start
        }

        label {
            font: 700 .85rem var(--head)
        }

        input,
        select {
            font: 400 1.1rem var(--body);
            color: var(--ink);
            background: var(--surface);
            border: 2px solid var(--rule);
            border-radius: 4px;
            padding: .6rem .7rem;
            width: 100%;
            min-width: 0;
            min-height: 2.8rem
        }

        input:focus,
        select:focus {
            outline: none;
            border-color: var(--accent)
        }

        input:focus-visible,
        select:focus-visible {
            outline: 3px solid var(--accent);
            outline-offset: 1px
        }

        .hint {
            color: var(--muted);
            font-size: .95rem;
            margin: .2rem 0 1rem
        }

        dialog {
            border: 0;
            border-radius: 10px;
            padding: 1.4rem;
            max-width: 26rem;
            width: calc(100% - 2rem);
            max-height: 90vh;
            background: var(--surface);
            color: var(--ink);
            box-shadow: 0 0 0 1px var(--rule), 0 20px 50px rgba(0, 0, 0, .3)
        }

        dialog::backdrop {
            background: rgba(0, 0, 0, .5)
        }

        .dh {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem
        }

        .dh h2 {
            font: 750 1.2rem var(--head);
            margin: 0
        }

        .prev {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: .5rem;
            margin: 1rem 0;
            border-top: 2px solid var(--ink);
            padding-top: .5rem
        }

        .prev div {
            font: 600 .8rem var(--head);
            color: var(--muted)
        }

        .prev b {
            display: block;
            font: 800 1.3rem var(--head);
            color: var(--ink);
            font-variant-numeric: tabular-nums
        }

        .sp {
            margin-top: 1rem
        }

        footer {
            padding: 1rem 0 3rem
        }

        @media (max-width:640px) {
            .grid {
                grid-template-columns: 1fr 1fr
            }

            .grid3 {
                grid-template-columns: 1fr
            }

            .mac {
                grid-template-columns: 1fr auto;
                gap: .3rem 1rem
            }

            .mac .track {
                grid-column: 1/-1;
                order: 3
            }

            .meal li {
                grid-template-columns: 1fr auto 2.2rem;
                gap: .5rem
            }
        }
    </style>
</head>

<body>
    <div class="wrap">
        <header>
            <a class="logo" href="dashboard.php">GymTrack</a>
            <nav aria-label="Main">
                <a href="dashboard.php">Dashboard</a>
                <a href="log-workout.php">Log workout</a>
                <a href="profile.php">Profile</a>
                <a href="friends.php">Friends</a>
                <a href="api/logout.php">Log out</a>
            </nav>
        </header>

        <main>
            <div class="top">
                <h1><?php echo gt_e(date('l, M j', strtotime($logDate))); ?></h1>
                <div class="days">
                    <a href="nutrition.php?date=<?php echo gt_e($prevDate); ?>">Previous day</a>
                    <?php if (!$isToday): ?><a href="nutrition.php">Back to today</a><?php endif; ?>
                    <a href="nutrition.php?date=<?php echo gt_e($nextDate); ?>">Next day</a>
                </div>
                <div class="msg" id="pageMessage" role="status" aria-live="polite" hidden></div>
            </div>

            <section class="sec" style="margin-top:.6rem">
                <h2><?php echo $isToday ? 'Today so far' : 'Totals for this day'; ?></h2>
                <?php foreach ($goalRows as $key => $meta):
                    $v = round($totals[$key]);
                    $g = (float) $goals[$key];
                    $diff = round($g - $v); ?>
                    <div class="mac">
                        <span><?php echo gt_e($meta['label']); ?></span>
                        <div class="track" role="img"
                            aria-label="<?php echo gt_e($meta['label'] . ': ' . $v . ' of ' . $g . $meta['unit']); ?>"><i
                                style="--c:<?php echo $meta['color']; ?>;width:<?php echo pct($totals[$key], $goals[$key]); ?>%"></i>
                        </div>
                        <em><b><?php echo number_format($v); ?></b> of
                            <?php echo number_format($g) . gt_e($meta['unit']); ?>,
                            <?php echo $diff >= 0 ? number_format($diff) . ' left' : number_format(-$diff) . ' over'; ?></em>
                    </div>
                <?php endforeach; ?>
            </section>

            <?php foreach ($mealLabels as $mealKey => $mealLabel): ?>
                <section class="meal">
                    <div class="mh">
                        <h2><?php echo gt_e($mealLabel); ?></h2>
                        <span><?php echo number_format(round(array_sum(array_column($meals[$mealKey], 'calories')))); ?>
                            kcal</span>
                    </div>
                    <?php if (empty($meals[$mealKey])): ?>
                        <p class="empty">Nothing logged yet.</p>
                    <?php else: ?>
                        <ul>
                            <?php foreach ($meals[$mealKey] as $entry): ?>
                                <li>
                                    <div class="n">
                                        <?php echo gt_e($entry['food_name']); ?><small><?php echo gt_e($entry['grams']); ?>
                                            g</small></div>
                                    <div class="m"><b><?php echo round($entry['calories']); ?> kcal</b>P
                                        <?php echo round($entry['protein']); ?> g, C <?php echo round($entry['carbs']); ?> g, F
                                        <?php echo round($entry['fat']); ?> g</div>
                                    <button type="button" class="x remove-entry" data-log-id="<?php echo (int) $entry['id']; ?>"
                                        aria-label="Remove <?php echo gt_e($entry['food_name']); ?>">&times;</button>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <button type="button" class="addfood" data-meal="<?php echo gt_e($mealKey); ?>">Add food to
                        <?php echo gt_e(strtolower($mealLabel)); ?></button>
                </section>
            <?php endforeach; ?>

            <section class="sec">
                <details id="goalsBox">
                    <summary>Daily goals</summary>
                    <div class="in">
                        <form id="goalsForm">
                            <div class="grid">
                                <div class="field"><label for="goal_calories">Calories</label><input type="number"
                                        id="goal_calories" value="<?php echo gt_e($goals['calories']); ?>" min="0">
                                </div>
                                <div class="field"><label for="goal_protein">Protein (g)</label><input type="number"
                                        id="goal_protein" value="<?php echo gt_e($goals['protein']); ?>" min="0"></div>
                                <div class="field"><label for="goal_carbs">Carbs (g)</label><input type="number"
                                        id="goal_carbs" value="<?php echo gt_e($goals['carbs']); ?>" min="0"></div>
                                <div class="field"><label for="goal_fat">Fat (g)</label><input type="number"
                                        id="goal_fat" value="<?php echo gt_e($goals['fat']); ?>" min="0"></div>
                            </div>
                            <button type="submit" class="btn" id="saveGoalsBtn">Save goals</button>
                        </form>
                    </div>
                </details>

                <details id="bodyBox">
                    <summary>Work out targets from your body</summary>
                    <div class="in">
                        <p class="hint">Fill this in once and your daily targets are estimated for you (Mifflin-St Jeor
                            formula). These are general estimates, not medical advice. Change the goals above if you
                            know better numbers for you.</p>
                        <div class="msg" id="bodyProfileMessage" role="status" aria-live="polite" hidden></div>
                        <form id="bodyProfileForm" class="sp">
                            <div class="grid3">
                                <div class="field"><label for="bp_height">Height (cm)</label><input type="number"
                                        id="bp_height" min="100" max="250"
                                        value="<?php echo gt_e($goals['height_cm'] ?? ''); ?>" placeholder="175"></div>
                                <div class="field"><label for="bp_weight">Weight (kg)</label><input type="number"
                                        id="bp_weight" min="30" max="300" step="0.1"
                                        value="<?php echo gt_e($goals['weight_kg'] ?? ''); ?>" placeholder="75"></div>
                                <div class="field"><label for="bp_age">Age</label><input type="number" id="bp_age"
                                        min="13" max="100" value="<?php echo gt_e($goals['age'] ?? ''); ?>"
                                        placeholder="25"></div>
                            </div>
                            <div class="grid3">
                                <div class="field"><label for="bp_sex">Sex</label>
                                    <select id="bp_sex">
                                        <option value="">Select</option>
                                        <option value="male" <?php echo ($goals['sex'] ?? '') === 'male' ? 'selected' : ''; ?>>Male</option>
                                        <option value="female" <?php echo ($goals['sex'] ?? '') === 'female' ? 'selected' : ''; ?>>Female</option>
                                    </select>
                                </div>
                                <div class="field"><label for="bp_activity">Activity level</label>
                                    <select id="bp_activity">
                                        <?php foreach ($activityOptions as $val => $label): ?>
                                            <option value="<?php echo gt_e($val); ?>" <?php echo $currentActivity === $val ? 'selected' : ''; ?>><?php echo gt_e($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="field"><label for="bp_goal">Goal</label>
                                    <select id="bp_goal">
                                        <option value="cut" <?php echo $currentGoal === 'cut' ? 'selected' : ''; ?>>Lose
                                            fat</option>
                                        <option value="maintain" <?php echo $currentGoal === 'maintain' ? 'selected' : ''; ?>>Maintain</option>
                                        <option value="bulk" <?php echo $currentGoal === 'bulk' ? 'selected' : ''; ?>>Gain
                                            muscle</option>
                                    </select>
                                </div>
                            </div>
                            <div class="field" style="max-width:14rem;margin-bottom:1rem"><label
                                    for="bp_target_weight">Target weight (kg, optional)</label><input type="number"
                                    id="bp_target_weight" min="30" max="300" step="0.1"
                                    value="<?php echo gt_e($goals['target_weight_kg'] ?? ''); ?>" placeholder="80">
                            </div>
                            <button type="submit" class="btn" id="calculateGoalsBtn">Calculate my targets</button>
                        </form>
                    </div>
                </details>
            </section>
        </main>
        <footer></footer>
    </div>

    <dialog id="addFoodDialog" aria-labelledby="addFoodTitle">
        <div class="dh">
            <h2 id="addFoodTitle">Add food</h2><button type="button" class="x" id="closeAddFood"
                aria-label="Close">&times;</button>
        </div>
        <div class="msg" id="modalMessage" role="alert" hidden></div>
        <form id="addFoodForm">
            <input type="hidden" id="addFoodMeal" value="">
            <div class="field sp">
                <label for="foodSelect">Food</label>
                <input type="text" id="foodSelect" list="foodOptions" placeholder="Start typing, like chicken breast"
                    autocomplete="off">
                <datalist id="foodOptions">
                    <?php foreach ($allFoods as $f): ?>
                        <option value="<?php echo gt_e($f['name']); ?>">
                        <?php endforeach; ?>
                </datalist>
            </div>
            <div class="field sp">
                <label for="foodGrams">Amount (grams)</label>
                <input type="number" id="foodGrams" min="0" step="any" inputmode="decimal" value="100">
            </div>
            <div class="prev" aria-live="polite">
                <div><b id="prevCal">-</b>kcal</div>
                <div><b id="prevProtein">-</b>protein</div>
                <div><b id="prevCarbs">-</b>carbs</div>
                <div><b id="prevFat">-</b>fat</div>
            </div>
            <details id="customBox" style="border-top:0;padding:0">
                <summary style="font-size:.95rem">Can't find it? Add a custom food</summary>
                <div class="in">
                    <div class="field" style="margin-bottom:1rem"><label for="customName">Food name</label><input
                            type="text" id="customName" placeholder="Mom's chili"></div>
                    <div class="grid" style="grid-template-columns:1fr 1fr">
                        <div class="field"><label for="customCal">Calories per 100 g</label><input type="number"
                                id="customCal" min="0" step="0.1"></div>
                        <div class="field"><label for="customProtein">Protein per 100 g</label><input type="number"
                                id="customProtein" min="0" step="0.1"></div>
                        <div class="field"><label for="customCarbs">Carbs per 100 g</label><input type="number"
                                id="customCarbs" min="0" step="0.1"></div>
                        <div class="field"><label for="customFat">Fat per 100 g</label><input type="number"
                                id="customFat" min="0" step="0.1"></div>
                    </div>
                    <p class="hint">It is saved once, so you can find it by name next time.</p>
                </div>
            </details>
            <button type="submit" class="btn sp" id="saveFoodBtn" style="width:100%">Log food</button>
        </form>
    </dialog>

    <script>
        const $ = id => document.getElementById(id);
        const FOODS = <?php echo json_encode($allFoods); ?>;
        const LOG_DATE = <?php echo json_encode($logDate); ?>;
        const foodByName = {};
        FOODS.forEach(f => { foodByName[f.name.toLowerCase()] = f; });

        function say(el, text, kind) { el.textContent = text; el.className = 'msg' + (kind === 'err' ? ' err' : ''); el.hidden = false; }
        function post(url, body) {
            return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
                .then(r => r.text())
                .then(t => { try { return JSON.parse(t); } catch (e) { throw new Error('bad response'); } });
        }
        const NET = 'Could not reach the server, or it sent back something unexpected. Nothing was changed.';

        // ---------- Edit goals ----------
        $('goalsForm').addEventListener('submit', function (e) {
            e.preventDefault();
            const btn = $('saveGoalsBtn'); btn.disabled = true;
            post('api/update-nutrition-goals.php', {
                calories: $('goal_calories').value, protein: $('goal_protein').value,
                carbs: $('goal_carbs').value, fat: $('goal_fat').value
            }).then(d => {
                if (d.success) { say($('pageMessage'), 'Goals saved.', 'ok'); setTimeout(() => location.reload(), 700); }
                else { say($('pageMessage'), d.error || 'Could not save goals.', 'err'); btn.disabled = false; window.scrollTo({ top: 0, behavior: 'smooth' }); }
            }).catch(() => { say($('pageMessage'), NET, 'err'); btn.disabled = false; });
        });

        // ---------- Body profile -> targets ----------
        $('bodyProfileForm').addEventListener('submit', function (e) {
            e.preventDefault();
            const btn = $('calculateGoalsBtn'); btn.disabled = true;
            post('api/calculate-nutrition-goals.php', {
                height_cm: $('bp_height').value, weight_kg: $('bp_weight').value, age: $('bp_age').value,
                sex: $('bp_sex').value, activity_level: $('bp_activity').value, goal_type: $('bp_goal').value,
                target_weight_kg: $('bp_target_weight').value
            }).then(d => {
                if (d.success) {
                    const t = d.targets;
                    say($('bodyProfileMessage'), 'Targets set: ' + t.calories + ' kcal, ' + t.protein + ' g protein, ' + t.carbs + ' g carbs, ' + t.fat + ' g fat.', 'ok');
                    setTimeout(() => location.reload(), 1400);
                } else { say($('bodyProfileMessage'), d.error || 'Could not calculate targets.', 'err'); btn.disabled = false; }
            }).catch(() => { say($('bodyProfileMessage'), NET, 'err'); btn.disabled = false; });
        });

        // ---------- Add food dialog ----------
        const dlg = $('addFoodDialog'), foodSelect = $('foodSelect'), foodGrams = $('foodGrams'), modalMsg = $('modalMessage');
        const mealNames = { breakfast: 'breakfast', lunch: 'lunch', dinner: 'dinner', snack: 'snacks' };

        document.querySelectorAll('.addfood').forEach(btn => btn.addEventListener('click', () => {
            $('addFoodMeal').value = btn.dataset.meal;
            $('addFoodTitle').textContent = 'Add food to ' + mealNames[btn.dataset.meal];
            $('addFoodForm').reset();
            foodGrams.value = 100;
            $('customBox').open = false;
            modalMsg.hidden = true;
            $('saveFoodBtn').disabled = false;
            updatePreview();
            dlg.showModal();
            foodSelect.focus();
        }));
        $('closeAddFood').addEventListener('click', () => dlg.close());
        dlg.addEventListener('click', e => { if (e.target === dlg) dlg.close(); });

        function updatePreview() {
            const food = foodByName[foodSelect.value.trim().toLowerCase()];
            const g = parseFloat(foodGrams.value) || 0;
            if (!food) { ['prevCal', 'prevProtein', 'prevCarbs', 'prevFat'].forEach(id => $(id).textContent = '-'); return; }
            const k = g / 100;
            $('prevCal').textContent = Math.round(food.calories_per_100g * k);
            $('prevProtein').textContent = Math.round(food.protein_per_100g * k) + ' g';
            $('prevCarbs').textContent = Math.round(food.carbs_per_100g * k) + ' g';
            $('prevFat').textContent = Math.round(food.fat_per_100g * k) + ' g';
        }
        foodSelect.addEventListener('input', updatePreview);
        foodGrams.addEventListener('input', updatePreview);
        $('customBox').addEventListener('toggle', function () {
            if (this.open && !$('customName').value) $('customName').value = foodSelect.value.trim();
        });

        $('addFoodForm').addEventListener('submit', function (e) {
            e.preventDefault();
            const grams = parseFloat(foodGrams.value);
            if (!grams || grams <= 0) { say(modalMsg, 'Enter an amount in grams greater than zero.', 'err'); return; }

            const existing = foodByName[foodSelect.value.trim().toLowerCase()];
            const payload = { meal: $('addFoodMeal').value, grams: grams, log_date: LOG_DATE };

            if (existing) {
                payload.food_id = existing.id;
            } else if ($('customBox').open) {
                const name = $('customName').value.trim();
                if (!name) { say(modalMsg, 'Enter a name for the custom food.', 'err'); return; }
                payload.new_food = {
                    name: name,
                    calories_per_100g: $('customCal').value || 0, protein_per_100g: $('customProtein').value || 0,
                    carbs_per_100g: $('customCarbs').value || 0, fat_per_100g: $('customFat').value || 0
                };
            } else {
                say(modalMsg, 'Pick a food from the list, or add it as a custom food.', 'err');
                return;
            }

            const btn = $('saveFoodBtn'); btn.disabled = true;
            post('api/log-food.php', payload).then(d => {
                if (d.success) location.reload();
                else { say(modalMsg, d.error || 'Could not log that food.', 'err'); btn.disabled = false; }
            }).catch(() => { say(modalMsg, NET, 'err'); btn.disabled = false; });
        });

        // ---------- Remove an entry ----------
        document.querySelectorAll('.remove-entry').forEach(btn => btn.addEventListener('click', () => {
            if (!confirm('Remove this food entry?')) return;
            btn.disabled = true;
            post('api/delete-food-log.php', { log_id: btn.dataset.logId }).then(d => {
                if (d.success) location.reload();
                else { say($('pageMessage'), d.error || 'Could not remove that entry.', 'err'); btn.disabled = false; window.scrollTo({ top: 0, behavior: 'smooth' }); }
            }).catch(() => { say($('pageMessage'), NET, 'err'); btn.disabled = false; });
        }));
    </script>
</body>

</html>