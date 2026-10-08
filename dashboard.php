<?php
// dashboard.php
// Main dashboard after login

require_once __DIR__ . '/api/includes/db.php';
require_once __DIR__ . '/api/includes/auth.php';

// Require login
requireLogin();

// Get user info
$userId = getUserId();
$user = getUserInfo($conn, $userId);

// Get user's stats
$stmt = $conn->prepare("
    SELECT 
        COUNT(DISTINCT ws.id) as total_sessions,
        COUNT(DISTINCT e.exercise_name) as unique_exercises,
        MAX(ws.session_date) as last_workout
    FROM workout_sessions ws
    LEFT JOIN exercises e ON ws.id = e.session_id
    WHERE ws.user_id = ?
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Today's nutrition — used in the "Today's momentum" cards
$today = date('Y-m-d');

$stmt = $conn->prepare("
    SELECT COALESCE(SUM(calories), 0) AS calories,
           COALESCE(SUM(protein), 0) AS protein,
           COALESCE(SUM(carbs), 0) AS carbs,
           COALESCE(SUM(fat), 0) AS fat
    FROM nutrition_logs
    WHERE user_id = ? AND log_date = ?
");
$stmt->bind_param("is", $userId, $today);
$stmt->execute();
$nutritionToday = $stmt->get_result()->fetch_assoc();
$stmt->close();

$stmt = $conn->prepare("SELECT calories, protein, carbs, fat FROM nutrition_goals WHERE user_id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$nutritionGoals = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$nutritionGoals) {
    $nutritionGoals = ['calories' => 2000, 'protein' => 150, 'carbs' => 200, 'fat' => 65];
}

// Get recent workouts
$stmt = $conn->prepare("
    SELECT 
        ws.id,
        ws.session_date,
        wp.plan_name,
        COUNT(DISTINCT e.exercise_name) as exercise_count,
        ws.duration_minutes
    FROM workout_sessions ws
    LEFT JOIN workout_plans wp ON ws.workout_plan_id = wp.id AND wp.user_id = ws.user_id
    LEFT JOIN exercises e ON ws.id = e.session_id
    WHERE ws.user_id = ?
    GROUP BY ws.id
    ORDER BY ws.session_date DESC
    LIMIT 5
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$recentWorkouts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get all workouts grouped by week
$stmt = $conn->prepare("
    SELECT 
        ws.id,
        ws.session_date,
        wp.plan_name,
        COUNT(DISTINCT e.exercise_name) as exercise_count,
        ws.duration_minutes
    FROM workout_sessions ws
    LEFT JOIN workout_plans wp ON ws.workout_plan_id = wp.id AND wp.user_id = ws.user_id
    LEFT JOIN exercises e ON ws.id = e.session_id
    WHERE ws.user_id = ?
    GROUP BY ws.id
    ORDER BY ws.session_date DESC
    LIMIT 200
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$allWorkouts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Group workouts by week
$workoutsByWeek = [];
foreach ($allWorkouts as $workout) {
    $date = new DateTime($workout['session_date']);
    $week = $date->format('W');
    $year = $date->format('Y');
    $weekKey = $year . '-W' . $week;
    
    if (!isset($workoutsByWeek[$weekKey])) {
        $startDate = new DateTime($workout['session_date']);
        $startDate->modify('Monday this week');
        $endDate = clone $startDate;
        $endDate->modify('Sunday this week');
        
        $workoutsByWeek[$weekKey] = [
            'week' => $week,
            'year' => $year,
            'startDate' => $startDate->format('Y-m-d'),
            'endDate' => $endDate->format('Y-m-d'),
            'workouts' => [],
            'totalDuration' => 0,
            'totalExercises' => 0,
            'dayData' => [] // Store data per day for charts
        ];
    }
    
    $workoutsByWeek[$weekKey]['workouts'][] = $workout;
    $workoutsByWeek[$weekKey]['totalDuration'] += $workout['duration_minutes'] ?? 0;
    $workoutsByWeek[$weekKey]['totalExercises'] += $workout['exercise_count'];
    
    // Track data by day for charts
    $dayOfWeek = $date->format('D');
    if (!isset($workoutsByWeek[$weekKey]['dayData'][$dayOfWeek])) {
        $workoutsByWeek[$weekKey]['dayData'][$dayOfWeek] = 0;
    }
    $workoutsByWeek[$weekKey]['dayData'][$dayOfWeek] += $workout['exercise_count'];
}

// Sort weeks in reverse order (newest first)
krsort($workoutsByWeek);

$latestWeekData = !empty($workoutsByWeek) ? reset($workoutsByWeek) : null;
$weekDays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

// Progress-ring math: given a value/goal, return the SVG stroke-dashoffset for a
// circle of the given radius so the ring visually fills clockwise toward the goal.
function ringOffset($value, $goal, $radius) {
    $circumference = 2 * M_PI * $radius;
    $pct = $goal > 0 ? max(0, min(1, $value / $goal)) : 0;
    return $circumference - ($circumference * $pct);
}
function ringCircumference($radius) {
    return 2 * M_PI * $radius;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Gym Progression Tracker</title>
    <style>
        /* Theme colors — tweak these variables first to change the dashboard palette quickly. */
        :root {
            color-scheme: dark;
            --bg-dark: #05030a;                 /* Main page background */
            --panel: rgba(15, 8, 28, 0.95);     /* Card / stat panel background */
            --panel-2: rgba(20, 12, 40, 0.98);  /* Secondary panel background */
            --text-main: #f6f7ff;              /* Main text color */
            --text-muted: #adb2d4;             /* Secondary text color */
            --accent: #7851A9;                 /* Royal purple accent color */
            --accent-strong: #9b6af0;          /* Bright purple accent */
            --accent-soft: rgba(120, 81, 169, 0.22); /* Soft purple glow */
            --cal: #a755ff;                     /* Calories ring color */
            --protein: #4fd6ac;                 /* Protein ring color */
            --carbs: #ffb454;                   /* Carbs ring color */
            --fat: #ff7ab8;                      /* Fat ring color */
            --border: rgba(151, 109, 222, 0.22); /* Border color */
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            background:
                radial-gradient(circle at top left, rgba(120, 81, 169, 0.18), transparent 20%),
                radial-gradient(circle at bottom right, rgba(120, 81, 169, 0.12), transparent 18%),
                var(--bg-dark);
            color: var(--text-main);
        }

        .navbar {
            background: rgba(5, 5, 15, 0.96);
            border-bottom: 1px solid rgba(151, 109, 222, 0.2);
            color: white;
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

        .navbar h1 {
            font-size: 1.9rem;
            letter-spacing: 0.03em;
        }

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

        .nav-toggle:hover,
        .nav-toggle:focus-visible {
            background: rgba(120, 81, 169, 0.2);
            border-color: rgba(155, 106, 240, 0.6);
            transform: translateY(-1px);
        }

        .nav-toggle.is-active {
            background: rgba(120, 81, 169, 0.24);
            border-color: rgba(155, 106, 240, 0.7);
        }

        .barbell-icon {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transform: rotate(0deg);
        }

        .barbell-icon .bar {
            width: 18px;
            height: 4px;
            border-radius: 999px;
            background: linear-gradient(90deg, #fff, #c284ff);
            box-shadow: 0 0 12px rgba(194, 132, 255, 0.3);
        }

        .barbell-icon .plate {
            width: 8px;
            height: 12px;
            border-radius: 999px;
            background: linear-gradient(135deg, #a755ff, #7a3ecf);
            border: 1px solid rgba(255, 255, 255, 0.28);
            box-shadow: inset 0 0 4px rgba(255, 255, 255, 0.2);
        }

        .navbar-right {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .navbar-right span {
            color: var(--text-muted);
            font-size: 0.95rem;
            white-space: nowrap;
        }

        .navbar-right a {
            color: var(--text-main);
            text-decoration: none;
            padding: 10px 16px;
            border-radius: 999px;
            transition: background 0.3s ease, transform 0.2s ease;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.08);
            font-weight: 600;
        }

        .navbar-right a:hover {
            background: rgba(120, 81, 169, 0.18);
            transform: translateY(-1px);
        }

        .container {
            max-width: 1240px;
            margin: 0 auto;
            padding: 32px 24px 40px;
        }

        .top-panel {
            display: grid;
            grid-template-columns: 1.65fr 1fr;
            gap: 24px;
            margin-bottom: 28px;
        }

        .welcome-panel,
        .overview-panel {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 28px;
            padding: 30px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.22);
        }

        .welcome-panel {
            min-height: 260px;
            background: linear-gradient(180deg, rgba(18, 10, 37, 0.98), rgba(15, 8, 28, 0.96));
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            align-items: center;
        }

        .welcome-copy {
            display: flex;
            flex-direction: column;
            gap: 18px;
        }

        .welcome-panel h2 {
            color: #fff;
            margin-bottom: 0;
            font-size: clamp(2rem, 2.3vw, 2.6rem);
            line-height: 1.05;
        }

        .welcome-panel p {
            color: var(--text-muted);
            font-size: 1rem;
            max-width: 560px;
            line-height: 1.8;
        }

        .welcome-chart {
            background: rgba(120, 81, 169, 0.08);
            border: 1px solid rgba(120, 81, 169, 0.18);
            border-radius: 22px;
            padding: 22px;
            display: grid;
            gap: 18px;
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }

        .chart-header p {
            margin: 0;
            color: var(--text-muted);
            font-size: 0.9rem;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .chart-scale {
            display: flex;
            align-items: flex-end;
            gap: 8px;
            height: 150px;
            padding-top: 30px; /* room for the value label above the tallest bar */
        }

        .chart-bar {
            position: relative;
            flex: 1 1 0;
            min-width: 0;
            background: linear-gradient(180deg, rgba(167, 85, 255, 0.92), rgba(120, 81, 169, 0.95));
            border-radius: 18px 18px 6px 6px;
            box-shadow: inset 0 2px 12px rgba(255,255,255,0.12), 0 0 18px rgba(167, 85, 255, 0.2);
            transition: height 0.3s ease;
        }

        .chart-bar::after {
            content: attr(data-value);
            position: absolute;
            top: -24px;
            left: 50%;
            transform: translateX(-50%);
            color: #f4f6ff;
            font-size: 0.85rem;
            font-weight: 700;
        }

        .chart-labels {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 8px;
            margin-top: 12px;
            color: var(--text-muted);
            font-size: 0.85rem;
            text-align: center;
        }

        .ring-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
        }

        .ring-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
        }

        .ring-item svg {
            width: 100%;
            max-width: 90px;
            transition: stroke-dashoffset 0.5s ease;
        }

        .ring-item svg circle {
            transition: stroke-dashoffset 0.5s ease;
        }

        .ring-label {
            font-size: 0.78rem;
            color: var(--text-muted);
            font-weight: 600;
            text-align: center;
        }

        .overview-panel {
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            gap: 24px;
        }

        .overview-title {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .overview-title span {
            font-size: 0.85rem;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: var(--accent-soft);
        }

        .overview-title h3 {
            font-size: 1.55rem;
            color: #fff;
            margin: 0;
            letter-spacing: -0.02em;
        }

        .overview-metrics {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
        }

        .metric-card {
            background: rgba(120, 81, 169, 0.08);
            border: 1px solid rgba(120, 81, 169, 0.18);
            border-radius: 18px;
            padding: 18px 16px;
            color: #f4f6ff;
        }

        .metric-card p {
            font-size: 0.82rem;
            color: var(--text-muted);
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .metric-card h4 {
            font-size: 1.45rem;
            margin: 0;
            color: #fff;
        }

        .overview-actions {
            display: grid;
            gap: 14px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 18px;
            margin-bottom: 28px;
        }

        .stat-card {
            background: rgba(18, 10, 37, 0.96);
            padding: 24px;
            border-radius: 24px;
            border: 1px solid rgba(120, 81, 169, 0.18);
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.02), 0 16px 30px rgba(0, 0, 0, 0.20);
            transition: transform 0.25s ease, border-color 0.25s ease, box-shadow 0.25s ease;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            border-color: rgba(120, 81, 169, 0.45);
            box-shadow: 0 22px 42px rgba(120, 81, 169, 0.22);
        }

        .stat-card p {
            color: var(--text-muted);
            font-size: 0.9rem;
            margin-bottom: 10px;
            letter-spacing: 0.03em;
        }

        .stat-card h3 {
            color: #fff;
            font-size: 2rem;
            letter-spacing: -0.03em;
        }

        .action-button {
            background: linear-gradient(135deg, #a755ff 0%, #7d3fd0 55%, #632a9f 100%);
            color: #f8f9ff;
            padding: 18px 22px;
            border-radius: 22px;
            text-align: center;
            text-decoration: none;
            transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
            font-weight: 700;
            box-shadow: 0 0 22px rgba(167, 85, 255, 0.38), 0 18px 35px rgba(95, 43, 166, 0.18);
            border: 1px solid rgba(177, 109, 255, 0.45);
        }

        .action-button:hover {
            transform: translateY(-2px);
            background: linear-gradient(135deg, #c284ff 0%, #925cdd 45%, #7e39c2 100%);
            box-shadow: 0 0 26px rgba(194, 132, 255, 0.65), 0 20px 38px rgba(126, 55, 204, 0.28);
        }

        .section-title {
            color: #fff;
            margin: 28px 0 16px 0;
            font-size: 1.35rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.12);
            padding-bottom: 12px;
        }

        .workout-item {
            background: var(--panel-2);
            padding: 18px 20px;
            border-radius: 16px;
            margin-bottom: 12px;
            box-shadow: 0 8px 18px rgba(0, 0, 0, 0.16);
            border: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }

        .workout-item h4 {
            color: #fff;
            margin-bottom: 5px;
        }

        .workout-item p {
            color: var(--text-muted);
            font-size: 0.92rem;
        }

        .workout-item a {
            background: rgba(124, 140, 255, 0.16);
            color: #e6ebff;
            padding: 8px 14px;
            border-radius: 999px;
            text-decoration: none;
            transition: background 0.2s ease;
            white-space: nowrap;
        }

        .workout-item a:hover {
            background: rgba(124, 140, 255, 0.26);
        }

        .empty-state {
            background: var(--panel-2);
            padding: 34px;
            border-radius: 18px;
            text-align: center;
            color: var(--text-muted);
            border: 1px solid var(--border);
        }

        .empty-state h3 {
            color: #fff;
            margin-bottom: 10px;
        }

        .empty-state p {
            margin-bottom: 18px;
        }

        .empty-state a {
            background: var(--accent);
            color: #f4f6ff;
            padding: 10px 18px;
            border-radius: 999px;
            text-decoration: none;
            display: inline-block;
            font-weight: 700;
        }

        .weekly-cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 28px;
        }

        .weekly-card {
            background: var(--panel-2);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 24px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            color: inherit;
            display: flex;
            flex-direction: column;
            gap: 18px;
        }

        .weekly-card:hover {
            background: rgba(30, 15, 50, 0.9);
            border-color: rgba(155, 106, 240, 0.4);
            transform: translateY(-6px);
            box-shadow: 0 12px 28px rgba(167, 85, 255, 0.2);
        }

        .weekly-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
        }

        .weekly-card-title {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .weekly-card-title h3 {
            color: #fff;
            font-size: 1.25rem;
            margin: 0;
        }

        .weekly-card-title p {
            color: var(--text-muted);
            font-size: 0.85rem;
            margin: 0;
        }

        .weekly-card-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .weekly-stat {
            background: rgba(120, 81, 169, 0.1);
            border: 1px solid rgba(151, 109, 222, 0.2);
            border-radius: 12px;
            padding: 12px;
            text-align: center;
        }

        .weekly-stat-label {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            margin-bottom: 4px;
        }

        .weekly-stat-value {
            font-size: 1.35rem;
            font-weight: 700;
            color: var(--accent-strong);
        }

        .weekly-card-days {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .day-tag {
            padding: 6px 10px;
            background: rgba(151, 109, 222, 0.15);
            border: 1px solid rgba(151, 109, 222, 0.3);
            border-radius: 8px;
            font-size: 0.8rem;
            font-weight: 600;
            color: #d8b8ff;
        }

        .weekly-card-chart {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 6px;
            align-items: flex-end;
            height: 120px;
        }

        .chart-bar-wrapper {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-end;
            height: 100%;
            gap: 4px;
        }

        .chart-bar-item {
            width: 100%;
            background: linear-gradient(180deg, rgba(167, 85, 255, 0.92), rgba(120, 81, 169, 0.95));
            border-radius: 4px 4px 0 0;
            min-height: 8px;
            transition: all 0.2s ease;
        }

        .chart-bar-item:hover {
            background: linear-gradient(180deg, #c284ff 0%, #9b6af0 100%);
            box-shadow: 0 0 12px rgba(194, 132, 255, 0.4);
        }

        .chart-bar-empty {
            width: 100%;
            background: rgba(151, 109, 222, 0.08);
            border-radius: 4px;
            height: 8px;
        }

        .chart-day-label {
            font-size: 0.7rem;
            color: var(--text-muted);
            text-align: center;
            margin-top: 4px;
            font-weight: 600;
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .workout-item {
                flex-direction: column;
                text-align: center;
            }
            .top-panel {
                grid-template-columns: 1fr;
            }

            .welcome-panel,
            .overview-panel {
                padding: 24px;
            }

            .welcome-chart {
                gap: 14px;
            }

            .overview-metrics {
                grid-template-columns: 1fr;
            }

            .overview-actions {
                grid-template-columns: 1fr;
            }

            .overview-actions a {
                width: 100%;
            }

            .chart-scale { height: 120px; }

            .workout-item a {
                width: 100%;
                white-space: normal;
            }

            .section-title {
                font-size: 1.2rem;
            }

            .weekly-cards-grid {
                grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
                gap: 16px;
            }

            .weekly-card-chart {
                height: 100px;
            }
        }

        @media (max-width: 480px) {
            .navbar { padding: 14px 18px; }

            .container { padding: 20px 14px 40px; }

            .welcome-panel { grid-template-columns: 1fr !important; padding: 18px; }
            .ring-grid { grid-template-columns: repeat(2, 1fr); gap: 16px; }

            .welcome-chart { 
                padding: 14px; 
                border-radius: 14px;
                gap: 12px;
                position: relative;
            }
            .chart-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }
            .chart-header p { font-size: 0.75rem; }
            .chart-header strong { font-size: 1rem; }
            .chart-header span { font-size: 0.8rem; }
            .chart-scale { height: 100px; gap: 5px; padding-top: 26px; }
            .chart-bar::after { font-size: 0.75rem; top: -20px; }
            .chart-labels { gap: 5px; font-size: 0.7rem; }

            .overview-panel { padding: 18px; }
            .overview-actions a { font-size: 0.95rem; padding: 12px; }

            .workout-item { padding: 14px; }
            .workout-item h4 { font-size: 1rem; }
            .workout-item p { font-size: 0.9rem; }

            .section-title { font-size: 1rem; }

            .weekly-cards-grid {
                grid-template-columns: 1fr;
            }

            .weekly-card {
                padding: 18px;
            }

            .weekly-card-title h3 {
                font-size: 1.1rem;
            }

            .weekly-card-chart {
                height: 80px;
                gap: 4px;
            }
        }

        /* ---------- Mobile hamburger menu (matches log-workout.php / under-development.php) ---------- */
        @media (max-width: 860px) {
            .nav-toggle {
                display: inline-flex;
            }

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

            .navbar-right.is-open {
                display: flex;
            }

            .navbar-right span {
                width: 100%;
                text-align: center;
                padding: 8px 0 12px;
                margin-bottom: 4px;
                border-bottom: 1px solid rgba(151, 109, 222, 0.18);
            }

            .navbar-right a {
                width: 100%;
                text-align: center;
                justify-content: center;
                border-radius: 999px;
                padding: 12px 16px;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <h1> Personal GymTracker </h1>
        <button class="nav-toggle" id="navToggle" aria-label="Toggle navigation" type="button">
            <span class="barbell-icon" aria-hidden="true">
                <span class="plate"></span>
                <span class="bar"></span>
                <span class="plate"></span>
            </span>
        </button>
        <div class="navbar-right" id="navMenu">
            <span>Welcome, <?php echo htmlspecialchars($user['username']); ?>!</span>
            <a href="nutrition.php">Nutrition</a>
            <a href="profile.php">Profile</a>
            <a href="friends.php">Friends</a>
            <a href="api/logout.php">Logout</a>
        </div>
    </nav>

    <div class="container">
        <div class="top-panel">
            <section class="welcome-panel">
                <div class="welcome-chart">
                    <div class="chart-header">
                        <div>
                            <p>Nutrition</p>
                            <strong style="color:#fff; font-size:1.1rem;">Today's intake</strong>
                        </div>
                    </div>
                    <div class="ring-grid">
                        <?php
                            $radius = 36;
                            $circumference = ringCircumference($radius);
                            $ringMacros = [
                                ['key' => 'calories', 'label' => 'Calories', 'unit' => '', 'color' => 'var(--cal)'],
                                ['key' => 'protein', 'label' => 'Protein', 'unit' => 'g', 'color' => 'var(--protein)'],
                                ['key' => 'carbs', 'label' => 'Carbs', 'unit' => 'g', 'color' => 'var(--carbs)'],
                                ['key' => 'fat', 'label' => 'Fat', 'unit' => 'g', 'color' => 'var(--fat)'],
                            ];
                        ?>
                        <?php foreach ($ringMacros as $m): ?>
                            <?php
                                $value = round($nutritionToday[$m['key']]);
                                $goal = $nutritionGoals[$m['key']];
                                $offset = ringOffset($value, $goal, $radius);
                            ?>
                            <div class="ring-item">
                                <svg viewBox="0 0 90 90">
                                    <circle cx="45" cy="45" r="<?php echo $radius; ?>" stroke="rgba(255,255,255,0.08)" stroke-width="9" fill="none" />
                                    <circle cx="45" cy="45" r="<?php echo $radius; ?>" stroke="<?php echo $m['color']; ?>" stroke-width="9" fill="none"
                                            stroke-dasharray="<?php echo $circumference; ?>"
                                            stroke-dashoffset="<?php echo $offset; ?>"
                                            stroke-linecap="round"
                                            transform="rotate(-90 45 45)" />
                                    <text x="45" y="42" text-anchor="middle" fill="#fff" font-size="15" font-weight="700"><?php echo $value; ?></text>
                                    <text x="45" y="57" text-anchor="middle" fill="rgba(173,178,212,0.9)" font-size="9"><?php echo $m['unit'] ? "/ {$goal}{$m['unit']}" : "/ {$goal}"; ?></text>
                                </svg>
                                <span class="ring-label"><?php echo $m['label']; ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="welcome-chart">
                    <div class="chart-header">
                        <div>
                            <p>Volume trend</p>
                            <strong style="color:#fff; font-size:1.1rem;">
                                <?php if ($latestWeekData): ?>
                                    Week <?php echo htmlspecialchars($latestWeekData['week']); ?> · <?php echo htmlspecialchars(date('M j', strtotime($latestWeekData['startDate']))); ?>–<?php echo htmlspecialchars(date('M j', strtotime($latestWeekData['endDate']))); ?>
                                <?php else: ?>
                                    No workouts logged yet
                                <?php endif; ?>
                            </strong>
                        </div>
                        <?php if ($latestWeekData): ?>
                            <span style="color:#c284ff; font-size:0.95rem; font-weight:700;"><?php echo $latestWeekData['totalExercises']; ?> exercises</span>
                        <?php endif; ?>
                    </div>
                    <div class="chart-scale">
                        <?php if ($latestWeekData): ?>
                            <?php
                                $maxLatestWeekValue = max(array_values($latestWeekData['dayData'])) ?: 1;
                                $maxBarPx = 120; // matches .chart-scale height minus the label/padding allowance
                                foreach ($weekDays as $day):
                                    $dayValue = $latestWeekData['dayData'][$day] ?? 0;
                                    $barPx = $dayValue > 0 ? max(14, round(($dayValue / $maxLatestWeekValue) * $maxBarPx)) : 0;
                            ?>
                                <div class="chart-bar" data-value="<?php echo $dayValue; ?>" style="height: <?php echo $barPx; ?>px;"></div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <?php for ($i = 0; $i < 7; $i++): ?>
                                <div class="chart-bar" data-value="0" style="height: 0px;"></div>
                            <?php endfor; ?>
                        <?php endif; ?>
                    </div>
                    <div class="chart-labels">
                        <?php foreach ($weekDays as $day): ?>
                            <span><?php echo htmlspecialchars($day); ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <aside class="overview-panel">
                <div class="overview-title">
                    <span>Dashboard overview</span>
                    <h3>Today’s momentum</h3>
                </div>

                <div class="overview-metrics">
                    <div class="metric-card">
                        <p>Calories today</p>
                        <h4><?php echo round($nutritionToday['calories']); ?> <span style="font-size:0.55em; color:var(--text-muted); font-weight:600;">/ <?php echo $nutritionGoals['calories']; ?></span></h4>
                    </div>
                    <div class="metric-card">
                        <p>Protein today</p>
                        <h4><?php echo round($nutritionToday['protein']); ?>g <span style="font-size:0.55em; color:var(--text-muted); font-weight:600;">/ <?php echo $nutritionGoals['protein']; ?>g</span></h4>
                    </div>
                    <div class="metric-card">
                        <p>Carbs today</p>
                        <h4><?php echo round($nutritionToday['carbs']); ?>g <span style="font-size:0.55em; color:var(--text-muted); font-weight:600;">/ <?php echo $nutritionGoals['carbs']; ?>g</span></h4>
                    </div>
                </div>

                <div class="overview-actions">
                    <a href="log-workout.php" class="action-button"> Log New Workout</a>
                    <a href="progression.php" class="action-button"> View Progression</a>
                    <a href="workouts.php" class="action-button"> My Workouts</a>
                </div>
            </aside>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <p>Workout count</p>
                <h3><?php echo $stats['total_sessions'] ?? 0; ?></h3>
            </div>
            <div class="stat-card">
                <p>Different moves</p>
                <h3><?php echo $stats['unique_exercises'] ?? 0; ?></h3>
            </div>
            <div class="stat-card">
                <p>Last active</p>
                <h3><?php echo $stats['last_workout'] ? date('M d', strtotime($stats['last_workout'])) : 'Never'; ?></h3>
            </div>
        </div>

        <h2 class="section-title">Weekly Breakdown</h2>

        <?php if (!empty($workoutsByWeek)): ?>
            <div class="weekly-cards-grid">
                <?php foreach ($workoutsByWeek as $weekKey => $weekData): ?>
                    <?php
                        // Calculate max value for chart scaling
                        $maxExercises = max(array_values($weekData['dayData'])) ?: 1;
                        // Days of week in order
                        $daysOrder = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
                    ?>
                    <a href="workouts.php#week-<?php echo htmlspecialchars($weekKey); ?>" class="weekly-card">
                        <div class="weekly-card-header">
                            <div class="weekly-card-title">
                                <h3>Week <?php echo htmlspecialchars($weekData['week']); ?></h3>
                                <p><?php 
                                    $startDate = new DateTime($weekData['startDate']);
                                    $endDate = new DateTime($weekData['endDate']);
                                    echo $startDate->format('M j') . ' - ' . $endDate->format('M j');
                                ?></p>
                            </div>
                        </div>

                        <div class="weekly-card-chart">
                            <?php foreach ($daysOrder as $day): ?>
                                <div class="chart-bar-wrapper">
                                    <?php if (isset($weekData['dayData'][$day]) && $weekData['dayData'][$day] > 0): ?>
                                        <?php $percentage = ($weekData['dayData'][$day] / $maxExercises) * 100; ?>
                                        <div class="chart-bar-item" style="height: <?php echo max(20, $percentage); ?>%;" title="<?php echo $weekData['dayData'][$day]; ?> exercises"></div>
                                    <?php else: ?>
                                        <div class="chart-bar-empty"></div>
                                    <?php endif; ?>
                                    <span class="chart-day-label"><?php echo htmlspecialchars($day); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="weekly-card-stats">
                            <div class="weekly-stat">
                                <div class="weekly-stat-label">Sessions</div>
                                <div class="weekly-stat-value"><?php echo count($weekData['workouts']); ?></div>
                            </div>
                            <div class="weekly-stat">
                                <div class="weekly-stat-label">Exercises</div>
                                <div class="weekly-stat-value"><?php echo $weekData['totalExercises']; ?></div>
                            </div>
                            <div class="weekly-stat">
                                <div class="weekly-stat-label">Duration</div>
                                <div class="weekly-stat-value"><?php echo $weekData['totalDuration']; ?> min</div>
                            </div>
                            <div class="weekly-stat">
                                <div class="weekly-stat-label">Avg/Day</div>
                                <div class="weekly-stat-value"><?php echo ceil($weekData['totalDuration'] / count($weekData['workouts'])); ?> min</div>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <h3>No workouts yet</h3>
                <p>Start tracking your fitness journey today!</p>
                <a href="log-workout.php">Log Your First Workout</a>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Mobile nav toggle (hamburger / barbell button)
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
    </script>
</body>
</html>