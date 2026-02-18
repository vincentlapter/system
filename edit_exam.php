<?php
include 'config.php';
session_start();
if (!isset($_SESSION['username'])) {
    header('Location: index.php');
    exit();
}

// Initialize variables
$success = $error = '';
$exam = null;
$subjects = [];
$selected_subjects = [];

// Get exam ID from the query string
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $exam_id = $conn->real_escape_string($_GET['id']);

    // Fetch exam details
    $sql_exam = "SELECT exam_id, exam_name, exam_date FROM examination WHERE exam_id = ?";
    $stmt_exam = $conn->prepare($sql_exam);
    $stmt_exam->bind_param("i", $exam_id);
    $stmt_exam->execute();
    $result_exam = $stmt_exam->get_result();

    if ($result_exam && $result_exam->num_rows == 1) {
        $exam = $result_exam->fetch_assoc();
    } else {
        $error = "Exam not found.";
    }
    $stmt_exam->close();

    // Fetch selected subjects for this exam
    $sql_selected_subjects = "SELECT subject_id FROM exam_subjects WHERE exam_id = ?";
    $stmt_selected_subjects = $conn->prepare($sql_selected_subjects);
    $stmt_selected_subjects->bind_param("i", $exam_id);
    $stmt_selected_subjects->execute();
    $result_selected_subjects = $stmt_selected_subjects->get_result();

    if ($result_selected_subjects && $result_selected_subjects->num_rows > 0) {
        while ($row = $result_selected_subjects->fetch_assoc()) {
            $selected_subjects[] = $row['subject_id'];
        }
    }
    $stmt_selected_subjects->close();
} else {
    $error = "Invalid exam ID.";
}

// Fetch all subjects for checkboxes
$sql_all_subjects = "SELECT s.subject_id, s.subject_name FROM subject s ORDER BY s.subject_name";
$result_all_subjects = $conn->query($sql_all_subjects);

if ($result_all_subjects && $result_all_subjects->num_rows > 0) {
    while ($row = $result_all_subjects->fetch_assoc()) {
        $subjects[] = $row;
    }
}

// Handle form submission for update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_exam'])) {
    $exam_id_update = $conn->real_escape_string($_POST['exam_id']);
    $exam_name = $conn->real_escape_string($_POST['exam_name']);
    $exam_date = $conn->real_escape_string($_POST['exam_date']);

    // Start transaction
    $conn->begin_transaction();

    try {
        // Update exam details
        $stmt_update_exam = $conn->prepare("UPDATE examination SET exam_name = ?, exam_date = ? WHERE exam_id = ?");
        $stmt_update_exam->bind_param("ssi", $exam_name, $exam_date, $exam_id_update);
        $stmt_update_exam->execute();
        $stmt_update_exam->close();

        // Delete existing exam subjects
        $stmt_delete_subjects = $conn->prepare("DELETE FROM exam_subjects WHERE exam_id = ?");
        $stmt_delete_subjects->bind_param("i", $exam_id_update);
        $stmt_delete_subjects->execute();
        $stmt_delete_subjects->close();

        // Insert selected subjects
        if (!empty($_POST['subjects'])) {
            $stmt_insert_subjects = $conn->prepare("INSERT INTO exam_subjects (exam_id, subject_id) VALUES (?, ?)");
            foreach ($_POST['subjects'] as $subject_id) {
                $subject_id = (int)$subject_id;
                $stmt_insert_subjects->bind_param("ii", $exam_id_update, $subject_id);
                $stmt_insert_subjects->execute();
            }
            $stmt_insert_subjects->close();
        }

        $conn->commit();
        $success = "Exam updated successfully!";

        // Refresh exam data after successful update
        $sql_exam = "SELECT exam_id, exam_name, exam_date FROM examination WHERE exam_id = ?";
        $stmt_exam = $conn->prepare($sql_exam);
        $stmt_exam->bind_param("i", $exam_id_update);
        $stmt_exam->execute();
        $result_exam = $stmt_exam->get_result();
        if ($result_exam && $result_exam->num_rows == 1) {
            $exam = $result_exam->fetch_assoc();
        }
        $stmt_exam->close();

        $selected_subjects = [];
        $sql_selected_subjects = "SELECT subject_id FROM exam_subjects WHERE exam_id = ?";
        $stmt_selected_subjects = $conn->prepare($sql_selected_subjects);
        $stmt_selected_subjects->bind_param("i", $exam_id_update);
        $stmt_selected_subjects->execute();
        $result_selected_subjects = $stmt_selected_subjects->get_result();
        if ($result_selected_subjects && $result_selected_subjects->num_rows > 0) {
            while ($row = $result_selected_subjects->fetch_assoc()) {
                $selected_subjects[] = $row['subject_id'];
            }
        }
        $stmt_selected_subjects->close();

    } catch (Exception $e) {
        $conn->rollback();
        $error = "Error updating exam: " . $e->getMessage();
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html>
<head>
    <link rel="stylesheet" href="assets/css/site.css">
    <title>Edit Exam</title>
    <style>
        .form-container {
            max-width: 600px;
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
        input[type="text"],
        input[type="date"],
        select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }
        .checkbox-group {
            margin: 10px 0;
        }
        .checkbox-item {
            display: inline-block;
            margin-right: 15px;
            margin-bottom: 10px;
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
        .back-link {
            display: block;
            margin-top: 10px;
            color: #6c757d;
            text-decoration: none;
        }
        .back-link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="form-container">
        <h2>Edit Exam</h2>

        <?php if (!empty($success)): ?>
            <div class="success"><?php echo $success; ?></div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>

        <?php if ($exam): ?>
            <form method="post">
                <input type="hidden" name="exam_id" value="<?php echo htmlspecialchars($exam['exam_id']); ?>">

                <div class="form-group">
                    <label for="exam_name">Exam Name:</label>
                    <input type="text" id="exam_name" name="exam_name" value="<?php echo htmlspecialchars($exam['exam_name']); ?>" required>
                </div>

                <div class="form-group">
                    <label for="exam_date">Exam Date:</label>
                    <input type="date" id="exam_date" name="exam_date" value="<?php echo htmlspecialchars($exam['exam_date']); ?>" required>
                </div>

                <div class="form-group">
                    <label>Subjects:</label>
                    <div class="checkbox-group">
                        <?php if (!empty($subjects)): ?>
                            <?php foreach ($subjects as $subject): ?>
                                <div class="checkbox-item">
                                    <input type="checkbox"
                                           id="subject_<?php echo $subject['subject_id']; ?>"
                                           name="subjects[]"
                                           value="<?php echo $subject['subject_id']; ?>"
                                           <?php echo in_array($subject['subject_id'], $selected_subjects) ? 'checked' : ''; ?>>
                                    <label for="subject_<?php echo $subject['subject_id']; ?>">
                                        <?php echo htmlspecialchars($subject['subject_name']); ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p>No subjects available. Please add subjects first.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-group">
                    <button type="submit" name="update_exam">Update Exam</button>
                    <a href="view_exams.php" class="back-link">Back to View Exams</a>
                </div>
            </form>
        <?php else: ?>
            <p><?php echo $error; ?></p>
            <a href="view_exams.php" class="back-link">Back to View Exams</a>
        <?php endif; ?>
    </div>
</body>
</html>