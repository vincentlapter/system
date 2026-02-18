<?php
include 'config.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $fname = $_POST['fname'];
    $lname = $_POST['lname'];
    $dob = $_POST['dob'];
    $gender = $_POST['gender'];
    $class_id = $_POST['class_id'];

    $sql = "INSERT INTO student (fname, lname, dob, gender, class_id) VALUES (?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);

    if ($stmt) {
        $stmt->bind_param("ssssi", $fname, $lname, $dob, $gender, $class_id);
        if ($stmt->execute()) {
            echo "<script>
            alert('Student added successfully!');
            window.location.href = 'view_students.php';
        </script>";
        } else {
            echo "Error: " . $stmt->error;
        }
        $stmt->close();
    } else {
        echo "Error preparing statement: " . $conn->error;
    }
}

$conn->close();
?>