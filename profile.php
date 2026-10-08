<?php
// profile.php
// Lets the user change their display name, username, and password.

require_once __DIR__ . '/api/includes/db.php';
require_once __DIR__ . '/api/includes/auth.php';

requireLogin();

$userId = getUserId();
$user = getUserInfo($conn, $userId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - Perosnal GymTracker </title>
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
        .container { max-width: 640px; margin: 0 auto; padding: 32px 24px 60px; }

        .page-head { margin-bottom: 22px; }
        .page-head h2 { font-size: clamp(1.8rem, 2.5vw, 2.2rem); margin-bottom: 6px; }
        .page-head p { color: var(--text-muted); font-size: 1rem; }

        .panel {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 22px;
            padding: 26px;
            margin-bottom: 20px;
            box-shadow: 0 16px 34px rgba(0, 0, 0, 0.2);
        }

        .panel-title { font-size: 1.05rem; font-weight: 700; margin-bottom: 4px; color: #fff; }
        .panel-subtitle { font-size: 0.85rem; color: var(--text-muted); margin-bottom: 18px; }

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

        .field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .form-group { display: grid; gap: 7px; margin-bottom: 16px; }
        .form-group:last-of-type { margin-bottom: 0; }

        label { color: #d7dcf5; font-size: 0.86rem; font-weight: 600; }

        input {
            width: 100%;
            padding: 12px 14px;
            min-height: 46px;
            border-radius: 12px;
            border: 1px solid rgba(151, 109, 222, 0.22);
            background: rgba(255, 255, 255, 0.05);
            color: var(--text-main);
            font-size: 0.95rem;
            font-family: inherit;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        input::placeholder { color: #7e89ab; }

        input:focus {
            outline: none;
            border-color: rgba(155, 106, 240, 0.8);
            box-shadow: 0 0 0 3px rgba(155, 106, 240, 0.16);
        }

        .hint { font-size: 0.78rem; color: var(--text-muted); margin-top: -2px; }

        .save-btn {
            margin-top: 6px;
            padding: 12px 26px;
            min-height: 46px;
            border: none;
            border-radius: 999px;
            background: linear-gradient(135deg, #a755ff 0%, #7d3fd0 55%, #632a9f 100%);
            color: #fff;
            font-weight: 700;
            font-size: 0.92rem;
            cursor: pointer;
            transition: transform 0.2s ease;
        }

        .save-btn:hover { transform: translateY(-2px); }
        .save-btn:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }

        @media (max-width: 560px) {
            .container { padding: 20px 16px 40px; }
            .navbar { padding: 16px 20px; }
            .nav-toggle { display: inline-flex; }
            .field-row { grid-template-columns: 1fr; }

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
            <a href="friends.php">Friends</a>
            <a href="api/logout.php">Logout</a>
        </div>
    </nav>

    <div class="container">
        <div class="page-head">
            <h2>Profile</h2>
            <p>Update how you're identified across GymTrack.</p>
        </div>

        <div class="message" id="profileMessage"></div>

        <!-- Display name + username -->
        <div class="panel">
            <p class="panel-title">Profile details</p>
            <p class="panel-subtitle">Your first name shows up in the dashboard greeting; your username is what you log in with.</p>

            <form id="profileForm">
                <div class="field-row">
                    <div class="form-group">
                        <label for="first_name">First name</label>
                        <input type="text" id="first_name" value="<?php echo htmlspecialchars($user['first_name'] ?? ''); ?>" placeholder="First name">
                    </div>
                    <div class="form-group">
                        <label for="last_name">Last name</label>
                        <input type="text" id="last_name" value="<?php echo htmlspecialchars($user['last_name'] ?? ''); ?>" placeholder="Last name">
                    </div>
                </div>

                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" value="<?php echo htmlspecialchars($user['username']); ?>" required>
                    <p class="hint">Letters, numbers, and underscores only. You'll use this to log in.</p>
                </div>

                <button type="submit" class="save-btn" id="profileSaveBtn">Save changes</button>
            </form>
        </div>

        <!-- Password -->
        <div class="panel">
            <p class="panel-title">Change password</p>
            <p class="panel-subtitle">You'll need your current password to set a new one.</p>

            <div class="message" id="passwordMessage"></div>

            <form id="passwordForm">
                <div class="form-group">
                    <label for="current_password">Current password</label>
                    <input type="password" id="current_password" required autocomplete="current-password">
                </div>
                <div class="form-group">
                    <label for="new_password">New password</label>
                    <input type="password" id="new_password" required autocomplete="new-password">
                    <p class="hint">At least 8 characters.</p>
                </div>
                <div class="form-group">
                    <label for="confirm_password">Confirm new password</label>
                    <input type="password" id="confirm_password" required autocomplete="new-password">
                </div>

                <button type="submit" class="save-btn" id="passwordSaveBtn">Update password</button>
            </form>
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

        function showMessage(el, text, type) {
            el.textContent = text;
            el.className = 'message show ' + type;
        }

        // ---------- Profile details (name + username) ----------
        const profileForm = document.getElementById('profileForm');
        const profileMessage = document.getElementById('profileMessage');
        const profileSaveBtn = document.getElementById('profileSaveBtn');

        profileForm.addEventListener('submit', function (e) {
            e.preventDefault();
            profileSaveBtn.disabled = true;

            fetch('api/update-profile.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    first_name: document.getElementById('first_name').value.trim(),
                    last_name: document.getElementById('last_name').value.trim(),
                    username: document.getElementById('username').value.trim(),
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showMessage(profileMessage, 'Profile updated!', 'success');
                } else {
                    showMessage(profileMessage, data.error || 'Could not save changes.', 'error');
                }
            })
            .catch(() => showMessage(profileMessage, 'Could not reach the server.', 'error'))
            .finally(() => { profileSaveBtn.disabled = false; });
        });

        // ---------- Password ----------
        const passwordForm = document.getElementById('passwordForm');
        const passwordMessage = document.getElementById('passwordMessage');
        const passwordSaveBtn = document.getElementById('passwordSaveBtn');

        passwordForm.addEventListener('submit', function (e) {
            e.preventDefault();

            const newPw = document.getElementById('new_password').value;
            const confirmPw = document.getElementById('confirm_password').value;

            if (newPw !== confirmPw) {
                showMessage(passwordMessage, 'New passwords do not match.', 'error');
                return;
            }

            passwordSaveBtn.disabled = true;

            fetch('api/update-password.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    current_password: document.getElementById('current_password').value,
                    new_password: newPw,
                    confirm_password: confirmPw,
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showMessage(passwordMessage, 'Password updated!', 'success');
                    passwordForm.reset();
                } else {
                    showMessage(passwordMessage, data.error || 'Could not update password.', 'error');
                }
            })
            .catch(() => showMessage(passwordMessage, 'Could not reach the server.', 'error'))
            .finally(() => { passwordSaveBtn.disabled = false; });
        });
    </script>
</body>
</html>