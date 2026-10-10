<?php
// api/includes/nav.php
// The one main menu used by every logged-in page, so every page is reachable from every page.
// Before including it, set:   $activePage = 'dashboard';   (or log, workouts, progression, nutrition, friends, profile)
// Optionally set $navUser to show the username at the start of the menu.
// To add a page to the menu, add one line to $navItems below and it appears everywhere.
$navItems = [
    'dashboard' => ['dashboard.php', 'Dashboard'],
    'log' => ['log-workout.php', 'Log workout'],
    'workouts' => ['workouts.php', 'My workouts'],
    'progression' => ['progression.php', 'Progression'],
    'nutrition' => ['nutrition.php', 'Nutrition'],
    'friends' => ['friends.php', 'Friends'],
    'profile' => ['profile.php', 'Profile'],
];
?>
<nav aria-label="Main">
    <?php if (!empty($navUser)): ?>
        <span class="who"><?php echo htmlspecialchars($navUser, ENT_QUOTES, 'UTF-8'); ?></span>
    <?php endif; ?>
    <?php foreach ($navItems as $navKey => $navItem): ?>
        <a href="<?php echo $navItem[0]; ?>" <?php echo (($activePage ?? '') === $navKey) ? ' aria-current="page"' : ''; ?>><?php echo $navItem[1]; ?></a>
    <?php endforeach; ?>
    <a href="api/logout.php">Log out</a>
</nav>