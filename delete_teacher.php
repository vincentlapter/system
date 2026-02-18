<?php
include 'config.php';
session_start();

// Debug: Log all GET parameters
error_log("DELETE TEACHER - GET Parameters: " . print_r($_GET, true));

// Redirect if not logged in
if (!isset($_SESSION['username'])) {
    header('Location: index.php');
    exit();
}

// Verify database connection
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// Enable detailed error reporting
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Initialize messages
$success = $error = '';

// Validate delete request
if (isset($_GET['delete'])) {
    error_log("DELETE TEACHER - Received ID: " . $_GET['delete']); // Debug
    
    // Check if the ID is numeric
    if (!is_numeric($_GET['delete'])) {
        $error = "Invalid teacher ID format. Must be a number.";
    } else {
        $delete_id = (int)$_GET['delete']; // Force integer type
        
        try {
            // Verify teacher exists first
            $check_sql = "SELECT teacher_id FROM teacher WHERE teacher_id = ?";
            $stmt_check = $conn->prepare($check_sql);
            $stmt_check->bind_param("i", $delete_id);
            $stmt_check->execute();
            $stmt_check->store_result();
            
            if ($stmt_check->num_rows === 0) {
                throw new Exception("Teacher ID $delete_id does not exist.");
            }
            
            // Disable foreign key checks (if needed)
            $conn->query("SET FOREIGN_KEY_CHECKS = 0");
            
            // Prepare and execute delete
            $sql_delete = "DELETE FROM teacher WHERE teacher_id = ?";
            $stmt_delete = $conn->prepare($sql_delete);
            $stmt_delete->bind_param("i", $delete_id);
            
            if ($stmt_delete->execute()) {
                $success = "Teacher #$delete_id deleted successfully!";
            } else {
                throw new Exception("Failed to execute DELETE query.");
            }
            
            // Re-enable foreign key checks
            $conn->query("SET FOREIGN_KEY_CHECKS = 1");
            
        } catch (Exception $e) {
            // Ensure foreign key checks are re-enabled on error
            $conn->query("SET FOREIGN_KEY_CHECKS = 1");
            $error = "Error: " . $e->getMessage();
            error_log("DELETE TEACHER ERROR: " . $e->getMessage()); // Debug
        }
        
        // Close statements
        if (isset($stmt_check)) $stmt_check->close();
        if (isset($stmt_delete)) $stmt_delete->close();
    }
} else {
    $error = "No teacher ID specified for deletion.";
}

// Redirect with status message
$redirect_url = "view_teachers.php?" . ($success ? "success=" . urlencode($success) : "error=" . urlencode($error));
header("Location: " . $redirect_url);
exit();

$conn->close();
?>