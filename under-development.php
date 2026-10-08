<?php
require_once __DIR__ . '/api/includes/db.php';
require_once __DIR__ . '/api/includes/auth.php';

requireLogin();
$user = getUserInfo($conn, getUserId());
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Under Development - GymTrack</title>
    <style>
        :root {
            color-scheme: dark;
            --bg-dark: #05030a;
            --panel: rgba(15, 8, 28, 0.95);
            --border: rgba(151, 109, 222, 0.22);
            --text-main: #f6f7ff;
            --text-muted: #adb2d4;
            --accent: #7851A9;
            --accent-strong: #9b6af0;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background:
                radial-gradient(circle at top left, rgba(120, 81, 169, 0.18), transparent 20%),
                radial-gradient(circle at bottom right, rgba(120, 81, 169, 0.12), transparent 18%),
                var(--bg-dark);
            color: var(--text-main);
        }

        .navbar {
            background: rgba(5, 5, 15, 0.96);
            border-bottom: 1px solid rgba(151, 109, 222, 0.2);
            padding: 20px 28px;
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
            font-size: 1.7rem;
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
            gap: 10px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .navbar-right a {
            color: var(--text-main);
            text-decoration: none;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.08);
            padding: 10px 16px;
            border-radius: 999px;
            font-weight: 600;
            transition: background 0.2s ease, transform 0.2s ease;
            font-size: 0.9rem;
        }

        .navbar-right a:hover {
            background: rgba(120, 81, 169, 0.18);
            transform: translateY(-1px);
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
            padding: 40px 24px 60px;
        }

        .panel {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 28px;
            padding: 42px 36px;
            box-shadow: 0 22px 50px rgba(0, 0, 0, 0.22);
            text-align: center;
        }

        .panel h1 {
            font-size: clamp(2rem, 4vw, 2.7rem);
            margin-bottom: 18px;
            line-height: 1.05;
        }

        .panel p {
            color: var(--text-muted);
            font-size: 1.05rem;
            margin-bottom: 28px;
            line-height: 1.7;
        }

        .btn-primary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 14px 26px;
            background: linear-gradient(135deg, var(--accent-strong), var(--accent));
            color: #fff;
            border-radius: 999px;
            text-decoration: none;
            font-weight: 700;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 18px 40px rgba(120, 81, 169, 0.18);
        }

        @media (max-width: 720px) {
            .navbar { flex-direction: column; align-items: stretch; }
            .navbar-right { justify-content: center; }
            .container { padding-top: 20px; }
            .panel { padding: 22px; }
            .panel h1 { font-size: 1.6rem; }
            .panel p { font-size: 0.98rem; }
            .btn-primary { width: 100%; padding: 12px 16px; display: block; }
        }

        @media (max-width: 860px) {
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

            .navbar-right a {
                width: 100%;
                text-align: center;
                justify-content: center;
            }
        }

        @media (max-width: 420px) {
            .panel { padding: 16px; }
            .panel h1 { font-size: 1.3rem; }
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <h1>💪 GymTrack</h1>
        <button class="nav-toggle" id="navToggle" aria-label="Toggle navigation" type="button">
            <span class="barbell-icon" aria-hidden="true">
                <span class="plate"></span>
                <span class="bar"></span>
                <span class="plate"></span>
            </span>
        </button>
        <div class="navbar-right" id="navMenu">
            <a href="dashboard.php">Dashboard</a>
            <a href="log-workout.php">Log Workout</a>
            <a href="progression.php">Progression</a>
            <a href="api/logout.php">Logout</a>
        </div>
    </nav>

    <div class="container">
        <div class="panel">
            <h1>Sorry for the inconvenience</h1>
            <p>Page is still underdevelopment.</p>
            <a class="btn-primary" href="dashboard.php">Back to Dashboard</a>
        </div>
    </div>
    <script>
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
