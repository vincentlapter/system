<?php
include 'config.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $student_id = $_POST['student_id'];
    $subject_id = $_POST['subject_id'];
    $exam_id = $_POST['exam_id'];
    $marks_obtained = $_POST['marks_obtained'];

    try {
        $stmt = $pdo->prepare("INSERT INTO marks (student_id, subject_id, exam_id, marks_obtained) VALUES (?, ?, ?, ?)");
        $stmt->execute([$student_id, $subject_id, $exam_id, $marks_obtained]);
        echo "Marks saved successfully!";
    } catch (PDOException $e) {
        echo "Error: " . $e->getMessage();
    }
}
?>