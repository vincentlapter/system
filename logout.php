<?php
// Start output buffering to prevent header issues
ob_start();

// Error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Session configuration - MUST match login.php exactly
ini_set('session.use_cookies', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);

// Try to find a writable session directory - MUST match login.php
$possiblePaths = [
    '/tmp',
    sys_get_temp_dir(),
    '/home/schsys.laptertech.store/tmp',
    '/home/schsys.laptertech.store/php_sessions'
];

foreach ($possiblePaths as $path) {
    if (is_dir($path) && is_writable($path)) {
        ini_set('session.save_path', $path);
        break;
    }
}

// Start session
session_start();

// Debug logout
error_log("=== LOGOUT PROCESS ===");
error_log("User logging out: " . ($_SESSION['username'] ?? 'Unknown'));
error_log("Session ID before destroy: " . session_id());

// Destroy all session data
$_SESSION = [];

// Delete the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), 
        '', 
        time() - 42000,
        $params["path"], 
        $params["domain"],
        $params["secure"], 
        $params["httponly"]
    );
}

// Destroy the session
if (session_destroy()) {
    error_log("Session destroyed successfully");
} else {
    error_log("Failed to destroy session");
}

// Clear any output buffer
ob_end_clean();

// Redirect to login page with logout parameter
header('Location: index.php?logout=1');
exit();
?>