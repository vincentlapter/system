<?php
session_start();
if (!isset($_SESSION['username'])) {
    header('Location: index.php');
    exit();
}
include 'config.php';

if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $subject_id_to_delete = $conn->real_escape_string($_GET['id']);

    // It's crucial to check if this subject is linked to any other tables (e.g., teacher, exam_subjects)
    // before deleting to maintain data integrity.

    // Example: Check if any teachers are assigned to this subject
    $sql_check_teacher = "SELECT COUNT(*) FROM teacher WHERE subject_id = ?";
    $stmt_check_teacher = $conn->prepare($sql_check_teacher);
    $stmt_check_teacher->bind_param("i", $subject_id_to_delete);
    $stmt_check_teacher->execute();
    $result_check_teacher = $stmt_check_teacher->get_result();
    $teacher_count = $result_check_teacher->fetch_row()[0];
    $stmt_check_teacher->close();

    if ($teacher_count > 0) {
        $_SESSION['error'] = "Cannot delete subject. It is currently assigned to one or more teachers.";
        header("Location: view_subjects.php");
        exit();
    }

    // Example: Check if this subject is used in any exams
    $sql_check_exam = "SELECT COUNT(*) FROM exam_subjects WHERE subject_id = ?";
    $stmt_check_exam = $conn->prepare($sql_check_exam);
    $stmt_check_exam->bind_param("i", $subject_id_to_delete);
    $stmt_check_exam->execute();
    $result_check_exam = $stmt_check_exam->get_result();
    $exam_count = $result_check_exam->fetch_row()[0];
    $stmt_check_exam->close();

    if ($exam_count > 0) {
        $_SESSION['error'] = "Cannot delete subject. It is currently used in one or more exams.";
        header("Location: view_subjects.php");
        exit();
    }

    // If no links found, proceed with deletion
    $sql_delete = "DELETE FROM subject WHERE subject_id = ?";
    $stmt_delete = $conn->prepare($sql_delete);

    if ($stmt_delete) {
        $stmt_delete->bind_param("i", $subject_id_to_delete);
        if ($stmt_delete->execute()) {
            $_SESSION['success'] = "Subject deleted successfully!";
        } else {
            $_SESSION['error'] = "Error deleting subject: " . $stmt_delete->error;
        }
        $stmt_delete->close();
    } else {
        $_SESSION['error'] = "Error preparing statement: " . $conn->error;
    }
} else {
    $_SESSION['error'] = "Invalid subject ID.";
}

$conn->close();
header("Location: view_subjects.php");
exit();
?>