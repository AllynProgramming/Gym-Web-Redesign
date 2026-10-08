<?php
// api/get-friend-week.php
// Returns a friend's logged workouts for the CURRENT Mon–Sun week only.
// Only works if the requester and the target are actually accepted friends.

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'You are not logged in. Please log in again.']);
    exit;
}

$userId = getUserId();
$friendId = (int) ($_GET['friend_id'] ?? 0);

if ($friendId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid request.']);
    exit;
}

// Privacy check: only proceed if there's an ACCEPTED friendship between these two users, either direction.
$stmt = $conn->prepare("
    SELECT id FROM friendships
    WHERE ((user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)) AND status = 'accepted'
    LIMIT 1
");
$stmt->bind_param("iiii", $userId, $friendId, $friendId, $userId);
$stmt->execute();
$isFriend = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$isFriend) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'You can only view workouts for your accepted friends.']);
    exit;
}

$stmt = $conn->prepare("SELECT username, first_name FROM users WHERE id = ?");
$stmt->bind_param("i", $friendId);
$stmt->execute();
$friend = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$friend) {
    echo json_encode(['success' => false, 'error' => 'User not found.']);
    exit;
}

// Current Mon–Sun week — same convention used everywhere else in the app
$weekStart = new DateTime('now');
$weekStart->modify('Monday this week');
$weekEnd = (clone $weekStart)->modify('Sunday this week');
$weekStartStr = $weekStart->format('Y-m-d');
$weekEndStr = $weekEnd->format('Y-m-d');

$stmt = $conn->prepare("
    SELECT ws.id, ws.session_date, ws.duration_minutes, ws.mood, wp.plan_name
    FROM workout_sessions ws
    LEFT JOIN workout_plans wp ON ws.workout_plan_id = wp.id AND wp.user_id = ws.user_id
    WHERE ws.user_id = ? AND ws.session_date BETWEEN ? AND ?
    ORDER BY ws.session_date DESC, ws.id DESC
");
$stmt->bind_param("iss", $friendId, $weekStartStr, $weekEndStr);
$stmt->execute();
$sessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$exercisesBySession = [];
$totalVolume = 0;

if (!empty($sessions)) {
    $sessionIds = array_column($sessions, 'id');
    $placeholders = implode(',', array_fill(0, count($sessionIds), '?'));
    $types = str_repeat('i', count($sessionIds));

    $stmt = $conn->prepare("
        SELECT session_id, exercise_name, weight, reps, notes, is_warmup
        FROM exercises
        WHERE session_id IN ($placeholders)
        ORDER BY session_id, id
    ");
    $stmt->bind_param($types, ...$sessionIds);
    $stmt->execute();
    $allExercises = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($allExercises as $ex) {
        $exercisesBySession[$ex['session_id']][] = $ex;
        $totalVolume += $ex['weight'] * $ex['reps'];
    }
}

$sessionsOut = [];
foreach ($sessions as $s) {
    $groups = [];
    foreach ($exercisesBySession[$s['id']] ?? [] as $ex) {
        $name = $ex['exercise_name'];
        if (!isset($groups[$name])) {
            $groups[$name] = ['name' => $name, 'notes' => $ex['notes'], 'sets' => []];
        }
        $groups[$name]['sets'][] = [
            'weight' => $ex['weight'],
            'reps' => $ex['reps'],
            'is_warmup' => (bool) $ex['is_warmup'],
        ];
    }

    $sessionsOut[] = [
        'date' => date('l, F j', strtotime($s['session_date'])),
        'plan' => $s['plan_name'] ?: null,
        'duration' => $s['duration_minutes'],
        'mood' => $s['mood'],
        'exercises' => array_values($groups),
    ];
}

echo json_encode([
    'success' => true,
    'friend' => [
        'name' => $friend['first_name'] ?: $friend['username'],
        'username' => $friend['username'],
    ],
    'week' => [
        'start' => $weekStart->format('M j'),
        'end' => $weekEnd->format('M j, Y'),
    ],
    'sessions' => $sessionsOut,
    'total_volume' => round($totalVolume),
]);
exit;
?>
