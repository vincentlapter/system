<?php
include 'config.php';
session_start();

// Permission check
function has_permission($permission) {
    return true; // Replace with actual logic
}

if (!has_permission('manage_exams')) {
    $_SESSION['error'] = "You do not have permission to delete exams.";
    header("Location: view_exams.php");
    exit();
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error'] = "Invalid exam ID.";
    header("Location: view_exams.php");
    exit();
}

$exam_id_to_delete = $_GET['id'];

// Step 1: Get all exam_subjects IDs linked to the exam
$subject_ids = [];
$subject_query = $conn->prepare("SELECT id FROM exam_subjects WHERE exam_id = ?");
$subject_query->bind_param("i", $exam_id_to_delete);
$subject_query->execute();
$result = $subject_query->get_result();

while ($row = $result->fetch_assoc()) {
    $subject_ids[] = $row['id'];
}
$subject_query->close();

// Step 2: Delete all marks for each exam_subject ID
if (!empty($subject_ids)) {
    $placeholders = implode(',', array_fill(0, count($subject_ids), '?'));
    $types = str_repeat('i', count($subject_ids));
    
    $stmt_marks = $conn->prepare("DELETE FROM marks WHERE id IN ($placeholders)");
    $stmt_marks->bind_param($types, ...$subject_ids);
    
    if (!$stmt_marks->execute()) {
        $_SESSION['error'] = "Failed to delete marks: " . $stmt_marks->error;
        $stmt_marks->close();
        header("Location: view_exams.php");
        exit();
    }
    $stmt_marks->close();
}

// Step 3: Delete exam_subjects
$delete_subjects = $conn->prepare("DELETE FROM exam_subjects WHERE exam_id = ?");
$delete_subjects->bind_param("i", $exam_id_to_delete);
if (!$delete_subjects->execute()) {
    $_SESSION['error'] = "Failed to delete exam subjects: " . $delete_subjects->error;
    $delete_subjects->close();
    header("Location: view_exams.php");
    exit();
}
$delete_subjects->close();

// Step 4: Delete exam
$delete_exam = $conn->prepare("DELETE FROM examination WHERE exam_id = ?");
$delete_exam->bind_param("i", $exam_id_to_delete);
if ($delete_exam->execute()) {
    $_SESSION['message'] = "Exam deleted successfully.";
} else {
    $_SESSION['error'] = "Error deleting exam: " . $delete_exam->error;
}
$delete_exam->close();
$conn->close();

header("Location: view_exams.php");
exit();
?>
