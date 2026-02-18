<?php 
include 'config.php';
session_start();
if (!isset($_SESSION['username'])) {
    header('Location: index.php');
    exit();
}
// Fetch data for dropdowns
$students = $pdo->query("SELECT student_id, CONCAT(fname, ' ', lname) AS name FROM student")->fetchAll(PDO::FETCH_ASSOC);
$subjects = $pdo->query("SELECT subject_id, subject_name FROM subject")->fetchAll(PDO::FETCH_ASSOC);
$exams = $pdo->query("SELECT exam_id, exam_name FROM examination")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html>
<head>
    <link rel="stylesheet" href="assets/css/site.css">
    <title>Enter Marks</title>
</head>
<body>
    <h2>Enter Student Marks</h2>
    <form action="process_marks.php" method="post">
        Student: 
        <select name="student_id" required>
            <?php foreach ($students as $student): ?>
                <option value="<?= $student['student_id'] ?>"><?= $student['name'] ?></option>
            <?php endforeach; ?>
        </select><br>
        
        Subject: 
        <select name="subject_id" required>
            <?php foreach ($subjects as $subject): ?>
                <option value="<?= $subject['subject_id'] ?>"><?= $subject['subject_name'] ?></option>
            <?php endforeach; ?>
        </select><br>
        
        Exam: 
        <select name="exam_id" required>
            <?php foreach ($exams as $exam): ?>
                <option value="<?= $exam['exam_id'] ?>"><?= $exam['exam_name'] ?></option>
            <?php endforeach; ?>
        </select><br>
        
        Marks Obtained: <input type="number" step="0.01" name="marks_obtained" required><br>
        
        <input type="submit" value="Save Marks">
    </form>
</body>
</html>