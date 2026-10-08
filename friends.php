<?php
// friends.php
// Add/accept/remove friends, plus a weekly total-weight leaderboard among you and your friends.

require_once __DIR__ . '/api/includes/db.php';
require_once __DIR__ . '/api/includes/auth.php';

requireLogin();

$userId = getUserId();
$user = getUserInfo($conn, $userId);

// --- Incoming requests (sent TO me, awaiting my response) ---
$stmt = $conn->prepare("
    SELECT f.id AS friendship_id, u.id AS other_id, u.username, u.first_name
    FROM friendships f
    JOIN users u ON u.id = f.user_id
    WHERE f.friend_id = ? AND f.status = 'pending'
    ORDER BY f.created_at DESC
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$incomingRequests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// --- Outgoing requests (sent BY me, awaiting their response) ---
$stmt = $conn->prepare("
    SELECT f.id AS friendship_id, u.id AS other_id, u.username, u.first_name
    FROM friendships f
    JOIN users u ON u.id = f.friend_id
    WHERE f.user_id = ? AND f.status = 'pending'
    ORDER BY f.created_at DESC
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$outgoingRequests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// --- Accepted friends (either direction) ---
$stmt = $conn->prepare("
    SELECT f.id AS friendship_id,
           CASE WHEN f.user_id = ? THEN f.friend_id ELSE f.user_id END AS other_id
    FROM friendships f
    WHERE (f.user_id = ? OR f.friend_id = ?) AND f.status = 'accepted'
");
$stmt->bind_param("iii", $userId, $userId, $userId);
$stmt->execute();
$friendRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$friendIds = array_column($friendRows, 'other_id');
$friendsById = [];
if (!empty($friendIds)) {
    $placeholders = implode(',', array_fill(0, count($friendIds), '?'));
    $types = str_repeat('i', count($friendIds));
    $stmt = $conn->prepare("SELECT id, username, first_name FROM users WHERE id IN ($placeholders)");
    $stmt->bind_param($types, ...$friendIds);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $u) {
        $friendsById[$u['id']] = $u;
    }
    $stmt->close();
}

$friends = [];
foreach ($friendRows as $row) {
    if (isset($friendsById[$row['other_id']])) {
        $friends[] = ['friendship_id' => $row['friendship_id']] + $friendsById[$row['other_id']];
    }
}

// --- Weekly leaderboard: me + all accepted friends, current Mon–Sun week ---
$leaderboardIds = array_merge([$userId], $friendIds);

$weekStart = new DateTime('now');
$weekStart->modify('Monday this week');
$weekEnd = (clone $weekStart)->modify('Sunday this week');
$weekStartStr = $weekStart->format('Y-m-d');
$weekEndStr = $weekEnd->format('Y-m-d');

$leaderboard = [];
if (!empty($leaderboardIds)) {
    $placeholders = implode(',', array_fill(0, count($leaderboardIds), '?'));

    $stmt = $conn->prepare("
        SELECT u.id, u.username, u.first_name,
               COALESCE(SUM(e.weight * e.reps), 0) AS total_weight,
               COUNT(DISTINCT ws.id) AS session_count
        FROM users u
        LEFT JOIN workout_sessions ws ON ws.user_id = u.id AND ws.session_date BETWEEN ? AND ?
        LEFT JOIN exercises e ON e.session_id = ws.id
        WHERE u.id IN ($placeholders)
        GROUP BY u.id
        ORDER BY total_weight DESC
    ");
    // Bind order must match placeholder order in the query text above:
    // the two BETWEEN dates first, then the IN(...) id list.
    $stmt->bind_param('ss' . str_repeat('i', count($leaderboardIds)), $weekStartStr, $weekEndStr, ...$leaderboardIds);
    $stmt->execute();
    $leaderboard = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$medals = ['🥇', '🥈', '🥉'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Friends - GymTrack</title>
    <style>
        :root {
            color-scheme: dark;
            --bg-dark: #05030a;
            --panel: rgba(15, 8, 28, 0.95);
            --panel-2: rgba(20, 12, 40, 0.98);
            --text-main: #f6f7ff;
            --text-muted: #adb2d4;
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
        .container { max-width: 820px; margin: 0 auto; padding: 32px 24px 60px; }

        .page-head { margin-bottom: 22px; }
        .page-head h2 { font-size: clamp(1.8rem, 2.5vw, 2.2rem); margin-bottom: 6px; }
        .page-head p { color: var(--text-muted); font-size: 1rem; }

        .panel {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 22px;
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: 0 16px 34px rgba(0, 0, 0, 0.2);
        }

        .panel-title { font-size: 1.05rem; font-weight: 700; margin-bottom: 16px; color: #fff; }
        .panel-subtitle { font-size: 0.85rem; color: var(--text-muted); margin: -10px 0 16px; }

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

        /* ---------- Add friend form ---------- */
        .add-friend-form { display: flex; gap: 10px; }

        .add-friend-form input {
            flex: 1;
            padding: 12px 14px;
            min-height: 46px;
            border-radius: 12px;
            border: 1px solid rgba(151, 109, 222, 0.22);
            background: rgba(255, 255, 255, 0.05);
            color: var(--text-main);
            font-size: 0.95rem;
            font-family: inherit;
        }

        .add-friend-form input:focus { outline: none; border-color: rgba(155, 106, 240, 0.8); box-shadow: 0 0 0 3px rgba(155, 106, 240, 0.16); }
        .add-friend-form input::placeholder { color: #7e89ab; }

        .add-friend-form button {
            padding: 12px 22px;
            min-height: 46px;
            border: none;
            border-radius: 12px;
            background: linear-gradient(135deg, #a755ff 0%, #7d3fd0 55%, #632a9f 100%);
            color: #fff;
            font-weight: 700;
            cursor: pointer;
            white-space: nowrap;
        }

        .add-friend-form button:disabled { opacity: 0.6; cursor: not-allowed; }

        /* ---------- Person rows ---------- */
        .person-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding: 12px 14px;
            background: var(--panel-2);
            border: 1px solid var(--border);
            border-radius: 14px;
            margin-bottom: 10px;
        }

        .person-row:last-child { margin-bottom: 0; }
        .person-info strong { display: block; font-size: 0.95rem; }
        .person-info span { color: var(--text-muted); font-size: 0.82rem; }

        .person-info-btn {
            background: none;
            border: none;
            padding: 0;
            margin: 0;
            text-align: left;
            cursor: pointer;
            flex: 1;
            min-width: 0;
            border-radius: 8px;
        }

        .person-info-btn:hover strong { color: #d8b8ff; }
        .person-info-btn:hover span { color: #c9a8f5; }
        .person-info-btn:focus-visible { outline: 2px solid rgba(155, 106, 240, 0.8); outline-offset: 3px; }

        .person-actions { display: flex; gap: 8px; flex-shrink: 0; }

        .btn-pill {
            padding: 8px 14px;
            border-radius: 999px;
            font-size: 0.82rem;
            font-weight: 700;
            cursor: pointer;
            border: 1px solid transparent;
            white-space: nowrap;
        }

        .btn-accept { background: rgba(55, 184, 147, 0.14); border-color: rgba(55, 184, 147, 0.35); color: #4fd6ac; }
        .btn-accept:hover { background: rgba(55, 184, 147, 0.24); }

        .btn-decline, .btn-remove { background: rgba(255, 94, 94, 0.1); border-color: rgba(255, 94, 94, 0.3); color: #ffb3b3; }
        .btn-decline:hover, .btn-remove:hover { background: rgba(255, 94, 94, 0.18); }

        .pending-tag {
            font-size: 0.78rem;
            color: var(--text-muted);
            font-style: italic;
        }

        .empty-note { color: var(--text-muted); font-size: 0.88rem; text-align: center; padding: 10px 0; }

        /* ---------- Friend's week modal ---------- */
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
            padding: 26px;
            box-shadow: 0 30px 60px rgba(0, 0, 0, 0.4);
        }

        .modal-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; margin-bottom: 18px; }
        .modal-head h3 { font-size: 1.2rem; color: #fff; margin-bottom: 2px; }

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

        .modal-total {
            background: rgba(151, 109, 222, 0.12);
            border: 1px solid rgba(151, 109, 222, 0.3);
            border-radius: 14px;
            padding: 12px 16px;
            margin-bottom: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-total strong { font-size: 1.2rem; color: #d8b8ff; }
        .modal-total span { color: var(--text-muted); font-size: 0.85rem; }

        .modal-session {
            background: var(--panel-2);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 16px;
            margin-bottom: 12px;
        }
        .modal-session:last-child { margin-bottom: 0; }

        .modal-session-head { display: flex; justify-content: space-between; align-items: baseline; flex-wrap: wrap; gap: 6px; margin-bottom: 10px; }
        .modal-session-head strong { font-size: 0.95rem; }
        .modal-session-head span { color: var(--text-muted); font-size: 0.82rem; }

        .modal-exercise { margin-bottom: 10px; }
        .modal-exercise:last-child { margin-bottom: 0; }
        .modal-exercise-name { font-size: 0.9rem; font-weight: 700; margin-bottom: 4px; }

        .modal-set-line {
            display: flex;
            justify-content: space-between;
            padding: 3px 0;
            font-size: 0.85rem;
            color: var(--text-muted);
        }
        .modal-set-line span:last-child { font-weight: 700; color: #d8b8ff; }
        .modal-set-line.is-warmup span:first-child,
        .modal-set-line.is-warmup span:last-child { color: #ffb454; }

        /* ---------- Leaderboard ---------- */
        .leaderboard-row {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 14px 16px;
            background: var(--panel-2);
            border: 1px solid var(--border);
            border-radius: 14px;
            margin-bottom: 10px;
        }

        .leaderboard-row.is-me { border-color: rgba(155, 106, 240, 0.55); background: rgba(151, 109, 222, 0.12); }
        .leaderboard-row:last-child { margin-bottom: 0; }

        .rank { font-size: 1.2rem; font-weight: 800; width: 32px; text-align: center; color: var(--text-muted); flex-shrink: 0; }
        .rank.medal { font-size: 1.5rem; }

        .lb-info { flex: 1; min-width: 0; }
        .lb-info strong { display: block; font-size: 0.98rem; }
        .lb-info span { color: var(--text-muted); font-size: 0.82rem; }

        .lb-weight { text-align: right; flex-shrink: 0; }
        .lb-weight strong { display: block; font-size: 1.15rem; color: #d8b8ff; }
        .lb-weight span { color: var(--text-muted); font-size: 0.78rem; }

        @media (max-width: 640px) {
            .container { padding: 20px 16px 40px; }
            .navbar { padding: 16px 20px; }
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

            .add-friend-form { flex-direction: column; }
            .person-row { flex-direction: column; align-items: flex-start; }
            .person-actions { width: 100%; }
            .person-actions .btn-pill { flex: 1; text-align: center; }
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
            <a href="api/logout.php">Logout</a>
        </div>
    </nav>

    <div class="container">
        <div class="page-head">
            <h2>Friends</h2>
            <p>Add friends and see who's moved the most weight this week.</p>
        </div>

        <div class="message" id="formMessage"></div>

        <!-- Add a friend -->
        <div class="panel">
            <p class="panel-title">Add a friend</p>
            <form class="add-friend-form" id="addFriendForm">
                <input type="text" id="friendUsername" placeholder="Their username" required>
                <button type="submit" id="addFriendBtn">Send request</button>
            </form>
        </div>

        <!-- Incoming requests -->
        <?php if (!empty($incomingRequests)): ?>
        <div class="panel">
            <p class="panel-title">Friend requests</p>
            <div id="incomingList">
                <?php foreach ($incomingRequests as $r): ?>
                    <div class="person-row" data-friendship-id="<?php echo $r['friendship_id']; ?>">
                        <div class="person-info">
                            <strong><?php echo htmlspecialchars($r['first_name'] ?: $r['username']); ?></strong>
                            <span>@<?php echo htmlspecialchars($r['username']); ?></span>
                        </div>
                        <div class="person-actions">
                            <button class="btn-pill btn-accept" data-action="accept" data-id="<?php echo $r['friendship_id']; ?>">Accept</button>
                            <button class="btn-pill btn-decline" data-action="decline" data-id="<?php echo $r['friendship_id']; ?>">Decline</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Your friends -->
        <div class="panel">
            <p class="panel-title">Your friends</p>
            <div id="friendsList">
                <?php if (empty($friends)): ?>
                    <p class="empty-note">No friends yet — add someone above.</p>
                <?php else: ?>
                    <?php foreach ($friends as $f): ?>
                        <div class="person-row" data-friendship-id="<?php echo $f['friendship_id']; ?>">
                            <button type="button" class="person-info person-info-btn" data-friend-id="<?php echo $f['id']; ?>">
                                <strong><?php echo htmlspecialchars($f['first_name'] ?: $f['username']); ?></strong>
                                <span>@<?php echo htmlspecialchars($f['username']); ?> · tap to view this week</span>
                            </button>
                            <div class="person-actions">
                                <button class="btn-pill btn-remove" data-action="remove" data-id="<?php echo $f['friendship_id']; ?>">Remove</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php foreach ($outgoingRequests as $r): ?>
                    <div class="person-row">
                        <div class="person-info">
                            <strong><?php echo htmlspecialchars($r['first_name'] ?: $r['username']); ?></strong>
                            <span>@<?php echo htmlspecialchars($r['username']); ?></span>
                        </div>
                        <span class="pending-tag">Request sent</span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Weekly leaderboard -->
        <div class="panel">
            <p class="panel-title">Weekly leaderboard</p>
            <p class="panel-subtitle"><?php echo $weekStart->format('M j'); ?> – <?php echo $weekEnd->format('M j, Y'); ?> · total weight moved (kg)</p>

            <?php if (empty($leaderboard)): ?>
                <p class="empty-note">Add friends to see a leaderboard.</p>
            <?php else: ?>
                <?php foreach ($leaderboard as $i => $row): ?>
                    <div class="leaderboard-row<?php echo $row['id'] == $userId ? ' is-me' : ''; ?>">
                        <span class="rank<?php echo $i < 3 ? ' medal' : ''; ?>"><?php echo $i < 3 ? $medals[$i] : ($i + 1); ?></span>
                        <div class="lb-info">
                            <strong><?php echo htmlspecialchars($row['first_name'] ?: $row['username']); ?><?php echo $row['id'] == $userId ? ' (you)' : ''; ?></strong>
                            <span><?php echo $row['session_count']; ?> session<?php echo $row['session_count'] == 1 ? '' : 's'; ?> this week</span>
                        </div>
                        <div class="lb-weight">
                            <strong><?php echo number_format($row['total_weight']); ?> kg</strong>
                            <span>total weight</span>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Friend's weekly workouts modal -->
    <div class="modal-backdrop" id="modalBackdrop">
        <div class="modal-panel" id="modalPanel">
            <div class="modal-head">
                <div>
                    <h3 id="modalFriendName"></h3>
                    <p id="modalWeekRange" class="panel-subtitle" style="margin:0;"></p>
                </div>
                <button type="button" class="modal-close" id="modalClose" aria-label="Close">×</button>
            </div>
            <div id="modalBody"></div>
        </div>
    </div>

    <script>
        const navToggle = document.getElementById('navToggle');
        const navMenu = document.getElementById('navMenu');

        if (navToggle && navMenu) {
            navToggle.addEventListener('click', () => navMenu.classList.toggle('is-open'));
            document.addEventListener('click', (e) => {
                if (!navToggle.contains(e.target) && !navMenu.contains(e.target)) {
                    navMenu.classList.remove('is-open');
                }
            });
        }

        const messageEl = document.getElementById('formMessage');
        function showMessage(text, type) {
            messageEl.textContent = text;
            messageEl.className = 'message show ' + type;
        }

        // ---------- Friend's weekly workouts modal ----------
        const modalBackdrop = document.getElementById('modalBackdrop');

        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }

        function openFriendWeek(friendId) {
            document.getElementById('modalFriendName').textContent = 'Loading…';
            document.getElementById('modalWeekRange').textContent = '';
            document.getElementById('modalBody').innerHTML = '';
            modalBackdrop.classList.add('is-open');

            fetch('api/get-friend-week.php?friend_id=' + encodeURIComponent(friendId))
                .then(res => res.json())
                .then(data => {
                    if (!data.success) {
                        document.getElementById('modalFriendName').textContent = 'Could not load';
                        document.getElementById('modalBody').innerHTML =
                            `<p class="empty-note">${escapeHtml(data.error || 'Something went wrong.')}</p>`;
                        return;
                    }

                    document.getElementById('modalFriendName').textContent = data.friend.name + "'s week";
                    document.getElementById('modalWeekRange').textContent = data.week.start + ' – ' + data.week.end;

                    if (data.sessions.length === 0) {
                        document.getElementById('modalBody').innerHTML =
                            '<p class="empty-note">No workouts logged this week yet.</p>';
                        return;
                    }

                    let html = `
                        <div class="modal-total">
                            <span>Total weight this week</span>
                            <strong>${data.total_volume.toLocaleString()} kg</strong>
                        </div>
                    `;

                    html += data.sessions.map(session => {
                        const facts = [];
                        if (session.duration) facts.push(session.duration + ' min');
                        if (session.mood) facts.push('Felt ' + session.mood);

                        const exercisesHtml = session.exercises.map(ex => {
                            let workingCount = 0;
                            const setsHtml = ex.sets.map(set => {
                                const label = set.is_warmup ? 'Warmup' : ('Set ' + (++workingCount));
                                return `<div class="modal-set-line${set.is_warmup ? ' is-warmup' : ''}">
                                            <span>${label}</span>
                                            <span>${set.weight}kg × ${set.reps}</span>
                                        </div>`;
                            }).join('');
                            return `<div class="modal-exercise">
                                        <div class="modal-exercise-name">${escapeHtml(ex.name)}</div>
                                        ${setsHtml}
                                    </div>`;
                        }).join('') || '<p class="empty-note" style="padding:0;">No exercises recorded.</p>';

                        return `
                            <div class="modal-session">
                                <div class="modal-session-head">
                                    <strong>${session.plan ? escapeHtml(session.plan) + ' — ' : ''}${session.date}</strong>
                                    <span>${facts.join(' · ')}</span>
                                </div>
                                ${exercisesHtml}
                            </div>
                        `;
                    }).join('');

                    document.getElementById('modalBody').innerHTML = html;
                })
                .catch(() => {
                    document.getElementById('modalFriendName').textContent = 'Could not load';
                    document.getElementById('modalBody').innerHTML =
                        '<p class="empty-note">Could not reach the server.</p>';
                });
        }

        document.querySelectorAll('.person-info-btn').forEach(btn => {
            btn.addEventListener('click', () => openFriendWeek(btn.dataset.friendId));
        });

        document.getElementById('modalClose').addEventListener('click', () => modalBackdrop.classList.remove('is-open'));
        modalBackdrop.addEventListener('click', (e) => { if (e.target === modalBackdrop) modalBackdrop.classList.remove('is-open'); });
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape') modalBackdrop.classList.remove('is-open'); });

        // ---------- Add friend ----------
        document.getElementById('addFriendForm').addEventListener('submit', function (e) {
            e.preventDefault();
            const input = document.getElementById('friendUsername');
            const btn = document.getElementById('addFriendBtn');
            const username = input.value.trim();
            if (!username) return;

            btn.disabled = true;
            fetch('api/add-friend.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ username })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showMessage('Friend request sent!', 'success');
                    input.value = '';
                    setTimeout(() => location.reload(), 900);
                } else {
                    showMessage(data.error || 'Could not send request.', 'error');
                }
            })
            .catch(() => showMessage('Could not reach the server.', 'error'))
            .finally(() => { btn.disabled = false; });
        });

        // ---------- Accept / decline / remove (delegated) ----------
        document.body.addEventListener('click', function (e) {
            const btn = e.target.closest('.btn-pill[data-action]');
            if (!btn) return;

            const action = btn.dataset.action;
            const friendshipId = btn.dataset.id;
            const row = btn.closest('.person-row');

            if (action === 'remove' && !confirm('Remove this friend?')) return;

            const endpoint = action === 'remove' ? 'api/remove-friend.php' : 'api/respond-friend.php';
            const body = action === 'remove'
                ? { friendship_id: friendshipId }
                : { friendship_id: friendshipId, action: action };

            btn.disabled = true;
            fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    showMessage(data.error || 'Something went wrong.', 'error');
                    btn.disabled = false;
                }
            })
            .catch(() => {
                showMessage('Could not reach the server.', 'error');
                btn.disabled = false;
            });
        });
    </script>
</body>
</html>