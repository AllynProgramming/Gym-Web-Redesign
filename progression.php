<?php
// progression.php
// Shows a weight-over-time chart plus session history for one exercise at a time.

require_once __DIR__ . '/api/includes/db.php';
require_once __DIR__ . '/api/includes/auth.php';

requireLogin();

$userId = getUserId();
$user = getUserInfo($conn, $userId);

// List of exercises this user has logged, most recently trained first
$stmt = $conn->prepare("
    SELECT e.exercise_name, MAX(ws.session_date) AS last_logged
    FROM exercises e
    JOIN workout_sessions ws ON e.session_id = ws.id
    WHERE ws.user_id = ?
    GROUP BY e.exercise_name
    ORDER BY last_logged DESC, e.exercise_name ASC
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$exerciseList = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$defaultExercise = $exerciseList[0]['exercise_name'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <script src="assets/theme.js"></script>
    <script src="assets/units.js"></script>
    <title>Progression | GymTrack</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@62..125,500..900&family=Newsreader:opsz,wght@6..72,400..600&display=swap"
        rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
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
            --red: #D3302B;
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
                --err: #FF8A80;
                --red: #E5524C;
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
            max-width: 1000px;
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
            max-width: 32rem
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

        .controls {
            display: grid;
            grid-template-columns: 1.4fr 1fr 1fr auto;
            gap: 1rem;
            padding: 1rem 0 1.6rem
        }

        .field {
            display: grid;
            gap: .3rem;
            align-content: start
        }

        label,
        .lbl {
            font: 700 .85rem var(--head)
        }

        select {
            font: 400 1.05rem var(--body);
            color: var(--ink);
            background: var(--surface);
            border: 2px solid var(--rule);
            border-radius: 4px;
            padding: .65rem .75rem;
            width: 100%;
            min-height: 2.9rem
        }

        select:focus-visible {
            outline: 3px solid var(--accent);
            outline-offset: 1px;
            border-color: var(--accent)
        }

        select:disabled {
            opacity: .55
        }

        .figs {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
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
            font: 800 2rem/1.15 var(--head);
            font-stretch: 112%;
            font-variant-numeric: tabular-nums
        }

        .figs dd.up {
            color: var(--green)
        }

        .figs dd.down {
            color: var(--err)
        }

        .hint {
            color: var(--muted);
            font-size: .95rem;
            margin: .6rem 0 0
        }

        .sec {
            padding: 2rem 0;
            border-top: 1px solid var(--rule);
            margin-top: 1.4rem
        }

        .sec>h2 {
            font: 750 1.2rem var(--head);
            margin: 0 0 1rem
        }

        .chart {
            position: relative;
            height: 300px
        }

        .scroll {
            overflow-x: auto
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-variant-numeric: tabular-nums;
            min-width: 30rem
        }

        th {
            text-align: left;
            font: 600 .85rem var(--head);
            color: var(--muted);
            padding: .4rem .8rem .4rem 0;
            border-bottom: 2px solid var(--ink)
        }

        td {
            padding: .65rem .8rem .65rem 0;
            border-bottom: 1px solid var(--rule);
            vertical-align: top
        }

        th.n,
        td.n {
            text-align: right
        }

        td.note {
            color: var(--muted);
            font-size: 1rem
        }

        tr.wu td {
            color: var(--muted)
        }

        .tag {
            font: 600 .75rem var(--head);
            padding: .15rem .5rem;
            border-radius: 20px;
            background: var(--yellow);
            color: #1D2024;
            margin-left: .5rem
        }

        .status {
            color: var(--muted);
            padding: 1rem 0
        }

        .status.err {
            color: var(--err)
        }

        .empty {
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

        .empty p {
            color: var(--muted);
            margin: 0 0 1rem
        }

        footer {
            padding: 1rem 0 3rem
        }

        @media (max-width:720px) {
            .controls {
                grid-template-columns: 1fr
            }

            .figs {
                grid-template-columns: 1fr 1fr
            }

            .figs dd {
                font-size: 1.6rem
            }

            .chart {
                height: 240px
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
                <h1>Progression</h1>
                <p class="lede">See whether the bar is moving for each exercise, across all your sessions or one week at
                    a time.</p>
            </div>

            <?php if (empty($exerciseList)): ?>
                <div class="empty">
                    <h2>Nothing to show yet</h2>
                    <p>Log a workout and your progress for each exercise will appear here.</p>
                    <a class="btn" href="log-workout.php">Log your first workout</a>
                </div>
            <?php else: ?>
                <div class="controls">
                    <div class="field">
                        <label for="exerciseSelect">Exercise</label>
                        <select id="exerciseSelect">
                            <?php foreach ($exerciseList as $ex): ?>
                                <option value="<?php echo htmlspecialchars($ex['exercise_name']); ?>">
                                    <?php echo htmlspecialchars($ex['exercise_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="primaryWeekSelect">Show</label>
                        <select id="primaryWeekSelect"></select>
                    </div>
                    <div class="field">
                        <label for="compareWeekSelect">Compare with</label>
                        <select id="compareWeekSelect">
                            <option value="">Nothing</option>
                        </select>
                    </div>
                    <div class="field">
                        <span class="lbl">Weight unit</span>
                        <div id="unitSwitch"></div>
                    </div>
                </div>

                <dl class="figs" aria-live="polite">
                    <div>
                        <dt>Personal best</dt>
                        <dd id="statBest">-</dd>
                    </div>
                    <div>
                        <dt>Latest session average</dt>
                        <dd id="statLatest">-</dd>
                    </div>
                    <div>
                        <dt>Sessions logged</dt>
                        <dd id="statSessions">-</dd>
                    </div>
                    <div>
                        <dt>Change since first session</dt>
                        <dd id="statDelta">-</dd>
                    </div>
                </dl>
                <p class="hint">Warm-up sets stay in your history but are left out of these numbers.</p>

                <section class="sec">
                    <h2 id="chartTitle">Average weight per session (kg)</h2>
                    <div class="chart"><canvas id="progressionChart" role="img"
                            aria-label="Average weight per session"></canvas></div>
                </section>

                <section class="sec">
                    <h2>Session history</h2>
                    <div id="entryList">
                        <p class="status">Loading</p>
                    </div>
                </section>
            <?php endif; ?>
        </main>
        <footer></footer>
    </div>

    <?php if (!empty($exerciseList)): ?>
        <script>
            const defaultExercise = <?php echo json_encode($defaultExercise); ?>;
            const $ = id => document.getElementById(id);
            const exerciseSelect = $('exerciseSelect'), primarySel = $('primaryWeekSelect'), compareSel = $('compareWeekSelect');
            const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
            // The database stores kg; these show it in the unit chosen with the kg / lb switch
            const U = GT.units;
            const unit = () => U.get();
            const disp = v => U.round(U.fromKg(v));
            const fmtW = v => U.fmt(v);
            let chartInstance = null, current = null;

            function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }
            function parseDate(iso) { return new Date(String(iso).slice(0, 10) + 'T00:00:00'); }
            function fmtDate(iso) { return parseDate(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric' }); }
            function cssVar(n) { return getComputedStyle(document.documentElement).getPropertyValue(n).trim(); }
            function dayIndex(iso) { return (parseDate(iso).getDay() + 6) % 7; } // Mon = 0

            function renderSummary(s) {
                const u = unit();
                $('statBest').textContent = fmtW(s.best) + ' ' + u;
                $('statLatest').textContent = fmtW(s.latest) + ' ' + u;
                $('statSessions').textContent = s.sessions;
                const d = $('statDelta'), shown = disp(Number(s.delta));
                d.textContent = (shown > 0 ? '+' : '') + fmtW(s.delta) + ' ' + u;
                d.className = shown > 0 ? 'up' : (shown < 0 ? 'down' : '');
            }

            function buildOptions(weeks) {
                primarySel.innerHTML = '<option value="">All sessions</option>';
                compareSel.innerHTML = '<option value="">Nothing</option>';
                weeks.forEach(w => {
                    primarySel.add(new Option(w.weekLabel, w.weekKey));
                    compareSel.add(new Option(w.weekLabel, w.weekKey));
                });
                primarySel.value = '';
                compareSel.value = '';
                compareSel.disabled = true;
            }

            function dataset(label, data, color, dashed) {
                return {
                    label, data, borderColor: color, backgroundColor: color, pointBackgroundColor: color,
                    borderWidth: 3, pointRadius: 5, pointHoverRadius: 7, tension: 0, spanGaps: true,
                    borderDash: dashed ? [8, 5] : [], pointStyle: dashed ? 'rectRot' : 'circle', fill: false
                };
            }

            function renderChart() {
                if (!current) return;
                const rows = current.chart, week = primarySel.value, cmp = compareSel.value;
                const weekLabel = k => (current.weeks.find(w => w.weekKey === k) || {}).weekLabel || k;
                let labels, sets;

                if (!week) {
                    const sorted = rows.slice().sort((a, b) => String(a.date).localeCompare(String(b.date)));
                    labels = sorted.map(r => fmtDate(r.date));
                    sets = [dataset('All sessions', sorted.map(r => disp(r.avg_weight)), cssVar('--accent'), false)];
                } else {
                    labels = DAYS;
                    const byDay = key => { const out = Array(7).fill(null); rows.filter(r => r.weekKey === key).forEach(r => { out[dayIndex(r.date)] = disp(r.avg_weight); }); return out; };
                    sets = [dataset(weekLabel(week), byDay(week), cssVar('--accent'), false)];
                    if (cmp && cmp !== week) sets.push(dataset(weekLabel(cmp), byDay(cmp), cssVar('--red'), true));
                }

                const vals = sets.flatMap(s => s.data).filter(v => v !== null);
                $('progressionChart').setAttribute('aria-label', 'Average weight per session for ' + current.exercise + (vals.length ? ', from ' + vals[0] + ' to ' + vals[vals.length - 1] + ' ' + unit() : ''));
                $('chartTitle').textContent = 'Average weight per session (' + unit() + ')' + (week ? ', by day of the week' : '');

                const muted = cssVar('--muted'), rule = cssVar('--rule'), ink = cssVar('--ink');
                if (chartInstance) chartInstance.destroy();
                chartInstance = new Chart($('progressionChart').getContext('2d'), {
                    type: 'line',
                    data: { labels, datasets: sets },
                    options: {
                        responsive: true, maintainAspectRatio: false, animation: false,
                        interaction: { mode: 'index', intersect: false },
                        plugins: {
                            legend: { display: sets.length > 1, labels: { color: ink, usePointStyle: true, padding: 16, font: { family: 'Archivo, Arial, sans-serif', weight: '600' } } },
                            tooltip: { callbacks: { label: c => c.dataset.label + ': ' + c.formattedValue + ' ' + unit() } }
                        },
                        scales: {
                            x: { grid: { color: rule }, ticks: { color: muted, font: { family: 'Archivo, Arial, sans-serif' } } },
                            y: { grid: { color: rule }, beginAtZero: false, ticks: { color: muted, padding: 8, font: { family: 'Archivo, Arial, sans-serif' } }, title: { display: true, text: unit(), color: muted } }
                        }
                    }
                });
            }

            function renderEntries(entries) {
                if (!entries || !entries.length) { $('entryList').innerHTML = '<p class="status">No sessions logged for this exercise yet.</p>'; return; }
                $('entryList').innerHTML = '<div class="scroll"><table><thead><tr><th>Date</th><th>Plan</th><th class="n">Weight (' + unit() + ')</th><th class="n">Reps</th><th>Notes</th></tr></thead><tbody>'
                    + entries.map(e => '<tr class="' + (e.is_warmup ? 'wu' : '') + '">'
                        + '<td>' + esc(fmtDate(e.date)) + (e.is_warmup ? '<span class="tag">Warm-up</span>' : '') + '</td>'
                        + '<td>' + esc(e.plan_name || 'No plan') + '</td>'
                        + '<td class="n">' + esc(fmtW(e.weight)) + ' ' + unit() + '</td>'
                        + '<td class="n">' + esc(e.reps) + '</td>'
                        + '<td class="note">' + esc(e.notes) + '</td></tr>').join('')
                    + '</tbody></table></div>';
            }

            function loadProgression(name) {
                $('entryList').innerHTML = '<p class="status">Loading</p>';
                fetch('api/get-progression.php?exercise=' + encodeURIComponent(name))
                    .then(res => res.json())
                    .then(data => {
                        if (!data.success) { $('entryList').innerHTML = '<p class="status err">' + esc(data.error || 'Could not load progression.') + '</p>'; return; }
                        current = data;
                        buildOptions(data.weeks || []);
                        renderSummary(data.summary);
                        renderChart();
                        renderEntries(data.entries);
                    })
                    .catch(() => { $('entryList').innerHTML = '<p class="status err">Could not reach the server. Check your connection and try again.</p>'; });
            }

            exerciseSelect.addEventListener('change', function () { loadProgression(this.value); });
            primarySel.addEventListener('change', function () {
                compareSel.disabled = !this.value;
                if (!this.value) compareSel.value = '';
                renderChart();
            });
            compareSel.addEventListener('change', renderChart);
            U.control($('unitSwitch'));
            document.addEventListener('unitchange', () => { if (current) { renderSummary(current.summary); renderChart(); renderEntries(current.entries); } });
            window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', renderChart);
            document.addEventListener('themechange', renderChart);

            loadProgression(defaultExercise);
        </script>
    <?php endif; ?>
</body>

</html>