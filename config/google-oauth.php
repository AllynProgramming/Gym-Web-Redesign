<?php
// config/google-oauth.php
// Google OAuth 2.0 Configuration

define('GOOGLE_CLIENT_ID', getenv('GOOGLE_CLIENT_ID') ?: '');
define('GOOGLE_CLIENT_SECRET', getenv('GOOGLE_CLIENT_SECRET') ?: '');
define('GOOGLE_REDIRECT_URI', 'https://progression.freedev.app/Gym-Web/api/google-callback.php');
?>