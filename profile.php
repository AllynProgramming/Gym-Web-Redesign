<?php
// profile.php
// Lets the user change their display name, username, and password.

require_once __DIR__ . '/api/includes/db.php';
require_once __DIR__ . '/api/includes/auth.php';

requireLogin();

$userId = getUserId();
$user = getUserInfo($conn, $userId);

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
    <title>Profile | GymTrack</title>
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
            max-width: 640px;
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
            padding: 2.2rem 0 .8rem
        }

        h1 {
            font: 850 clamp(2.1rem, 6vw, 3.4rem)/1.02 var(--head);
            font-stretch: 118%;
            letter-spacing: -.025em;
            margin: 0 0 .5rem;
            overflow-wrap: anywhere
        }

        .lede {
            color: var(--muted);
            margin: 0
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
            margin: 0 0 1.2rem
        }

        .two {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem
        }

        .field {
            display: grid;
            gap: .3rem;
            align-content: start;
            margin-bottom: 1rem
        }

        label {
            font: 700 .85rem var(--head)
        }

        input[type=text],
        input[type=password] {
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

        input[type=text]:focus,
        input[type=password]:focus {
            outline: none;
            border-color: var(--accent)
        }

        input[type=text]:focus-visible,
        input[type=password]:focus-visible {
            outline: 3px solid var(--accent);
            outline-offset: 1px
        }

        input[type=checkbox] {
            width: 1.2rem;
            height: 1.2rem;
            accent-color: var(--accent)
        }

        .hint {
            color: var(--muted);
            font-size: .95rem;
            margin: 0
        }

        .show {
            display: flex;
            align-items: center;
            gap: .5rem;
            font: 600 .95rem var(--head);
            margin: 0 0 1.2rem;
            cursor: pointer
        }

        .btn {
            display: inline-block;
            background: var(--accent);
            color: var(--on-accent);
            font: 700 1rem var(--head);
            padding: .75rem 1.4rem;
            border: 0;
            border-radius: 6px;
            cursor: pointer;
            min-height: 2.8rem
        }

        .btn[disabled] {
            opacity: .6;
            cursor: wait
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

        footer {
            padding: 1rem 0 3rem
        }

        @media (max-width:560px) {
            .two {
                grid-template-columns: 1fr
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
            <?php $activePage = 'profile';
            include __DIR__ . '/api/includes/nav.php'; ?>
        </header>

        <main>
            <div class="top">
                <h1 id="pageName"><?php echo gt_e($user['username']); ?></h1>
                <p class="lede">Your account. Update your name, username and password here.</p>
            </div>

            <section class="sec">
                <h2>Profile details</h2>
                <p class="sub">Your username is what you log in with. Your name is optional.</p>
                <div class="msg" id="profileMessage" role="status" aria-live="polite" hidden></div>
                <form id="profileForm">
                    <div class="two">
                        <div class="field"><label for="first_name">First name</label><input type="text" id="first_name"
                                value="<?php echo gt_e($user['first_name'] ?? ''); ?>" autocomplete="given-name"></div>
                        <div class="field"><label for="last_name">Last name</label><input type="text" id="last_name"
                                value="<?php echo gt_e($user['last_name'] ?? ''); ?>" autocomplete="family-name"></div>
                    </div>
                    <div class="field">
                        <label for="username">Username</label>
                        <input type="text" id="username" value="<?php echo gt_e($user['username']); ?>" required
                            minlength="3" maxlength="50" pattern="[A-Za-z0-9_]+" autocomplete="username"
                            aria-describedby="unameHint">
                        <p class="hint" id="unameHint">3 to 50 letters, numbers or underscores.</p>
                    </div>
                    <button type="submit" class="btn" id="profileSaveBtn">Save changes</button>
                </form>
            </section>

            <section class="sec">
                <h2>Change password</h2>
                <p class="sub">Enter your current password first, then choose a new one.</p>
                <div class="msg" id="passwordMessage" role="status" aria-live="polite" hidden></div>
                <form id="passwordForm">
                    <div class="field"><label for="current_password">Current password</label><input type="password"
                            id="current_password" required autocomplete="current-password"></div>
                    <div class="field"><label for="new_password">New password</label><input type="password"
                            id="new_password" required minlength="8" autocomplete="new-password"
                            aria-describedby="pwHint">
                        <p class="hint" id="pwHint">At least 8 characters.</p>
                    </div>
                    <div class="field"><label for="confirm_password">Confirm new password</label><input type="password"
                            id="confirm_password" required minlength="8" autocomplete="new-password"></div>
                    <label class="show"><input type="checkbox" id="showPw"> Show passwords</label>
                    <button type="submit" class="btn" id="passwordSaveBtn">Update password</button>
                </form>
            </section>
        </main>
        <footer></footer>
    </div>

    <script>
        const $ = id => document.getElementById(id);
        function say(el, text, kind) { el.textContent = text; el.className = 'msg' + (kind === 'err' ? ' err' : ''); el.hidden = false; }
        function post(url, body) {
            return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
                .then(r => r.text())
                .then(t => { try { return JSON.parse(t); } catch (e) { throw new Error('bad response'); } });
        }
        const NET = 'Could not reach the server, or it sent back something unexpected. Nothing was changed.';

        // ---------- Profile details ----------
        $('profileForm').addEventListener('submit', function (e) {
            e.preventDefault();
            const btn = $('profileSaveBtn'), msg = $('profileMessage');
            const username = $('username').value.trim();
            btn.disabled = true;
            post('api/update-profile.php', {
                first_name: $('first_name').value.trim(),
                last_name: $('last_name').value.trim(),
                username: username
            }).then(d => {
                if (d.success) { say(msg, 'Profile saved.', 'ok'); $('pageName').textContent = username; }
                else say(msg, d.error || 'Could not save changes.', 'err');
            }).catch(() => say(msg, NET, 'err')).finally(() => { btn.disabled = false; });
        });

        // ---------- Password ----------
        $('showPw').addEventListener('change', function () {
            ['current_password', 'new_password', 'confirm_password'].forEach(id => { $(id).type = this.checked ? 'text' : 'password'; });
        });

        $('passwordForm').addEventListener('submit', function (e) {
            e.preventDefault();
            const btn = $('passwordSaveBtn'), msg = $('passwordMessage');
            const cur = $('current_password').value, nw = $('new_password').value, cf = $('confirm_password').value;
            if (nw !== cf) { say(msg, 'The new passwords don\u2019t match. Type the same password in both boxes.', 'err'); $('confirm_password').focus(); return; }
            btn.disabled = true;
            post('api/update-password.php', { current_password: cur, new_password: nw, confirm_password: cf })
                .then(d => {
                    if (d.success) { say(msg, 'Password updated.', 'ok'); this.reset(); $('showPw').dispatchEvent(new Event('change')); }
                    else say(msg, d.error || 'Could not update the password. Check your current password and try again.', 'err');
                })
                .catch(() => say(msg, NET, 'err'))
                .finally(() => { btn.disabled = false; });
        });
    </script>
</body>

</html>