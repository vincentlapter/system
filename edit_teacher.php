<?php
include 'config.php';
session_start();
if (!isset($_SESSION['username'])) {
    header('Location: index.php');
    exit();
}

// Initialize variables
$teacher = null;
$subjects = [];
$classes = [];
$teacher_subjects = [];
$teacher_classes = [];

// Fetch all subjects
$sql_subjects = "SELECT subject_id, subject_name FROM subject ORDER BY subject_name";
$result_subjects = $conn->query($sql_subjects);
if ($result_subjects && $result_subjects->num_rows > 0) {
    while ($row = $result_subjects->fetch_assoc()) {
        $subjects[] = $row;
    }
}

// Fetch all classes
$sql_classes = "SELECT class_id, class_name FROM class ORDER BY class_name";
$result_classes = $conn->query($sql_classes);
if ($result_classes && $result_classes->num_rows > 0) {
    while ($row = $result_classes->fetch_assoc()) {
        $classes[] = $row;
    }
}

// Fetch teacher data
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $teacher_id = $_GET['edit'];
    
    // Basic teacher info
    $sql_teacher = "SELECT * FROM teacher WHERE teacher_id = ?";
    $stmt = $conn->prepare($sql_teacher);
    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $teacher = $result->fetch_assoc();
    $stmt->close();

    // Teacher's subjects
    $sql_teacher_subjects = "SELECT subject_id FROM teacher_subject WHERE teacher_id = ?";
    $stmt = $conn->prepare($sql_teacher_subjects);
    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $teacher_subjects[] = $row['subject_id'];
    }
    $stmt->close();

    // Teacher's classes
    $sql_teacher_classes = "SELECT class_id FROM teacher_class WHERE teacher_id = ?";
    $stmt = $conn->prepare($sql_teacher_classes);
    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $teacher_classes[] = $row['class_id'];
    }
    $stmt->close();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_teacher'])) {
    $teacher_id = $_POST['teacher_id'];
    $fname = $_POST['fname'];
    $lname = $_POST['lname'];
    $dob = $_POST['dob'];
    $contact = $_POST['contact'];
    $gender = $_POST['gender'];
    $new_subjects = $_POST['subjects'] ?? [];
    $new_classes = $_POST['classes'] ?? [];

    try {
        $conn->begin_transaction();

        // Update basic teacher info
        $sql = "UPDATE teacher SET fname=?, lname=?, dob=?, contact=?, gender=? WHERE teacher_id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssssi", $fname, $lname, $dob, $contact, $gender, $teacher_id);
        $stmt->execute();
        $stmt->close();

        // Update subjects - first delete existing, then insert new
        $conn->query("DELETE FROM teacher_subject WHERE teacher_id = $teacher_id");
        foreach ($new_subjects as $subject_id) {
            $sql = "INSERT INTO teacher_subject (teacher_id, subject_id) VALUES (?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ii", $teacher_id, $subject_id);
            $stmt->execute();
            $stmt->close();
        }

        // Update classes - first delete existing, then insert new
        $conn->query("DELETE FROM teacher_class WHERE teacher_id = $teacher_id");
        foreach ($new_classes as $class_id) {
            $sql = "INSERT INTO teacher_class (teacher_id, class_id) VALUES (?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ii", $teacher_id, $class_id);
            $stmt->execute();
            $stmt->close();
        }

        $conn->commit();
        $_SESSION['success'] = "Teacher updated successfully!";
        header("Location: view_teachers.php");
        exit();
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = "Error updating teacher: " . $e->getMessage();
        header("Location: edit_teacher.php?edit=$teacher_id");
        exit();
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <link rel="stylesheet" href="assets/css/site.css">
    <title>Edit Teacher</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { padding: 20px; background-color: #f8f9fa; }
        .form-container {
            max-width: 600px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        h2 { margin-bottom: 20px; color: #343a40; }
        select[multiple] { height: 150px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="form-container">
            <h2>Edit Teacher</h2>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger"><?= $_SESSION['error']; unset($_SESSION['error']); ?></div>
            <?php endif; ?>

            <?php if ($teacher): ?>
                <form method="post">
                    <input type="hidden" name="teacher_id" value="<?= $teacher['teacher_id'] ?>">

                    <div class="mb-3">
                        <label for="fname" class="form-label">First Name:</label>
                        <input type="text" class="form-control" id="fname" name="fname" 
                               value="<?= htmlspecialchars($teacher['fname']) ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="lname" class="form-label">Last Name:</label>
                        <input type="text" class="form-control" id="lname" name="lname" 
                               value="<?= htmlspecialchars($teacher['lname']) ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="dob" class="form-label">Date of Birth:</label>
                        <input type="date" class="form-control" id="dob" name="dob" 
                               value="<?= htmlspecialchars($teacher['dob']) ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="contact" class="form-label">Contact:</label>
                        <input type="text" class="form-control" id="contact" name="contact" 
                               value="<?= htmlspecialchars($teacher['contact']) ?>">
                    </div>
                    
                    <div class="mb-3">
                        <label for="gender" class="form-label">Gender:</label>
                        <select class="form-select" id="gender" name="gender" required>
                            <option value="">Select Gender</option>
                            <option value="Male" <?= $teacher['gender'] == 'Male' ? 'selected' : '' ?>>Male</option>
                            <option value="Female" <?= $teacher['gender'] == 'Female' ? 'selected' : '' ?>>Female</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="subjects" class="form-label">Subjects:</label>
                        <select name="subjects[]" id="subjects" class="form-select" multiple required>
                            <?php foreach ($subjects as $subject): ?>
                                <option value="<?= $subject['subject_id'] ?>"
                                    <?= in_array($subject['subject_id'], $teacher_subjects) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($subject['subject_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Hold Ctrl/Cmd to select multiple</small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="classes" class="form-label">Classes:</label>
                        <select name="classes[]" id="classes" class="form-select" multiple required>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?= $class['class_id'] ?>"
                                    <?= in_array($class['class_id'], $teacher_classes) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($class['class_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Hold Ctrl/Cmd to select multiple</small>
                    </div>
                    
                    <button type="submit" name="update_teacher" class="btn btn-primary">Update Teacher</button>
                    <a href="view_teachers.php" class="btn btn-secondary">Cancel</a>
                </form>
            <?php else: ?>
                <div class="alert alert-danger">Teacher not found.</div>
                <a href="view_teachers.php" class="btn btn-secondary">Back to Teachers</a>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>