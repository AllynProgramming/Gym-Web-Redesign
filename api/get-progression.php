<?php
// api/get-progression.php
// Returns chart + list data for one exercise, for the logged-in user. Called via fetch() from progression.php.
// Warmup sets are shown in the entry list (tagged) but excluded from the chart average and personal best.

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'You are not logged in. Please log in again.']);
    exit;
}

$userId = getUserId();
$exerciseName = trim($_GET['exercise'] ?? '');

if ($exerciseName === '') {
    echo json_encode(['success' => false, 'error' => 'No exercise specified.']);
    exit;
}

// Raw entries — every logged set for this exercise, most recent first. Includes warmups (tagged).
$stmt = $conn->prepare("
    SELECT ws.session_date AS date, wp.plan_name AS plan_name, e.weight, e.reps, e.sets, e.notes, e.is_warmup
    FROM exercises e
    JOIN workout_sessions ws ON e.session_id = ws.id
    LEFT JOIN workout_plans wp ON ws.workout_plan_id = wp.id
    WHERE ws.user_id = ? AND e.exercise_name = ?
    ORDER BY ws.session_date DESC, e.id DESC
");
$stmt->bind_param("is", $userId, $exerciseName);
$stmt->execute();
$entries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (empty($entries)) {
    echo json_encode(['success' => false, 'error' => 'No entries found for that exercise.']);
    exit;
}

foreach ($entries as &$e) {
    $e['is_warmup'] = (bool) $e['is_warmup'];
}
unset($e);

// Chart data — average weight per session date, WORKING SETS ONLY (warmups excluded so a light
// warmup doesn't drag the day's average down), oldest first.
$stmt = $conn->prepare("
    SELECT ws.session_date AS date, AVG(e.weight) AS avg_weight
    FROM exercises e
    JOIN workout_sessions ws ON e.session_id = ws.id
    WHERE ws.user_id = ? AND e.exercise_name = ? AND e.is_warmup = 0
    GROUP BY ws.session_date
    ORDER BY ws.session_date ASC
");
$stmt->bind_param("is", $userId, $exerciseName);
$stmt->execute();
$chartRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$weeks = [];
$chart = [];

foreach ($chartRows as $row) {
    $date = new DateTime($row['date']);
    $weekKey = $date->format('o') . '-W' . $date->format('W');
    $weekStart = (clone $date)->modify('Monday this week');
    $weekEnd = (clone $weekStart)->modify('Sunday this week');
    $weekLabel = $weekStart->format('M j') . ' – ' . $weekEnd->format('M j, Y');

    if (!isset($weeks[$weekKey])) {
        $weeks[$weekKey] = [
            'weekKey' => $weekKey,
            'weekLabel' => $weekLabel,
            'startDate' => $weekStart->format('Y-m-d'),
            'endDate' => $weekEnd->format('Y-m-d'),
        ];
    }

    $chart[] = [
        'date' => $row['date'],
        'avg_weight' => round((float) $row['avg_weight'], 1),
        'weekKey' => $weekKey,
        'weekLabel' => $weekLabel,
    ];
}

usort($weeks, function ($a, $b) {
    return strcmp($b['startDate'], $a['startDate']);
});

if (empty($chart)) {
    echo json_encode(['success' => false, 'error' => 'Only warmup sets logged for this exercise so far — no working-set data yet.']);
    exit;
}

// Personal best also excludes warmups
$workingWeights = array_column(array_filter($entries, fn($e) => !$e['is_warmup']), 'weight');
$bestWeight = !empty($workingWeights) ? max($workingWeights) : 0;

$firstAvg = $chart[0]['avg_weight'];
$latestAvg = $chart[count($chart) - 1]['avg_weight'];

echo json_encode([
    'success' => true,
    'exercise' => $exerciseName,
    'summary' => [
        'best' => (float) $bestWeight,
        'latest' => $latestAvg,
        'first' => $firstAvg,
        'delta' => round($latestAvg - $firstAvg, 1),
        'sessions' => count($chart),
    ],
    'weeks' => array_values($weeks),
    'chart' => $chart,
    'entries' => array_map(function ($entry) {
        $entryDate = new DateTime($entry['date']);
        $weekKey = $entryDate->format('o') . '-W' . $entryDate->format('W');
        return array_merge($entry, ['weekKey' => $weekKey]);
    }, $entries),
]);
exit;
?>