<?php
// admin.php
// Admin-only: list every user, click into one to see their logged workouts (read-only).
// Access is gated by requireAdmin() — the logged-in user's email must be on @admin.com.

require_once __DIR__ . '/api/includes/db.php';
require_once __DIR__ . '/api/includes/auth.php';

requireLogin();

$userId = getUserId();
$currentUser = getUserInfo($conn, $userId);

if (!isAdminUser($conn, $userId)) {
    // Not an admin — bounce back to the dashboard rather than showing anything here.
    header('Location: dashboard.php');
    exit;
}

$viewedUserId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : null;
$viewedUser = null;
$viewedSessions = [];
$viewedExercises = [];

if ($viewedUserId) {
    $stmt = $conn->prepare("SELECT id, username, email, first_name, last_name, created_at FROM users WHERE id = ?");
    $stmt->bind_param("i", $viewedUserId);
    $stmt->execute();
    $viewedUser = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($viewedUser) {
        $stmt = $conn->prepare("
            SELECT ws.id, ws.session_date, ws.duration_minutes, ws.mood, wp.plan_name
            FROM workout_sessions ws
            LEFT JOIN workout_plans wp ON ws.workout_plan_id = wp.id AND wp.user_id = ws.user_id
            WHERE ws.user_id = ?
            ORDER BY ws.session_date DESC, ws.id DESC
        ");
        $stmt->bind_param("i", $viewedUserId);
        $stmt->execute();
        $viewedSessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Batch-fetch every exercise/set for all of this user's sessions in one query
        if (!empty($viewedSessions)) {
            $sessionIds = array_column($viewedSessions, 'id');
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
                $viewedExercises[$ex['session_id']][] = $ex;
            }
        }
    }
} else {
    // Users list, with a quick workout count per user
    $stmt = $conn->prepare("
        SELECT u.id, u.username, u.email, u.first_name, u.last_name, u.created_at,
               COUNT(DISTINCT ws.id) AS workout_count
        FROM users u
        LEFT JOIN workout_sessions ws ON ws.user_id = u.id
        GROUP BY u.id
        ORDER BY u.username
    ");
    $stmt->execute();
    $allUsers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - GymTrack</title>
    <style>
        :root {
            color-scheme: dark;
            --bg-dark: #05030a;
            --panel: rgba(15, 8, 28, 0.95);
            --panel-2: rgba(20, 12, 40, 0.98);
            --text-main: #f6f7ff;
            --text-muted: #adb2d4;
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

        .admin-badge {
            font-size: 0.7rem;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #ffb454;
            background: rgba(255, 180, 84, 0.14);
            border: 1px solid rgba(255, 180, 84, 0.35);
            padding: 3px 9px;
            border-radius: 999px;
            margin-left: 10px;
            vertical-align: middle;
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
        .container { max-width: 900px; margin: 0 auto; padding: 32px 24px 60px; }

        .page-head { margin-bottom: 22px; }
        .page-head h2 { font-size: clamp(1.8rem, 2.5vw, 2.2rem); margin-bottom: 6px; }
        .page-head p { color: var(--text-muted); font-size: 1rem; }

        .back-link {
            display: inline-block;
            color: var(--text-muted);
            text-decoration: none;
            font-size: 0.9rem;
            margin-bottom: 16px;
        }
        .back-link:hover { color: var(--text-main); }

        /* ---------- Users list ---------- */
        .user-card {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 18px 20px;
            margin-bottom: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            text-decoration: none;
            color: inherit;
            transition: background 0.2s ease, border-color 0.2s ease, transform 0.2s ease;
        }

        .user-card:hover { background: rgba(30, 15, 50, 0.9); border-color: rgba(155, 106, 240, 0.4); transform: translateY(-2px); }

        .user-info h4 { font-size: 1.05rem; margin-bottom: 4px; }
        .user-info p { color: var(--text-muted); font-size: 0.85rem; }

        .user-meta { text-align: right; flex-shrink: 0; }
        .user-meta .count { font-size: 1.3rem; font-weight: 700; color: #d8b8ff; }
        .user-meta .label { font-size: 0.78rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; }

        .admin-tag {
            display: inline-block;
            font-size: 0.7rem;
            font-weight: 800;
            color: #ffb454;
            background: rgba(255, 180, 84, 0.14);
            border: 1px solid rgba(255, 180, 84, 0.35);
            padding: 2px 8px;
            border-radius: 999px;
            margin-left: 8px;
            text-transform: uppercase;
        }

        /* ---------- Viewed-user header ---------- */
        .viewed-user-panel {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 22px 24px;
            margin-bottom: 22px;
        }

        .viewed-user-panel h3 { font-size: 1.3rem; margin-bottom: 6px; }
        .viewed-user-panel p { color: var(--text-muted); font-size: 0.9rem; }

        /* ---------- Session cards ---------- */
        .session-card {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 20px 22px;
            margin-bottom: 14px;
        }

        .session-head {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 12px;
            padding-bottom: 10px;
            border-bottom: 1px solid rgba(151, 109, 222, 0.15);
        }

        .session-head h4 { font-size: 1.05rem; }
        .session-head .session-facts { color: var(--text-muted); font-size: 0.85rem; }

        .plan-chip {
            display: inline-block;
            background: rgba(151, 109, 222, 0.16);
            color: #d8b8ff;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 700;
            margin-right: 8px;
        }

        .exercise-block { margin-bottom: 10px; }
        .exercise-block:last-child { margin-bottom: 0; }
        .exercise-block strong { display: block; font-size: 0.92rem; margin-bottom: 4px; }

        .set-line {
            display: flex;
            justify-content: space-between;
            font-size: 0.85rem;
            padding: 3px 0;
            color: var(--text-muted);
        }

        .set-line span:last-child { color: #d8b8ff; font-weight: 700; }
        .set-line.is-warmup span:first-child { color: var(--warmup); }
        .set-line.is-warmup span:last-child { color: var(--warmup); }

        .empty-state {
            background: var(--panel);
            border: 1px solid var(--border);
            padding: 40px;
            border-radius: 20px;
            text-align: center;
            color: var(--text-muted);
        }

        @media (max-width: 720px) {
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

            .user-card { flex-direction: column; align-items: flex-start; }
            .user-meta { text-align: left; }
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <h1>💪 GymTrack <span class="admin-badge">Admin</span></h1>
        <button class="nav-toggle" id="navToggle" aria-label="Toggle navigation" type="button">
            <span class="barbell-icon" aria-hidden="true">
                <span class="plate"></span>
                <span class="bar"></span>
                <span class="plate"></span>
            </span>
        </button>
        <div class="navbar-right" id="navMenu">
            <a href="dashboard.php">Dashboard</a>
            <a href="api/logout.php">Logout</a>
        </div>
    </nav>

    <div class="container">
        <?php if ($viewedUserId && $viewedUser): ?>
            <a class="back-link" href="admin.php">← Back to all users</a>

            <div class="viewed-user-panel">
                <h3><?php echo htmlspecialchars($viewedUser['first_name'] ?: $viewedUser['username']); ?>
                    <?php if (str_ends_with(strtolower($viewedUser['email']), '@admin.com')): ?><span class="admin-tag">Admin</span><?php endif; ?>
                </h3>
                <p>@<?php echo htmlspecialchars($viewedUser['username']); ?> · <?php echo htmlspecialchars($viewedUser['email']); ?></p>
                <p>Joined <?php echo date('M j, Y', strtotime($viewedUser['created_at'])); ?> · <?php echo count($viewedSessions); ?> workout<?php echo count($viewedSessions) === 1 ? '' : 's'; ?> logged</p>
            </div>

            <?php if (empty($viewedSessions)): ?>
                <div class="empty-state">
                    <h3 style="color:#fff; margin-bottom:8px;">No workouts logged yet</h3>
                    <p>This user hasn't saved any sessions.</p>
                </div>
            <?php else: ?>
                <?php foreach ($viewedSessions as $session): ?>
                    <?php
                        $sessionExercises = $viewedExercises[$session['id']] ?? [];
                        $exerciseGroups = [];
                        foreach ($sessionExercises as $ex) {
                            $exerciseGroups[$ex['exercise_name']][] = $ex;
                        }
                    ?>
                    <div class="session-card">
                        <div class="session-head">
                            <h4>
                                <?php if ($session['plan_name']): ?>
                                    <span class="plan-chip"><?php echo htmlspecialchars($session['plan_name']); ?></span>
                                <?php endif; ?>
                                <?php echo date('l, F j, Y', strtotime($session['session_date'])); ?>
                            </h4>
                            <span class="session-facts">
                                <?php echo count($exerciseGroups); ?> exercise<?php echo count($exerciseGroups) === 1 ? '' : 's'; ?>
                                <?php if ($session['duration_minutes']): ?> · <?php echo $session['duration_minutes']; ?> min<?php endif; ?>
                                <?php if ($session['mood']): ?> · felt <?php echo htmlspecialchars($session['mood']); ?><?php endif; ?>
                            </span>
                        </div>

                        <?php if (empty($exerciseGroups)): ?>
                            <p style="color:var(--text-muted); font-size:0.88rem;">No exercises recorded for this session.</p>
                        <?php else: ?>
                            <?php foreach ($exerciseGroups as $name => $sets): ?>
                                <div class="exercise-block">
                                    <strong><?php echo htmlspecialchars($name); ?></strong>
                                    <?php
                                        $workingCount = 0;
                                        foreach ($sets as $set):
                                            $label = $set['is_warmup'] ? 'Warmup' : ('Set ' . ++$workingCount);
                                    ?>
                                        <div class="set-line<?php echo $set['is_warmup'] ? ' is-warmup' : ''; ?>">
                                            <span><?php echo $label; ?></span>
                                            <span><?php echo $set['weight']; ?>kg × <?php echo $set['reps']; ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (!empty($sets[0]['notes'])): ?>
                                        <p style="color:var(--text-muted); font-size:0.82rem; margin-top:4px;"><?php echo htmlspecialchars($sets[0]['notes']); ?></p>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

        <?php elseif ($viewedUserId && !$viewedUser): ?>
            <a class="back-link" href="admin.php">← Back to all users</a>
            <div class="empty-state">
                <h3 style="color:#fff; margin-bottom:8px;">User not found</h3>
            </div>

        <?php else: ?>
            <div class="page-head">
                <h2>All users</h2>
                <p><?php echo count($allUsers); ?> registered user<?php echo count($allUsers) === 1 ? '' : 's'; ?>. Click one to see their workouts.</p>
            </div>

            <?php if (empty($allUsers)): ?>
                <div class="empty-state"><h3 style="color:#fff;">No users yet</h3></div>
            <?php else: ?>
                <?php foreach ($allUsers as $u): ?>
                    <a class="user-card" href="admin.php?user_id=<?php echo $u['id']; ?>">
                        <div class="user-info">
                            <h4>
                                <?php echo htmlspecialchars($u['first_name'] ?: $u['username']); ?>
                                <?php if (str_ends_with(strtolower($u['email']), '@admin.com')): ?><span class="admin-tag">Admin</span><?php endif; ?>
                            </h4>
                            <p>@<?php echo htmlspecialchars($u['username']); ?> · <?php echo htmlspecialchars($u['email']); ?></p>
                        </div>
                        <div class="user-meta">
                            <div class="count"><?php echo $u['workout_count']; ?></div>
                            <div class="label">Workout<?php echo $u['workout_count'] == 1 ? '' : 's'; ?></div>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <script>
        const navToggle = document.getElementById('navToggle');
        const navMenu = document.getElementById('navMenu');

        if (navToggle && navMenu) {
            navToggle.addEventListener('click', function () {
                navMenu.classList.toggle('is-open');
            });

            document.addEventListener('click', function (event) {
                if (!navToggle.contains(event.target) && !navMenu.contains(event.target)) {
                    navMenu.classList.remove('is-open');
                }
            });
        }
    </script>
</body>
</html>