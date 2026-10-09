<?php
// api/includes/timezone.php
// One place that sets the app's time zone for PHP *and* MySQL.
//
// Why: PHP defaults to UTC unless php.ini says otherwise. In the Philippines (UTC+8) that means
// "today" is still yesterday from midnight until 8:00 AM, so the dashboard, the nutrition page and
// the weekly leaderboard all used the wrong date during those hours. Workout dates came from the
// browser (correct local date), so the two disagreed.
//
// Change GT_TIMEZONE if you ever move; any PHP time zone name works (e.g. 'Asia/Manila').
if (!defined('GT_TIMEZONE')) {
    define('GT_TIMEZONE', 'Asia/Manila');
}
date_default_timezone_set(GT_TIMEZONE);

// Keep MySQL's NOW() / CURDATE() / TIMESTAMP columns on the same clock
if (isset($conn) && $conn instanceof mysqli) {
    $offset = (new DateTime('now', new DateTimeZone(GT_TIMEZONE)))->format('P'); // e.g. +08:00
    $conn->query("SET time_zone = '" . $offset . "'");
}