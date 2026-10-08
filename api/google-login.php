<?php
// api/google-login.php
// Initiates Google OAuth login flow

session_start();
require_once __DIR__ . '/../config/google-oauth.php';

$authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
    'client_id' => GOOGLE_CLIENT_ID,
    'redirect_uri' => GOOGLE_REDIRECT_URI,
    'response_type' => 'code',
    'scope' => 'openid email profile',
    'access_type' => 'online'
]);

header('Location: ' . $authUrl);
exit;
?>
