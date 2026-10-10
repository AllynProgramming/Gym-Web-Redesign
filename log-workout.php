<?php
// log-workout.php
// Form to log (or edit) a workout session. Each exercise gets its own sheet with as
// many set rows as needed (weight + reps can differ set to set, and a set can be a warm-up).

require_once __DIR__ . '/api/includes/db.php';
require_once __DIR__ . '/api/includes/auth.php';

requireLogin();

$userId = getUserId();
$user = getUserInfo($conn, $userId);

// Distinct plan names this user has used before, so they can quickly reuse a custom split
$stmt = $conn->prepare("SELECT DISTINCT plan_name FROM workout_plans WHERE user_id = ? ORDER BY plan_name");
$stmt->bind_param("i", $userId);
$stmt->execute();
$planNames = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$commonSplits = ['Upper Day', 'Lower Day', 'Push Day', 'Pull Day', 'Leg Day', 'Full Body', 'Rest / Recovery'];

$customPlanNames = array_filter($planNames, function ($p) use ($commonSplits) {
    return !in_array($p['plan_name'], $commonSplits, true);
});

// Default to the browser's date on a new log, but keep an explicit query-date or edit-mode value.
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

// Exercise name suggestions: the user's own history first, topped up with common lifts.
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
    'Barbell Squat',
    'Deadlift',
    'Bench Press',
    'Incline Bench Press',
    'Overhead Press',
    'Barbell Row',
    'Pull-Up',
    'Lat Pulldown',
    'Leg Press',
    'Romanian Deadlift',
    'Bulgarian Split Squat',
    'Hip Thrust',
    'Bicep Curl',
    'Tricep Pushdown',
    'Lateral Raise',
    'Dumbbell Shoulder Press',
    'Cable Row',
    'Chest Fly',
    'Leg Curl',
    'Leg Extension',
    'Calf Raise',
    'Plank',
    'Hanging Leg Raise',
    'Face Pull',
    'Hip Abduction',
];

$exerciseSuggestions = array_unique(array_merge($loggedExerciseNames, $commonExercises));
sort($exerciseSuggestions, SORT_NATURAL | SORT_FLAG_CASE);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <script src="assets/theme.js"></script>
    <script src="assets/units.js"></script>
    <title><?php echo $editWorkoutId ? 'Edit workout' : 'Log workout'; ?> | GymTrack</title>
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
            padding: 2.2rem 0 1rem
        }

        h1 {
            font: 850 clamp(2.1rem, 6vw, 3.4rem)/1 var(--head);
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
            padding: .8rem 1.4rem;
            border: 0;
            border-radius: 6px;
            text-decoration: none;
            cursor: pointer;
            min-height: 2.9rem
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
            margin: 1rem 0;
            padding: .8rem 1rem;
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

        .sec {
            padding: 1.8rem 0;
            border-top: 1px solid var(--rule)
        }

        .sec>h2 {
            font: 750 1.2rem var(--head);
            margin: 0 0 1rem
        }

        .grid {
            display: grid;
            grid-template-columns: 1.4fr 1fr;
            gap: 1rem
        }

        .g3 {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin-top: 1rem
        }

        .field {
            display: grid;
            gap: .3rem;
            align-content: start
        }

        label,
        .cap {
            font: 700 .85rem var(--head)
        }

        input,
        select {
            font: 400 1.1rem var(--body);
            color: var(--ink);
            background: var(--surface);
            border: 2px solid var(--rule);
            border-radius: 4px;
            padding: .65rem .75rem;
            width: 100%;
            min-width: 0;
            min-height: 2.9rem
        }

        input:focus,
        select:focus {
            outline: none;
            border-color: var(--accent)
        }

        input:focus-visible,
        select:focus-visible {
            outline: 3px solid var(--accent);
            outline-offset: 1px
        }

        input[type=checkbox] {
            width: 1.35rem;
            height: 1.35rem;
            min-height: 0;
            padding: 0;
            accent-color: var(--accent)
        }

        .hint {
            color: var(--muted);
            font-size: .95rem;
            margin: .3rem 0 0
        }

        .ex {
            border-top: 2px solid var(--ink);
            padding: .9rem 0 1.4rem;
            margin-bottom: .4rem
        }

        .exhead {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: .6rem;
            font: 700 .9rem var(--head);
            color: var(--muted)
        }

        .sets {
            margin: .8rem 0 .6rem
        }

        .cols,
        .set {
            display: grid;
            grid-template-columns: 4.6rem 1fr 1fr 4.2rem 2.4rem;
            gap: .5rem;
            align-items: center
        }

        .cols {
            font: 600 .8rem var(--head);
            color: var(--muted);
            padding-bottom: .3rem;
            border-bottom: 1px solid var(--ink)
        }

        .set {
            padding: .4rem 0;
            border-bottom: 1px solid var(--rule)
        }

        .set .lbl {
            font: 700 .95rem var(--head)
        }

        .set.wu .lbl {
            background: var(--yellow);
            color: #1D2024;
            border-radius: 20px;
            padding: .1rem .5rem;
            font-size: .8rem;
            text-align: center;
            justify-self: start
        }

        .set.wu input.w,
        .set.wu input.r {
            color: var(--muted)
        }

        .set .wu {
            justify-self: center
        }

        .x,
        .rmex {
            border: 0;
            background: transparent;
            color: var(--muted);
            font: 700 1.3rem/1 var(--head);
            width: 2.4rem;
            height: 2.4rem;
            border-radius: 6px;
            cursor: pointer
        }

        .x:hover,
        .rmex:hover {
            color: var(--err);
            box-shadow: inset 0 0 0 1.5px var(--err)
        }

        .x:disabled,
        .rmex:disabled {
            opacity: .3;
            cursor: not-allowed;
            box-shadow: none;
            color: var(--muted)
        }

        .addset,
        .addex {
            width: 100%;
            background: transparent;
            color: var(--ink);
            font: 700 .95rem var(--head);
            border: 2px dashed var(--rule);
            border-radius: 6px;
            min-height: 2.8rem;
            cursor: pointer
        }

        .addset:hover,
        .addex:hover {
            border-color: var(--ink)
        }

        .addex {
            margin-top: .6rem
        }

        .figs {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem 1.5rem;
            margin: 0
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
            font: 800 1.7rem/1.15 var(--head);
            font-stretch: 112%;
            font-variant-numeric: tabular-nums
        }

        .save {
            display: flex;
            gap: .7rem;
            justify-content: flex-end;
            padding: 1.6rem 0 3rem;
            flex-wrap: wrap
        }

        dialog {
            border: 0;
            border-radius: 10px;
            padding: 1.4rem;
            max-width: 24rem;
            width: calc(100% - 2rem);
            background: var(--surface);
            color: var(--ink);
            box-shadow: 0 0 0 1px var(--rule), 0 20px 50px rgba(0, 0, 0, .3)
        }

        dialog::backdrop {
            background: rgba(0, 0, 0, .5)
        }

        dialog h2 {
            font: 750 1.2rem var(--head);
            margin: 0 0 .4rem
        }

        dialog p {
            margin: 0 0 1rem;
            color: var(--muted)
        }

        dialog .row {
            display: flex;
            gap: .7rem;
            margin-top: 1.1rem;
            justify-content: flex-end
        }

        .sh {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: .8rem 1rem;
            flex-wrap: wrap;
            margin-bottom: .4rem
        }

        .sh h2 {
            font: 750 1.2rem var(--head);
            margin: 0
        }

        .hidden {
            display: none
        }

        @media (max-width:640px) {
            .grid {
                grid-template-columns: 1fr
            }

            .g3 {
                grid-template-columns: 1fr 1fr
            }

            .g3 .field:last-child {
                grid-column: 1/-1
            }

            .figs dd {
                font-size: 1.3rem
            }

            .cols,
            .set {
                grid-template-columns: 3.6rem 1fr 1fr 3.4rem 2.2rem;
                gap: .35rem
            }

            .save .btn {
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
            <?php $activePage = 'log';
            include __DIR__ . '/api/includes/nav.php'; ?>
        </header>

        <main>
            <div class="top">
                <div>
                    <h1><?php echo $editWorkoutId ? 'Edit workout' : 'Log a workout'; ?></h1>
                    <p class="lede">One sheet per exercise. Add every set you did and tick warm-ups so they stay out of
                        your records.</p>
                </div>
                <button type="button" id="duplicateLastBtn" class="btn alt">Copy last workout</button>
            </div>

            <div class="msg" id="formMessage" role="status" aria-live="polite" hidden></div>

            <form id="workoutForm">
                <section class="sec">
                    <h2>Session</h2>
                    <div class="grid">
                        <div class="field">
                            <label for="plan_select">Workout plan</label>
                            <select id="plan_select">
                                <option value="">No plan</option>
                                <optgroup label="Common splits">
                                    <?php foreach ($commonSplits as $split): ?>
                                        <option value="<?php echo htmlspecialchars($split); ?>">
                                            <?php echo htmlspecialchars($split); ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <?php if (!empty($customPlanNames)): ?>
                                    <optgroup label="Your plans">
                                        <?php foreach ($customPlanNames as $p): ?>
                                            <option value="<?php echo htmlspecialchars($p['plan_name']); ?>">
                                                <?php echo htmlspecialchars($p['plan_name']); ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endif; ?>
                                <option value="__custom__">Custom split</option>
                            </select>
                            <input type="text" id="plan_name" name="plan_name" class="hidden"
                                placeholder="Chest and legs" aria-label="Custom plan name">
                        </div>
                        <div class="field">
                            <label for="session_date">Date</label>
                            <input type="date" id="session_date" name="session_date"
                                value="<?php echo htmlspecialchars($selectedSessionDate); ?>" required>
                        </div>
                    </div>
                    <div class="g3">
                        <div class="field"><label for="start_time">Start time</label><input type="time" id="start_time">
                        </div>
                        <div class="field"><label for="end_time">End time</label><input type="time" id="end_time"></div>
                        <div class="field"><label for="duration_minutes">Duration (minutes)</label><input type="number"
                                id="duration_minutes" name="duration_minutes" min="0" step="1" inputmode="numeric"
                                placeholder="60"></div>
                    </div>
                    <p class="hint">Enter start and end times and the duration fills itself in, or type the minutes
                        yourself.</p>
                </section>

                <section class="sec">
                    <div class="sh">
                        <h2>Exercises</h2>
                        <div id="unitSwitch"></div>
                    </div>
                    <p class="hint" style="margin:0 0 .8rem">Type weights in the unit you picked. They are saved in
                        kilograms, so you can switch any time and your numbers convert.</p>
                    <div id="exerciseList"></div>
                    <button type="button" class="addex" id="addExerciseBtn">Add exercise</button>
                </section>

                <section class="sec" aria-live="polite">
                    <h2>This session so far</h2>
                    <dl class="figs">
                        <div>
                            <dt>Working sets</dt>
                            <dd id="sumSets">0</dd>
                        </div>
                        <div>
                            <dt>Volume</dt>
                            <dd id="sumVol">0 kg</dd>
                        </div>
                        <div>
                            <dt>Heaviest set</dt>
                            <dd id="sumTop">None yet</dd>
                        </div>
                    </dl>
                    <p class="hint">Warm-up sets are not counted here.</p>
                </section>

                <div class="save">
                    <a class="btn alt" href="dashboard.php">Cancel</a>
                    <button type="submit" class="btn"
                        id="saveBtn"><?php echo $editWorkoutId ? 'Update workout' : 'Save workout'; ?></button>
                </div>
            </form>
        </main>
    </div>

    <datalist id="exerciseNames">
        <?php foreach ($exerciseSuggestions as $name): ?>
            <option value="<?php echo htmlspecialchars($name); ?>">
            <?php endforeach; ?>
    </datalist>

    <dialog id="adjustDialog" aria-labelledby="adjTitle">
        <h2 id="adjTitle">Workout saved. Change the weights for next time?</h2>
        <p>Raise or lower every weight in this workout and edit it before your next session. Use a negative number to
            lower.</p>
        <div class="field"><label for="adjustAmount" id="adjustLabel">Change by (kg)</label><input type="number"
                id="adjustAmount" value="2.5" step="0.5"></div>
        <div class="row">
            <button type="button" class="btn alt" id="adjustSkip">Skip</button>
            <button type="button" class="btn" id="adjustApply">Apply and edit</button>
        </div>
    </dialog>

    <script>
        const userId = <?php echo json_encode($userId); ?>;
        const workoutId = <?php echo json_encode($editWorkoutId ?: null); ?>;
        const editWorkoutData = <?php echo json_encode($editWorkoutData ?: null); ?>;
        const hasExplicitSessionDate = <?php echo json_encode($hasExplicitSessionDate); ?>;

        const $ = id => document.getElementById(id);
        const list = $('exerciseList');
        const planSelect = $('plan_select');
        const planNameInput = $('plan_name');
        const saveBtn = $('saveBtn');
        const msgEl = $('formMessage');

        // ---------- Units: weights are stored in kg; the unit only changes what you type and see ----------
        const U = GT.units;
        const unit = () => U.get();
        const r2 = n => Math.round(n * 100) / 100;
        function setWeight(inp, kg) {
            const n = parseFloat(kg);
            if (isNaN(n)) { inp.dataset.kg = ''; inp.value = ''; return; }
            inp.dataset.kg = r2(n);
            inp.value = U.fmt(inp.dataset.kg);
        }
        function weightKg(inp) { // canonical kg of one weight box, or null when empty
            if (inp.value.trim() === '') return null;
            if (inp.dataset.kg !== undefined && inp.dataset.kg !== '') return parseFloat(inp.dataset.kg);
            const v = parseFloat(inp.value);
            return isNaN(v) ? null : r2(U.toKg(v));
        }
        function syncUnitUI() {
            const u = unit();
            list.querySelectorAll('.wlab').forEach(el => { el.textContent = 'Weight (' + u + ')'; });
            list.querySelectorAll('.w').forEach(inp => {
                inp.setAttribute('aria-label', 'Weight in ' + (u === 'lb' ? 'pounds' : 'kilograms'));
                const k = weightKg(inp);
                if (k !== null) inp.value = U.fmt(k);
            });
            $('adjustLabel').textContent = 'Change by (' + u + ')';
            $('adjustAmount').value = u === 'lb' ? 5 : 2.5;
            updateSummary();
        }
        document.addEventListener('unitchange', syncUnitUI);
        U.control($('unitSwitch'));
        syncUnitUI();


        function today() {
            const d = new Date();
            return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
        }
        function showMsg(text, kind, link) {
            msgEl.textContent = text;
            msgEl.className = 'msg' + (kind === 'err' ? ' err' : '');
            if (link) { msgEl.append(' '); const a = document.createElement('a'); a.href = link.href; a.textContent = link.text; msgEl.append(a); }
            msgEl.hidden = false;
        }
        function hideMsg() { msgEl.hidden = true; }

        if (!editWorkoutData && !hasExplicitSessionDate) $('session_date').value = today();

        // ---------- Plan select <-> custom plan field ----------
        planSelect.addEventListener('change', function () {
            if (this.value === '__custom__') {
                planNameInput.value = ''; planNameInput.classList.remove('hidden'); planNameInput.required = true; planNameInput.focus();
            } else {
                planNameInput.value = this.value; planNameInput.classList.add('hidden'); planNameInput.required = false;
            }
        });
        function setPlan(name) {
            const exists = name && [...planSelect.options].some(o => o.value === name);
            planSelect.value = !name ? '' : (exists ? name : '__custom__');
            planNameInput.value = name || '';
            const custom = planSelect.value === '__custom__';
            planNameInput.classList.toggle('hidden', !custom);
            planNameInput.required = custom;
        }

        // ---------- Duration from start/end ----------
        function calcDuration() {
            const s = $('start_time').value, e = $('end_time').value;
            if (!s || !e) return;
            const [a, b] = s.split(':').map(Number), [c, d] = e.split(':').map(Number);
            let m = (c * 60 + d) - (a * 60 + b);
            if (m <= 0) m += 1440; // crossed midnight
            $('duration_minutes').value = m;
        }
        $('start_time').addEventListener('change', calcDuration);
        $('end_time').addEventListener('change', calcDuration);

        // ---------- Exercise sheets and set rows ----------
        function setRow() {
            const row = document.createElement('div');
            row.className = 'set';
            row.innerHTML = '<span class="lbl"></span>'
                + '<input class="w" type="number" inputmode="decimal" step="any" min="0" required aria-label="Weight in kilograms">'
                + '<input class="r" type="number" inputmode="numeric" step="1" min="1" required aria-label="Reps">'
                + '<input class="wu" type="checkbox" aria-label="Warm-up set">'
                + '<button type="button" class="x" aria-label="Remove set">\u00d7</button>';
            return row;
        }
        function renumberSets(card) {
            const rows = card.querySelectorAll('.set');
            let n = 0;
            rows.forEach(r => {
                const wu = r.querySelector('.wu').checked;
                r.classList.toggle('wu', wu);
                r.querySelector('.lbl').textContent = wu ? 'Warm-up' : 'Set ' + (++n);
                r.querySelector('.x').disabled = rows.length <= 1;
            });
        }
        function renumberExercises() {
            const cards = list.querySelectorAll('.ex');
            cards.forEach((c, i) => {
                c.querySelector('.exn').textContent = 'Exercise ' + (i + 1);
                c.querySelector('.rmex').disabled = cards.length <= 1;
            });
        }
        // Set 1 (the first non-warm-up row) is what a new set copies from
        function copySource(card) {
            for (const r of card.querySelectorAll('.set')) {
                if (r.querySelector('.wu').checked) continue;
                const k = weightKg(r.querySelector('.w')), rp = r.querySelector('.r').value;
                return (k !== null && rp !== '') ? { weight: k, reps: rp } : null;
            }
            return null;
        }
        function addSet(card, s, focus) {
            const row = setRow();
            card.querySelector('.sets').appendChild(row);
            if (s) {
                setWeight(row.querySelector('.w'), s.weight);
                row.querySelector('.r').value = s.reps;
                row.querySelector('.wu').checked = !!(s.is_warmup && s.is_warmup !== '0');
            }
            renumberSets(card);
            if (focus) { const w = row.querySelector('.w'); w.focus(); w.select(); }
        }
        function addExercise(task, focus) {
            const card = document.createElement('div');
            card.className = 'ex';
            card.innerHTML = '<div class="exhead"><span class="exn"></span><button type="button" class="rmex" aria-label="Remove exercise">\u00d7</button></div>'
                + '<div class="field"><label>Exercise name</label><input type="text" class="nm" list="exerciseNames" placeholder="Incline bench press" required></div>'
                + '<div class="sets"><div class="cols" aria-hidden="true"><span>Set</span><span class="wlab">Weight (' + unit() + ')</span><span>Reps</span><span>Warm-up</span><span></span></div></div>'
                + '<button type="button" class="addset">Add set</button>'
                + '<div class="field" style="margin-top:.9rem"><label>Notes (optional)</label><input type="text" class="nt" placeholder="Felt strong today"></div>';
            list.appendChild(card);
            if (task) {
                card.querySelector('.nm').value = task.name || '';
                card.querySelector('.nt').value = task.notes || '';
                (task.sets && task.sets.length ? task.sets : [null]).forEach(s => addSet(card, s, false));
            } else {
                addSet(card, null, false);
            }
            renumberExercises();
            if (focus) card.querySelector('.nm').focus();
            updateSummary();
        }
        function populate(data) {
            if (data.session_date) $('session_date').value = data.session_date;
            setPlan(data.plan_name || '');
            $('duration_minutes').value = data.duration_minutes ? data.duration_minutes : '';
            list.innerHTML = '';
            (data.exercises && data.exercises.length ? data.exercises : [null]).forEach(t => addExercise(t, false));
            updateSummary();
        }

        list.addEventListener('click', e => {
            const rmEx = e.target.closest('.rmex');
            if (rmEx && !rmEx.disabled) { rmEx.closest('.ex').remove(); renumberExercises(); updateSummary(); return; }
            const add = e.target.closest('.addset');
            if (add) { const card = add.closest('.ex'); addSet(card, copySource(card), true); updateSummary(); return; }
            const rm = e.target.closest('.x');
            if (rm && !rm.disabled) { const card = rm.closest('.ex'); rm.closest('.set').remove(); renumberSets(card); updateSummary(); }
        });
        list.addEventListener('change', e => {
            if (e.target.classList.contains('wu')) { renumberSets(e.target.closest('.ex')); updateSummary(); }
        });
        list.addEventListener('input', e => {
            if (e.target.classList.contains('w')) { const v = parseFloat(e.target.value); e.target.dataset.kg = isNaN(v) ? '' : r2(U.toKg(v)); }
            updateSummary();
        });
        $('addExerciseBtn').addEventListener('click', () => addExercise(null, true));

        // ---------- Summary (working sets only) ----------
        function updateSummary() {
            let sets = 0, vol = 0, top = null; // vol and top.w are in kg
            list.querySelectorAll('.set').forEach(r => {
                if (r.querySelector('.wu').checked) return;
                const w = weightKg(r.querySelector('.w')), rp = parseInt(r.querySelector('.r').value, 10);
                if (w === null || isNaN(rp)) return;
                sets++; vol += w * rp;
                if (!top || w > top.w || (w === top.w && rp > top.r)) top = { w, r: rp };
            });
            const u = unit();
            $('sumSets').textContent = sets;
            $('sumVol').textContent = Math.round(U.fromKg(vol)).toLocaleString() + ' ' + u;
            $('sumTop').textContent = top ? U.fmt(top.w) + ' ' + u + ' x ' + top.r : 'None yet';
        }

        // ---------- Payload (same shape the api files already expect) ----------
        function buildPayload() {
            const exercises = [];
            list.querySelectorAll('.ex').forEach(card => {
                const name = card.querySelector('.nm').value.trim();
                const notes = card.querySelector('.nt').value.trim();
                const sets = [];
                card.querySelectorAll('.set').forEach(row => {
                    const wk = weightKg(row.querySelector('.w')), reps = row.querySelector('.r').value;
                    const weight = wk === null ? '' : String(wk); // always kilograms
                    if (weight === '' && reps === '') return;
                    sets.push({ weight, reps, is_warmup: row.querySelector('.wu').checked });
                });
                if (name === '' && sets.length === 0) return;
                exercises.push({ name, notes, sets });
            });
            const dv = $('duration_minutes').value;
            return {
                workout_id: workoutId,
                plan_name: planNameInput.value,
                session_date: $('session_date').value,
                duration_minutes: dv === '' ? null : parseInt(dv, 10),
                exercises,
            };
        }

        // ---------- Draft auto-save (new workouts only) ----------
        const STORAGE_KEY = 'gym-workout-draft-' + userId;
        let draftTimer;
        function saveDraft() {
            if (editWorkoutData) return;
            try { localStorage.setItem(STORAGE_KEY, JSON.stringify(buildPayload())); } catch (e) { }
        }
        function clearDraft() { try { localStorage.removeItem(STORAGE_KEY); } catch (e) { } }
        function restoreDraft() {
            try {
                const saved = localStorage.getItem(STORAGE_KEY);
                if (!saved || editWorkoutData) return false;
                const data = JSON.parse(saved);
                if (!data.exercises || !data.exercises.length) return false;
                populate(data);
                showMsg('Restored your unsaved draft. Check the date before saving.', 'ok');
                return true;
            } catch (e) { return false; }
        }
        ['input', 'change'].forEach(ev => document.addEventListener(ev, () => { clearTimeout(draftTimer); draftTimer = setTimeout(saveDraft, 1000); }));

        // ---------- Copy last workout ----------
        $('duplicateLastBtn').addEventListener('click', async function () {
            const btn = this; btn.disabled = true; btn.textContent = 'Loading';
            try {
                const res = await fetch('api/get-last-workout.php');
                const data = await res.json();
                if (data.success) {
                    populate(data);
                    // A copy is a NEW session: date it today and clear the old session's times
                    $('session_date').value = today();
                    $('start_time').value = ''; $('end_time').value = ''; $('duration_minutes').value = '';
                    updateSummary();
                    showMsg('Last workout copied and dated today. Adjust the weights and save.', 'ok');
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                } else {
                    showMsg(data.error || 'No earlier workout to copy yet.', 'err');
                }
            } catch (e) {
                showMsg('Could not load your last workout. Check your connection and try again.', 'err');
            } finally { btn.disabled = false; btn.textContent = 'Copy last workout'; }
        });

        // ---------- Reset after a save ----------
        function resetForm() {
            $('session_date').value = today();
            setPlan('');
            $('start_time').value = ''; $('end_time').value = ''; $('duration_minutes').value = '';
            list.innerHTML = '';
            addExercise(null, false);
            updateSummary();
        }

        // ---------- Weight adjust dialog ----------
        const dlg = $('adjustDialog');
        let lastSessionId = null;
        $('adjustSkip').addEventListener('click', () => { dlg.close(); resetForm(); });
        dlg.addEventListener('cancel', () => resetForm());
        $('adjustApply').addEventListener('click', () => {
            const amt = parseFloat($('adjustAmount').value);
            if (isNaN(amt) || amt === 0) { $('adjustAmount').focus(); return; }
            const amtKg = r2(U.toKg(amt)); // the edit page always works in kilograms
            location.href = 'log-workout.php?workout_id=' + encodeURIComponent(lastSessionId) + '&adjust=' + encodeURIComponent(amtKg);
        });

        // ---------- Submit ----------
        $('workoutForm').addEventListener('submit', function (e) {
            e.preventDefault();
            hideMsg();
            const payload = buildPayload();
            if (!payload.exercises.length) { showMsg('Add at least one exercise with a set before saving.', 'err'); return; }
            saveBtn.disabled = true; saveBtn.textContent = 'Saving';

            fetch(workoutId ? 'api/update-workout.php' : 'api/add-workout.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
                .then(res => res.text().then(t => { try { return JSON.parse(t); } catch (err) { throw new Error('bad response: ' + t.slice(0, 200)); } }))
                .then(data => {
                    if (data.success) {
                        clearTimeout(draftTimer);
                        clearDraft();
                        if (workoutId) {
                            showMsg('Workout updated.', 'ok', { href: 'workouts.php', text: 'View it in My workouts' });
                        } else {
                            showMsg('Workout saved.', 'ok', { href: 'workouts.php', text: 'View my workouts' });
                            if (data.sessionId) { lastSessionId = data.sessionId; dlg.showModal(); } else { resetForm(); }
                        }
                    } else {
                        showMsg(data.error || 'The workout could not be saved. Check the details and try again.', 'err');
                    }
                })
                .catch(err => {
                    console.error(err);
                    showMsg('The server sent back something unexpected, so nothing was saved. Open the browser console for details.', 'err');
                })
                .finally(() => {
                    saveBtn.disabled = false; saveBtn.textContent = workoutId ? 'Update workout' : 'Save workout';
                });
        });

        // ---------- Start up ----------
        if (editWorkoutData) populate(editWorkoutData);
        else if (!restoreDraft()) addExercise(null, false);

        // Weight adjust coming back from the dialog
        const adj = parseFloat(new URLSearchParams(location.search).get('adjust'));
        if (!isNaN(adj) && adj !== 0 && editWorkoutData) {
            list.querySelectorAll('.set .w').forEach(inp => {
                const k = weightKg(inp) || 0;
                setWeight(inp, Math.max(0, r2(k + adj))); // adj arrives in kilograms
            });
            updateSummary();
            showMsg('All weights changed by ' + (adj > 0 ? '+' : '') + U.fmt(adj) + ' ' + unit() + '. Review them and click Update workout.', 'ok');
            history.replaceState({}, document.title, location.pathname + '?workout_id=' + workoutId);
        }
    </script>
</body>

</html>