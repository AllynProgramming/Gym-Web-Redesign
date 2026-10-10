<?php
// friends.php
// Add/accept/remove friends, plus a weekly total-weight leaderboard among you and your friends.

require_once __DIR__ . '/api/includes/db.php';
require_once __DIR__ . '/api/includes/timezone.php';
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

// --- Weekly leaderboard: me + all accepted friends, current Mon-Sun week ---
$leaderboardIds = array_merge([$userId], $friendIds);

$weekStart = new DateTime('now');
$weekStart->modify('Monday this week');
$weekEnd = (clone $weekStart)->modify('Sunday this week');
$weekStartStr = $weekStart->format('Y-m-d');
$weekEndStr = $weekEnd->format('Y-m-d');
$weekNextStr = (clone $weekStart)->modify('+7 days')->format('Y-m-d'); // exclusive upper bound

$leaderboard = [];
if (!empty($leaderboardIds)) {
    $placeholders = implode(',', array_fill(0, count($leaderboardIds), '?'));

    // Warm-up sets are not counted, so the ranking matches the rest of the app.
    $stmt = $conn->prepare("
        SELECT u.id, u.username, u.first_name,
               COALESCE(SUM(CASE WHEN e.is_warmup = 1 THEN 0
                                 ELSE e.weight * e.reps * GREATEST(COALESCE(e.sets, 1), 1) END), 0) AS total_weight,
               COUNT(DISTINCT ws.id) AS session_count
        FROM users u
        LEFT JOIN workout_sessions ws ON ws.user_id = u.id AND ws.session_date >= ? AND ws.session_date < ?
        LEFT JOIN exercises e ON e.session_id = ws.id
        WHERE u.id IN ($placeholders)
        GROUP BY u.id
        ORDER BY total_weight DESC
    ");
    // Bind order must match placeholder order in the query text above:
    // the two date bounds first, then the IN(...) id list.
    $stmt->bind_param('ss' . str_repeat('i', count($leaderboardIds)), $weekStartStr, $weekNextStr, ...$leaderboardIds);
    $stmt->execute();
    $leaderboard = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Ranks with ties; people with nothing lifted this week are not ranked
$ranked = [];
$position = 0;
$rank = 0;
$prevTotal = null;
foreach ($leaderboard as $row) {
    $total = (float) $row['total_weight'];
    if ($total > 0) {
        $position++;
        if ($prevTotal === null || $total != $prevTotal) {
            $rank = $position;
        }
        $prevTotal = $total;
        $row['rank'] = $rank;
    } else {
        $row['rank'] = null;
    }
    $ranked[] = $row;
}
$topTotal = !empty($ranked) ? (float) $ranked[0]['total_weight'] : 0;

function gt_e($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}
function gt_name($r)
{
    return $r['first_name'] ?: $r['username'];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <script src="assets/theme.js"></script>
    <title>Friends | GymTrack</title>
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
            --yellow: #EDBE2B;
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
                --err: #FF8A80
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
            align-items: center;
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

        .top {
            padding: 2.2rem 0 .6rem
        }

        h1 {
            font: 850 clamp(2.1rem, 6vw, 3.6rem)/1 var(--head);
            font-stretch: 118%;
            letter-spacing: -.025em;
            margin: 0 0 .5rem
        }

        .lede {
            color: var(--muted);
            margin: 0;
            max-width: 32rem
        }

        .sec {
            padding: 1.8rem 0;
            border-top: 1px solid var(--rule);
            margin-top: 1.4rem
        }

        .sec>h2 {
            font: 750 1.2rem var(--head);
            margin: 0 0 .3rem
        }

        .sub {
            color: var(--muted);
            margin: 0 0 1rem;
            font-size: 1rem
        }

        .btn {
            display: inline-block;
            background: var(--accent);
            color: var(--on-accent);
            font: 700 1rem var(--head);
            padding: .75rem 1.3rem;
            border: 0;
            border-radius: 6px;
            cursor: pointer;
            min-height: 2.8rem;
            white-space: nowrap
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

        .add {
            display: flex;
            gap: .6rem;
            align-items: end;
            flex-wrap: wrap
        }

        .add .field {
            flex: 1;
            min-width: 12rem
        }

        .field {
            display: grid;
            gap: .3rem
        }

        label {
            font: 700 .85rem var(--head)
        }

        input[type=text] {
            font: 400 1.1rem var(--body);
            color: var(--ink);
            background: var(--surface);
            border: 2px solid var(--rule);
            border-radius: 4px;
            padding: .65rem .75rem;
            width: 100%;
            min-width: 0;
            min-height: 2.8rem
        }

        input[type=text]:focus {
            outline: none;
            border-color: var(--accent)
        }

        input[type=text]:focus-visible {
            outline: 3px solid var(--accent);
            outline-offset: 1px
        }

        .lb {
            list-style: none;
            margin: 0;
            padding: 0
        }

        .lb li {
            display: grid;
            grid-template-columns: 2.6rem 1fr auto;
            gap: .2rem .8rem;
            align-items: center;
            padding: .8rem 0;
            border-bottom: 1px solid var(--rule)
        }

        .lb .rk {
            font: 850 1.5rem var(--head);
            font-stretch: 112%;
            font-variant-numeric: tabular-nums;
            color: var(--muted);
            text-align: center
        }

        .lb li.top1 .rk {
            color: var(--ink)
        }

        .lb .nm {
            font: 700 1.05rem var(--head);
            overflow-wrap: anywhere
        }

        .lb .nm small {
            display: block;
            font: 400 .95rem var(--body);
            color: var(--muted)
        }

        .lb .kg {
            font: 800 1.2rem var(--head);
            font-variant-numeric: tabular-nums;
            text-align: right
        }

        .lb .kg small {
            font: 600 .85rem var(--head);
            color: var(--muted)
        }

        .lb .bar {
            grid-column: 2/-1;
            height: 8px;
            border-radius: 4px;
            background: var(--surface);
            box-shadow: inset 0 0 0 1px var(--rule);
            overflow: hidden
        }

        .lb .bar i {
            display: block;
            height: 100%;
            border-radius: 4px;
            background: var(--muted)
        }

        .lb li.me .bar i {
            background: var(--accent)
        }

        .lb li.me .nm {
            color: var(--accent)
        }

        .people {
            list-style: none;
            margin: 0;
            padding: 0
        }

        .people li {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: .8rem;
            padding: .75rem 0;
            border-bottom: 1px solid var(--rule);
            flex-wrap: wrap
        }

        .who {
            font: 700 1.05rem var(--head);
            overflow-wrap: anywhere
        }

        .who small {
            display: block;
            font: 400 .95rem var(--body);
            color: var(--muted)
        }

        .acts {
            display: flex;
            gap: .4rem;
            flex-wrap: wrap
        }

        .acts button {
            font: 700 .9rem var(--head);
            min-height: 2.4rem;
            padding: .3rem .9rem;
            border-radius: 6px;
            cursor: pointer;
            background: transparent;
            color: var(--ink);
            border: 2px solid var(--rule)
        }

        .acts button:hover {
            border-color: var(--ink)
        }

        .acts button.go {
            background: var(--accent);
            color: var(--on-accent);
            border-color: var(--accent)
        }

        .acts button.no {
            color: var(--err)
        }

        .acts button.no:hover {
            border-color: var(--err)
        }

        .acts button[disabled] {
            opacity: .6;
            cursor: wait
        }

        .pend {
            font: 600 .9rem var(--head);
            color: var(--muted)
        }

        .empty {
            color: var(--muted);
            margin: 0
        }

        dialog {
            border: 0;
            border-radius: 10px;
            padding: 1.5rem;
            max-width: 30rem;
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
            align-items: start;
            gap: 1rem;
            margin-bottom: .6rem
        }

        .dh h2 {
            font: 800 1.4rem/1.2 var(--head);
            margin: 0
        }

        .dh p {
            margin: .1rem 0 0;
            color: var(--muted);
            font-size: 1rem
        }

        .x {
            border: 0;
            background: transparent;
            color: var(--muted);
            font: 700 1.4rem/1 var(--head);
            width: 2.4rem;
            height: 2.4rem;
            border-radius: 6px;
            cursor: pointer;
            flex: none
        }

        .x:hover {
            color: var(--ink);
            box-shadow: inset 0 0 0 1.5px var(--ink)
        }

        .tot {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            border-top: 2px solid var(--ink);
            padding: .5rem 0 .8rem
        }

        .tot span {
            font: 600 .95rem var(--head);
            color: var(--muted)
        }

        .tot b {
            font: 800 1.5rem var(--head);
            font-variant-numeric: tabular-nums
        }

        .ses {
            border-top: 1px solid var(--rule);
            padding: .8rem 0
        }

        .ses h3 {
            font: 750 1.05rem var(--head);
            margin: 0
        }

        .ses .fa {
            color: var(--muted);
            font-size: .95rem;
            margin: 0 0 .5rem
        }

        .ses h4 {
            font: 700 .95rem var(--head);
            margin: .6rem 0 .2rem;
            overflow-wrap: anywhere
        }

        .sl {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            padding: .3rem 0;
            border-bottom: 1px solid var(--rule);
            font-variant-numeric: tabular-nums
        }

        .sl span:first-child {
            font: 600 .9rem var(--head);
            color: var(--muted)
        }

        .sl.wu span:first-child {
            background: var(--yellow);
            color: #1D2024;
            border-radius: 20px;
            padding: 0 .5rem;
            font-size: .8rem;
            align-self: center
        }

        .sl span:last-child {
            font: 700 1rem var(--head)
        }

        footer {
            padding: 1rem 0 3rem
        }

        @media (max-width:560px) {
            .add .btn {
                width: 100%
            }

            .people li .acts {
                width: 100%
            }

            .people li .acts button {
                flex: 1
            }
        }

        nav a[aria-current="page"] {
            text-decoration: underline;
            text-decoration-thickness: 2px;
            text-underline-offset: 6px
        }

        nav .who {
            color: var(--muted);
            font-weight: 500
        }

        @media (max-width:560px) {
            header nav {
                gap: .1rem 1rem;
                font-size: .9rem
            }
        }
    </style>
</head>

<body>
    <div class="wrap">
        <header>
            <a class="logo" href="dashboard.php">GymTrack</a>
            <?php $activePage = 'friends';
            include __DIR__ . '/api/includes/nav.php'; ?>
        </header>

        <main>
            <div class="top">
                <h1>Friends</h1>
                <p class="lede">Add friends and see who has moved the most weight this week.</p>
            </div>

            <section class="sec" style="margin-top:.6rem">
                <h2>Add a friend</h2>
                <form class="add" id="addFriendForm">
                    <div class="field"><label for="friendUsername">Their username</label><input type="text"
                            id="friendUsername" required autocomplete="off" autocapitalize="none" spellcheck="false">
                    </div>
                    <button type="submit" class="btn" id="addFriendBtn">Send request</button>
                </form>
                <div class="msg" id="formMessage" role="status" aria-live="polite" hidden></div>
            </section>

            <?php if (!empty($incomingRequests)): ?>
                <section class="sec">
                    <h2>Friend requests</h2>
                    <ul class="people">
                        <?php foreach ($incomingRequests as $r): ?>
                            <li>
                                <div class="who">
                                    <?php echo gt_e(gt_name($r)); ?><small>@<?php echo gt_e($r['username']); ?></small></div>
                                <div class="acts">
                                    <button type="button" class="go" data-action="accept"
                                        data-id="<?php echo (int) $r['friendship_id']; ?>">Accept</button>
                                    <button type="button" class="no" data-action="decline"
                                        data-id="<?php echo (int) $r['friendship_id']; ?>">Decline</button>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endif; ?>

            <section class="sec">
                <h2>Weekly leaderboard</h2>
                <p class="sub"><?php echo gt_e($weekStart->format('M j')); ?> to
                    <?php echo gt_e($weekEnd->format('M j, Y')); ?>. Total weight moved in kg, working sets only.</p>
                <ol class="lb">
                    <?php foreach ($ranked as $row):
                        $isMe = ((int) $row['id'] === (int) $userId);
                        $t = (float) $row['total_weight'];
                        $w = $topTotal > 0 ? max(2, round($t / $topTotal * 100)) : 0; ?>
                        <li class="<?php echo $isMe ? 'me' : ''; ?><?php echo $row['rank'] === 1 ? ' top1' : ''; ?>">
                            <span class="rk"
                                aria-label="<?php echo $row['rank'] ? 'Rank ' . (int) $row['rank'] : 'Not ranked'; ?>"><?php echo $row['rank'] ? (int) $row['rank'] : '-'; ?></span>
                            <div class="nm"><?php echo gt_e(gt_name($row)); ?><?php echo $isMe ? ' (you)' : ''; ?>
                                <small><?php echo (int) $row['session_count']; ?>
                                    session<?php echo (int) $row['session_count'] === 1 ? '' : 's'; ?> this week</small>
                            </div>
                            <div class="kg"><?php echo number_format($t); ?> <small>kg</small></div>
                            <?php if ($t > 0): ?>
                                <div class="bar" role="img"
                                    aria-label="<?php echo $topTotal > 0 ? round($t / $topTotal * 100) : 0; ?> percent of the top total">
                                    <i style="width:<?php echo $w; ?>%"></i></div><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
                <?php if (empty($friends)): ?>
                    <p class="sub" style="margin:.8rem 0 0">Add a friend to compete with them here.</p><?php endif; ?>
            </section>

            <section class="sec">
                <h2>Your friends</h2>
                <?php if (empty($friends) && empty($outgoingRequests)): ?>
                    <p class="empty">No friends yet. Send a request above.</p>
                <?php else: ?>
                    <ul class="people">
                        <?php foreach ($friends as $f): ?>
                            <li>
                                <div class="who">
                                    <?php echo gt_e(gt_name($f)); ?><small>@<?php echo gt_e($f['username']); ?></small></div>
                                <div class="acts">
                                    <button type="button" class="view" data-friend-id="<?php echo (int) $f['id']; ?>">View this
                                        week</button>
                                    <button type="button" class="no" data-action="remove"
                                        data-id="<?php echo (int) $f['friendship_id']; ?>"
                                        aria-label="Remove <?php echo gt_e(gt_name($f)); ?>">Remove</button>
                                </div>
                            </li>
                        <?php endforeach; ?>
                        <?php foreach ($outgoingRequests as $r): ?>
                            <li>
                                <div class="who">
                                    <?php echo gt_e(gt_name($r)); ?><small>@<?php echo gt_e($r['username']); ?></small></div>
                                <span class="pend">Request sent</span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        </main>
        <footer></footer>
    </div>

    <dialog id="weekDialog" aria-labelledby="dlgName">
        <div class="dh">
            <div>
                <h2 id="dlgName"></h2>
                <p id="dlgRange"></p>
            </div>
            <button type="button" class="x" id="dlgClose" aria-label="Close">&times;</button>
        </div>
        <div id="dlgBody" aria-live="polite"></div>
    </dialog>

    <script>
        const $ = id => document.getElementById(id);
        function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }
        function say(text, kind) { const m = $('formMessage'); m.textContent = text; m.className = 'msg' + (kind === 'err' ? ' err' : ''); m.hidden = false; }
        function post(url, body) {
            return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
                .then(r => r.text())
                .then(t => { try { return JSON.parse(t); } catch (e) { throw new Error('bad response'); } });
        }
        const NET = 'Could not reach the server, or it sent back something unexpected. Nothing was changed.';

        // ---------- Friend's week dialog ----------
        const dlg = $('weekDialog');
        function openFriendWeek(friendId) {
            $('dlgName').textContent = 'Loading';
            $('dlgRange').textContent = '';
            $('dlgBody').innerHTML = '';
            dlg.showModal();

            fetch('api/get-friend-week.php?friend_id=' + encodeURIComponent(friendId))
                .then(r => r.text())
                .then(t => { try { return JSON.parse(t); } catch (e) { throw new Error('bad response'); } })
                .then(data => {
                    if (!data.success) { $('dlgName').textContent = 'Could not load'; $('dlgBody').innerHTML = '<p class="empty">' + esc(data.error || 'Something went wrong.') + '</p>'; return; }
                    $('dlgName').textContent = data.friend.name + '\u2019s week';
                    $('dlgRange').textContent = data.week.start + ' to ' + data.week.end;
                    if (!data.sessions.length) { $('dlgBody').innerHTML = '<p class="empty">No workouts logged this week yet.</p>'; return; }

                    // Working sets only, the same rule the leaderboard uses
                    let total = 0;
                    data.sessions.forEach(s => s.exercises.forEach(ex => ex.sets.forEach(st => { if (!st.is_warmup) total += (parseFloat(st.weight) || 0) * (parseInt(st.reps, 10) || 0); })));

                    let html = '<div class="tot"><span>Total weight this week</span><b>' + Math.round(total).toLocaleString() + ' kg</b></div>';
                    html += data.sessions.map(s => {
                        const facts = [];
                        if (s.duration) facts.push(s.duration + ' min');
                        if (s.mood) facts.push('Felt ' + s.mood);
                        const ex = s.exercises.map(e => {
                            let n = 0;
                            const sets = e.sets.map(st => '<div class="sl' + (st.is_warmup ? ' wu' : '') + '"><span>' + (st.is_warmup ? 'Warm-up' : 'Set ' + (++n)) + '</span><span>' + esc(st.weight) + ' kg x ' + esc(st.reps) + '</span></div>').join('');
                            return '<h4>' + esc(e.name) + '</h4>' + sets;
                        }).join('') || '<p class="empty">No exercises recorded.</p>';
                        return '<div class="ses"><h3>' + esc((s.plan ? s.plan + ', ' : '') + s.date) + '</h3>' + (facts.length ? '<p class="fa">' + esc(facts.join(', ')) + '</p>' : '') + ex + '</div>';
                    }).join('');
                    $('dlgBody').innerHTML = html;
                })
                .catch(() => { $('dlgName').textContent = 'Could not load'; $('dlgBody').innerHTML = '<p class="empty">Could not reach the server, or it sent back something unexpected.</p>'; });
        }
        $('dlgClose').addEventListener('click', () => dlg.close());
        dlg.addEventListener('click', e => { if (e.target === dlg) dlg.close(); });

        // ---------- Add friend ----------
        $('addFriendForm').addEventListener('submit', function (e) {
            e.preventDefault();
            const input = $('friendUsername'), btn = $('addFriendBtn');
            const username = input.value.trim().replace(/^@/, '');
            if (!username) return;
            btn.disabled = true;
            post('api/add-friend.php', { username })
                .then(d => {
                    if (d.success) { say('Friend request sent.', 'ok'); input.value = ''; setTimeout(() => location.reload(), 900); }
                    else { say(d.error || 'Could not send the request.', 'err'); btn.disabled = false; }
                })
                .catch(() => { say(NET, 'err'); btn.disabled = false; });
        });

        // ---------- Accept / decline / remove / view week (delegated) ----------
        document.addEventListener('click', function (e) {
            const view = e.target.closest('button.view');
            if (view) { openFriendWeek(view.dataset.friendId); return; }

            const btn = e.target.closest('button[data-action]');
            if (!btn) return;
            const action = btn.dataset.action, id = btn.dataset.id;
            if (action === 'remove' && !confirm('Remove this friend?')) return;

            const url = action === 'remove' ? 'api/remove-friend.php' : 'api/respond-friend.php';
            const body = action === 'remove' ? { friendship_id: id } : { friendship_id: id, action: action };
            btn.disabled = true;
            post(url, body)
                .then(d => {
                    if (d.success) location.reload();
                    else { say(d.error || 'Something went wrong.', 'err'); btn.disabled = false; window.scrollTo({ top: 0, behavior: 'smooth' }); }
                })
                .catch(() => { say(NET, 'err'); btn.disabled = false; });
        });
    </script>
</body>

</html>