<?php
// ===============================
// ADVANCED SESSION CONTROL
// ===============================
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

ini_set('session.use_cookies', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);

// Session save path
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

session_start();

// ===============================
// AUTHENTICATION & ROLE CHECK
// ===============================
if (!isset($_SESSION['username'], $_SESSION['user_id'])) {
    header("Location: index.php?session_error=1");
    exit();
}

$username = $_SESSION['username'];
$user_role = $_SESSION['role'] ?? 'Guest';

// ===============================
// DATABASE
// ===============================
require 'config.php';

// ===============================
// FETCH STUDENT DATA IF ID PROVIDED
// ===============================
$student = null;
$classes = [];

if (isset($_GET['id'])) {
    $student_id = intval($_GET['id']);
    $sql_student = "SELECT s.student_id, s.fname, s.lname, s.dob, s.gender, s.class_id, c.class_name
                    FROM student s
                    LEFT JOIN class c ON s.class_id = c.class_id
                    WHERE s.student_id = ?";
    $stmt = $conn->prepare($sql_student);
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $student = $result->fetch_assoc();
    }
    $stmt->close();
}

// Fetch all classes for dropdown
$sql_classes = "SELECT class_id, class_name FROM class ORDER BY class_name";
$result_classes = $conn->query($sql_classes);
if ($result_classes) {
    while ($row = $result_classes->fetch_assoc()) {
        $classes[] = $row;
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Student | School Management System</title>
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

        /* Form Styles */
        .form-control {
            border-radius: 8px;
            border: 1px solid #ddd;
            padding: 10px 15px;
            font-size: 0.95rem;
        }

        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(67, 97, 238, 0.25);
        }

        .form-label {
            font-weight: 500;
            color: #495057;
            margin-bottom: 8px;
        }

        .btn {
            border-radius: 8px;
            font-weight: 500;
            padding: 10px 20px;
            transition: all 0.3s ease;
        }

        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .btn-primary:hover {
            background-color: var(--secondary-color);
            border-color: var(--secondary-color);
            transform: translateY(-2px);
        }

        .btn-secondary {
            background-color: #6c757d;
            border-color: #6c757d;
        }

        .btn-secondary:hover {
            background-color: #5a6268;
            border-color: #5a6268;
        }

        /* Responsive Adjustments */
        @media (max-width: 768px) {
            .dashboard-header h1 {
                font-size: 1.5rem;
            }

            .btn {
                width: 100%;
                margin-bottom: 10px;
            }
        }
    </style>
</head>
<body>
    <header class="dashboard-header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center flex-wrap">
                <div>
                    <h1><i class="bi bi-person-fill"></i> Edit Student</h1>
                    <p class="mb-0">Update student information</p>
                </div>
                <div class="mt-2 mt-md-0 d-flex align-items-center">
                    <span class="badge bg-light text-dark me-3">
                        <i class="bi bi-person-badge"></i> Role: <?= htmlspecialchars($user_role) ?>
                    </span>
                    <a href="admin_dashboard.php" class="btn btn-outline-light btn-sm">
                        <i class="bi bi-house-door"></i> Dashboard
                    </a>
                </div>
            </div>
        </div>
    </header>

    <div class="container">
        <?php if ($student): ?>
            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <div class="dashboard-card">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0"><i class="bi bi-pencil-square"></i> Edit Student Details</h5>
                        </div>
                        <div class="card-body">
                            <form action="process_student.php" method="POST">
                                <input type="hidden" name="student_id" value="<?= $student['student_id'] ?>">
                                <input type="hidden" name="action" value="update">

                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label for="fname" class="form-label">First Name</label>
                                        <input type="text" class="form-control" id="fname" name="fname" value="<?= htmlspecialchars($student['fname']) ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="lname" class="form-label">Last Name</label>
                                        <input type="text" class="form-control" id="lname" name="lname" value="<?= htmlspecialchars($student['lname']) ?>" required>
                                    </div>
                                </div>

                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label for="dob" class="form-label">Date of Birth</label>
                                        <input type="date" class="form-control" id="dob" name="dob" value="<?= $student['dob'] ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="gender" class="form-label">Gender</label>
                                        <select class="form-control" id="gender" name="gender" required>
                                            <option value="">Select Gender</option>
                                            <option value="Male" <?= $student['gender'] == 'Male' ? 'selected' : '' ?>>Male</option>
                                            <option value="Female" <?= $student['gender'] == 'Female' ? 'selected' : '' ?>>Female</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label for="class_id" class="form-label">Class</label>
                                    <select class="form-control" id="class_id" name="class_id" required>
                                        <option value="">Select Class</option>
                                        <?php foreach ($classes as $class): ?>
                                            <option value="<?= $class['class_id'] ?>" <?= $student['class_id'] == $class['class_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($class['class_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="d-flex justify-content-between mt-4">
                                    <a href="view_students.php" class="btn btn-secondary">
                                        <i class="bi bi-arrow-left"></i> Back to Students
                                    </a>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-check-circle"></i> Update Student
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-danger text-center">
                <i class="bi bi-exclamation-triangle" style="font-size: 2rem;"></i>
                <h4 class="alert-heading mt-2">Student Not Found</h4>
                <p>The student you're trying to edit does not exist or the ID is invalid.</p>
                <a href="view_students.php" class="btn btn-primary">
                    <i class="bi bi-arrow-left"></i> Back to Students
                </a>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
