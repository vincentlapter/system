<?php
session_start();
if (!isset($_SESSION['username'])) {
    header('Location: index.php');
    exit();
}
include 'config.php';



// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $subject_name = $conn->real_escape_string($_POST['subject_name']);

    $sql_insert = "INSERT INTO subject (subject_name) VALUES (?)";
    $stmt_insert = $conn->prepare($sql_insert);

    if ($stmt_insert) {
        $stmt_insert->bind_param("s", $subject_name);
        if ($stmt_insert->execute()) {
            $success = "Subject added successfully!";
        } else {
            $error = "Error adding subject: " . $stmt_insert->error;
        }
        $stmt_insert->close();
    } else {
        $error = "Error preparing statement: " . $conn->error;
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Add New Subject</title>
    <style>
        /* Same styling as add_class.php */
        .form-container {
            max-width: 500px;
            margin: 20px auto;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 5px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        input, select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }
        button {
            background-color: #4CAF50;
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        button:hover {
            background-color: #45a049;
        }
        .success {
            color: green;
            margin-bottom: 15px;
        }
        .error {
            color: red;
            margin-bottom: 15px;
        }
    </style>
</head>
<body>
    <div class="form-container">
        <h2>Add New Subject</h2>

        <?php if (isset($success)): ?>
            <div class="success"><?= $success ?></div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="error"><?= $error ?></div>
        <?php endif; ?>

        <form method="post">
            <div class="form-group">
                <label for="subject_name">Subject Name:</label>
                <input type="text" id="subject_name" name="subject_name" required>
            </div>


            <div class="form-group">
                <button type="submit">Add Subject</button>
                <a href="view_subjects.php" style="margin-left: 10px;">View All Subjects</a>
            </div>
        </form>
    </div>
</body>
</html>