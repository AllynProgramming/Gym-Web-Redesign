<?php
// includes/auth.php
// Authentication helper functions

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function getAppBasePath() {
    $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '/';
    $scriptDir = rtrim(dirname($scriptPath), '/');

    if ($scriptDir !== '' && basename($scriptDir) === 'api') {
        $scriptDir = dirname($scriptDir);
    }

    return $scriptDir === '' ? '/' : $scriptDir;
}

function redirectToLoginPage() {
    $basePath = getAppBasePath();
    header('Location: ' . $basePath . '/index.php');
    exit;
}

/**
 * Check if user is logged in
 * Redirects to login page if not authenticated
 */
function requireLogin() {
    if (!isset($_SESSION['userId'])) {
        redirectToLoginPage();
    }
}

/**
 * Check if user is logged in (returns boolean)
 */
function isLoggedIn() {
    return isset($_SESSION['userId']);
}

/**
 * Get current logged in user's ID
 */
function getUserId() {
    return $_SESSION['userId'] ?? null;
}

/**
 * Get current logged in user's username
 */
function getUsername() {
    return $_SESSION['username'] ?? null;
}

/**
 * Logout user and redirect to login page
 */
function logout() {
    session_destroy();
    redirectToLoginPage();
}

/**
 * Verify that a workout/exercise belongs to the current user
 * Prevents users from accessing other users' data
 */
function verifyOwnership($conn, $tableName, $recordId) {
    $userId = getUserId();
    
    if (!$userId) {
        return false;
    }

    // Determine the foreign key based on table
    $userColumn = 'user_id';
    
    if ($tableName === 'exercises') {
        // For exercises, we need to check through workout_sessions
        $stmt = $conn->prepare("
            SELECT e.id FROM exercises e
            JOIN workout_sessions ws ON e.session_id = ws.id
            WHERE e.id = ? AND ws.user_id = ?
        ");
        $stmt->bind_param("ii", $recordId, $userId);
    } else if ($tableName === 'workout_sessions') {
        $stmt = $conn->prepare("SELECT id FROM workout_sessions WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $recordId, $userId);
    } else if ($tableName === 'workout_plans') {
        $stmt = $conn->prepare("SELECT id FROM workout_plans WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $recordId, $userId);
    } else {
        return false;
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();

    return $result->num_rows === 1;
}

/**
 * Get user info from database
 */
function getUserInfo($conn, $userId) {
    $stmt = $conn->prepare("
        SELECT id, username, email, first_name, last_name, is_profile_public
        FROM users
        WHERE id = ?
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    return $user;
}

/**
 * Check if the given user is an admin — identified by an @admin.com email address.
 */
function isAdminUser($conn, $userId) {
    $user = getUserInfo($conn, $userId);
    if (!$user || empty($user['email'])) {
        return false;
    }
    return str_ends_with(strtolower($user['email']), '@admin.com');
}

/**
 * Redirect non-admins away from admin-only pages. Call after requireLogin().
 */
function requireAdmin($conn, $userId) {
    if (!isAdminUser($conn, $userId)) {
        header('Location: dashboard.php');
        exit;
    }
}

/**
 * Check if one user can view another user's profile
 */
function canViewProfile($conn, $viewingUserId, $profileUserId) {
    // Users can always see their own profile
    if ($viewingUserId === $profileUserId) {
        return true;
    }

    // Check if profile is public
    $stmt = $conn->prepare("SELECT is_profile_public FROM users WHERE id = ?");
    $stmt->bind_param("i", $profileUserId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($result['is_profile_public']) {
        return true;
    }

    // Check if they are friends
    $stmt = $conn->prepare("
        SELECT id FROM friendships
        WHERE user_id = ? AND friend_id = ? AND status = 'accepted'
    ");
    $stmt->bind_param("ii", $profileUserId, $viewingUserId);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();

    return $result->num_rows === 1;
}
?>