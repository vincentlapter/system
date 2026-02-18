<?php
include 'config.php';
session_start();
if (!isset($_SESSION['username'])) {
    header('Location: index.php');
    exit();
}

if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $class_id_delete = intval($_GET['id']);

    // Begin transaction
    $conn->begin_transaction();

    try {
        // 1. Delete related marks first (if needed)
        // Note: You may need to adjust this based on your actual schema
        $delete_marks_sql = "DELETE m FROM marks m 
                            JOIN student s ON m.student_id = s.student_id
                            WHERE s.class_id = ?";
        $stmt_marks = $conn->prepare($delete_marks_sql);
        $stmt_marks->bind_param("i", $class_id_delete);
        $stmt_marks->execute();
        $stmt_marks->close();

        // 2. Delete the class (will cascade to students and teacher_class)
        $sql_delete = "DELETE FROM class WHERE class_id = ?";
        $stmt_delete = $conn->prepare($sql_delete);
        $stmt_delete->bind_param("i", $class_id_delete);
        $stmt_delete->execute();
        
        if ($stmt_delete->affected_rows > 0) {
            $_SESSION['message'] = [
                'type' => 'success',
                'text' => 'Class and all associated data deleted successfully!'
            ];
        } else {
            $_SESSION['message'] = [
                'type' => 'error',
                'text' => 'Class not found or already deleted'
            ];
        }
        $stmt_delete->close();
        
        // Commit transaction
        $conn->commit();
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => 'Error deleting class: ' . $e->getMessage()
        ];
    }
} else {
    $_SESSION['message'] = [
        'type' => 'error',
        'text' => 'Invalid class ID.'
    ];
}

header("Location: view_classes.php");
exit();
?>