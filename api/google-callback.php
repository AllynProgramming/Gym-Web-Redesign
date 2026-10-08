<?php
// api/google-callback.php
// Google OAuth callback handler

error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/../config/google-oauth.php';

header('Content-Type: application/json');

if (!isset($_GET['code'])) {
    header('Location: ../index.php?error=missing_auth_code');
    exit;
}

$code = $_GET['code'];

// Exchange authorization code for access token
$tokenUrl = 'https://oauth2.googleapis.com/token';
$tokenData = [
    'client_id' => GOOGLE_CLIENT_ID,
    'client_secret' => GOOGLE_CLIENT_SECRET,
    'code' => $code,
    'grant_type' => 'authorization_code',
    'redirect_uri' => GOOGLE_REDIRECT_URI
];

$options = [
    'http' => [
        'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
        'method' => 'POST',
        'content' => http_build_query($tokenData)
    ]
];

$context = stream_context_create($options);
$tokenResponse = @file_get_contents($tokenUrl, false, $context);

if ($tokenResponse === false) {
    error_log("Token exchange failed");
    header('Location: ../index.php?error=token_exchange_failed');
    exit;
}

$tokenDataResponse = json_decode($tokenResponse, true);

if (!isset($tokenDataResponse['access_token'])) {
    error_log("No access token in response: " . json_encode($tokenDataResponse));
    header('Location: ../index.php?error=token_exchange_failed');
    exit;
}

// Get user info from Google
$accessToken = $tokenDataResponse['access_token'];
$userInfoUrl = 'https://www.googleapis.com/oauth2/v2/userinfo?access_token=' . urlencode($accessToken);
$userInfoResponse = @file_get_contents($userInfoUrl);

if ($userInfoResponse === false) {
    error_log("User info fetch failed");
    header('Location: ../index.php?error=user_info_failed');
    exit;
}

$userInfo = json_decode($userInfoResponse, true);

if (!isset($userInfo['id']) || !isset($userInfo['email'])) {
    error_log("Invalid user data: " . json_encode($userInfo));
    header('Location: ../index.php?error=invalid_user_data');
    exit;
}

$googleId = $userInfo['id'];
$email = $userInfo['email'];
$firstName = $userInfo['given_name'] ?? '';
$lastName = $userInfo['family_name'] ?? '';
$picture = $userInfo['picture'] ?? null;

// Check if user with this Google ID or email exists
$stmt = $conn->prepare("SELECT id, username, email FROM users WHERE google_id = ? OR email = ?");
if (!$stmt) {
    error_log("Prepare failed: " . $conn->error);
    header('Location: ../index.php?error=database_error');
    exit;
}

$stmt->bind_param("ss", $googleId, $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    // User exists - update and log in
    $user = $result->fetch_assoc();
    $stmt->close();
    
    // Update Google ID and provider if not set
    $updateStmt = $conn->prepare("UPDATE users SET google_id = ?, auth_provider = 'google', profile_picture = ? WHERE id = ?");
    if (!$updateStmt) {
        error_log("Update prepare failed: " . $conn->error);
        header('Location: ../index.php?error=database_error');
        exit;
    }
    
    $updateStmt->bind_param("ssi", $googleId, $picture, $user['id']);
    $updateStmt->execute();
    $updateStmt->close();
    
    $_SESSION['userId'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['email'] = $user['email'];
    error_log("Existing user logged in: " . $user['id']);
} else {
    // New user - create account
    $stmt->close();
    
    // Generate unique username
    $baseUsername = strstr($email, '@', true);
    $username = $baseUsername;
    $counter = 1;
    
    while (true) {
        $checkStmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
        if (!$checkStmt) {
            error_log("Check prepare failed: " . $conn->error);
            header('Location: ../index.php?error=database_error');
            exit;
        }
        
        $checkStmt->bind_param("s", $username);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        $checkStmt->close();
        
        if ($checkResult->num_rows === 0) {
            break;
        }
        
        $username = $baseUsername . $counter;
        $counter++;
    }
    
    // Create random password for Google users
    $hashedPassword = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
    
    // Insert new user
    $insertStmt = $conn->prepare("
        INSERT INTO users (username, email, password_hash, first_name, last_name, google_id, auth_provider, profile_picture)
        VALUES (?, ?, ?, ?, ?, ?, 'google', ?)
    ");
    
    if (!$insertStmt) {
        error_log("Insert prepare failed: " . $conn->error);
        header('Location: ../index.php?error=database_error');
        exit;
    }
    
    $insertStmt->bind_param("sssssss", $username, $email, $hashedPassword, $firstName, $lastName, $googleId, $picture);
    
    if (!$insertStmt->execute()) {
        error_log("Insert execute failed: " . $insertStmt->error);
        header('Location: ../index.php?error=account_creation_failed');
        $insertStmt->close();
        exit;
    }
    
    $userId = $conn->insert_id;
    $insertStmt->close();
    error_log("New user created: " . $userId);
    
    $_SESSION['userId'] = $userId;
    $_SESSION['username'] = $username;
    $_SESSION['email'] = $email;
}

error_log("Redirecting to dashboard for user: " . $_SESSION['userId']);
header('Location: ../dashboard.php');
exit;
?>
