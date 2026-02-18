<?php
// Enable error reporting for debugging (remove in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['username'])) {
    header('Location: index.php');
    exit();
}
include 'config.php';

// Initialize variables for messages
$success = $error = '';
$stmt_delete = null; // Initialize statement outside the conditional block

try {
    // Handle delete action
    if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
        $delete_id = $conn->real_escape_string($_GET['delete']);

        // Prepare the delete statement
        $sql_delete = "DELETE FROM student WHERE student_id = ?";
        $stmt_delete = $conn->prepare($sql_delete);

        if ($stmt_delete) { // Check if prepare was successful
            $stmt_delete->bind_param("i", $delete_id);

            if ($stmt_delete->execute()) {
                $success = "Student deleted successfully!";
                // Redirect back to the list of students with a success message
                header("Location: view_students.php?success=" . urlencode($success));
                exit();
            } else {
                $error = "Error deleting student: " . $stmt_delete->error;
                // Redirect back to the list of students with an error message
                header("Location: view_students.php?error=" . urlencode($error));
                exit();
            }
        } else {
            $error = "Error preparing delete statement: " . $conn->error;
            header("Location: view_students.php?error=" . urlencode($error));
            exit();
        }
    } elseif (isset($_GET['delete'])) {
        // If delete parameter is present but not numeric
        $error = "Invalid student ID format for deletion.";
        header("Location: view_students.php?error=" . urlencode($error));
        exit();
    }
} catch (Exception $e) {
    $error = "An unexpected error occurred: " . $e->getMessage();
    header("Location: view_students.php?error=" . urlencode($error));
    exit();
} finally {
    // Close the prepared statement if it was created
    if ($stmt_delete) {
        $stmt_delete->close();
    }
    // Close the database connection
    if ($conn) {
        $conn->close();
    }
}
?>