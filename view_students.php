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
// FETCH ALL STUDENTS WITH CLASS NAMES
// ===============================
$sql_students = "SELECT s.student_id, s.fname, s.lname, s.dob, s.gender, c.class_name
                 FROM student s
                 LEFT JOIN class c ON s.class_id = c.class_id
                 ORDER BY c.class_name, s.fname, s.lname";

$result_students = $conn->query($sql_students);

$students_by_class = [];
if ($result_students) {
    while ($row = $result_students->fetch_assoc()) {
        $class_name = $row['class_name'] ?? 'Unassigned';
        $students_by_class[$class_name][] = $row;
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="stylesheet" href="assets/css/site.css">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Students | School Management System</title>
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

        /* Table Styles */
        .table-responsive {
            max-height: 400px;
            overflow-y: auto;
            border-radius: 8px;
        }

        .table {
            margin-bottom: 0;
        }

        .table th {
            background-color: #f8f9fa;
            font-weight: 500;
            color: #495057;
            border-top: none;
            padding: 12px 15px;
        }

        .table td {
            padding: 12px 15px;
            vertical-align: middle;
        }

        .table-hover tbody tr:hover {
            background-color: rgba(67, 97, 238, 0.05);
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

        .action-btn {
            margin-right: 5px;
            padding: 0.25rem 0.5rem;
            font-size: 0.8rem;
            border-radius: 4px;
        }

        .action-btn i {
            vertical-align: middle;
        }

        /* Badge Styles */
        .badge {
            font-weight: 500;
            padding: 5px 10px;
            border-radius: 4px;
        }

        /* Accordion Styles */
        .accordion-button:focus {
            box-shadow: none;
        }

        .accordion-item {
            border-radius: 8px;
            margin-bottom: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }

        .accordion-button {
            border-radius: 8px 8px 0 0 !important;
            font-weight: 500;
        }

        .accordion-body {
            border-radius: 0 0 8px 8px;
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

        .tab-pane {
            animation: fadeIn 0.3s ease-out;
        }

        /* Status Colors */
        .bg-primary { background-color: var(--primary-color) !important; }
        .bg-secondary { background-color: var(--secondary-color) !important; }
        .bg-success { background-color: var(--success-color) !important; }
        .bg-info { background-color: var(--info-color) !important; }
        .bg-warning { background-color: var(--warning-color) !important; }
        .bg-danger { background-color: var(--danger-color) !important; }

        /* Additional Utility Classes */
        .text-shadow { text-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .hover-shadow:hover { box-shadow: 0 10px 20px rgba(0,0,0,0.1); }
        .transition { transition: all 0.3s ease; }
    </style>
</head>
<body>

<header class="dashboard-header">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center flex-wrap">
            <div>
                <h1><i class="bi bi-people-fill"></i> Student Management</h1>
                <p class="mb-0">Welcome back, <?= htmlspecialchars($username) ?>!</p>
            </div>
            <div class="mt-2 mt-md-0 d-flex align-items-center">
                <span class="badge bg-light text-dark me-3">
                    <i class="bi bi-person-badge"></i> Role: <?= htmlspecialchars($user_role) ?>
                </span>
                <a href="admin_dashboard.php" class="btn btn-outline-light btn-sm" title="Back to Dashboard">
                    <i class="bi bi-house-door"></i> Dashboard
                </a>
            </div>
        </div>
    </div>
</header>

<div class="container">
    <div class="dashboard-card">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="bi bi-people-fill"></i> Student Management</h5>
            <?php if($user_role === 'Administrator'): ?>
                <a href="student_form.php" class="btn btn-light btn-sm">
                    <i class="bi bi-plus-circle"></i> Add Student
                </a>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php if(!empty($students_by_class)): ?>
            <div class="accordion" id="classesAccordion">
            <?php $i = 0; ?>
            <?php foreach($students_by_class as $class_name => $students): ?>
                <div class="accordion-item mb-2 shadow-sm">
                    <h2 class="accordion-header" id="heading<?= $i ?>">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse<?= $i ?>" aria-expanded="false" aria-controls="collapse<?= $i ?>">
                            <?= htmlspecialchars($class_name) ?> (<?= count($students) ?> Students)
                        </button>
                    </h2>
                    <div id="collapse<?= $i ?>" class="accordion-collapse collapse" aria-labelledby="heading<?= $i ?>" data-bs-parent="#classesAccordion">
                        <div class="accordion-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover table-striped table-sm mb-0">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Name</th>
                                            <th>DOB</th>
                                            <th>Gender</th>
                                            <?php if($user_role === 'Admin'): ?><th>Actions</th><?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($students as $student): ?>
                                        <tr>
                                            <td><?= $student['student_id'] ?></td>
                                            <td><?= htmlspecialchars($student['fname'] . ' ' . $student['lname']) ?></td>
                                            <td><?= date('M d, Y', strtotime($student['dob'])) ?></td>
                                            <td>
                                                <span class="badge <?= $student['gender']=='Male'?'bg-primary':'bg-danger' ?> badge-gender">
                                                    <?= htmlspecialchars($student['gender']) ?>
                                                </span>
                                            </td>
                                            <?php if($user_role === 'Admin'): ?>
                                            <td>
                                                <a href="view_student_details.php?id=<?= $student['student_id'] ?>" class="btn btn-sm btn-info action-btn" title="View">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <a href="edit_student.php?id=<?= $student['student_id'] ?>" class="btn btn-sm btn-warning action-btn" title="Edit">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <a href="delete_student.php?id=<?= $student['student_id'] ?>" class="btn btn-sm btn-danger action-btn" title="Delete" onclick="return confirm('Are you sure?')">
                                                    <i class="bi bi-trash"></i>
                                                </a>
                                            </td>
                                            <?php endif; ?>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <?php $i++; ?>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="alert alert-secondary text-center">
            No students found.
        </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/site.js"></script>
</body>
</html>
