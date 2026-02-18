<?php
session_start();
if (!isset($_SESSION['username'])) {
    header('Location: index.php');
    exit();
}
include 'config.php';

$error = '';
$success = '';
$subject_data = null;

// Fetch subject data if ID is provided
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $subject_id = $conn->real_escape_string($_GET['id']);
    $sql_select = "SELECT subject_id, subject_name FROM subject WHERE subject_id = ?";
    $stmt_select = $conn->prepare($sql_select);
    if ($stmt_select) {
        $stmt_select->bind_param("i", $subject_id);
        $stmt_select->execute();
        $result_select = $stmt_select->get_result();
        if ($result_select->num_rows == 1) {
            $subject_data = $result_select->fetch_assoc();
        } else {
            $error = "Subject not found.";
        }
        $stmt_select->close();
    } else {
        $error = "Error preparing statement: " . $conn->error;
    }
} else {
    $error = "Invalid subject ID.";
}

// Handle form submission for updating subject
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_subject'])) {
    $subject_id = $conn->real_escape_string($_POST['subject_id']);
    $subject_name = $conn->real_escape_string($_POST['subject_name']);

    $sql_update = "UPDATE subject SET subject_name = ? WHERE subject_id = ?";
    $stmt_update = $conn->prepare($sql_update);

    if ($stmt_update) {
        $stmt_update->bind_param("si", $subject_name, $subject_id);
        if ($stmt_update->execute()) {
            $success = "Subject updated successfully!";
            // Optionally, redirect back to view_subjects.php
            header("Location: view_subjects.php?success=" . urlencode($success));
            exit();
        } else {
            $error = "Error updating subject: " . $stmt_update->error;
        }
        $stmt_update->close();
    } else {
        $error = "Error preparing statement: " . $conn->error;
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Edit Subject</title>
    <style>
        /* Same styling as add_subject.php */
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
        input {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }
        button {
            background-color: #007bff;
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        button:hover {
            background-color: #0056b3;
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
        <h2>Edit Subject</h2>

        <?php if (isset($success)): ?>
            <div class="success"><?= $success ?></div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="error"><?= $error ?></div>
        <?php endif; ?>

        <?php if ($subject_data): ?>
            <form method="post">
                <input type="hidden" name="subject_id" value="<?= $subject_data['subject_id'] ?>">
                <div class="form-group">
                    <label for="subject_name">Subject Name:</label>
                    <input type="text" id="subject_name" name="subject_name" value="<?= htmlspecialchars($subject_data['subject_name']) ?>" required>
                </div>

                <div class="form-group">
                    <button type="submit" name="update_subject">Update Subject</button>
                    <a href="view_subjects.php" style="margin-left: 10px;">Cancel</a>
                </div>
            </form>
        <?php else: ?>
            <p><?= $error ?></p>
            <p><a href="view_subjects.php">Back to Subject List</a></p>
        <?php endif; ?>
    </div>
</body>
</html>