<?php
// workouts.php
// Full workout history, grouped by week — with a quick-glance summary, a plan filter,
// and an in-page detail modal (no separate page load, no more "coming soon" placeholder).

require_once __DIR__ . '/api/includes/db.php';
require_once __DIR__ . '/api/includes/auth.php';

requireLogin();

$userId = getUserId();
$user = getUserInfo($conn, $userId);

// All sessions for this user
$stmt = $conn->prepare("
    SELECT ws.id, ws.workout_plan_id, ws.session_date, ws.duration_minutes, ws.notes, ws.mood, wp.plan_name
    FROM workout_sessions ws
    LEFT JOIN workout_plans wp ON ws.workout_plan_id = wp.id AND wp.user_id = ws.user_id
    WHERE ws.user_id = ?
    ORDER BY ws.session_date DESC, ws.id DESC
    LIMIT 200
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$workouts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Exercises for every session in ONE query (instead of one query per session)
$workoutDetails = [];
$workoutVolume = [];
if (!empty($workouts)) {
    $sessionIds = array_column($workouts, 'id');
    $placeholders = implode(',', array_fill(0, count($sessionIds), '?'));
    $types = str_repeat('i', count($sessionIds));

    $stmt = $conn->prepare("
        SELECT session_id, exercise_name, weight, reps, sets, notes, is_warmup
        FROM exercises
        WHERE session_id IN ($placeholders)
        ORDER BY session_id, id
    ");
    $stmt->bind_param($types, ...$sessionIds);
    $stmt->execute();
    $allExercises = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($allExercises as $ex) {
        $sid = $ex['session_id'];
        $workoutDetails[$sid][] = $ex;
        $workoutVolume[$sid] = ($workoutVolume[$sid] ?? 0) + ($ex['weight'] * $ex['reps'] * $ex['sets']);
    }
}

// Helper: how many distinct exercises (not sets) were done in a session
function distinctExerciseCount($sessionExercises)
{
    return count(array_unique(array_column($sessionExercises, 'exercise_name')));
}

// Page-level summary (sessions / distinct exercises / total sets / total volume across everything shown)
$totalSessions = count($workouts);
$totalExercises = array_sum(array_map(fn($w) => distinctExerciseCount($workoutDetails[$w['id']] ?? []), $workouts));
$totalSets = array_sum(array_map(fn($w) => count($workoutDetails[$w['id']] ?? []), $workouts));
$totalVolume = array_sum($workoutVolume);

// Distinct plan names actually in use, for the filter dropdown
$planFilterOptions = [];
foreach ($workouts as $w) {
    $key = $w['plan_name'] ?: '__none__';
    $planFilterOptions[$key] = $w['plan_name'] ?: 'No plan';
}
asort($planFilterOptions);

// Group workouts by ISO week, newest week first
$workoutsByWeek = [];
foreach ($workouts as $workout) {
    $date = new DateTime($workout['session_date']);
    $week = $date->format('W');
    $year = $date->format('o'); // ISO year, matches ISO week numbering
    $weekKey = $year . '-W' . $week;

    if (!isset($workoutsByWeek[$weekKey])) {
        $startDate = (clone $date)->modify('Monday this week');
        $endDate = (clone $startDate)->modify('Sunday this week');

        $workoutsByWeek[$weekKey] = [
            'week' => $week,
            'year' => $year,
            'startDate' => $startDate->format('Y-m-d'),
            'endDate' => $endDate->format('Y-m-d'),
            'workouts' => [],
        ];
    }

    $workoutsByWeek[$weekKey]['workouts'][] = $workout;
}

// JSON blob the modal reads from client-side — avoids a second round trip when "View" is clicked.
// Sets are grouped by exercise name (each DB row is one set, so several rows can share a name).
$modalData = [];
foreach ($workouts as $w) {
    $exerciseGroups = [];
    foreach ($workoutDetails[$w['id']] ?? [] as $ex) {
        $name = $ex['exercise_name'];
        if (!isset($exerciseGroups[$name])) {
            $exerciseGroups[$name] = ['name' => $name, 'notes' => $ex['notes'], 'sets' => []];
        }
        $exerciseGroups[$name]['sets'][] = [
            'weight' => $ex['weight'],
            'reps' => $ex['reps'],
            'is_warmup' => (bool) $ex['is_warmup'],
        ];
    }

    $modalData[$w['id']] = [
        'date' => date('l, F j, Y', strtotime($w['session_date'])),
        'plan' => $w['plan_name'] ?: null,
        'duration' => $w['duration_minutes'],
        'mood' => $w['mood'],
        'volume' => round($workoutVolume[$w['id']] ?? 0),
        'exercises' => array_values($exerciseGroups),
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Workouts - GymTrack</title>
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

        /* ---------- Navbar (same pattern site-wide) ---------- */
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
            transition: background 0.3s ease;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.08);
            font-weight: 600;
            font-size: 0.92rem;
        }

        .navbar-right a:hover { background: rgba(120, 81, 169, 0.18); }

        /* ---------- Layout ---------- */
        .container { max-width: 900px; margin: 0 auto; padding: 32px 24px 60px; }

        .page-head { margin-bottom: 22px; display: flex; justify-content: space-between; align-items: flex-start; gap: 20px; flex-wrap: wrap; }
        .page-head-text h2 { font-size: clamp(1.8rem, 2.5vw, 2.2rem); margin-bottom: 6px; }
        .page-head-text p { color: var(--text-muted); font-size: 1rem; }

        .page-head-action a {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            background: linear-gradient(135deg, #a755ff 0%, #7d3fd0 55%, #632a9f 100%);
            color: #f8f9ff;
            text-decoration: none;
            border-radius: 999px;
            font-weight: 700;
            font-size: 0.95rem;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            box-shadow: 0 16px 30px rgba(167, 85, 255, 0.32);
            border: 1px solid rgba(177, 109, 255, 0.35);
            white-space: nowrap;
        }

        .page-head-action a:hover { transform: translateY(-2px); box-shadow: 0 18px 34px rgba(194, 132, 255, 0.42); }

        /* ---------- Summary strip ---------- */
        .summary-strip {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-bottom: 18px;
        }

        .summary-chip {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 14px 16px;
        }

        .summary-chip p { font-size: 0.78rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px; }
        .summary-chip h4 { font-size: 1.3rem; color: #fff; }

        /* ---------- Filter bar ---------- */
        .filter-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .filter-bar label { font-size: 0.85rem; color: var(--text-muted); font-weight: 600; }

        .filter-bar select {
            padding: 10px 14px;
            border-radius: 999px;
            border: 1px solid rgba(151, 109, 222, 0.28);
            background: var(--panel);
            color: var(--text-main);
            font-size: 0.9rem;
            font-family: inherit;
            min-width: 180px;
        }

        .filter-bar select:focus { outline: none; border-color: rgba(155, 106, 240, 0.8); }
        .filter-bar select option { background: #14092b; color: #fff; }

        #filterCount { color: var(--text-muted); font-size: 0.88rem; }

        /* ---------- Empty states ---------- */
        .empty-state {
            text-align: center;
            padding: 60px 32px;
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 24px;
        }

        .empty-state h3 { font-size: 1.4rem; margin-bottom: 12px; color: var(--text-main); }
        .empty-state p { color: var(--text-muted); margin-bottom: 24px; font-size: 1rem; }

        .empty-state a {
            display: inline-block;
            padding: 12px 24px;
            background: rgba(151, 109, 222, 0.2);
            border: 1px solid rgba(151, 109, 222, 0.4);
            color: #d8b8ff;
            text-decoration: none;
            border-radius: 999px;
            font-weight: 600;
            transition: all 0.2s ease;
        }

        .empty-state a:hover { background: rgba(151, 109, 222, 0.3); border-color: rgba(155, 106, 240, 0.6); }

        .no-match-note {
            display: none;
            text-align: center;
            padding: 30px;
            color: var(--text-muted);
            font-size: 0.95rem;
        }

        /* ---------- Week cards ---------- */
        .week-card {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 24px;
            padding: 26px;
            margin-bottom: 20px;
        }

        .week-header {
            margin-bottom: 18px;
            padding-bottom: 14px;
            border-bottom: 1px solid rgba(151, 109, 222, 0.15);
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            flex-wrap: wrap;
            gap: 8px;
        }

        .week-title { font-size: 1.2rem; font-weight: 700; color: var(--text-main); }
        .week-range { font-size: 0.88rem; color: var(--text-muted); }

        .workouts-list { display: grid; gap: 14px; }

        .workout-item {
            background: var(--panel-2);
            border: 1px solid rgba(151, 109, 222, 0.12);
            border-radius: 16px;
            padding: 16px;
            transition: background 0.2s ease, border-color 0.2s ease, opacity 0.25s ease, transform 0.25s ease;
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 16px;
            align-items: center;
        }

        .workout-item:hover { background: rgba(30, 15, 50, 0.9); border-color: rgba(155, 106, 240, 0.2); }
        .workout-item.removing { opacity: 0; transform: scale(0.97); }

        .workout-info { min-width: 0; }

        .workout-date-day {
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            font-weight: 600;
            margin-bottom: 4px;
        }

        .workout-name { font-size: 1.05rem; font-weight: 700; color: var(--text-main); margin-bottom: 6px; word-break: break-word; }

        .workout-stats { display: flex; gap: 14px; flex-wrap: wrap; }

        .stat { display: flex; align-items: baseline; gap: 4px; font-size: 0.85rem; }
        .stat-label { color: var(--text-muted); }
        .stat-value { font-weight: 700; color: var(--accent-strong); }

        .workout-actions { display: flex; gap: 8px; flex-direction: column; }

        .workout-actions button {
            padding: 8px 14px;
            border: 1px solid rgba(151, 109, 222, 0.3);
            background: rgba(151, 109, 222, 0.08);
            color: #d8b8ff;
            border-radius: 999px;
            font-weight: 600;
            font-size: 0.82rem;
            cursor: pointer;
            transition: all 0.2s ease;
            white-space: nowrap;
        }

        .workout-actions button:hover { background: rgba(151, 109, 222, 0.16); border-color: rgba(155, 106, 240, 0.5); }

        .workout-actions button.delete { background: rgba(255, 94, 94, 0.1); border-color: rgba(255, 94, 94, 0.3); color: #ffb3b3; }
        .workout-actions button.delete:hover { background: rgba(255, 94, 94, 0.18); border-color: rgba(255, 94, 94, 0.5); }

        /* ---------- Detail modal ---------- */
        .modal-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(5, 3, 10, 0.72);
            backdrop-filter: blur(6px);
            z-index: 50;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal-backdrop.is-open { display: flex; }

        .modal-panel {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 24px;
            max-width: 480px;
            width: 100%;
            max-height: 85vh;
            overflow-y: auto;
            padding: 28px;
            box-shadow: 0 30px 60px rgba(0, 0, 0, 0.4);
        }

        .modal-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; margin-bottom: 4px; }
        .modal-head h3 { font-size: 1.25rem; color: #fff; }

        .modal-close {
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: var(--text-main);
            width: 32px;
            height: 32px;
            border-radius: 50%;
            font-size: 1rem;
            cursor: pointer;
            flex-shrink: 0;
        }

        .modal-close:hover { background: rgba(255, 255, 255, 0.12); }

        .modal-plan-chip {
            display: inline-block;
            background: rgba(151, 109, 222, 0.16);
            color: #d8b8ff;
            padding: 4px 12px;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .modal-facts { display: flex; gap: 16px; color: var(--text-muted); font-size: 0.88rem; margin-bottom: 20px; flex-wrap: wrap; }
        .modal-facts strong { color: #fff; }

        .modal-exercise {
            background: var(--panel-2);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 14px;
            margin-bottom: 10px;
        }

        .modal-exercise-top { display: flex; justify-content: space-between; gap: 10px; margin-bottom: 8px; }
        .modal-exercise-top strong { font-size: 0.95rem; }
        .modal-exercise-notes { color: var(--text-muted); font-size: 0.84rem; margin-top: 6px; }

        .modal-set-line {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            font-size: 0.88rem;
            border-top: 1px solid rgba(151, 109, 222, 0.1);
        }
        .modal-set-line:first-of-type { border-top: none; }
        .modal-set-label { color: var(--text-muted); }
        .modal-set-line span:last-child { font-weight: 700; color: #d8b8ff; }
        .modal-set-line.is-warmup .modal-set-label { color: #ffb454; }
        .modal-set-line.is-warmup span:last-child { color: #ffb454; }

        .modal-actions { margin-top: 18px; display: flex; justify-content: flex-end; }

        /* ---------- Mobile ---------- */
        @media (max-width: 720px) {
            .page-head { flex-direction: column; align-items: flex-start; }
            .summary-strip { grid-template-columns: 1fr 1fr; }
            .filter-bar { width: 100%; }
            .filter-bar select { flex: 1; }

            .week-card { padding: 18px; }

            .workout-item { grid-template-columns: 1fr; gap: 12px; }
            .workout-actions { flex-direction: row; }
            .workout-actions button { flex: 1; }

            .container { padding: 20px 16px 40px; }
            .navbar { padding: 16px 20px; }
            .navbar h1 { font-size: 1.6rem; }
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
            .navbar-right a { width: 100%; text-align: center; justify-content: center; }
        }

        @media (max-width: 420px) {
            .summary-strip { grid-template-columns: 1fr; }
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
            <div class="page-head-text">
                <h2>My Workouts</h2>
                <p>Every session you've logged, grouped by week.</p>
            </div>
            <div class="page-head-action">
                <a href="log-workout.php">+ Log Workout</a>
            </div>
        </div>

        <?php if (empty($workouts)): ?>
            <div class="empty-state">
                <h3>No workouts logged yet</h3>
                <p>Start tracking your fitness journey by logging your first workout session.</p>
                <a href="log-workout.php">Log Your First Workout</a>
            </div>
        <?php else: ?>
            <div class="summary-strip">
                <div class="summary-chip"><p>Sessions</p><h4 id="statSessions"><?php echo $totalSessions; ?></h4></div>
                <div class="summary-chip"><p>Exercises logged</p><h4 id="statExercises"><?php echo $totalExercises; ?></h4></div>
                <div class="summary-chip"><p>Sets logged</p><h4 id="statSets"><?php echo $totalSets; ?></h4></div>
                <div class="summary-chip"><p>Total volume</p><h4 id="statVolume"><?php echo number_format($totalVolume); ?> kg</h4></div>
            </div>

            <div class="filter-bar">
                <div>
                    <label for="planFilter">Filter by plan &nbsp;</label>
                    <select id="planFilter">
                        <option value="__all__">All plans</option>
                        <?php foreach ($planFilterOptions as $value => $label): ?>
                            <option value="<?php echo htmlspecialchars($value); ?>"><?php echo htmlspecialchars($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <span id="filterCount"><?php echo $totalSessions; ?> session<?php echo $totalSessions === 1 ? '' : 's'; ?></span>
            </div>

            <p class="no-match-note" id="noMatchNote">No sessions match that filter.</p>

            <?php foreach ($workoutsByWeek as $weekKey => $weekData): ?>
                <div class="week-card" id="week-<?php echo htmlspecialchars($weekKey); ?>">
                    <div class="week-header">
                        <span class="week-title">Week <?php echo $weekData['week']; ?></span>
                        <span class="week-range">
                            <?php
                                $startDate = new DateTime($weekData['startDate']);
                                $endDate = new DateTime($weekData['endDate']);
                                echo $startDate->format('M j') . ' – ' . $endDate->format('M j, Y');
                            ?>
                        </span>
                    </div>

                    <div class="workouts-list">
                        <?php foreach ($weekData['workouts'] as $workout): ?>
                            <?php $planKey = $workout['plan_name'] ?: '__none__'; ?>
                            <div class="workout-item" data-plan="<?php echo htmlspecialchars($planKey); ?>" data-id="<?php echo $workout['id']; ?>">
                                <div class="workout-info">
                                    <div class="workout-date-day"><?php echo date('l, F j', strtotime($workout['session_date'])); ?></div>
                                    <div class="workout-name"><?php echo htmlspecialchars($workout['plan_name'] ?: 'Workout'); ?></div>
                                    <div class="workout-stats">
                                        <div class="stat"><span class="stat-label">Exercises</span> <span class="stat-value"><?php echo distinctExerciseCount($workoutDetails[$workout['id']] ?? []); ?></span></div>
                                        <?php if ($workout['duration_minutes']): ?>
                                            <div class="stat"><span class="stat-label">Duration</span> <span class="stat-value"><?php echo $workout['duration_minutes']; ?> min</span></div>
                                        <?php endif; ?>
                                        <?php if ($workout['mood']): ?>
                                            <div class="stat"><span class="stat-label">Mood</span> <span class="stat-value"><?php echo htmlspecialchars(ucfirst($workout['mood'])); ?></span></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="workout-actions">
                                    <button type="button" class="view-btn" data-id="<?php echo $workout['id']; ?>">View</button>
                                    <button type="button" class="edit-btn" data-id="<?php echo $workout['id']; ?>">Edit</button>
                                    <button type="button" class="delete" data-id="<?php echo $workout['id']; ?>">Delete</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Detail modal — populated from WORKOUTS_DATA, no extra network request -->
    <div class="modal-backdrop" id="modalBackdrop">
        <div class="modal-panel" id="modalPanel">
            <div class="modal-head">
                <div>
                    <div id="modalPlanChip"></div>
                    <h3 id="modalDate"></h3>
                </div>
                <button type="button" class="modal-close" id="modalClose" aria-label="Close">×</button>
            </div>
            <div class="modal-facts" id="modalFacts"></div>
            <div id="modalExercises"></div>
            <div class="modal-actions">
                <button type="button" class="edit-btn" id="modalEditBtn" style="border:1px solid rgba(120,81,169,0.4); background:rgba(120,81,169,0.14); color:#d8b8ff; padding:10px 18px; border-radius:999px; font-weight:700; cursor:pointer;">Edit this workout</button>
                <button type="button" class="delete" id="modalDeleteBtn" style="border:1px solid rgba(255,94,94,0.3); background:rgba(255,94,94,0.1); color:#ffb3b3; padding:10px 18px; border-radius:999px; font-weight:700; cursor:pointer;">Delete this workout</button>
            </div>
        </div>
    </div>

    <script>
        const WORKOUTS_DATA = <?php echo json_encode($modalData); ?>;

        // ---------- Nav toggle ----------
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

        // ---------- Plan filter (client-side, no reload) ----------
        const planFilter = document.getElementById('planFilter');
        const filterCount = document.getElementById('filterCount');
        const noMatchNote = document.getElementById('noMatchNote');

        function applyFilter() {
            const value = planFilter.value;
            let visibleCount = 0;

            document.querySelectorAll('.workout-item').forEach(item => {
                const match = value === '__all__' || item.dataset.plan === value;
                item.style.display = match ? '' : 'none';
                if (match) visibleCount++;
            });

            document.querySelectorAll('.week-card').forEach(card => {
                const hasVisible = [...card.querySelectorAll('.workout-item')].some(i => i.style.display !== 'none');
                card.style.display = hasVisible ? '' : 'none';
            });

            filterCount.textContent = visibleCount + ' session' + (visibleCount === 1 ? '' : 's');
            noMatchNote.style.display = visibleCount === 0 ? 'block' : 'none';
        }

        if (planFilter) planFilter.addEventListener('change', applyFilter);

        // ---------- Detail modal ----------
        const modalBackdrop = document.getElementById('modalBackdrop');
        let activeWorkoutId = null;

        function openModal(id) {
            const data = WORKOUTS_DATA[id];
            if (!data) return;
            activeWorkoutId = id;

            document.getElementById('modalDate').textContent = data.date;
            document.getElementById('modalPlanChip').innerHTML = data.plan
                ? `<span class="modal-plan-chip">${escapeHtml(data.plan)}</span>` : '';

            const facts = [`${data.exercises.length} exercise${data.exercises.length === 1 ? '' : 's'}`];
            if (data.duration) facts.push(`${data.duration} min`);
            if (data.mood) facts.push(`Felt ${data.mood}`);
            facts.push(`${data.volume.toLocaleString()} kg total volume`);
            document.getElementById('modalFacts').innerHTML = facts.map(f => `<span><strong>${f}</strong></span>`).join('');

            document.getElementById('modalExercises').innerHTML = data.exercises.map(ex => {
                let workingCount = 0;
                const setLines = ex.sets.map(set => {
                    const label = set.is_warmup ? 'Warmup' : ('Set ' + (++workingCount));
                    return `<div class="modal-set-line${set.is_warmup ? ' is-warmup' : ''}">
                                <span class="modal-set-label">${label}</span>
                                <span>${set.weight}kg × ${set.reps}</span>
                            </div>`;
                }).join('');

                return `
                    <div class="modal-exercise">
                        <div class="modal-exercise-top">
                            <strong>${escapeHtml(ex.name)}</strong>
                        </div>
                        ${setLines}
                        ${ex.notes ? `<div class="modal-exercise-notes">${escapeHtml(ex.notes)}</div>` : ''}
                    </div>
                `;
            }).join('') || '<p style="color:var(--text-muted);">No exercises recorded for this session.</p>';

            modalBackdrop.classList.add('is-open');
        }

        function closeModal() {
            modalBackdrop.classList.remove('is-open');
            activeWorkoutId = null;
        }

        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }

        document.getElementById('modalClose').addEventListener('click', closeModal);
        modalBackdrop.addEventListener('click', (e) => { if (e.target === modalBackdrop) closeModal(); });
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeModal(); });

        // ---------- Delete (removes the row in place, no full page reload) ----------
        function deleteWorkout(id, rowEl) {
            if (!confirm('Delete this workout? This cannot be undone.')) return;

            fetch('api/delete-workout.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ workout_id: id })
            })
            .then(res => res.json())
            .then(data => {
                if (!data.success) {
                    alert('Error: ' + (data.error || 'Could not delete workout'));
                    return;
                }

                delete WORKOUTS_DATA[id];
                if (activeWorkoutId === id) closeModal();

                const row = rowEl || document.querySelector(`.workout-item[data-id="${id}"]`);
                if (row) {
                    const weekCard = row.closest('.week-card');
                    row.classList.add('removing');
                    setTimeout(() => {
                        row.remove();
                        if (weekCard && !weekCard.querySelector('.workout-item')) weekCard.remove();
                        applyFilter();
                    }, 200);
                }

                // Update the summary strip
                const sessionsEl = document.getElementById('statSessions');
                const exercisesEl = document.getElementById('statExercises');
                const volumeEl = document.getElementById('statVolume');
                if (sessionsEl) sessionsEl.textContent = Math.max(0, parseInt(sessionsEl.textContent) - 1);
            })
            .catch(() => alert('Could not reach the server.'));
        }

        // ---------- Delegated clicks for View / Delete / modal delete ----------
        document.querySelectorAll('.view-btn').forEach(btn => {
            btn.addEventListener('click', () => openModal(btn.dataset.id));
        });

        document.querySelectorAll('.workout-actions .delete').forEach(btn => {
            btn.addEventListener('click', () => deleteWorkout(btn.dataset.id, btn.closest('.workout-item')));
        });

        document.querySelectorAll('.workout-actions .edit-btn').forEach(btn => {
            btn.addEventListener('click', () => window.location.href = 'log-workout.php?workout_id=' + encodeURIComponent(btn.dataset.id));
        });

        const modalEditBtn = document.getElementById('modalEditBtn');
        if (modalEditBtn) {
            modalEditBtn.addEventListener('click', () => {
                if (activeWorkoutId) {
                    window.location.href = 'log-workout.php?workout_id=' + encodeURIComponent(activeWorkoutId);
                }
            });
        }

        document.getElementById('modalDeleteBtn').addEventListener('click', () => {
            if (activeWorkoutId) deleteWorkout(activeWorkoutId, null);
        });
    </script>
</body>
</html>