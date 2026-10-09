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
    <script src="assets/theme.js"></script>
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
            --focus: #1F4FCC;
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
                --focus: #6C93FF;
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
            outline: 3px solid var(--focus);
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
            padding: 1.4rem 0;
            border-bottom: 1px solid var(--rule)
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
            grid-template-columns: minmax(0, .9fr) minmax(24rem, 1.1fr);
            gap: clamp(2rem, 7vw, 7rem);
            align-items: center;
            padding: clamp(3rem, 8vh, 6rem) 0 5rem
        }

        h1 {
            font: 850 clamp(2.5rem, 6vw, 4.7rem)/.96 var(--head);
            font-stretch: 118%;
            letter-spacing: -.025em;
            margin: 0 0 1rem;
            max-width: 10ch;
            text-wrap: balance
        }

        .lede {
            color: var(--muted);
            font-size: 1.28rem;
            line-height: 1.45;
            max-width: 26rem;
            margin: 0
        }

        .sheet {
            background: var(--surface);
            border: 1px solid var(--rule);
            border-top: 7px solid var(--accent);
            border-radius: 2px;
            padding: clamp(1.5rem, 4vw, 2.4rem);
            box-shadow: 10px 10px 0 rgba(23, 33, 43, .10)
        }

        .tabs {
            display: flex;
            gap: 1.6rem;
            border-bottom: 1px solid var(--rule);
            margin-bottom: 1.2rem
        }

        .tabs button {
            font: 700 1.05rem var(--head);
            background: none;
            border: 0;
            color: var(--muted);
            padding: .35rem 0 .75rem;
            cursor: pointer;
            margin-bottom: -1px;
            border-bottom: 3px solid transparent
        }

        .tabs button[aria-selected="true"] {
            color: var(--ink);
            border-bottom-color: var(--accent)
        }

        .field {
            display: grid;
            gap: .35rem;
            margin-top: 1rem
        }

        .field label {
            font: 700 .82rem var(--head);
            color: var(--ink)
        }

        .field input {
            font: 400 1.12rem var(--body);
            color: var(--ink);
            background: var(--bg);
            border: 2px solid var(--rule);
            border-radius: 2px;
            padding: .78rem .8rem;
            width: 100%;
            min-width: 0;
            min-height: 3.1rem;
            transition: border-color .15s ease, background-color .15s ease
        }

        .field input:focus {
            outline: none;
            border-color: var(--accent);
            background: var(--surface)
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
            margin-top: 1.35rem;
            background: var(--accent);
            color: var(--on-accent);
            font: 800 1.05rem var(--head);
            padding: 1rem 1.4rem;
            border: 0;
            border-radius: 2px;
            cursor: pointer;
            transition: transform .15s ease, filter .15s ease
        }

        .btn:hover {
            filter: brightness(1.08);
            transform: translateY(-2px)
        }

        .oauth-divider {
            display: flex;
            align-items: center;
            gap: .75rem;
            margin: 1.25rem 0 1rem;
            color: var(--muted);
            font: 600 .78rem var(--head)
        }

        .oauth-divider::before,
        .oauth-divider::after {
            content: "";
            height: 1px;
            flex: 1;
            background: var(--rule)
        }

        .google-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: .65rem;
            width: 100%;
            min-height: 3.1rem;
            padding: .8rem 1.2rem;
            border: 2px solid var(--rule);
            border-radius: 2px;
            background: var(--surface);
            color: var(--ink);
            font: 700 1rem var(--head);
            text-decoration: none;
            transition: border-color .15s ease, background-color .15s ease
        }

        .google-btn:hover {
            border-color: var(--ink);
            background: var(--bg)
        }

        .google-mark {
            color: #4285F4;
            font: 800 1.15rem Arial, sans-serif
        }

        [hidden] {
            display: none !important
        }

        @media (max-width:820px) {
            main {
                grid-template-columns: 1fr;
                align-items: start
            }

            main {
                padding-top: 3rem
            }
        }

        @media (max-width:520px) {
            .wrap {
                padding-left: 1rem;
                padding-right: 1rem
            }

            main {
                gap: 2.5rem;
                padding-bottom: 3rem
            }

            .sheet {
                padding: 1.25rem;
                box-shadow: 6px 6px 0 rgba(23, 33, 43, .10)
            }

            .tabs {
                gap: 1rem
            }

            .tabs button {
                font-size: .95rem
            }
        }

        @media (prefers-reduced-motion: reduce) {

            .field input,
            .btn {
                transition: none
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
                    <div class="oauth-divider" aria-hidden="true">or</div>
                    <a class="google-btn" href="api/google-login.php">
                        <span class="google-mark" aria-hidden="true">G</span>
                        Continue with Google
                    </a>
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