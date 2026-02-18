<?php
session_start(); // Start session to potentially store messages or check admin login
include 'config.php'; // Ensure this file correctly establishes $conn

// --- Authorization Check: Ensure only Admin can access ---
// Adapt this check based on your session variables
if (!isset($_SESSION['username']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'Administrator') {
    $_SESSION['error_message'] = "Access Denied: You do not have permission to register users.";
    // Redirect to login page or admin dashboard if appropriate
    header('Location: ' . (isset($_SESSION['username']) ? 'admin_dashboard.php' : 'index.php'));
    exit();
}

$errors = []; // Array to store validation errors
$success_message = '';

// --- Fetch Teachers for the Dropdown ---
$teachers = [];
// Fetch only teachers NOT already linked to a user account
$teacher_query = $conn->query("
    SELECT t.teacher_id, t.fname, t.lname
    FROM teacher t
    LEFT JOIN users u ON t.teacher_id = u.linked_teacher_id
    WHERE u.id IS NULL
    ORDER BY t.lname, t.fname
");
if ($teacher_query) {
    while ($row = $teacher_query->fetch_assoc()) {
        $teachers[] = $row;
    }
} else {
    // Handle error fetching teachers if needed, but don't block registration entirely
    $errors[] = "Warning: Could not fetch list of available teachers. " . $conn->error;
}


// --- Process Form Submission ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Retrieve and sanitize inputs (using filter_input is generally safer)
    $username = filter_input(INPUT_POST, 'username', FILTER_SANITIZE_STRING);
    $password_raw = $_POST['password'] ?? ''; // Get raw password for validation
    $role = filter_input(INPUT_POST, 'role', FILTER_SANITIZE_STRING);
    $teacher_id = filter_input(INPUT_POST, 'teacher_id', FILTER_VALIDATE_INT); // Get selected teacher ID

    // --- Server-Side Validation ---
    if (empty($username)) {
        $errors[] = "Username is required.";
    } elseif (strlen($username) < 3) {
         $errors[] = "Username must be at least 3 characters long.";
    }

    if (empty($password_raw)) {
        $errors[] = "Password is required.";
    } elseif (strlen($password_raw) < 6) { // Example: minimum length
        $errors[] = "Password must be at least 6 characters long.";
    }
     // Add more password complexity checks if needed (uppercase, number, symbol)

    if (empty($role) || !in_array($role, ['Teacher', 'Administrator'])) { // Validate role
        $errors[] = "Invalid role selected.";
    }

    $teacher_id_to_link = null; // Initialize teacher ID to link as null

    // If role is Teacher, validate the teacher_id
    if ($role === 'Teacher') {
        if (empty($teacher_id)) {
            $errors[] = "Please select a Teacher record to link for the Teacher role.";
        } else {
            // Verify the selected teacher_id is valid and available
            $stmt_check_teacher = $conn->prepare("
                SELECT t.teacher_id FROM teacher t
                LEFT JOIN users u ON t.teacher_id = u.linked_teacher_id
                WHERE t.teacher_id = ? AND u.id IS NULL
            ");
            if ($stmt_check_teacher) {
                $stmt_check_teacher->bind_param("i", $teacher_id);
                $stmt_check_teacher->execute();
                $stmt_check_teacher->store_result();
                if ($stmt_check_teacher->num_rows == 1) {
                    $teacher_id_to_link = $teacher_id; // Valid and available teacher selected
                } else {
                    $errors[] = "The selected Teacher is invalid or already linked to a user account.";
                }
                $stmt_check_teacher->close();
            } else {
                 $errors[] = "Database error checking teacher ID: " . $conn->error;
            }
        }
    }

    // Check if username already exists (using prepared statement)
    if (empty($errors)) { // Only check username if other validations passed
        $stmt_check_user = $conn->prepare("SELECT id FROM users WHERE username = ?");
        if ($stmt_check_user) {
            $stmt_check_user->bind_param("s", $username);
            $stmt_check_user->execute();
            $stmt_check_user->store_result(); // Needed to check num_rows
            if ($stmt_check_user->num_rows > 0) {
                $errors[] = "Username '$username' is already taken!";
            }
            $stmt_check_user->close();
        } else {
             $errors[] = "Database error checking username: " . $conn->error;
        }
    }

    // --- If No Errors, Proceed with Insertion ---
    if (empty($errors)) {
        // Hash the password securely
        $password_hashed = password_hash($password_raw, PASSWORD_DEFAULT); // Use PASSWORD_DEFAULT

        // Prepare the INSERT statement
        $stmt_insert = $conn->prepare("INSERT INTO users (username, password, role, linked_teacher_id) VALUES (?, ?, ?, ?)");

        if ($stmt_insert) {
            // Bind parameters (s=string, i=integer). Bind NULL if teacher_id_to_link is null.
            $stmt_insert->bind_param("sssi", $username, $password_hashed, $role, $teacher_id_to_link);

            if ($stmt_insert->execute()) {
                $success_message = "User '".htmlspecialchars($username)."' registered successfully!";
                // Optional: Clear form fields or redirect
                // header('Location: admin_dashboard.php?registration=success'); exit();

                 // Refresh teacher list after successful registration
                 $teachers = []; // Clear old list
                 $teacher_query = $conn->query("SELECT t.teacher_id, t.fname, t.lname FROM teacher t LEFT JOIN users u ON t.teacher_id = u.linked_teacher_id WHERE u.id IS NULL ORDER BY t.lname, t.fname");
                 if ($teacher_query) {
                    while ($row = $teacher_query->fetch_assoc()) { $teachers[] = $row; }
                 }

            } else {
                $errors[] = "Failed to register user: " . $stmt_insert->error;
            }
            $stmt_insert->close();
        } else {
             $errors[] = "Database error preparing insert statement: " . $conn->error;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register New User</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
     <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        /* Optional: Style for the hidden teacher select div */
        #teacher_select_div { display: none; } /* Initially hidden */
    </style>
</head>
<body>
    <div class="container mt-4 mb-5">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-6">
                 <div class="d-flex justify-content-between align-items-center mb-3">
                     <h1 class="mb-0 h2">Register New User</h1>
                     <a href="admin_dashboard.php" class="btn btn-sm btn-outline-secondary">
                         <i class="bi bi-arrow-left"></i> Back to Dashboard
                     </a>
                 </div>

                <?php if (!empty($success_message)): ?>
                    <div class="alert alert-success"><?= $success_message ?></div>
                <?php endif; ?>
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <strong>Please fix the following errors:</strong><br>
                        <ul>
                            <?php foreach ($errors as $error): ?>
                                <li><?= htmlspecialchars($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>


                <div class="card shadow-sm">
                    <div class="card-body">
                        <form action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" method="post" id="registerForm">
                            <div class="mb-3">
                                <label for="username" class="form-label">Username <span class="text-danger">*</span></label>
                                <input type="text" name="username" id="username" class="form-control" placeholder="Enter a unique username" required minlength="3" value="<?= isset($_POST['username']) ? htmlspecialchars($_POST['username']) : '' ?>">
                            </div>
                            <div class="mb-3">
                                <label for="password" class="form-label">Password <span class="text-danger">*</span></label>
                                <input type="password" name="password" id="password" class="form-control" placeholder="Enter a strong password" required minlength="6">
                                <div class="form-text">Must be at least 6 characters long.</div>
                            </div>
                            <div class="mb-3">
                                <label for="role" class="form-label">Role <span class="text-danger">*</span></label>
                                <select name="role" id="role" class="form-select" required>
                                    <option value="">-- Select Role --</option>
                                    <option value="Teacher" <?= (isset($_POST['role']) && $_POST['role'] == 'Teacher') ? 'selected' : '' ?>>Teacher</option>
                                    <option value="Administrator" <?= (isset($_POST['role']) && $_POST['role'] == 'Administrator') ? 'selected' : '' ?>>Administrator</option>
                                    <?php // Add other roles if needed ?>
                                </select>
                            </div>

                            <div class="mb-3" id="teacher_select_div">
                                <label for="teacher_id" class="form-label">Link to Teacher Record <span class="text-danger">*</span></label>
                                <select name="teacher_id" id="teacher_id" class="form-select">
                                    <option value="">-- Select Teacher --</option>
                                    <?php if (!empty($teachers)): ?>
                                        <?php foreach ($teachers as $teacher): ?>
                                            <option value="<?= $teacher['teacher_id'] ?>" <?= (isset($_POST['teacher_id']) && $_POST['teacher_id'] == $teacher['teacher_id']) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($teacher['lname'] . ', ' . $teacher['fname'] . ' (ID: ' . $teacher['teacher_id'] . ')') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <option value="" disabled>No available teachers found to link.</option>
                                    <?php endif; ?>
                                </select>
                                <div class="form-text">Select the corresponding record from the Teachers table.</div>
                            </div>

                            <button type="submit" class="btn btn-primary w-100">
                               <i class="bi bi-person-plus-fill"></i> Register User
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        const roleSelect = document.getElementById('role');
        const teacherSelectDiv = document.getElementById('teacher_select_div');
        const teacherSelect = document.getElementById('teacher_id');

        function toggleTeacherSelect() {
            if (roleSelect.value === 'Teacher') {
                teacherSelectDiv.style.display = 'block'; // Show the div
                teacherSelect.required = true;         // Make teacher selection required
            } else {
                teacherSelectDiv.style.display = 'none';  // Hide the div
                teacherSelect.required = false;        // Make teacher selection not required
                teacherSelect.value = '';              // Reset selection when hiding
            }
        }

        // Add event listener to role select
        roleSelect.addEventListener('change', toggleTeacherSelect);

        // Run on page load in case the form is reloaded with 'Teacher' pre-selected
        document.addEventListener('DOMContentLoaded', toggleTeacherSelect);
    </script>
</body>
</html>