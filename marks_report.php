<?php include 'config.php';
session_start();
if (!isset($_SESSION['username'])) {
    header('Location: index.php');
    exit();
} ?>

<!DOCTYPE html>
<html>
<head>
    <title>Marks Report</title>
</head>
<body>
    <h2>Student Marks Report</h2>
    <table border="1">
        <tr>
            <th>Student</th>
            <th>Subject</th>
            <th>Exam</th>
            <th>Marks</th>
        </tr>
        <?php
        $stmt = $pdo->query("
            SELECT st.fname, st.lname, su.subject_name, e.exam_name, m.marks_obtained
            FROM marks m
            JOIN student st ON m.student_id = st.student_id
            JOIN subject su ON m.subject_id = su.subject_id
            JOIN examination e ON m.exam_id = e.exam_id
            ORDER BY st.lname, st.fname, su.subject_name
        ");
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo "<tr>";
            echo "<td>" . $row['fname'] . " " . $row['lname'] . "</td>";
            echo "<td>" . $row['subject_name'] . "</td>";
            echo "<td>" . $row['exam_name'] . "</td>";
            echo "<td>" . $row['marks_obtained'] . "</td>";
            echo "</tr>";
        }
        ?>
    </table>
</body>
</html>