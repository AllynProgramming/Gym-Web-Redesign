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

// Today's nutrition
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
            'dayData' => []
        ];
    }

    $workoutsByWeek[$weekKey]['workouts'][] = $workout;
    $workoutsByWeek[$weekKey]['totalDuration'] += $workout['duration_minutes'] ?? 0;
    $workoutsByWeek[$weekKey]['totalExercises'] += $workout['exercise_count'];

    $dayOfWeek = $date->format('D');
    if (!isset($workoutsByWeek[$weekKey]['dayData'][$dayOfWeek])) {
        $workoutsByWeek[$weekKey]['dayData'][$dayOfWeek] = 0;
    }
    $workoutsByWeek[$weekKey]['dayData'][$dayOfWeek] += $workout['exercise_count'];
}

// Sort weeks in reverse order (newest first)
krsort($workoutsByWeek);

$weekDays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

// ---- View helpers (display only) ----
$thisWeek = $workoutsByWeek[date('Y') . '-W' . date('W')] ?? null;
$todayName = date('D');
$last = $recentWorkouts[0] ?? null;
$when = '';
if ($last) {
    $d = (new DateTime('today'))->diff(new DateTime(date('Y-m-d', strtotime($last['session_date']))))->days;
    $when = $d === 0 ? 'today' : ($d === 1 ? 'yesterday' : $d . ' days ago');
}
$macros = [
    ['key' => 'calories', 'label' => 'Calories', 'unit' => '', 'color' => 'var(--blue)'],
    ['key' => 'protein', 'label' => 'Protein', 'unit' => ' g', 'color' => 'var(--green)'],
    ['key' => 'carbs', 'label' => 'Carbs', 'unit' => ' g', 'color' => 'var(--yellow)'],
    ['key' => 'fat', 'label' => 'Fat', 'unit' => ' g', 'color' => 'var(--red)'],
];
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
    <title>Dashboard | GymTrack</title>
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
            max-width: 1120px;
            margin: 0 auto;
            padding: 0 clamp(1.1rem, 4vw, 2.5rem)
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
            align-items: center;
            flex-wrap: wrap;
            font: 600 .95rem var(--head)
        }

        nav a {
            text-decoration: none;
            padding: .3rem 0
        }

        nav a:hover {
            text-decoration: underline;
            text-underline-offset: 4px
        }

        nav .who {
            color: var(--muted);
            font-weight: 500
        }

        .btn {
            display: inline-block;
            background: var(--accent);
            color: var(--on-accent);
            font: 700 1rem var(--head);
            padding: .8rem 1.4rem;
            border-radius: 6px;
            text-decoration: none
        }

        .btn.alt {
            background: transparent;
            color: var(--ink);
            box-shadow: inset 0 0 0 2px var(--ink)
        }

        .today {
            display: grid;
            grid-template-columns: 1.1fr 1fr;
            gap: clamp(2rem, 6vw, 5rem);
            align-items: center;
            padding: clamp(2.5rem, 7vh, 4.5rem) 0
        }

        h1 {
            font: 850 clamp(2.3rem, 6vw, 4.2rem)/1 var(--head);
            font-stretch: 118%;
            letter-spacing: -.025em;
            margin: 0 0 1rem;
            max-width: 14ch;
            text-wrap: balance
        }

        .lede {
            color: var(--muted);
            font-size: 1.25rem;
            margin: 0 0 1.6rem;
            max-width: 30rem
        }

        .cta {
            display: flex;
            gap: .7rem;
            flex-wrap: wrap
        }

        .weekbox h2,
        .sec h2 {
            font: 750 1.2rem var(--head);
            margin: 0 0 .8rem
        }

        .week {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: .4rem
        }

        .day {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: .4rem;
            font: 600 .85rem var(--head);
            color: var(--muted)
        }

        .day b {
            display: grid;
            place-items: center;
            width: 100%;
            aspect-ratio: 1;
            border-radius: 6px;
            font: 800 1.15rem var(--head);
            box-shadow: inset 0 0 0 1.5px var(--rule);
            color: transparent
        }

        .day.on b {
            background: var(--accent);
            color: var(--on-accent);
            box-shadow: none
        }

        .day.now {
            color: var(--ink)
        }

        .day.now b {
            box-shadow: inset 0 0 0 2.5px var(--ink)
        }

        .day.on.now b {
            box-shadow: 0 0 0 3px var(--bg), 0 0 0 5px var(--ink)
        }

        .note {
            color: var(--muted);
            font-size: 1rem;
            margin: .8rem 0 0
        }

        .sec {
            padding: 2.6rem 0;
            border-top: 1px solid var(--rule)
        }

        .sec .head {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: 1rem;
            flex-wrap: wrap;
            margin-bottom: .6rem
        }

        .sec .head a {
            font: 600 .95rem var(--head)
        }

        .figs {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem 2rem
        }

        .figs div {
            border-top: 2px solid var(--ink);
            padding-top: .5rem
        }

        .figs dt {
            font: 600 .9rem var(--head);
            color: var(--muted)
        }

        .figs dd {
            margin: 0;
            font: 800 2.4rem/1.1 var(--head);
            font-stretch: 112%;
            font-variant-numeric: tabular-nums
        }

        .mac {
            display: grid;
            grid-template-columns: 7rem 1fr 11rem;
            gap: 1rem;
            align-items: center;
            padding: .75rem 0;
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

        .scroll {
            overflow-x: auto
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-variant-numeric: tabular-nums;
            min-width: 34rem
        }

        th {
            text-align: left;
            font: 600 .85rem var(--head);
            color: var(--muted);
            padding: .4rem .8rem .4rem 0;
            border-bottom: 2px solid var(--ink)
        }

        td {
            padding: .7rem .8rem .7rem 0;
            border-bottom: 1px solid var(--rule)
        }

        th.n,
        td.n {
            text-align: right
        }

        td a {
            font: 700 1rem var(--head)
        }

        .dots {
            display: flex;
            gap: 3px
        }

        .dots i {
            width: 10px;
            height: 10px;
            border-radius: 2px;
            box-shadow: inset 0 0 0 1.5px var(--rule)
        }

        .dots i.on {
            background: var(--accent);
            box-shadow: none
        }

        .empty {
            background: var(--surface);
            box-shadow: 0 0 0 1px var(--rule);
            border-radius: 10px;
            padding: 1.6rem
        }

        .empty h3 {
            font: 750 1.2rem var(--head);
            margin: 0 0 .3rem
        }

        .empty p {
            color: var(--muted);
            margin: 0 0 1rem
        }

        footer {
            padding: 1rem 0 3rem
        }

        @media (max-width:820px) {
            .today {
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

            .figs dd {
                font-size: 1.8rem
            }
        }

        @media (max-width:480px) {
            .figs {
                grid-template-columns: 1fr
            }
        }
    </style>
</head>

<body>
    <div class="wrap">
        <header>
            <a class="logo" href="dashboard.php">GymTrack</a>
            <nav aria-label="Main">
                <span class="who"><?php echo gt_e($user['username']); ?></span>
                <a href="nutrition.php">Nutrition</a>
                <a href="profile.php">Profile</a>
                <a href="friends.php">Friends</a>
                <a href="api/logout.php">Log out</a>
            </nav>
        </header>

        <main>
            <section class="today">
                <div>
                    <?php if ($last): ?>
                        <h1>Your last session was <?php echo gt_e($when); ?>.</h1>
                        <p class="lede"><?php echo gt_e($last['plan_name'] ?: 'Workout'); ?>:
                            <?php echo (int) $last['exercise_count']; ?>
                            exercises<?php echo $last['duration_minutes'] ? ', ' . (int) $last['duration_minutes'] . ' min' : ''; ?>.
                            Beat it today.</p>
                    <?php else: ?>
                        <h1>Log your first session.</h1>
                        <p class="lede">Your sets, records and weekly progress will show up here.</p>
                    <?php endif; ?>
                    <div class="cta">
                        <a class="btn" href="log-workout.php">Log workout</a>
                        <a class="btn alt" href="progression.php">View progression</a>
                        <a class="btn alt" href="workouts.php">My workouts</a>
                    </div>
                </div>
                <div class="weekbox">
                    <h2>This week</h2>
                    <div class="week">
                        <?php foreach ($weekDays as $day):
                            $n = $thisWeek['dayData'][$day] ?? 0; ?>
                            <div
                                class="day<?php echo $n > 0 ? ' on' : ''; ?><?php echo $day === $todayName ? ' now' : ''; ?>">
                                <b
                                    title="<?php echo $n; ?> exercises"><?php echo $n > 0 ? $n : ''; ?></b><?php echo gt_e($day); ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="note">
                        <?php echo $thisWeek ? count($thisWeek['workouts']) . ' session' . (count($thisWeek['workouts']) === 1 ? '' : 's') . ' so far. Numbers show exercises per day.' : 'No sessions yet this week.'; ?>
                    </p>
                </div>
            </section>

            <section class="sec">
                <dl class="figs" style="margin:0">
                    <div>
                        <dt>Workouts logged</dt>
                        <dd><?php echo (int) ($stats['total_sessions'] ?? 0); ?></dd>
                    </div>
                    <div>
                        <dt>Different exercises</dt>
                        <dd><?php echo (int) ($stats['unique_exercises'] ?? 0); ?></dd>
                    </div>
                    <div>
                        <dt>Last workout</dt>
                        <dd><?php echo !empty($stats['last_workout']) ? gt_e(date('M j', strtotime($stats['last_workout']))) : 'None yet'; ?>
                        </dd>
                    </div>
                </dl>
            </section>

            <section class="sec">
                <div class="head">
                    <h2>Nutrition today</h2><a href="nutrition.php">Log food</a>
                </div>
                <?php foreach ($macros as $m):
                    $v = round($nutritionToday[$m['key']]);
                    $g = (float) $nutritionGoals[$m['key']];
                    $pct = $g > 0 ? max(0, min(100, $v / $g * 100)) : 0; ?>
                    <div class="mac">
                        <span><?php echo gt_e($m['label']); ?></span>
                        <div class="track" role="img"
                            aria-label="<?php echo gt_e($m['label'] . ': ' . $v . ' of ' . $g . $m['unit']); ?>"><i
                                style="--c:<?php echo $m['color']; ?>;width:<?php echo round($pct); ?>%"></i></div>
                        <em><b><?php echo number_format($v); ?></b> of
                            <?php echo number_format($g) . gt_e($m['unit']); ?></em>
                    </div>
                <?php endforeach; ?>
            </section>

            <section class="sec">
                <div class="head">
                    <h2>Weekly history</h2><a href="workouts.php">All workouts</a>
                </div>
                <?php if (!empty($workoutsByWeek)): ?>
                    <div class="scroll">
                        <table>
                            <thead>
                                <tr>
                                    <th>Week</th>
                                    <th>Days trained</th>
                                    <th class="n">Sessions</th>
                                    <th class="n">Exercises</th>
                                    <th class="n">Minutes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($workoutsByWeek as $weekKey => $w): ?>
                                    <tr>
                                        <td><a href="workouts.php#week-<?php echo gt_e($weekKey); ?>"><?php echo gt_e(date('M j', strtotime($w['startDate']))); ?>
                                                to <?php echo gt_e(date('M j', strtotime($w['endDate']))); ?></a></td>
                                        <td>
                                            <div class="dots"
                                                aria-label="Trained on <?php echo gt_e(implode(', ', array_keys(array_filter($w['dayData'])))); ?>">
                                                <?php foreach ($weekDays as $day): ?><i
                                                        class="<?php echo !empty($w['dayData'][$day]) ? 'on' : ''; ?>"
                                                        title="<?php echo gt_e($day); ?>"></i><?php endforeach; ?></div>
                                        </td>
                                        <td class="n"><?php echo count($w['workouts']); ?></td>
                                        <td class="n"><?php echo (int) $w['totalExercises']; ?></td>
                                        <td class="n"><?php echo (int) $w['totalDuration']; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty">
                        <h3>No workouts yet</h3>
                        <p>Log a session and your weeks will build up here.</p>
                        <a class="btn" href="log-workout.php">Log your first workout</a>
                    </div>
                <?php endif; ?>
            </section>
        </main>
        <footer></footer>
    </div>
</body>

</html>