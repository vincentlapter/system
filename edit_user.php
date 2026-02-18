<?php
session_start();
include 'config.php';

// --- Admin Access Check ---
if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'Administrator') {
    header('Location: index.php');
    exit();
}

// --- Initialize Variables ---
$errors = [];
$success_message = '';
$user_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($user_id <= 0) {
    $_SESSION['error_message'] = "Invalid user ID.";
    header('Location: admin_dashboard.php');
    exit();
}

// --- Fetch Existing User Info ---
$stmt_user = $conn->prepare("SELECT id, username, role, linked_teacher_id FROM users WHERE id = ?");
$stmt_user->bind_param("i", $user_id);
$stmt_user->execute();
$result = $stmt_user->get_result();
$user = $result->fetch_assoc();
$stmt_user->close();

if (!$user) {
    $_SESSION['error_message'] = "User not found.";
    header('Location: admin_dashboard.php');
    exit();
}

// --- Fetch Teachers for Dropdown ---
$teachers = [];
$teacher_query = $conn->query("
    SELECT t.teacher_id, t.fname, t.lname 
    FROM teacher t 
    LEFT JOIN users u ON t.teacher_id = u.linked_teacher_id AND u.id != $user_id 
    WHERE u.id IS NULL OR u.linked_teacher_id IS NULL 
    ORDER BY t.lname, t.fname
");
while ($row = $teacher_query->fetch_assoc()) {
    $teachers[] = $row;
}

// --- Handle Form Submission ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password_raw = $_POST['password'] ?? '';
    $role = $_POST['role'];
    $teacher_id = isset($_POST['teacher_id']) ? (int)$_POST['teacher_id'] : null;

    // Validation
    if (empty($username) || strlen($username) < 3) {
        $errors[] = "Username must be at least 3 characters.";
    }

    if (!in_array($role, ['Teacher', 'Administrator'])) {
        $errors[] = "Invalid role selected.";
    }

    if ($role === 'Teacher') {
        if (!$teacher_id) {
            $errors[] = "Please select a teacher to link.";
        } else {
            // Validate teacher availability
            $stmt = $conn->prepare("SELECT teacher_id FROM teacher WHERE teacher_id = ? AND teacher_id NOT IN (SELECT linked_teacher_id FROM users WHERE id != ?)");
            $stmt->bind_param("ii", $teacher_id, $user_id);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows === 0) {
                $errors[] = "Selected teacher is already linked to another user.";
            }
            $stmt->close();
        }
    } else {
        $teacher_id = null; // Clear for Admins
    }

    // Check username uniqueness
    $stmt_check = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
    $stmt_check->bind_param("si", $username, $user_id);
    $stmt_check->execute();
    $stmt_check->store_result();
    if ($stmt_check->num_rows > 0) {
        $errors[] = "Username is already taken.";
    }
    $stmt_check->close();

    // Update if no errors
    if (empty($errors)) {
        if (!empty($password_raw) && strlen($password_raw) >= 6) {
            $hashed = password_hash($password_raw, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET username = ?, password = ?, role = ?, linked_teacher_id = ? WHERE id = ?");
            $stmt->bind_param("sssii", $username, $hashed, $role, $teacher_id, $user_id);
        } else {
            $stmt = $conn->prepare("UPDATE users SET username = ?, role = ?, linked_teacher_id = ? WHERE id = ?");
            $stmt->bind_param("ssii", $username, $role, $teacher_id, $user_id);
        }

        if ($stmt->execute()) {
            $success_message = "User updated successfully.";
            // Refresh user info
            header("Location: edit_user.php?id=$user_id&success=1");
            exit();
        } else {
            $errors[] = "Update failed: " . $stmt->error;
        }
        $stmt->close();
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="stylesheet" href="assets/css/site.css">
    <meta charset="UTF-8">
    <title>Edit User</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container mt-5">
    <div class="col-md-8 mx-auto">
        <h2 class="mb-3">Edit User Details</h2>
        <a href="admin_dashboard.php" class="btn btn-secondary btn-sm mb-3">&larr; Back to Dashboard</a>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <ul><?php foreach ($errors as $e) echo "<li>" . htmlspecialchars($e) . "</li>"; ?></ul>
            </div>
        <?php elseif (isset($_GET['success'])): ?>
            <div class="alert alert-success">User updated successfully!</div>
        <?php endif; ?>

        <form method="post">
            <div class="mb-3">
                <label for="username">Username <span class="text-danger">*</span></label>
                <input type="text" name="username" id="username" value="<?= htmlspecialchars($user['username']) ?>" class="form-control" required minlength="3">
            </div>

            <div class="mb-3">
                <label for="password">New Password (leave blank to keep current)</label>
                <input type="password" name="password" id="password" class="form-control" minlength="6">
            </div>

            <div class="mb-3">
                <label for="role">Role <span class="text-danger">*</span></label>
                <select name="role" id="role" class="form-select" required>
                    <option value="Administrator" <?= $user['role'] === 'Administrator' ? 'selected' : '' ?>>Administrator</option>
                    <option value="Teacher" <?= $user['role'] === 'Teacher' ? 'selected' : '' ?>>Teacher</option>
                </select>
            </div>

            <div class="mb-3" id="teacherSelectContainer" style="display: none;">
                <label for="teacher_id">Linked Teacher</label>
                <select name="teacher_id" id="teacher_id" class="form-select">
                    <option value="">-- Select Teacher --</option>
                    <?php foreach ($teachers as $teacher): ?>
                        <option value="<?= $teacher['teacher_id'] ?>" <?= $user['linked_teacher_id'] == $teacher['teacher_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($teacher['lname'] . ', ' . $teacher['fname']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" class="btn btn-primary w-100">Update User</button>
        </form>
    </div>
</div>

<script>
    const roleSelect = document.getElementById('role');
    const teacherSelectContainer = document.getElementById('teacherSelectContainer');
    const teacherSelect = document.getElementById('teacher_id');

    function toggleTeacherField() {
        if (roleSelect.value === 'Teacher') {
            teacherSelectContainer.style.display = 'block';
        } else {
            teacherSelectContainer.style.display = 'none';
            teacherSelect.value = '';
        }
    }

    roleSelect.addEventListener('change', toggleTeacherField);
    document.addEventListener('DOMContentLoaded', toggleTeacherField);
</script>
</body>
</html>
