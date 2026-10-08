<?php
// log-workout.php
// Form to log a new workout session. Each exercise gets its own card with as
// many individual set rows as needed (weight + reps can differ set to set,
// and a set can be flagged as a warmup).

require_once __DIR__ . '/api/includes/db.php';
require_once __DIR__ . '/api/includes/auth.php';

requireLogin();

$userId = getUserId();
$user = getUserInfo($conn, $userId);

// Pull distinct plan names this user has used before, so they can quickly reuse a custom split
$stmt = $conn->prepare("SELECT DISTINCT plan_name FROM workout_plans WHERE user_id = ? ORDER BY plan_name");
$stmt->bind_param("i", $userId);
$stmt->execute();
$planNames = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Common preset splits shown in the dropdown by default
$commonSplits = ['Upper Day', 'Lower Day', 'Push Day', 'Pull Day', 'Leg Day', 'Full Body', 'Rest / Recovery'];

// Only show past custom plans that aren't already covered by the common presets above
$customPlanNames = array_filter($planNames, function ($p) use ($commonSplits) {
    return !in_array($p['plan_name'], $commonSplits, true);
});

// Default to browser current date on initial load, but preserve an explicit query-date or edit-mode value.
$selectedSessionDate = $_GET['session_date'] ?? '';
$hasExplicitSessionDate = isset($_GET['session_date']);
$editWorkoutId = isset($_GET['workout_id']) ? (int) $_GET['workout_id'] : null;
$editWorkoutData = null;

if ($editWorkoutId) {
    $stmt = $conn->prepare(
        "SELECT ws.session_date, ws.duration_minutes, wp.plan_name
         FROM workout_sessions ws
         LEFT JOIN workout_plans wp ON ws.workout_plan_id = wp.id
         WHERE ws.id = ? AND ws.user_id = ? LIMIT 1"
    );
    $stmt->bind_param("ii", $editWorkoutId, $userId);
    $stmt->execute();
    $sessionRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($sessionRow) {
        $selectedSessionDate = $sessionRow['session_date'];
        $editWorkoutData = [
            'session_date' => $sessionRow['session_date'],
            'duration_minutes' => $sessionRow['duration_minutes'],
            'plan_name' => $sessionRow['plan_name'] ?? '',
            'exercises' => [],
        ];

        $stmt = $conn->prepare(
            "SELECT exercise_name, weight, reps, notes, is_warmup
             FROM exercises
             WHERE session_id = ?
             ORDER BY id ASC"
        );
        $stmt->bind_param("i", $editWorkoutId);
        $stmt->execute();
        $exerciseRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $currentKey = null;
        foreach ($exerciseRows as $row) {
            $rowKey = $row['exercise_name'] . '|' . $row['notes'];
            if ($currentKey !== $rowKey) {
                $editWorkoutData['exercises'][] = [
                    'name' => $row['exercise_name'],
                    'notes' => $row['notes'],
                    'sets' => [],
                ];
                $currentKey = $rowKey;
            }

            $lastIndex = count($editWorkoutData['exercises']) - 1;
            $editWorkoutData['exercises'][$lastIndex]['sets'][] = [
                'weight' => $row['weight'],
                'reps' => $row['reps'],
                'is_warmup' => (bool) $row['is_warmup'],
            ];
        }
    } else {
        $editWorkoutId = null;
    }
}

// Exercise name suggestions: the user's own history first, topped up with common lifts
// they haven't logged yet, so the datalist is useful from day one.
$stmt = $conn->prepare("
    SELECT DISTINCT e.exercise_name
    FROM exercises e
    JOIN workout_sessions ws ON e.session_id = ws.id
    WHERE ws.user_id = ?
    ORDER BY e.exercise_name
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$loggedExerciseNames = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'exercise_name');
$stmt->close();

$commonExercises = [
    'Barbell Squat', 'Deadlift', 'Bench Press', 'Incline Bench Press', 'Overhead Press',
    'Barbell Row', 'Pull-Up', 'Lat Pulldown', 'Leg Press', 'Romanian Deadlift',
    'Bulgarian Split Squat', 'Hip Thrust', 'Bicep Curl', 'Tricep Pushdown', 'Lateral Raise',
    'Dumbbell Shoulder Press', 'Cable Row', 'Chest Fly', 'Leg Curl', 'Leg Extension',
    'Calf Raise', 'Plank', 'Hanging Leg Raise', 'Face Pull', 'Hip Abduction',
];

$exerciseSuggestions = array_unique(array_merge($loggedExerciseNames, $commonExercises));
sort($exerciseSuggestions, SORT_NATURAL | SORT_FLAG_CASE);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log Workout - GymTrack</title>
    <style>
        :root {
            color-scheme: dark;
            --bg-dark: #05030a;
            --panel: rgba(15, 8, 28, 0.95);
            --panel-2: rgba(20, 12, 40, 0.98);
            --text-main: #f6f7ff;
            --text-muted: #adb2d4;
            --accent: #7851A9;
            --accent-strong: #9b6af0;
            --border: rgba(151, 109, 222, 0.22);
            --warmup: #ffb454;
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
            transition: transform 0.25s ease, opacity 0.25s ease;
            will-change: transform, opacity;
        }

        .navbar.navbar-hidden { transform: translateY(-100%); opacity: 0; pointer-events: none; }
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
            transition: transform 0.2s ease, background 0.2s ease, border-color 0.2s ease;
        }

        .nav-toggle:hover, .nav-toggle:focus-visible {
            background: rgba(120, 81, 169, 0.2);
            border-color: rgba(155, 106, 240, 0.6);
            transform: translateY(-1px);
        }

        .nav-toggle.is-active { background: rgba(120, 81, 169, 0.24); border-color: rgba(155, 106, 240, 0.7); }

        .barbell-icon { display: inline-flex; align-items: center; gap: 4px; }
        .barbell-icon .bar { width: 18px; height: 4px; border-radius: 999px; background: linear-gradient(90deg, #fff, #c284ff); box-shadow: 0 0 12px rgba(194, 132, 255, 0.3); }
        .barbell-icon .plate { width: 8px; height: 12px; border-radius: 999px; background: linear-gradient(135deg, #a755ff, #7a3ecf); border: 1px solid rgba(255, 255, 255, 0.28); box-shadow: inset 0 0 4px rgba(255, 255, 255, 0.2); }

        .navbar-right { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; justify-content: flex-end; }

        .navbar-right a {
            color: var(--text-main);
            text-decoration: none;
            padding: 10px 16px;
            border-radius: 999px;
            transition: background 0.3s ease, transform 0.2s ease;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.08);
            font-weight: 600;
            font-size: 0.92rem;
        }

        .navbar-right a:hover { background: rgba(120, 81, 169, 0.18); transform: translateY(-1px); }

        /* ---------- Layout ---------- */
        .container { max-width: 760px; margin: 0 auto; padding: 28px 20px 56px; }

        .page-head { margin-bottom: 20px; }
        .page-head h2 { font-size: clamp(1.6rem, 2.4vw, 2.1rem); margin-bottom: 4px; }
        .page-head p { color: var(--text-muted); font-size: 0.95rem; }

        .message {
            padding: 12px 14px;
            border-radius: 12px;
            font-weight: 600;
            border: 1px solid transparent;
            font-size: 0.92rem;
            margin-bottom: 18px;
            display: none;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
        }

        .message.show { display: flex; }
        .message.success { background: rgba(151, 109, 222, 0.14); border-color: rgba(151, 109, 222, 0.3); color: #e7d6ff; }
        .message.error { background: rgba(255, 94, 94, 0.16); border-color: rgba(255, 94, 94, 0.24); color: #ffd7d7; }
        .message a { color: inherit; text-decoration: underline; font-weight: 700; white-space: nowrap; }

        /* ---------- Panels & fields ---------- */
        .panel {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 22px;
            padding: 24px;
            box-shadow: 0 16px 34px rgba(0, 0, 0, 0.2);
            margin-bottom: 18px;
        }

        .panel-title { font-size: 1rem; font-weight: 700; margin-bottom: 16px; color: #fff; }

        .field-grid {
            display: grid;
            grid-template-columns: minmax(220px, 1.6fr) minmax(140px, 0.85fr) minmax(140px, 0.7fr);
            gap: 14px;
            align-items: end;
        }

        .form-group { display: grid; gap: 7px; }
        label { color: #d7dcf5; font-size: 0.86rem; font-weight: 600; }

        input, select {
            width: 100%;
            padding: 12px 14px;
            min-height: 46px;
            border-radius: 12px;
            border: 1px solid rgba(151, 109, 222, 0.22);
            background: rgba(255, 255, 255, 0.05);
            color: var(--text-main);
            font-size: 0.95rem;
            font-family: inherit;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        input::placeholder { color: #7e89ab; }

        input:focus, select:focus {
            outline: none;
            border-color: rgba(155, 106, 240, 0.8);
            box-shadow: 0 0 0 3px rgba(155, 106, 240, 0.16);
        }

        select option { background: #14092b; color: #fff; }
        input[type="date"] { color-scheme: dark; }

        /* ---------- Exercise cards ---------- */
        #exerciseList { display: grid; gap: 12px; margin-bottom: 14px; }

        .exercise-card {
            background: var(--panel-2);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 16px;
        }

        .exercise-card-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }

        .exercise-card-head span {
            font-size: 0.78rem;
            color: var(--text-muted);
            font-weight: 700;
            letter-spacing: 0.02em;
            text-transform: uppercase;
        }

        .remove-exercise-btn, .remove-set-btn {
            background: rgba(255, 94, 94, 0.12);
            border: 1px solid rgba(255, 94, 94, 0.28);
            color: #ffb3b3;
            border-radius: 50%;
            font-size: 1rem;
            line-height: 1;
            cursor: pointer;
            display: grid;
            place-items: center;
            transition: background 0.2s ease;
            flex-shrink: 0;
        }

        .remove-exercise-btn { width: 30px; height: 30px; }
        .remove-set-btn { width: 26px; height: 26px; font-size: 0.9rem; }

        .remove-exercise-btn:hover, .remove-set-btn:hover { background: rgba(255, 94, 94, 0.22); }
        .remove-exercise-btn:disabled, .remove-set-btn:disabled { opacity: 0.3; cursor: not-allowed; }

        .exercise-name-field { margin-bottom: 12px; }

        /* Each set: label, weight, reps, warmup toggle, remove — wraps gracefully on narrow screens */
        .sets-list { display: grid; gap: 8px; margin-bottom: 10px; }

        .set-row {
            display: flex;
            align-items: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 8px 10px;
            flex-wrap: wrap;
        }

        .set-row.is-warmup { border-color: rgba(255, 180, 84, 0.4); background: rgba(255, 180, 84, 0.07); }

        .set-label {
            font-size: 0.78rem;
            font-weight: 700;
            color: var(--text-muted);
            width: 62px;
            flex-shrink: 0;
        }

        .set-row.is-warmup .set-label { color: var(--warmup); }

        .set-row input {
            min-height: 40px;
            padding: 8px 10px;
            flex: 1 1 90px;
        }

        .warmup-toggle {
            width: 34px;
            height: 34px;
            min-height: 0;
            border-radius: 8px;
            border: 1px solid rgba(151, 109, 222, 0.3);
            background: rgba(255, 255, 255, 0.04);
            color: var(--text-muted);
            font-size: 0.72rem;
            font-weight: 800;
            cursor: pointer;
            flex-shrink: 0;
            transition: background 0.2s ease, border-color 0.2s ease, color 0.2s ease;
        }

        .warmup-toggle.active { background: rgba(255, 180, 84, 0.18); border-color: var(--warmup); color: var(--warmup); }
        .warmup-toggle:hover { border-color: rgba(155, 106, 240, 0.6); }

        .add-set-btn {
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
            transition: background 0.2s ease, border-color 0.2s ease;
        }

        .add-set-btn:hover { background: rgba(151, 109, 222, 0.18); border-color: rgba(155, 106, 240, 0.7); }

        .exercise-notes-field { margin-top: 12px; }

        .add-row-btn {
            width: 100%;
            padding: 13px 0;
            min-height: 46px;
            background: rgba(151, 109, 222, 0.12);
            border: 1px dashed rgba(155, 106, 240, 0.5);
            color: #d8b8ff;
            border-radius: 14px;
            font-weight: 700;
            font-size: 0.9rem;
            cursor: pointer;
            transition: background 0.2s ease, border-color 0.2s ease;
        }

        .add-row-btn:hover { background: rgba(151, 109, 222, 0.2); border-color: rgba(155, 106, 240, 0.8); }

        /* ---------- Save bar ---------- */
        .save-bar { display: flex; gap: 12px; justify-content: flex-end; }

        button.btn-primary, button.btn-ghost, button.btn-secondary {
            border: none;
            cursor: pointer;
            font-weight: 700;
            border-radius: 999px;
            padding: 13px 26px;
            min-height: 46px;
            font-size: 0.92rem;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .btn-primary {
            background: linear-gradient(135deg, #a755ff 0%, #7d3fd0 55%, #632a9f 100%);
            color: #f8f9ff;
            box-shadow: 0 14px 26px rgba(167, 85, 255, 0.3);
            border: 1px solid rgba(177, 109, 255, 0.35);
        }

        .btn-primary:hover { transform: translateY(-2px); }
        .btn-primary:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }

        .btn-ghost { background: rgba(255, 255, 255, 0.05); color: var(--text-main); border: 1px solid rgba(255, 255, 255, 0.1); }
        .btn-ghost:hover { background: rgba(255, 255, 255, 0.1); }

        .btn-secondary { background: rgba(151, 109, 222, 0.15); color: #d8b8ff; border: 1px solid rgba(151, 109, 222, 0.4); }
        .btn-secondary:hover { background: rgba(151, 109, 222, 0.25); transform: translateY(-2px); }
        .btn-secondary:disabled { opacity: 0.5; cursor: not-allowed; }

        .hidden { display: none; }

        /* ---------- Quick Adjust Modal ---------- */
        .quick-adjust-modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            padding: 16px;
        }

        .quick-adjust-modal {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 380px;
            width: 100%;
            overflow: hidden;
            animation: slideUp 0.3s ease;
        }

        @keyframes slideUp {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .modal-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 18px 20px;
            border-bottom: 1px solid var(--border);
        }

        .modal-head h3 { font-size: 1.1rem; margin: 0; }

        .modal-close {
            background: none;
            border: none;
            color: var(--text-muted);
            font-size: 1.6rem;
            cursor: pointer;
            transition: color 0.2s ease;
            padding: 0;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-close:hover { color: var(--text-main); }

        .modal-body {
            padding: 20px;
        }

        .adjust-buttons {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 10px;
            margin-bottom: 12px;
        }

        .adjust-btn {
            padding: 12px 14px;
            border-radius: 12px;
            border: 1px solid rgba(151, 109, 222, 0.3);
            background: rgba(151, 109, 222, 0.08);
            color: #d8b8ff;
            font-weight: 700;
            font-size: 0.85rem;
            cursor: pointer;
            transition: background 0.2s ease, border-color 0.2s ease;
            min-height: 44px;
        }

        .adjust-btn:hover { background: rgba(151, 109, 222, 0.16); border-color: rgba(151, 109, 222, 0.5); }

        .modal-footer {
            display: flex;
            gap: 10px;
            padding: 16px 20px;
            border-top: 1px solid var(--border);
        }

        .modal-footer button { flex: 1; }

        .modal-apply:disabled { opacity: 0.4; cursor: not-allowed; }

        /* ---------- Mobile ---------- */
        @media (max-width: 860px) {
            .nav-toggle { display: inline-flex; }

            .navbar-right {
                display: none;
                position: absolute;
                top: calc(100% + 10px);
                right: 20px;
                left: 20px;
                flex-direction: column;
                align-items: stretch;
                padding: 14px;
                background: rgba(5, 5, 15, 0.98);
                border: 1px solid rgba(151, 109, 222, 0.24);
                border-radius: 18px;
                box-shadow: 0 16px 32px rgba(0, 0, 0, 0.24);
            }

            .navbar-right.is-open { display: flex; }
            .navbar-right a { width: 100%; text-align: center; justify-content: center; padding: 12px 16px; }
        }

        @media (max-width: 720px) {
            .navbar { padding: 16px 20px; }
            .container { padding: 20px 16px 48px; }
            .panel { padding: 18px; border-radius: 18px; }

            .field-grid { grid-template-columns: 1fr; gap: 12px; align-items: stretch; }

            .save-bar { flex-direction: column-reverse; gap: 10px; }
            button.btn-primary, button.btn-ghost { width: 100%; }
        }

        @media (max-width: 380px) {
            .exercise-card { padding: 12px; }
            .set-row input { padding: 8px 8px; }
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <h1>Personal GymTracker </h1>
        <button class="nav-toggle" id="navToggle" aria-label="Toggle navigation" type="button">
            <span class="barbell-icon" aria-hidden="true">
                <span class="plate"></span>
                <span class="bar"></span>
                <span class="plate"></span>
            </span>
        </button>
        <div class="navbar-right" id="navMenu">
            <a href="dashboard.php">Dashboard</a>
            <a href="nutrition.php">Nutrition</a>
            <a href="profile.php">Profile</a>
            <a href="friends.php">Friends</a>
            <a href="api/logout.php">Logout</a>
        </div>
    </nav>

    <div class="container">
        <div class="page-head">
            <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap;">
                <div>
                    <h2>Log a workout</h2>
                    <p>One card per exercise — add as many sets as you actually did, warmups included.</p>
                </div>
                <button type="button" id="duplicateLastBtn" class="btn-secondary">📋 Duplicate last</button>
            </div>
        </div>

        <div class="message" id="formMessage"></div>

        <form id="workoutForm">
            <div class="panel">
                <p class="panel-title">Session details</p>
                <div class="field-grid">
                    <div class="form-group">
                        <label for="plan_select">Workout plan</label>
                        <select id="plan_select">
                            <option value="">No plan / not sure yet</option>
                            <optgroup label="Common splits">
                                <?php foreach ($commonSplits as $split): ?>
                                    <option value="<?php echo htmlspecialchars($split); ?>"><?php echo htmlspecialchars($split); ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <?php if (!empty($customPlanNames)): ?>
                                <optgroup label="Your plans">
                                    <?php foreach ($customPlanNames as $p): ?>
                                        <option value="<?php echo htmlspecialchars($p['plan_name']); ?>"><?php echo htmlspecialchars($p['plan_name']); ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endif; ?>
                            <option value="__custom__">Custom split…</option>
                        </select>
                        <input type="text" id="plan_name" name="plan_name" class="hidden" placeholder="e.g. Chest + Legs, Shoulders + Back" style="margin-top: 8px;">
                        <input type="hidden" id="workout_id" value="<?php echo htmlspecialchars($editWorkoutId ?: ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="session_date">Date</label>
                        <input type="date" id="session_date" name="session_date" value="<?php echo htmlspecialchars($selectedSessionDate); ?>" required>
                    </div>
                </div>
                <div class="field-grid">
                    <div class="form-group">
                        <label for="duration_minutes">Duration (minutes)</label>
                        <input type="number" id="duration_minutes" name="duration_minutes" placeholder="60" min="0">
                    </div>
                    <div class="form-group" style="display: flex; flex-direction: column; justify-content: flex-end; padding-bottom: 12px;">
                        <span id="duration_display" style="font-size: 0.9rem; color: var(--text-muted); font-weight: 600;"></span>
                    </div>
                </div>
            </div>

            <div class="panel">
                <p class="panel-title">Exercises</p>
                <div id="exerciseList"></div>
                <button type="button" class="add-row-btn" id="addExerciseBtn">+ Add exercise</button>
            </div>

            <div class="save-bar">
                <a href="dashboard.php"><button type="button" class="btn-ghost">Cancel</button></a>
                <button type="submit" class="btn-primary" id="saveBtn">Save workout</button>
            </div>
        </form>
    </div>

    <datalist id="exerciseNames">
        <?php foreach ($exerciseSuggestions as $name): ?>
            <option value="<?php echo htmlspecialchars($name); ?>">
        <?php endforeach; ?>
    </datalist>

    <template id="exerciseCardTemplate">
        <div class="exercise-card">
            <div class="exercise-card-head">
                <span class="exercise-number">Exercise #1</span>
                <button type="button" class="remove-exercise-btn" aria-label="Remove exercise">×</button>
            </div>
            <div class="form-group exercise-name-field">
                <label>Exercise name</label>
                <input type="text" class="ex-name-input" list="exerciseNames" placeholder="Incline Bench Press" required>
            </div>
            <div class="sets-list"></div>
            <button type="button" class="add-set-btn">+ Add set</button>
            <div class="form-group exercise-notes-field">
                <label>Notes (optional)</label>
                <input type="text" class="ex-notes-input" placeholder="Felt strong today">
            </div>
        </div>
    </template>

    <template id="setRowTemplate">
        <div class="set-row">
            <span class="set-label">Set 1</span>
            <input type="number" class="set-weight-input" step="0.5" min="0" placeholder="Weight (kg)" required>
            <input type="number" class="set-reps-input" min="1" placeholder="Reps" required>
            <button type="button" class="warmup-toggle" aria-pressed="false" title="Mark as warmup set">W</button>
            <button type="button" class="remove-set-btn" aria-label="Remove set">×</button>
        </div>
    </template>

    <script>
        // Get userId from server FIRST (needed for localStorage)
        const userId = <?php echo json_encode($userId); ?>;

        // ---------- Plan dropdown <-> custom plan text field ----------
        const planSelect = document.getElementById('plan_select');
        const planNameInput = document.getElementById('plan_name');
        const workoutIdInput = document.getElementById('workout_id');
        const workoutId = <?php echo json_encode($editWorkoutId ?: null); ?> || (workoutIdInput.value ? parseInt(workoutIdInput.value, 10) : null);
        const editWorkoutData = <?php echo json_encode($editWorkoutData ?: null); ?>;
        const hasExplicitSessionDate = <?php echo json_encode($hasExplicitSessionDate); ?>;

        function getLocalDateString(date = new Date()) {
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            return `${year}-${month}-${day}`;
        }

        if (!editWorkoutData && !hasExplicitSessionDate) {
            document.getElementById('session_date').value = getLocalDateString();
        }

        planSelect.addEventListener('change', function () {
            if (this.value === '__custom__') {
                planNameInput.value = '';
                planNameInput.classList.remove('hidden');
                planNameInput.required = true;
                planNameInput.focus();
            } else {
                planNameInput.value = this.value;
                planNameInput.classList.add('hidden');
                planNameInput.required = false;
            }
        });

        function setPlanSelection(planName) {
            if (!planName) {
                planSelect.value = '';
                planNameInput.value = '';
                planNameInput.classList.add('hidden');
                planNameInput.required = false;
                return;
            }

            const optionExists = [...planSelect.options].some(option => option.value === planName);
            if (optionExists) {
                planSelect.value = planName;
                planNameInput.value = planName;
                planNameInput.classList.add('hidden');
                planNameInput.required = false;
            } else {
                planSelect.value = '__custom__';
                planNameInput.value = planName;
                planNameInput.classList.remove('hidden');
                planNameInput.required = true;
            }
        }

        function populateWorkout(data) {
            document.getElementById('session_date').value = data.session_date;
            setPlanSelection(data.plan_name ?? '');

            exerciseList.innerHTML = '';
            data.exercises.forEach(task => {
                addExerciseCard();
                const card = exerciseList.querySelector('.exercise-card:last-child');
                card.querySelector('.ex-name-input').value = task.name;
                card.querySelector('.ex-notes-input').value = task.notes;
                const setsList = card.querySelector('.sets-list');
                setsList.innerHTML = '';

                task.sets.forEach((set, index) => {
                    const clone = setTemplate.content.cloneNode(true);
                    setsList.appendChild(clone);
                    const row = setsList.querySelector('.set-row:last-child');
                    row.querySelector('.set-weight-input').value = set.weight;
                    row.querySelector('.set-reps-input').value = set.reps;
                    if (set.is_warmup) {
                        row.classList.add('is-warmup');
                        const warmupToggle = row.querySelector('.warmup-toggle');
                        warmupToggle.classList.add('active');
                        warmupToggle.setAttribute('aria-pressed', 'true');
                    }
                });

                renumberSets(card);
            });

            renumberExercises();
            saveBtn.textContent = 'Update workout';
        }

        // ---------- Exercise cards + set rows ----------
        const exerciseList = document.getElementById('exerciseList');
        const exerciseTemplate = document.getElementById('exerciseCardTemplate');
        const setTemplate = document.getElementById('setRowTemplate');

        function renumberSets(card) {
            const rows = card.querySelectorAll('.set-row');
            let workingCount = 0;
            rows.forEach(row => {
                const isWarmup = row.classList.contains('is-warmup');
                row.querySelector('.set-label').textContent = isWarmup ? 'Warmup' : 'Set ' + (++workingCount);
                row.querySelector('.remove-set-btn').disabled = rows.length <= 1;
            });
        }

        function renumberExercises() {
            const cards = exerciseList.querySelectorAll('.exercise-card');
            cards.forEach((card, i) => {
                card.querySelector('.exercise-number').textContent = 'Exercise #' + (i + 1);
                card.querySelector('.remove-exercise-btn').disabled = cards.length <= 1;
            });
        }

        function addSetRow(card, focus = false) {
            const clone = setTemplate.content.cloneNode(true);
            card.querySelector('.sets-list').appendChild(clone);
            renumberSets(card);
            if (focus) {
                card.querySelector('.set-row:last-child input').focus();
            }
        }

        function addExerciseCard(focus = false) {
            const clone = exerciseTemplate.content.cloneNode(true);
            exerciseList.appendChild(clone);
            const card = exerciseList.querySelector('.exercise-card:last-child');
            addSetRow(card);
            renumberExercises();
            if (focus) {
                card.querySelector('.ex-name-input').focus();
            }
        }

        // One delegated listener handles every button inside every exercise card,
        // including ones added later — no per-clone listeners needed.
        exerciseList.addEventListener('click', function (e) {
            const removeExBtn = e.target.closest('.remove-exercise-btn');
            if (removeExBtn && !removeExBtn.disabled) {
                removeExBtn.closest('.exercise-card').remove();
                renumberExercises();
                return;
            }

            const addSetBtn = e.target.closest('.add-set-btn');
            if (addSetBtn) {
                addSetRow(addSetBtn.closest('.exercise-card'), true);
                return;
            }

            const removeSetBtn = e.target.closest('.remove-set-btn');
            if (removeSetBtn && !removeSetBtn.disabled) {
                const card = removeSetBtn.closest('.exercise-card');
                removeSetBtn.closest('.set-row').remove();
                renumberSets(card);
                return;
            }

            const warmupBtn = e.target.closest('.warmup-toggle');
            if (warmupBtn) {
                const row = warmupBtn.closest('.set-row');
                const isNowWarmup = row.classList.toggle('is-warmup');
                warmupBtn.classList.toggle('active', isNowWarmup);
                warmupBtn.setAttribute('aria-pressed', isNowWarmup);
                renumberSets(warmupBtn.closest('.exercise-card'));
            }
        });

        document.getElementById('addExerciseBtn').addEventListener('click', () => addExerciseCard(true));

        // ---------- Nav toggle (mobile) ----------
        const navToggle = document.getElementById('navToggle');
        const navMenu = document.getElementById('navMenu');

        if (navToggle && navMenu) {
            navToggle.addEventListener('click', function () {
                navMenu.classList.toggle('is-open');
                navToggle.classList.toggle('is-active');
            });

            document.addEventListener('click', function (event) {
                if (!navToggle.contains(event.target) && !navMenu.contains(event.target)) {
                    navMenu.classList.remove('is-open');
                    navToggle.classList.remove('is-active');
                }
            });
        }

        // ---------- Hide navbar on scroll down, show on scroll up ----------
        const navbar = document.querySelector('.navbar');
        let lastScrollY = window.scrollY;

        window.addEventListener('scroll', () => {
            const currentScrollY = window.scrollY;
            if (currentScrollY > lastScrollY && currentScrollY > 80) {
                navbar.classList.add('navbar-hidden');
            } else if (currentScrollY < lastScrollY) {
                navbar.classList.remove('navbar-hidden');
            }
            lastScrollY = currentScrollY;
        });

        // ---------- LocalStorage auto-save ----------
        const STORAGE_KEY = `gym-workout-draft-${userId}`;
        const AUTOSAVE_DELAY = 1000; // Save after 1 second of inactivity
        let autosaveTimeout;

        function saveFormToLocalStorage() {
            const formData = buildPayload();
            try {
                localStorage.setItem(STORAGE_KEY, JSON.stringify(formData));
                console.log('Form auto-saved to localStorage');
            } catch (e) {
                console.warn('Could not save to localStorage:', e);
            }
        }

        function loadFormFromLocalStorage() {
            try {
                const saved = localStorage.getItem(STORAGE_KEY);
                if (saved) {
                    const data = JSON.parse(saved);
                    // Only restore if not editing an existing workout
                    if (!editWorkoutData) {
                        populateWorkout(data);
                        showMessage('📝 Restored your previous session draft', 'success');
                        setTimeout(() => messageEl.classList.remove('show'), 3000);
                        return true;
                    }
                }
            } catch (e) {
                console.warn('Could not restore from localStorage:', e);
            }
            return false;
        }

        function clearFormLocalStorage() {
            try {
                localStorage.removeItem(STORAGE_KEY);
            } catch (e) {
                console.warn('Could not clear localStorage:', e);
            }
        }

        // Auto-save on input changes
        document.addEventListener('change', () => {
            clearTimeout(autosaveTimeout);
            autosaveTimeout = setTimeout(saveFormToLocalStorage, AUTOSAVE_DELAY);
        });

        document.addEventListener('input', () => {
            clearTimeout(autosaveTimeout);
            autosaveTimeout = setTimeout(saveFormToLocalStorage, AUTOSAVE_DELAY);
        });

        // ---------- Duplicate last workout ----------
        async function duplicateLastWorkout() {
            const btn = document.getElementById('duplicateLastBtn');
            btn.disabled = true;
            btn.textContent = 'Loading...';

            try {
                const res = await fetch('api/get-last-workout.php');
                const data = await res.json();

                if (data.success) {
                    populateWorkout(data);
                    showMessage('✅ Last workout loaded! Adjust weights and save.', 'success');
                    setTimeout(() => messageEl.classList.remove('show'), 3000);
                    // Scroll to top
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                } else {
                    showMessage(data.error || 'Could not load last workout.', 'error');
                }
            } catch (e) {
                showMessage('Error loading last workout.', 'error');
            } finally {
                btn.disabled = false;
                btn.textContent = '📋 Duplicate last';
            }
        }

        document.getElementById('duplicateLastBtn').addEventListener('click', duplicateLastWorkout);

        // ---------- Quick weight adjustment modal ----------
        function showWeightAdjustmentModal(sessionId) {
            const modal = document.createElement('div');
            modal.className = 'quick-adjust-modal-overlay';
            modal.innerHTML = `
                <div class="quick-adjust-modal">
                    <div class="modal-head">
                        <h3>Adjust weights for next time?</h3>
                        <button class="modal-close" type="button">×</button>
                    </div>
                    <div class="modal-body">
                        <p style="color: var(--text-muted); margin-bottom: 16px;">Bump all weights up or down for your next session.</p>
                        <div class="adjust-buttons">
                            <button type="button" class="adjust-btn adjust-minus">-2.5 kg</button>
                            <button type="button" class="adjust-btn adjust-custom">Custom</button>
                            <button type="button" class="adjust-btn adjust-plus">+2.5 kg</button>
                        </div>
                        <input type="number" id="customAdjustAmount" class="hidden" placeholder="Enter amount (e.g., 5 or -2.5)" step="0.5" style="width: 100%; margin-top: 12px; display: none;">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn-ghost modal-cancel">Skip</button>
                        <button type="button" class="btn-primary modal-apply" disabled>Apply & Edit</button>
                    </div>
                </div>
            `;

            document.body.appendChild(modal);

            let adjustAmount = null;
            const customInput = modal.querySelector('#customAdjustAmount');
            const applyBtn = modal.querySelector('.modal-apply');
            const customBtn = modal.querySelector('.adjust-custom');

            modal.querySelector('.adjust-minus').addEventListener('click', () => {
                adjustAmount = -2.5;
                applyBtn.disabled = false;
            });

            modal.querySelector('.adjust-plus').addEventListener('click', () => {
                adjustAmount = 2.5;
                applyBtn.disabled = false;
            });

            customBtn.addEventListener('click', () => {
                customInput.style.display = customInput.style.display === 'none' ? 'block' : 'none';
                customInput.focus();
            });

            customInput.addEventListener('change', () => {
                const val = parseFloat(customInput.value);
                if (!isNaN(val)) {
                    adjustAmount = val;
                    applyBtn.disabled = false;
                }
            });

            applyBtn.addEventListener('click', () => {
                if (adjustAmount !== null) {
                    window.location.href = `log-workout.php?workout_id=${sessionId}&adjust=${adjustAmount}`;
                }
            });

            modal.querySelector('.modal-close').addEventListener('click', () => modal.remove());
            modal.querySelector('.modal-cancel').addEventListener('click', () => modal.remove());
            modal.addEventListener('click', (e) => {
                if (e.target === modal) modal.remove();
            });
        }

        // Get userId for localStorage (from the server)
        

        // ---------- Calculate duration from start and end times ----------
        function calculateDuration() {
            const startTime = document.getElementById('start_time').value;
            const endTime = document.getElementById('end_time').value;
            const durationDisplay = document.getElementById('duration_display');

            if (!startTime || !endTime) {
                if (durationDisplay) {
                    durationDisplay.textContent = '';
                }
                return null;
            }

            const [startHour, startMin] = startTime.split(':').map(Number);
            const [endHour, endMin] = endTime.split(':').map(Number);

            const startTotalMin = startHour * 60 + startMin;
            const endTotalMin = endHour * 60 + endMin;
            const duration = endTotalMin - startTotalMin;

            if (durationDisplay && duration > 0) {
                durationDisplay.textContent = `~${duration} min`;
            }

            return duration > 0 ? duration : null;
        }

        // Auto-calculate duration when times change
        document.getElementById('start_time').addEventListener('change', calculateDuration);
        document.getElementById('end_time').addEventListener('change', calculateDuration);

        // ---------- Build the payload from the DOM, then submit as JSON ----------
        function buildPayload() {
            const exercises = [];

            exerciseList.querySelectorAll('.exercise-card').forEach(card => {
                const name = card.querySelector('.ex-name-input').value.trim();
                const notes = card.querySelector('.ex-notes-input').value.trim();
                const sets = [];

                card.querySelectorAll('.set-row').forEach(row => {
                    const weight = row.querySelector('.set-weight-input').value;
                    const reps = row.querySelector('.set-reps-input').value;
                    if (weight === '' && reps === '') return; // skip a fully empty set row

                    sets.push({
                        weight: weight,
                        reps: reps,
                        is_warmup: row.classList.contains('is-warmup'),
                    });
                });

                if (name === '' && sets.length === 0) return; // skip a fully empty exercise card
                exercises.push({ name, notes, sets });
            });

            const durationMinutes = calculateDuration();

            return {
                workout_id: workoutId,
                plan_name: planNameInput.value,
                session_date: document.getElementById('session_date').value,
                duration_minutes: durationMinutes,
                exercises,
            };
        }

        // ---------- Form submit via AJAX — stays on this page after saving ----------
        const form = document.getElementById('workoutForm');
        const messageEl = document.getElementById('formMessage');
        const saveBtn = document.getElementById('saveBtn');

        function showMessage(html, type) {
            messageEl.innerHTML = html;
            messageEl.className = 'message show ' + type;
        }

        function resetFormForNextEntry() {
            // Start fresh after saving, default to today’s date so add flow is always current.
            document.getElementById('session_date').value = getLocalDateString();
            
            
            document.getElementById('duration_display').textContent = '';
            exerciseList.innerHTML = '';
            addExerciseCard();
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            saveBtn.disabled = true;
            saveBtn.textContent = 'Saving…';

            const endpoint = workoutId ? 'api/update-workout.php' : 'api/add-workout.php';
            fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(buildPayload())
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    clearFormLocalStorage(); // Clear saved draft after successful save
                    if (workoutId) {
                        // Editing an existing workout
                        showMessage('Workout updated! <a href="workouts.php">View in My Workouts</a>', 'success');
                    } else {
                        // New workout saved — show quick adjust modal then redirect to edit
                        showMessage('✅ Workout saved!', 'success');
                        setTimeout(() => {
                            showWeightAdjustmentModal(data.sessionId);
                        }, 500);
                    }
                } else {
                    showMessage(data.error || 'Something went wrong. Please try again.', 'error');
                }
            })
            .catch(() => {
                showMessage('Could not reach the server. Please try again.', 'error');
            })
            .finally(() => {
                saveBtn.disabled = false;
                saveBtn.textContent = workoutId ? 'Update workout' : 'Save workout';
            });
        });

        // Populate the form now that everything above (saveBtn, the submit handler, etc.) exists.
        if (editWorkoutData) {
            populateWorkout(editWorkoutData);
        } else if (!loadFormFromLocalStorage()) {
            // Start with one exercise card (which itself starts with one set row)
            addExerciseCard();
        }

        // Handle weight adjustment if coming from quick adjust modal
        const urlParams = new URLSearchParams(window.location.search);
        const adjustAmount = parseFloat(urlParams.get('adjust'));
        if (!isNaN(adjustAmount) && adjustAmount !== 0 && editWorkoutData) {
            // Adjust all weights
            exerciseList.querySelectorAll('.set-weight-input').forEach(input => {
                const currentWeight = parseFloat(input.value) || 0;
                const newWeight = Math.max(0, currentWeight + adjustAmount);
                input.value = newWeight.toFixed(adjustAmount % 1 !== 0 ? 1 : 0);
            });
            showMessage(`📈 Weights adjusted by ${adjustAmount > 0 ? '+' : ''}${adjustAmount} kg`, 'success');
            setTimeout(() => messageEl.classList.remove('show'), 3000);
            // Clean up URL
            window.history.replaceState({}, document.title, window.location.pathname + '?workout_id=' + editWorkoutId);
        }
    </script>
</body>
</html>