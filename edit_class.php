<?php
// Start output buffering to prevent header issues
ob_start();

// Error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Session configuration - MUST match login.php exactly
ini_set('session.use_cookies', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);

// Try to find a writable session directory - MUST match login.php
$possiblePaths = [
    '/tmp',
    sys_get_temp_dir(),
    '/home/schsys.laptertech.store/tmp',
    '/home/schsys.laptertech.store/php_sessions'
];

foreach ($possiblePaths as $path) {
    if (is_dir($path) && is_writable($path)) {
        ini_set('session.save_path', $path);
        break;
    }
}

// Start session
session_start();

// Debug session
error_log("=== ADMIN DASHBOARD ===");
error_log("Session ID: " . session_id());
error_log("Username in session: " . ($_SESSION['username'] ?? 'NOT SET'));
error_log("Role in session: " . ($_SESSION['role'] ?? 'NOT SET'));
error_log("User ID in session: " . ($_SESSION['user_id'] ?? 'NOT SET'));

// Check authentication - FIXED VERSION
if (!isset($_SESSION['username']) ) {
    error_log("Authentication failed: Missing username or user_id in session");
    // Redirect with a query parameter to indicate session issue
    header('Location: index.php?session_error=1');
    exit();
}

// Check if user is Admin (optional, but recommended)
if (isset($_SESSION['role']) && $_SESSION['role'] !== 'Admin') {
    error_log("Access denied: User role is " . $_SESSION['role'] . ", not Admin");
    // You might want to redirect to a different dashboard or show access denied
    // For now, we'll just log it but allow access
}

// Include database config
include 'config.php';

// Get user role from session
$user_role = $_SESSION['role'] ?? 'Guest';

// Fetch teachers for the dropdown
$teachers = [];
$sql_teachers = "SELECT teacher_id, fname, lname FROM teacher";
$result_teachers = $conn->query($sql_teachers);
if ($result_teachers && $result_teachers->num_rows > 0) {
    while ($row = $result_teachers->fetch_assoc()) {
        $teachers[] = $row;
    }
}

// Handle form submission for updating the class
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $class_id = $conn->real_escape_string($_POST['class_id']);
    $class_name = $conn->real_escape_string($_POST['class_name']);
    $class_teacher_id = !empty($_POST['class_teacher_id']) ? $conn->real_escape_string($_POST['class_teacher_id']) : null;

    $sql_update = "UPDATE class SET class_name = ?, class_teacher_id = ? WHERE class_id = ?";
    $stmt_update = $conn->prepare($sql_update);

    if ($stmt_update) {
        $stmt_update->bind_param("sii", $class_name, $class_teacher_id, $class_id);
        if ($stmt_update->execute()) {
            $success = "Class updated successfully!";
            // Optionally redirect back to view_classes.php
            header("Location: view_classes.php");
            exit();
        } else {
            $error = "Error updating class: " . $stmt_update->error;
        }
        $stmt_update->close();
    } else {
        $error = "Error preparing update statement: " . $conn->error;
    }
}

// Fetch class details based on the ID in the URL
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $class_id_edit = $conn->real_escape_string($_GET['id']);
    $sql_select = "SELECT class_id, class_name, class_teacher_id FROM class WHERE class_id = ?";
    $stmt_select = $conn->prepare($sql_select);

    if ($stmt_select) {
        $stmt_select->bind_param("i", $class_id_edit);
        $stmt_select->execute();
        $result_select = $stmt_select->get_result();
        if ($result_select->num_rows === 1) {
            $class = $result_select->fetch_assoc();
        } else {
            $error = "Class not found.";
        }
        $stmt_select->close();
    } else {
        $error = "Error preparing select statement: " . $conn->error;
    }
} else {
    $error = "Invalid class ID.";
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Class | School Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #4361ee;
            --secondary-color: #3a0ca3;
            --success-color: #4cc9f0;
            --info-color: #4895ef;
            --warning-color: #f8961e;
            --danger-color: #f72585;
            --light-color: #f8f9fa;
            --dark-color: #212529;
            --sidebar-width: 250px;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background-color: #f5f7fa;
            color: #333;
        }

        /* Header Styles */
        .dashboard-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 15px 0;
            margin-bottom: 25px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        .dashboard-header h1 {
            font-weight: 600;
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
        }

        /* Card Styles */
        .dashboard-card {
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
            margin-bottom: 25px;
            border: none;
            overflow: hidden;
            background-color: white;
        }

        .dashboard-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }

        .card-header {
            border-radius: 12px 12px 0 0 !important;
            font-weight: 600;
            padding: 15px 20px;
            border-bottom: 1px solid rgba(0,0,0,0.05);
            background-color: white;
        }

        /* Button Styles */
        .btn {
            border-radius: 6px;
            font-weight: 500;
            padding: 8px 15px;
            transition: all 0.3s ease;
        }

        .btn-sm {
            padding: 5px 10px;
            font-size: 0.8rem;
        }

        /* Form Styles */
        .form-label {
            font-weight: 500;
            color: #495057;
        }

        .form-control, .form-select {
            border-radius: 6px;
            border: 1px solid #ced4da;
            padding: 0.5rem 0.75rem;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(67, 97, 238, 0.25);
        }

        /* Alert Styles */
        .alert {
            border-radius: 8px;
            border: none;
            font-weight: 500;
        }

        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }

        /* Animation */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .dashboard-card {
            animation: fadeIn 0.3s ease-out;
        }
    </style>
</head>
<body>
    <header class="dashboard-header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center flex-wrap">
                <div>
                    <h1><i class="bi bi-pencil-square"></i> Edit Class</h1>
                    <p class="mb-0">Update class information</p>
                </div>
                <div class="mt-2 mt-md-0 d-flex align-items-center">
                    <span class="badge bg-light text-dark me-3">
                        <i class="bi bi-person-badge"></i> Role: <?= htmlspecialchars($user_role) ?>
                    </span>
                    <a href="admin_dashboard.php" class="btn btn-outline-light btn-sm me-2" title="Back to Dashboard">
                        <i class="bi bi-house-door"></i> Dashboard
                    </a>
                    <a href="logout.php" class="btn btn-outline-light btn-sm" title="Logout">
                        <i class="bi bi-box-arrow-right"></i> Logout
                    </a>
                </div>
            </div>
        </div>
    </header>

    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-6">
                <div class="dashboard-card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-building"></i> Class Details</h5>
                        <a href="view_classes.php" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-arrow-left"></i> Back to Classes
                        </a>
                    </div>
                    <div class="card-body">
                        <?php if (isset($error)): ?>
                            <div class="alert alert-danger" role="alert">
                                <i class="bi bi-exclamation-triangle"></i> <?= $error ?>
                            </div>
                        <?php endif; ?>

                        <?php if (isset($success)): ?>
                            <div class="alert alert-success" role="alert">
                                <i class="bi bi-check-circle"></i> <?= $success ?>
                            </div>
                        <?php endif; ?>

                        <?php if (isset($class)): ?>
                            <form method="post">
                                <input type="hidden" name="class_id" value="<?= $class['class_id'] ?>">

                                <div class="mb-3">
                                    <label for="class_name" class="form-label">Class Name</label>
                                    <input type="text" class="form-control" id="class_name" name="class_name"
                                           value="<?= htmlspecialchars($class['class_name']) ?>" required>
                                </div>

                                <div class="mb-3">
                                    <label for="class_teacher_id" class="form-label">Class Teacher (Optional)</label>
                                    <select class="form-select" id="class_teacher_id" name="class_teacher_id">
                                        <option value="">-- Select Teacher --</option>
                                        <?php foreach ($teachers as $teacher): ?>
                                            <option value="<?= $teacher['teacher_id'] ?>"
                                                <?= ($teacher['teacher_id'] == $class['class_teacher_id']) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($teacher['fname'] . ' ' . $teacher['lname']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                        <option value="">-- Unassign Teacher --</option>
                                    </select>
                                </div>

                                <div class="d-grid">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-check2"></i> Update Class
                                    </button>
                                </div>
                            </form>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="bi bi-exclamation-circle" style="font-size: 3rem; color: #6c757d;"></i>
                                <p class="mt-3">No class selected for editing.</p>
                                <a href="view_classes.php" class="btn btn-primary">
                                    <i class="bi bi-arrow-left"></i> Back to Class List
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
