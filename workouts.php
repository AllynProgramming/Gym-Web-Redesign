<?php
// workouts.php
// Full workout history, grouped by week, with a plan filter and an in-page detail dialog.

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
$workoutVolume = [];   // working sets only: warm-ups are kept in the history but not counted
$workoutSets = [];     // working sets only
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
        if (empty($ex['is_warmup'])) {
            $n = max(1, (int) $ex['sets']);
            $workoutVolume[$sid] = ($workoutVolume[$sid] ?? 0) + ($ex['weight'] * $ex['reps'] * $n);
            $workoutSets[$sid] = ($workoutSets[$sid] ?? 0) + $n;
        }
    }
}

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

// Data the dialog (and the summary figures) read client-side, so "View" needs no extra request.
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
        'plan_key' => $w['plan_name'] ?: '__none__',
        'duration' => $w['duration_minutes'],
        'mood' => $w['mood'],
        'notes' => $w['notes'],
        'volume' => round($workoutVolume[$w['id']] ?? 0),
        'sets' => (int) ($workoutSets[$w['id']] ?? 0),
        'exercises' => array_values($exerciseGroups),
    ];
}

// Page-level summary, using the same definitions the page script uses when you filter or delete
$totalSessions = count($workouts);
$totalExercises = array_sum(array_map(fn($m) => count($m['exercises']), $modalData));
$totalSets = array_sum(array_column($modalData, 'sets'));
$totalVolume = array_sum(array_column($modalData, 'volume'));

// Distinct plan names actually in use, for the filter dropdown
$planFilterOptions = [];
foreach ($workouts as $w) {
    $key = $w['plan_name'] ?: '__none__';
    $planFilterOptions[$key] = $w['plan_name'] ?: 'No plan';
}
asort($planFilterOptions);

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
    <title>My workouts | GymTrack</title>
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
            max-width: 860px;
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
            display: flex;
            justify-content: space-between;
            align-items: end;
            gap: 1rem;
            flex-wrap: wrap;
            padding: 2.2rem 0 1.2rem
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
            max-width: 30rem
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

        .btn.danger {
            background: transparent;
            color: var(--err);
            box-shadow: inset 0 0 0 2px var(--err)
        }

        .msg {
            margin: 0 0 1rem;
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

        .figs {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem 1.5rem;
            margin: 1rem 0 0
        }

        .figs div {
            border-top: 2px solid var(--ink);
            padding-top: .4rem
        }

        .figs dt {
            font: 600 .85rem var(--head);
            color: var(--muted)
        }

        .figs dd {
            margin: 0;
            font: 800 1.9rem/1.15 var(--head);
            font-stretch: 112%;
            font-variant-numeric: tabular-nums
        }

        .filter {
            display: flex;
            justify-content: space-between;
            align-items: end;
            gap: 1rem;
            flex-wrap: wrap;
            padding: 1.6rem 0 .4rem
        }

        .field {
            display: grid;
            gap: .3rem
        }

        label {
            font: 700 .85rem var(--head)
        }

        select {
            font: 400 1.05rem var(--body);
            color: var(--ink);
            background: var(--surface);
            border: 2px solid var(--rule);
            border-radius: 4px;
            padding: .6rem .7rem;
            min-width: 13rem;
            min-height: 2.8rem
        }

        select:focus-visible {
            outline: 3px solid var(--accent);
            outline-offset: 1px;
            border-color: var(--accent)
        }

        #filterCount {
            font: 600 .95rem var(--head);
            color: var(--muted)
        }

        .week {
            padding: 1.4rem 0 .6rem;
            margin-top: 1.2rem;
            border-top: 2px solid var(--ink);
            scroll-margin-top: 1rem
        }

        .week:target {
            box-shadow: -.7rem 0 0 -.3rem var(--accent)
        }

        .wh {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: .5rem 1rem;
            flex-wrap: wrap;
            margin-bottom: .4rem
        }

        .wh h2 {
            font: 750 1.2rem var(--head);
            margin: 0
        }

        .wh span {
            font: 600 .95rem var(--head);
            color: var(--muted)
        }

        .row {
            display: grid;
            grid-template-columns: 7.5rem 1fr auto;
            gap: .5rem 1rem;
            align-items: center;
            padding: .8rem 0;
            border-bottom: 1px solid var(--rule)
        }

        .row .d {
            font: 700 1rem var(--head)
        }

        .row .p {
            font: 700 1.05rem var(--head);
            overflow-wrap: anywhere
        }

        .row .s {
            color: var(--muted);
            font-size: 1rem
        }

        .acts {
            display: flex;
            gap: .4rem
        }

        .acts button {
            font: 700 .9rem var(--head);
            min-height: 2.4rem;
            padding: .3rem .8rem;
            border-radius: 6px;
            cursor: pointer;
            background: transparent;
            color: var(--ink);
            border: 2px solid var(--rule)
        }

        .acts button:hover {
            border-color: var(--ink)
        }

        .acts button.del {
            color: var(--err)
        }

        .acts button.del:hover {
            border-color: var(--err)
        }

        .row.gone {
            opacity: 0
        }

        @media (prefers-reduced-motion:no-preference) {
            .row {
                transition: opacity .2s ease
            }
        }

        .empty,
        .nomatch {
            background: var(--surface);
            box-shadow: 0 0 0 1px var(--rule);
            border-radius: 10px;
            padding: 1.8rem;
            margin-top: 1.5rem
        }

        .empty h2 {
            font: 750 1.3rem var(--head);
            margin: 0 0 .3rem
        }

        .empty p,
        .nomatch {
            color: var(--muted);
            margin: 0 0 1rem
        }

        .nomatch {
            margin: 1.4rem 0 0
        }

        .nomatch[hidden] {
            display: none
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
            gap: 1rem
        }

        .dh h2 {
            font: 800 1.4rem/1.2 var(--head);
            margin: 0
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

        .plan {
            font: 700 1rem var(--head);
            color: var(--accent);
            margin: .2rem 0 0
        }

        .facts {
            display: flex;
            gap: .3rem 1.2rem;
            flex-wrap: wrap;
            font: 600 .95rem var(--head);
            color: var(--muted);
            margin: .6rem 0 1rem
        }

        .ex {
            border-top: 2px solid var(--ink);
            padding: .7rem 0 .4rem
        }

        .ex h3 {
            font: 750 1.05rem var(--head);
            margin: 0 0 .3rem;
            overflow-wrap: anywhere
        }

        .sl {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            padding: .4rem 0;
            border-bottom: 1px solid var(--rule);
            font-variant-numeric: tabular-nums
        }

        .sl span:first-child {
            font: 600 .95rem var(--head);
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

        .exn {
            color: var(--muted);
            font-size: 1rem;
            margin: .4rem 0 0
        }

        .da {
            display: flex;
            gap: .6rem;
            flex-wrap: wrap;
            justify-content: flex-end;
            margin-top: 1.2rem
        }

        footer {
            padding: 1rem 0 3rem
        }

        @media (max-width:640px) {
            .figs {
                grid-template-columns: 1fr 1fr
            }

            .figs dd {
                font-size: 1.5rem
            }

            .row {
                grid-template-columns: 1fr auto
            }

            .row .d {
                grid-column: 1/-1;
                color: var(--muted)
            }

            .acts {
                grid-column: 1/-1
            }

            .acts button {
                flex: 1
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
                <a href="nutrition.php">Nutrition</a>
                <a href="profile.php">Profile</a>
                <a href="friends.php">Friends</a>
                <a href="api/logout.php">Log out</a>
            </nav>
        </header>

        <main>
            <div class="top">
                <div>
                    <h1>My workouts</h1>
                    <p class="lede">Every session you've logged, week by week.</p>
                </div>
                <a class="btn" href="log-workout.php">Log workout</a>
            </div>

            <div class="msg err" id="pageMessage" role="alert" hidden></div>

            <?php if (empty($workouts)): ?>
                <div class="empty">
                    <h2>No workouts logged yet</h2>
                    <p>Log your first session and it will show up here, grouped by week.</p>
                    <a class="btn" href="log-workout.php">Log your first workout</a>
                </div>
            <?php else: ?>
                <dl class="figs" aria-live="polite">
                    <div>
                        <dt>Sessions</dt>
                        <dd id="statSessions"><?php echo $totalSessions; ?></dd>
                    </div>
                    <div>
                        <dt>Exercises</dt>
                        <dd id="statExercises"><?php echo $totalExercises; ?></dd>
                    </div>
                    <div>
                        <dt>Working sets</dt>
                        <dd id="statSets"><?php echo $totalSets; ?></dd>
                    </div>
                    <div>
                        <dt>Volume</dt>
                        <dd id="statVolume"><?php echo number_format($totalVolume); ?> kg</dd>
                    </div>
                </dl>

                <div class="filter">
                    <div class="field">
                        <label for="planFilter">Show</label>
                        <select id="planFilter">
                            <option value="__all__">All plans</option>
                            <?php foreach ($planFilterOptions as $value => $label): ?>
                                <option value="<?php echo gt_e($value); ?>"><?php echo gt_e($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <span id="filterCount" aria-live="polite"><?php echo $totalSessions; ?>
                        session<?php echo $totalSessions === 1 ? '' : 's'; ?></span>
                </div>

                <p class="nomatch" id="noMatchNote" hidden>No sessions use that plan. Pick another plan or show all plans.
                </p>

                <?php foreach ($workoutsByWeek as $weekKey => $weekData):
                    $startDate = new DateTime($weekData['startDate']);
                    $endDate = new DateTime($weekData['endDate']);
                    $n = count($weekData['workouts']);
                    $mins = array_sum(array_map(fn($w) => (int) $w['duration_minutes'], $weekData['workouts'])); ?>
                    <section class="week" id="week-<?php echo gt_e($weekKey); ?>"
                        aria-label="Week <?php echo gt_e($weekData['week']); ?>">
                        <div class="wh">
                            <h2>Week <?php echo gt_e($weekData['week']); ?>, <?php echo gt_e($startDate->format('M j')); ?> to
                                <?php echo gt_e($endDate->format('M j, Y')); ?></h2>
                            <span class="wsum" data-total="<?php echo $n; ?>"><?php echo $n; ?>
                                session<?php echo $n === 1 ? '' : 's'; ?><?php echo $mins ? ', ' . $mins . ' min' : ''; ?></span>
                        </div>
                        <?php foreach ($weekData['workouts'] as $workout):
                            $m = $modalData[$workout['id']]; ?>
                            <div class="row" data-id="<?php echo (int) $workout['id']; ?>"
                                data-plan="<?php echo gt_e($m['plan_key']); ?>">
                                <div class="d"><?php echo gt_e(date('D, M j', strtotime($workout['session_date']))); ?></div>
                                <div>
                                    <div class="p"><?php echo gt_e($workout['plan_name'] ?: 'Workout'); ?></div>
                                    <div class="s"><?php echo count($m['exercises']); ?>
                                        exercise<?php echo count($m['exercises']) === 1 ? '' : 's'; ?><?php echo $workout['duration_minutes'] ? ', ' . (int) $workout['duration_minutes'] . ' min' : ''; ?><?php echo $workout['mood'] ? ', felt ' . gt_e($workout['mood']) : ''; ?>
                                    </div>
                                </div>
                                <div class="acts">
                                    <button type="button" class="view" data-id="<?php echo (int) $workout['id']; ?>">View</button>
                                    <button type="button" class="edit" data-id="<?php echo (int) $workout['id']; ?>">Edit</button>
                                    <button type="button" class="del" data-id="<?php echo (int) $workout['id']; ?>">Delete</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </section>
                <?php endforeach; ?>
            <?php endif; ?>
        </main>
        <footer></footer>
    </div>

    <dialog id="detailDialog" aria-labelledby="dlgDate">
        <div class="dh">
            <div>
                <h2 id="dlgDate"></h2>
                <p class="plan" id="dlgPlan"></p>
            </div>
            <button type="button" class="x" id="dlgClose" aria-label="Close">&times;</button>
        </div>
        <div class="facts" id="dlgFacts"></div>
        <div id="dlgExercises"></div>
        <div class="da">
            <button type="button" class="btn danger" id="dlgDelete">Delete workout</button>
            <button type="button" class="btn" id="dlgEdit">Edit workout</button>
        </div>
    </dialog>

    <script>
        const WORKOUTS_DATA = <?php echo json_encode($modalData); ?>;
        const $ = id => document.getElementById(id);
        function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }
        function plural(n, w) { return n + ' ' + w + (n === 1 ? '' : 's'); }
        function showError(text) { const m = $('pageMessage'); m.textContent = text; m.hidden = false; window.scrollTo({ top: 0, behavior: 'smooth' }); }

        // ---------- Filter and summary (summary always matches what is shown) ----------
        const planFilter = $('planFilter');
        function applyFilter() {
            if (!planFilter) return;
            const value = planFilter.value;
            let sessions = 0, exercises = 0, sets = 0, volume = 0;

            document.querySelectorAll('.row').forEach(row => {
                const show = value === '__all__' || row.dataset.plan === value;
                row.hidden = !show;
                row.style.display = show ? '' : 'none';
                const d = WORKOUTS_DATA[row.dataset.id];
                if (show && d) { sessions++; exercises += d.exercises.length; sets += d.sets; volume += d.volume; }
            });
            document.querySelectorAll('.week').forEach(w => {
                const any = [...w.querySelectorAll('.row')].some(r => r.style.display !== 'none');
                w.style.display = any ? '' : 'none';
                let n = 0, mins = 0;
                w.querySelectorAll('.row').forEach(r => { if (r.style.display !== 'none') { n++; mins += parseInt((WORKOUTS_DATA[r.dataset.id] || {}).duration, 10) || 0; } });
                const sum = w.querySelector('.wsum');
                if (sum) sum.textContent = plural(n, 'session') + (mins ? ', ' + mins + ' min' : '');
            });
            $('statSessions').textContent = sessions;
            $('statExercises').textContent = exercises;
            $('statSets').textContent = sets;
            $('statVolume').textContent = Math.round(volume).toLocaleString() + ' kg';
            $('filterCount').textContent = plural(sessions, 'session');
            $('noMatchNote').hidden = sessions !== 0;
        }
        if (planFilter) planFilter.addEventListener('change', applyFilter);

        // ---------- Detail dialog ----------
        const dlg = $('detailDialog');
        let activeId = null;

        function openDetail(id) {
            const d = WORKOUTS_DATA[id];
            if (!d) return;
            activeId = id;
            $('dlgDate').textContent = d.date;
            $('dlgPlan').textContent = d.plan || '';
            $('dlgPlan').hidden = !d.plan;

            const facts = [plural(d.exercises.length, 'exercise'), plural(d.sets, 'working set'), d.volume.toLocaleString() + ' kg volume'];
            if (d.duration) facts.push(d.duration + ' min');
            if (d.mood) facts.push('Felt ' + d.mood);
            $('dlgFacts').innerHTML = facts.map(f => '<span>' + esc(f) + '</span>').join('');

            $('dlgExercises').innerHTML = d.exercises.map(ex => {
                let n = 0;
                const lines = ex.sets.map(s => {
                    const label = s.is_warmup ? 'Warm-up' : 'Set ' + (++n);
                    return '<div class="sl' + (s.is_warmup ? ' wu' : '') + '"><span>' + label + '</span><span>' + esc(s.weight) + ' kg x ' + esc(s.reps) + '</span></div>';
                }).join('');
                return '<div class="ex"><h3>' + esc(ex.name) + '</h3>' + lines + (ex.notes ? '<p class="exn">' + esc(ex.notes) + '</p>' : '') + '</div>';
            }).join('') || '<p class="exn">No exercises were recorded for this session.</p>';
            if (d.notes) $('dlgExercises').insertAdjacentHTML('beforeend', '<p class="exn">' + esc(d.notes) + '</p>');

            dlg.showModal();
        }
        $('dlgClose') && $('dlgClose').addEventListener('click', () => dlg.close());
        dlg.addEventListener('click', e => { if (e.target === dlg) dlg.close(); });
        dlg.addEventListener('close', () => { activeId = null; });

        // ---------- Delete (removes the row in place) ----------
        function deleteWorkout(id) {
            if (!confirm('Delete this workout? This cannot be undone.')) return;
            fetch('api/delete-workout.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ workout_id: id })
            })
                .then(r => r.text())
                .then(t => { try { return JSON.parse(t); } catch (e) { throw new Error('bad response'); } })
                .then(data => {
                    if (!data.success) { showError(data.error || 'The workout could not be deleted.'); return; }
                    delete WORKOUTS_DATA[id];
                    if (dlg.open) dlg.close();
                    const row = document.querySelector('.row[data-id="' + id + '"]');
                    if (!row) return;
                    const week = row.closest('.week');
                    row.classList.add('gone');
                    setTimeout(() => {
                        row.remove();
                        if (week && !week.querySelector('.row')) week.remove();
                        if (!document.querySelector('.row')) { location.reload(); return; }
                        applyFilter();
                    }, 220);
                })
                .catch(() => showError('Could not reach the server, or it sent back something unexpected. Nothing was deleted.'));
        }

        // ---------- Row buttons ----------
        document.addEventListener('click', e => {
            const b = e.target.closest('button[data-id]');
            if (!b) return;
            if (b.classList.contains('view')) openDetail(b.dataset.id);
            else if (b.classList.contains('edit')) location.href = 'log-workout.php?workout_id=' + encodeURIComponent(b.dataset.id);
            else if (b.classList.contains('del')) deleteWorkout(b.dataset.id);
        });
        $('dlgEdit').addEventListener('click', () => { if (activeId) location.href = 'log-workout.php?workout_id=' + encodeURIComponent(activeId); });
        $('dlgDelete').addEventListener('click', () => { if (activeId) deleteWorkout(activeId); });
    </script>
</body>

</html>