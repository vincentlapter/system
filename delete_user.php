<?php
session_start();
include 'config.php';

// Authorization: Ensure only admin can perform deletion
if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'Administrator') {
    $_SESSION['error_message'] = "Access Denied.";
    header('Location: index.php');
    exit();
}

// Validate and sanitize input
$user_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$user_id) {
    $_SESSION['error_message'] = "Invalid user ID.";
    header('Location: admin_dashboard.php');
    exit();
}

// Prevent self-deletion (optional but recommended)
$current_user_stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
$current_user_stmt->bind_param("s", $_SESSION['username']);
$current_user_stmt->execute();
$current_user_stmt->bind_result($current_user_id);
$current_user_stmt->fetch();
$current_user_stmt->close();

if ($user_id == $current_user_id) {
    $_SESSION['error_message'] = "You cannot delete your own account!";
    header("Location: admin_dashboard.php");
    exit();
}

// Delete user from the database
$stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    if ($stmt->execute()) {
        $_SESSION['success_message'] = "User deleted successfully.";
    } else {
        $_SESSION['error_message'] = "Error deleting user: " . $stmt->error;
    }
    $stmt->close();
} else {
    $_SESSION['error_message'] = "Database error: " . $conn->error;
}

header("Location: admin_dashboard.php");
exit();
?>
