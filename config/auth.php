<?php
/**
 * DARN Dashboard - Authentication Guard
 * Include this file to protect pages and APIs.
 * Pages: redirects to /login.php
 * APIs: returns 401 JSON
 */

// Session config (before session_start)
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Legacy env var credentials (fallback if dashboard_users table is empty)
define('AUTH_USER', getenv('DASHBOARD_USER') ?: '74719225');
define('AUTH_PASS', getenv('DASHBOARD_PASS') ?: '');

// getCurrentUser() is defined in database.php (available to all files including api_android.php)
// getCurrentUserRole() is only needed by dashboard pages (which always include auth.php)
if (!function_exists('getCurrentUserRole')) {
    function getCurrentUserRole() {
        return $_SESSION['user_role'] ?? 'user';
    }
}

// Don't guard login.php itself
$currentScript = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
if ($currentScript === 'login.php') {
    return;
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: /login.php');
    exit;
}

// Check if logged in
if (empty($_SESSION['logged_in'])) {
    $isApi = (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false);
    if ($isApi) {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    header('Location: /login.php');
    exit;
}

/**
 * Check rate limit for login attempts.
 * Returns minutes remaining if locked, or 0 if OK.
 */
function checkRateLimit($db, $ip) {
    // Clean old attempts (> 15 min)
    $db->prepare("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)")->execute();

    $stmt = $db->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
    $stmt->execute([$ip]);
    $count = (int)$stmt->fetchColumn();

    if ($count >= 5) {
        $stmt = $db->prepare("SELECT attempted_at FROM login_attempts WHERE ip_address = ? ORDER BY attempted_at DESC LIMIT 1");
        $stmt->execute([$ip]);
        $last = $stmt->fetchColumn();
        $unlockAt = strtotime($last) + (15 * 60);
        $remaining = ceil(($unlockAt - time()) / 60);
        return max(1, $remaining);
    }
    return 0;
}

/**
 * Record a failed login attempt.
 */
function recordFailedAttempt($db, $ip) {
    $db->prepare("INSERT INTO login_attempts (ip_address, attempted_at) VALUES (?, NOW())")->execute([$ip]);
}

/**
 * Clear attempts after successful login.
 */
function clearAttempts($db, $ip) {
    $db->prepare("DELETE FROM login_attempts WHERE ip_address = ?")->execute([$ip]);
}
