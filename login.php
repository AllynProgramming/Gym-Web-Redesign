<?php
// login.php - log in / create account. Plain form posts to api/login.php and api/signup.php,
// which redirect back here with ?error=... or ?signup_success=1.
require_once __DIR__ . '/api/includes/db.php';
require_once __DIR__ . '/api/includes/auth.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!empty($_SESSION['userId'])) {
    header('Location: dashboard.php');
    exit;
}

$errors = [
    'invalid_login' => 'Wrong username or password. Check both and try again.',
    'invalid_input' => 'Check your details. Usernames are 3 to 50 letters, numbers or underscores, and passwords need at least 8 characters.',
    'password_mismatch' => 'The two passwords don\'t match.',
    'user_exists' => 'That username or email is already registered. Log in instead, or use different details.',
];
$notice = '';
$ok = false;
if (!empty($_GET['error']) && isset($errors[$_GET['error']])) {
    $notice = $errors[$_GET['error']];
} elseif (!empty($_GET['signup_success'])) {
    $notice = 'Account created. Log in to start your logbook.';
    $ok = true;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Log in | GymTrack</title>
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

        html {
            height: 100%
        }

        body {
            margin: 0;
            min-height: 100%;
            background: var(--bg);
            color: var(--ink);
            font: 400 1.125rem/1.55 var(--body);
            padding: env(safe-area-inset-top, 0px) 0 env(safe-area-inset-bottom, 0px)
        }

        :focus-visible {
            outline: 3px solid var(--accent);
            outline-offset: 3px
        }

        .wrap {
            max-width: 1040px;
            margin: 0 auto;
            padding: 0 clamp(1.1rem, 4vw, 2.5rem);
            min-height: 100vh;
            display: flex;
            flex-direction: column
        }

        header {
            padding: 1.4rem 0
        }

        .logo {
            font: 800 1.25rem var(--head);
            font-stretch: 112%;
            text-decoration: none;
            color: inherit
        }

        main {
            flex: 1;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: clamp(2rem, 6vw, 5rem);
            align-items: center;
            padding: 2rem 0 4rem
        }

        h1 {
            font: 850 clamp(2.4rem, 6vw, 4.4rem)/1 var(--head);
            font-stretch: 118%;
            letter-spacing: -.025em;
            margin: 0 0 1rem;
            max-width: 11ch
        }

        .lede {
            color: var(--muted);
            font-size: 1.25rem;
            max-width: 26rem;
            margin: 0
        }

        .sheet {
            background: var(--surface);
            border-radius: 10px;
            padding: 1.4rem 1.5rem 1.6rem;
            box-shadow: 0 0 0 1px var(--rule)
        }

        .tabs {
            display: flex;
            gap: 1.4rem;
            border-bottom: 2px solid var(--ink);
            margin-bottom: .4rem
        }

        .tabs button {
            font: 700 1rem var(--head);
            background: none;
            border: 0;
            color: var(--muted);
            padding: .5rem 0;
            cursor: pointer;
            margin-bottom: -2px;
            border-bottom: 4px solid transparent
        }

        .tabs button[aria-selected="true"] {
            color: var(--ink);
            border-bottom-color: var(--accent)
        }

        .field {
            display: grid;
            grid-template-columns: 8.5rem 1fr;
            align-items: center;
            border-bottom: 1px solid var(--rule);
            gap: .6rem
        }

        .field label {
            font: 600 .9rem var(--head);
            color: var(--muted)
        }

        .field input {
            font: 400 1.1rem var(--body);
            color: var(--ink);
            background: transparent;
            border: 0;
            padding: .85rem 0;
            width: 100%;
            min-width: 0
        }

        .field input:focus-visible {
            outline-offset: -2px;
            border-radius: 4px
        }

        .msg {
            margin: .9rem 0 0;
            font-size: 1rem;
            color: var(--err)
        }

        .msg.ok {
            color: var(--ink);
            font-weight: 600
        }

        .hint {
            color: var(--muted);
            font-size: .95rem;
            margin: .7rem 0 0
        }

        .btn {
            width: 100%;
            margin-top: 1rem;
            background: var(--accent);
            color: var(--on-accent);
            font: 700 1rem var(--head);
            padding: .9rem 1.4rem;
            border: 0;
            border-radius: 6px;
            cursor: pointer
        }

        [hidden] {
            display: none !important
        }

        @media (max-width:820px) {
            main {
                grid-template-columns: 1fr;
                align-items: start
            }

            .field {
                grid-template-columns: 1fr;
                gap: 0
            }

            .field label {
                padding-top: .7rem
            }

            .field input {
                padding: .4rem 0 .8rem
            }
        }
    </style>
</head>

<body>
    <div class="wrap">
        <header><a class="logo" href="index.php">GymTrack</a></header>
        <main>
            <div>
                <h1 id="title">Pick up where you left off.</h1>
                <p class="lede" id="sub">Your last session is saved. Log in and beat it.</p>
            </div>
            <div class="sheet">
                <div class="tabs" role="tablist">
                    <button type="button" role="tab" id="t-login" aria-selected="true" aria-controls="f-login">Log
                        in</button>
                    <button type="button" role="tab" id="t-signup" aria-selected="false" aria-controls="f-signup">Create
                        account</button>
                </div>
                <?php if ($notice): ?>
                    <p class="msg<?php echo $ok ? ' ok' : ''; ?>" role="alert"><?php echo htmlspecialchars($notice); ?></p>
                <?php endif; ?>

                <form id="f-login" role="tabpanel" aria-labelledby="t-login" method="post" action="api/login.php">
                    <div class="field"><label for="l-user">Username</label><input id="l-user" name="username"
                            autocomplete="username" required></div>
                    <div class="field"><label for="l-pass">Password</label><input id="l-pass" name="password"
                            type="password" autocomplete="current-password" required></div>
                    <button class="btn" type="submit">Log in</button>
                </form>

                <form id="f-signup" role="tabpanel" aria-labelledby="t-signup" method="post" action="api/signup.php"
                    hidden>
                    <div class="field"><label for="s-user">Username</label><input id="s-user" name="username"
                            autocomplete="username" required minlength="3" maxlength="50" pattern="[A-Za-z0-9_]+"
                            title="Letters, numbers and underscores only"></div>
                    <div class="field"><label for="s-mail">Email</label><input id="s-mail" name="email" type="email"
                            autocomplete="email" required></div>
                    <div class="field"><label for="s-first">First name</label><input id="s-first" name="first_name"
                            autocomplete="given-name"></div>
                    <div class="field"><label for="s-last">Last name</label><input id="s-last" name="last_name"
                            autocomplete="family-name"></div>
                    <div class="field"><label for="s-pass">Password</label><input id="s-pass" name="password"
                            type="password" autocomplete="new-password" required minlength="8"></div>
                    <div class="field"><label for="s-pass2">Confirm password</label><input id="s-pass2"
                            name="password_confirm" type="password" autocomplete="new-password" required minlength="8">
                    </div>
                    <p class="hint">At least 8 characters. First and last name are optional.</p>
                    <button class="btn" type="submit">Create account</button>
                </form>
            </div>
        </main>
    </div>
    <script>
        const $ = id => document.getElementById(id);
        const copy = {
            login: ['Pick up where you left off.', 'Your last session is saved. Log in and beat it.', 'Log in | GymTrack'],
            signup: ['Start your logbook.', 'Log your first set in under a minute.', 'Create account | GymTrack']
        };
        function show(which) {
            for (const k of ['login', 'signup']) { $('f-' + k).hidden = k !== which; $('t-' + k).setAttribute('aria-selected', k === which); }
            $('title').textContent = copy[which][0]; $('sub').textContent = copy[which][1]; document.title = copy[which][2];
        }
        $('t-login').onclick = () => show('login');
        $('t-signup').onclick = () => show('signup');
        if (location.hash === '#signup') show('signup');
    </script>
</body>

</html>