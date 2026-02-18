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
if (!isset($_SESSION['username']) || !isset($_SESSION['user_id'])) {
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

// --- Consolidated Data Fetching ---
$all_classes = [];
$all_teachers = [];
$all_subjects = [];
$all_exams = [];
$all_students = [];
$class_demographics = [];
$counts = [
    'students' => 0,
    'teachers' => 0,
    'subjects' => 0,
    'exams' => 0,
];

// Fetch All Users
$all_users = [];
$sql_users = "SELECT * FROM users ORDER BY username";
$result_users = $conn->query($sql_users);
if ($result_users) {
    while ($row = $result_users->fetch_assoc()) {
        $all_users[] = $row;
    }
}

// Fetch Counts for Stat Cards
$counts_query = $conn->query("
    SELECT
        (SELECT COUNT(*) FROM student) as students,
        (SELECT COUNT(*) FROM teacher) as teachers,
        (SELECT COUNT(*) FROM subject) as subjects,
        (SELECT COUNT(*) FROM examination) as exams
");
if ($counts_query) {
    $counts = $counts_query->fetch_assoc();
}

// Fetch All Classes with Teacher Name and Student Count
$sql_classes = "
    SELECT
        cl.class_id,
        cl.class_name,
        cl.class_teacher_id,
        CONCAT(t.fname, ' ', t.lname) AS teacher_name,
        (SELECT COUNT(*) FROM student s WHERE s.class_id = cl.class_id) AS student_count
    FROM class cl
    LEFT JOIN teacher t ON cl.class_teacher_id = t.teacher_id
    ORDER BY cl.class_name
";
$result_classes = $conn->query($sql_classes);
if ($result_classes) {
    while ($row = $result_classes->fetch_assoc()) {
        $all_classes[] = $row;
    }
}

// Fetch All Teachers
$sql_teachers = "SELECT * FROM teacher ORDER BY fname, lname";
$result_teachers = $conn->query($sql_teachers);
if ($result_teachers) {
    while ($row = $result_teachers->fetch_assoc()) {
        $all_teachers[] = $row;
    }
}

// Fetch All Subjects
$sql_subjects = "
    SELECT subject_id, subject_name 
    FROM subject 
    ORDER BY subject_name
";
$result_subjects = $conn->query($sql_subjects);
if ($result_subjects) {
    while ($row = $result_subjects->fetch_assoc()) {
        $all_subjects[] = $row;
    }
}

// Fetch All Exams
$sql_exams = "
    SELECT e.exam_id, e.exam_name, e.exam_date, 
           GROUP_CONCAT(s.subject_name SEPARATOR ', ') AS subject_names
    FROM examination e
    LEFT JOIN exam_subjects es ON e.exam_id = es.exam_id
    LEFT JOIN subject s ON es.subject_id = s.subject_id
    GROUP BY e.exam_id, e.exam_name, e.exam_date
    ORDER BY e.exam_date DESC
";
$result_exams = $conn->query($sql_exams);
if ($result_exams) {
    while ($row = $result_exams->fetch_assoc()) {
        $all_exams[] = $row;
    }
}
$recent_exams = array_slice($all_exams, 0, 5);

// Fetch All Students with Class Names
$sql_students = "
    SELECT s.student_id, s.fname, s.lname, s.dob, s.gender, c.class_name
    FROM student s
    JOIN class c ON s.class_id = c.class_id
    ORDER BY s.student_id
";
$result_students = $conn->query($sql_students);
if ($result_students) {
    while ($row = $result_students->fetch_assoc()) {
        $all_students[] = $row;
    }
}

// Fetch Class Demographics
$sql_demographics = "
    SELECT
        c.class_name,
        SUM(CASE WHEN s.gender = 'Male' THEN 1 ELSE 0 END) AS male_students,
        SUM(CASE WHEN s.gender = 'Female' THEN 1 ELSE 0 END) AS female_students,
        COUNT(s.student_id) AS total_students
    FROM class c
    LEFT JOIN student s ON c.class_id = s.class_id
    GROUP BY c.class_id, c.class_name
    ORDER BY c.class_name
";
$result_demographics = $conn->query($sql_demographics);
$grandTotalStudents = 0;
if ($result_demographics) {
    while ($row = $result_demographics->fetch_assoc()) {
        $class_demographics[] = $row;
        $grandTotalStudents += $row['total_students'];
    }
}

// Fetch class list for dropdown
$classes_for_dropdown = [];
$sql_classes_dropdown = "SELECT class_id, class_name FROM class ORDER BY class_name";
$result_classes_dropdown = $conn->query($sql_classes_dropdown);
if ($result_classes_dropdown) {
    while ($row = $result_classes_dropdown->fetch_assoc()) {
        $classes_for_dropdown[] = $row;
    }
}

// Fetch Finance Data
$all_finance = [];
$finance_summary = [
    'total_income' => 0,
    'total_expenses' => 0,
    'collected_fees' => 0,
    'potential_fees' => 0
];
$last_five_transactions = [];

$sql_finance = "
    SELECT f.finance_id, f.student_id, f.type, f.amount, f.transaction_date, f.description,
           CONCAT(s.fname, ' ', s.lname) AS student_name
    FROM finance f
    LEFT JOIN student s ON f.student_id = s.student_id
    ORDER BY f.transaction_date DESC
";
$result_finance = $conn->query($sql_finance);
if ($result_finance) {
    while ($row = $result_finance->fetch_assoc()) {
        $all_finance[] = $row;
        if ($row['type'] === 'Payment') {
            $finance_summary['total_income'] += $row['amount'];
        } elseif ($row['type'] === 'Expense') {
            $finance_summary['total_expenses'] += $row['amount'];
        }
    }
    $last_five_transactions = array_slice($all_finance, 0, 5);
}

// Calculate fees based on students and set fee structure
$fee_per_student = 100000; // UGX
$finance_summary['potential_fees'] = $counts['students'] * $fee_per_student;
$finance_summary['collected_fees'] = $finance_summary['total_income'];

// Log successful dashboard access
error_log("Dashboard loaded successfully for user: " . $_SESSION['username'] . " (ID: " . $_SESSION['user_id'] . ")");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="stylesheet" href="assets/css/site.css">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Management System Dashboard</title>
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
        
        /* Stat Cards */
        .stat-card {
            text-align: center;
            padding: 20px;
            border-radius: 12px;
            color: white;
            margin-bottom: 20px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            position: relative;
            overflow: hidden;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.15);
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: -10px;
            right: -10px;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background-color: rgba(255,255,255,0.2);
        }
        
        .stat-card i {
            font-size: 2.5rem;
            margin-bottom: 15px;
            position: relative;
        }
        
        .stat-card h5 {
            font-weight: 500;
            margin-bottom: 5px;
            position: relative;
        }
        
        .stat-card h3 {
            font-weight: 700;
            margin-bottom: 0;
            position: relative;
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
        
        /* Tab Styles */
        .nav-pills {
            background-color: white;
            border-radius: 8px;
            padding: 5px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            margin-bottom: 25px;
        }
        
        .nav-pills .nav-link {
            margin: 0 3px;
            border-radius: 6px;
            font-weight: 500;
            color: #495057;
            padding: 8px 15px;
            transition: all 0.3s ease;
        }
        
        .nav-pills .nav-link.active {
            background-color: var(--light-color);
            color: blue;
            box-shadow: 0 4px 8px rgba(67, 97, 238, 0.3);
        }
        
        .nav-pills .nav-link:not(.active):hover {
            background-color: #e9ecef;
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
        
        /* Responsive Adjustments */
        @media (max-width: 768px) {
            .stat-card {
                margin-bottom: 15px;
            }
            
            .nav-pills .nav-link {
                padding: 8px 10px;
                font-size: 0.85rem;
            }
            
            .dashboard-header h1 {
                font-size: 1.5rem;
            }
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
                    <h1><i class="bi bi-speedometer2"></i> School Dashboard</h1>
                    <p class="mb-0">Welcome back, <?= htmlspecialchars($_SESSION['username']) ?>!</p>
                </div>
                <div class="mt-2 mt-md-0 d-flex align-items-center"> 
                    <span class="badge bg-light text-dark me-3">
                        <i class="bi bi-person-badge"></i> Role: <?= htmlspecialchars($user_role) ?>
                    </span>

                    <?php if ($user_role === 'Admin'): ?>
                        <a href="register.php" class="btn btn-outline-light btn-sm me-2" title="Register a new system user">
                            <i class="bi bi-person-plus-fill"></i> Register User
                        </a>
                    <?php endif; ?>

                    <a href="logout.php" class="btn btn-outline-light btn-sm" title="Logout">
                        <i class="bi bi-box-arrow-right"></i> Logout
                    </a>
                </div>
            </div>
        </div>
    </header>

    <div class="container">
        <!-- Quick Stats Row -->
        <div class="row mb-4 g-3">
            <div class="col-6 col-md-3">
                <div class="stat-card bg-primary">                
                    <i class="bi bi-people-fill"></i>
                    <h5>Students</h5>
                    <h3><?= $counts['students'] ?></h3>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card bg-success">
                    <i class="bi bi-person-badge"></i>
                    <h5>Teachers</h5>
                    <h3><?= $counts['teachers'] ?></h3>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card bg-warning"> 
                    <i class="bi bi-book"></i>
                    <h5>Subjects</h5>
                    <h3><?= $counts['subjects'] ?></h3>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card bg-danger">
                    <i class="bi bi-clipboard-data"></i>
                    <h5>Exams</h5>
                    <h3><?= $counts['exams'] ?></h3>
                </div>
            </div>
        </div>

        <!-- Navigation Tabs -->
        <ul class="nav nav-pills mb-4" id="dashboardTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="overview-tab" data-bs-toggle="pill" data-bs-target="#overview" type="button" role="tab" aria-controls="overview" aria-selected="true">
                    <i class="bi bi-house-door"></i> Overview
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="classes-tab" data-bs-toggle="pill" data-bs-target="#classes" type="button" role="tab" aria-controls="classes" aria-selected="false">
                    <i class="bi bi-building"></i> Classes
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="students-tab" data-bs-toggle="pill" data-bs-target="#students" type="button" role="tab" aria-controls="students" aria-selected="false">
                    <i class="bi bi-people-fill"></i> Students
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="teachers-tab" data-bs-toggle="pill" data-bs-target="#teachers" type="button" role="tab" aria-controls="teachers" aria-selected="false">
                    <i class="bi bi-person-badge"></i> Teachers
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="subjects-tab" data-bs-toggle="pill" data-bs-target="#subjects" type="button" role="tab" aria-controls="subjects" aria-selected="false">
                    <i class="bi bi-book-half"></i> Subjects
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="exams-tab" data-bs-toggle="pill" data-bs-target="#exams" type="button" role="tab" aria-controls="exams" aria-selected="false">
                    <i class="bi bi-clipboard-data"></i> Exams
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="users-tab" data-bs-toggle="pill" data-bs-target="#users" type="button" role="tab" aria-controls="users" aria-selected="false">
                    <i class="bi bi-people"></i> Users
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="users-tab" data-bs-toggle="pill" data-bs-target="#users" type="button" role="tab" aria-controls="users" aria-selected="false">
                        <a href="assesment.php">
                        <i class="bi bi-person-lines-fill"></i> Marks
                    </a>
                </button>
            </li>
            <li class="nav-item">
                <a href="grades.php" class="nav-link">
                    <i class="bi bi-bar-chart-line-fill"></i> Grading
                </a>
            </li>
                        <li class="nav-item" role="presentation">
                <button class="nav-link" id="users-tab" data-bs-toggle="pill" data-bs-target="#users" type="button" role="tab" aria-controls="users" aria-selected="false">
                     <a href="report_card.php" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-printer-fill"></i> Reports
                    </a>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="users-tab" data-bs-toggle="pill" data-bs-target="#users" type="button" role="tab" aria-controls="users" aria-selected="false">

                        <a href="terms.php" class="btn btn-outline-primary btn-sm me-2">
                        <i class="bi bi-calendar-range"></i> Terms
                    </a>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="finance-tab" data-bs-toggle="pill" data-bs-target="#finance" type="button" role="tab" aria-controls="finance" aria-selected="false">
                    <i class="bi bi-cash-coin"></i> Finance
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="store-tab" data-bs-toggle="pill" data-bs-target="#store" type="button" role="tab" aria-controls="store" aria-selected="false">
                    <i class="bi bi-shop"></i> Store
                </button>
            </li>

        </ul>

        <!-- Tab Content -->
        <div class="tab-content" id="dashboardTabsContent">
            <!-- Overview Tab -->
            <div class="tab-pane fade show active" id="overview" role="tabpanel" aria-labelledby="overview-tab">
                <div class="row">
                    <div class="col-lg-6 mb-4">
                        <div class="card dashboard-card h-100">
                            <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
                                <h5 class="mb-0"><i class="bi bi-clipboard-data"></i> Recent Exams</h5>
                                <a href="#exams" data-bs-toggle="pill" class="btn btn-light btn-sm">
                                    View All <i class="bi bi-arrow-right"></i>
                                </a>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($recent_exams)): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover table-sm">
                                        <thead>
                                            <tr>
                                                <th>Exam</th>
                                                <th>Subject</th>
                                                <th>Date</th>
                                                <?php if($user_role === 'Admin'): ?>
                                                <th>Actions</th>
                                                <?php endif; ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach($recent_exams as $exam): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($exam['exam_name']) ?></td>
                                                <td><?= htmlspecialchars($exam['subject_name'] ?? 'N/A') ?></td>
                                                <td><?= date('M d, Y', strtotime($exam['exam_date'])) ?></td>
                                                <?php if($user_role === 'Admin'): ?>
                                                <td>
                                                    <a href="edit_exam.php?id=<?= $exam['exam_id'] ?>" class="btn btn-sm btn-warning action-btn" title="Edit">
                                                        <i class="bi bi-pencil"></i>
                                                    </a>
                                                    <a href="delete_exam.php?id=<?= $exam['exam_id'] ?>" class="btn btn-sm btn-danger action-btn" title="Delete" onclick="return confirm('Are you sure?')">
                                                        <i class="bi bi-trash"></i>
                                                    </a>
                                                </td>
                                                <?php endif; ?>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php else: ?>
                                    <div class="text-center py-4">
                                        <i class="bi bi-clipboard-x" style="font-size: 2rem; color: #6c757d;"></i>
                                        <p class="mt-2">No exams found</p>
                                        <?php if($user_role === 'Admin'): ?>
                                            <a href="exams.php" class="btn btn-primary btn-sm mt-2">
                                                <i class="bi bi-plus-circle"></i> Add Exam
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-6 mb-4">
                        <div class="card dashboard-card h-100">
                            <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
                                <h5 class="mb-0"><i class="bi bi-pie-chart-fill"></i> Class Demographics</h5>
                                <a href="#classes" data-bs-toggle="pill" class="btn btn-light btn-sm">
                                    View All <i class="bi bi-arrow-right"></i>
                                </a>
                            </div>
                            <div class="card-body d-flex flex-column">
                                <?php if (!empty($class_demographics)): ?>
                                <div class="table-responsive flex-grow-1">
                                    <table class="table table-hover table-sm">
                                        <thead>
                                            <tr>
                                                <th>Class</th>
                                                <th>Male</th>
                                                <th>Female</th>
                                                <th>Total</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($class_demographics as $demo): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($demo['class_name']) ?></td>
                                                <td><?= $demo['male_students'] ?></td>
                                                <td><?= $demo['female_students'] ?></td>
                                                <td><?= $demo['total_students'] ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <tfoot>
                                            <tr class="table-active fw-bold">
                                                <td>Total</td>
                                                <td><?= array_sum(array_column($class_demographics, 'male_students')) ?></td>
                                                <td><?= array_sum(array_column($class_demographics, 'female_students')) ?></td>
                                                <td><?= $grandTotalStudents ?></td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                                <a href="#classes" data-bs-toggle="pill" class="btn btn-info mt-3 align-self-start">
                                    <i class="bi bi-gear"></i> Manage Classes
                                </a>
                                <?php else: ?>
                                    <div class="text-center py-4 flex-grow-1 d-flex flex-column justify-content-center">
                                        <i class="bi bi-people" style="font-size: 2rem; color: #6c757d;"></i>
                                        <p class="mt-2">No class demographic data available</p>
                                        <?php if($user_role === 'Admin'): ?>
                                            <a href="manageclasses.php" class="btn btn-primary btn-sm mt-2">
                                                <i class="bi bi-plus-circle"></i> Add Class
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Classes Tab -->
            <div class="tab-pane fade" id="classes" role="tabpanel" aria-labelledby="classes-tab">
                <div class="card dashboard-card">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-building"></i> Class Management</h5>
                        <?php if($user_role === 'Admin'): ?>
                            <div>
                                <a href="view_classes.php" class="btn btn-light btn-sm me-2">
                                    <i class="bi bi-eye"></i> View Classes
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($all_classes)): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Class Name</th>
                                        <th>Class Teacher</th>
                                        <?php if($user_role === 'Admin'): ?>
                                        <th>Actions</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($all_classes as $class_item): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($class_item['class_name']) ?></td>
                                        <td><?= htmlspecialchars($class_item['teacher_name'] ?? 'Not Assigned') ?></td>
                                        <?php if($user_role === 'Admin'): ?>
                                        <td>
                                            <a href="edit_class.php?id=<?= $class_item['class_id'] ?>" class="btn btn-sm btn-warning action-btn" title="Edit">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <a href="delete_class.php?id=<?= $class_item['class_id'] ?>" class="btn btn-sm btn-danger action-btn" title="Delete" onclick="return confirm('Are you sure?')">
                                                <i class="bi bi-trash"></i>
                                            </a>
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="bi bi-building" style="font-size: 2rem; color: #6c757d;"></i>
                                <p class="mt-2">No classes found</p>
                                <?php if ($user_role === 'Admin'): ?>
                                    <a href="manageclasses.php" class="btn btn-primary mt-2">
                                        <i class="bi bi-plus-circle"></i> Add New Class
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Students Tab -->
            <div class="tab-pane fade" id="students" role="tabpanel" aria-labelledby="students-tab">
                <div class="card dashboard-card">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-people-fill"></i> Student Management</h5>
                        <?php if($user_role === 'Admin'): ?>
                            <a href="view_students.php" class="btn btn-light btn-sm">
                                <i class="bi bi-eye"></i> view students
                            </a>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($all_students)): ?>
                        <div class="table-responsive">
                            <table class="table table-hover table-striped table-sm">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Name</th>
                                        <th>DOB</th>
                                        <th>Gender</th>
                                        <th>Class</th>
                                        <?php if($user_role === 'Admin'): ?>
                                        <th>Actions</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($all_students as $student): ?>
                                    <tr>
                                        <td><?= $student['student_id'] ?></td>
                                        <td><?= htmlspecialchars($student['fname'] . " " . $student['lname']) ?></td>
                                        <td><?= date('M d, Y', strtotime($student['dob'])) ?></td>
                                        <td>
                                            <span class="badge <?= $student['gender'] === 'Male' ? 'bg-primary' : 'bg-danger' ?>">
                                                <?= htmlspecialchars($student['gender']) ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($student['class_name']) ?></td>
                                        <?php if($user_role === 'Admin'): ?>
                                        <td>
                                            <a href="view_students.php?id=<?= $student['student_id'] ?>" 
                                               class="btn btn-sm btn-info action-btn" 
                                               title="View">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            
                                            <a href="edit_student.php?id=<?= $student['student_id'] ?>" 
                                               class="btn btn-sm btn-warning action-btn" 
                                               title="Edit">
                                                <i class="bi bi-pencil-square"></i>
                                            </a>
                                            
                                            <a href="delete_student.php?id=<?= $student['student_id'] ?>" 
                                               class="btn btn-sm btn-danger action-btn" 
                                               title="Delete" 
                                               onclick="return confirm('Are you sure you want to delete this student?')">
                                                <i class="bi bi-trash"></i>
                                            </a>

                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="bi bi-person" style="font-size: 2rem; color: #6c757d;"></i>
                                <p class="mt-2">No students found</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Teachers Tab -->
            <div class="tab-pane fade" id="teachers" role="tabpanel" aria-labelledby="teachers-tab">
                <div class="card dashboard-card">
                    <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-person-badge"></i> Teacher Management</h5>
                        <?php if($user_role === 'Admin'): ?>
                            <a href="teacher_form.php" class="btn btn-light btn-sm">
                                <i class="bi bi-plus-circle"></i> Add Teacher
                            </a>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($all_teachers)): ?>
                        <div class="table-responsive">
                            <table class="table table-hover table-striped table-sm">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Contact</th>
                                        <th>Gender</th>
                                        <?php if($user_role === 'Admin'): ?>
                                        <th>Actions</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($all_teachers as $teacher): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($teacher['fname'] . " " . $teacher['lname']) ?></td>
                                        <td><?= htmlspecialchars($teacher['contact']) ?></td>
                                        <td>
                                            <span class="badge <?= $teacher['gender'] === 'Male' ? 'bg-primary' : 'bg-danger' ?>">
                                                <?= htmlspecialchars($teacher['gender']) ?>
                                            </span>
                                        </td>
                                        <?php if($user_role === 'Admin'): ?>
                                        <td>
                                            <a href="edit_teacher.php?edit=<?= $teacher['teacher_id'] ?>" class="btn btn-sm btn-warning action-btn" title="Edit">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <a href="delete_teacher.php?delete=<?= $teacher['teacher_id'] ?>" class="btn btn-sm btn-danger action-btn" title="Delete" onclick="return confirm('Are you sure?')">
                                                <i class="bi bi-trash"></i>
                                            </a>
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="bi bi-person" style="font-size: 2rem; color: #6c757d;"></i>
                                <p class="mt-2">No teachers found</p>
                                <?php if($user_role === 'Admin'): ?>
                                    <a href="teacher_form.php" class="btn btn-success mt-2">
                                        <i class="bi bi-plus-circle"></i> Add New Teacher
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Users Tab -->
            <div class="tab-pane fade" id="users" role="tabpanel" aria-labelledby="users-tab">
                <div class="card dashboard-card">
                    <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-people-fill"></i> User Management</h5>
                        <?php if($user_role === 'Admin'): ?>
                            <a href="register.php" class="btn btn-light btn-sm">
                                <i class="bi bi-plus-circle"></i> Add User
                            </a>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($all_users)): ?>
                        <div class="table-responsive">
                            <table class="table table-hover table-striped table-sm">
                                <thead>
                                    <tr>
                                        <th>Username</th>
                                        <th>Role</th>
                                        <th>Created At</th>
                                        <?php if($user_role === 'Admin'): ?>
                                        <th>Actions</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($all_users as $user): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($user['username']) ?></td>
                                        <td>
                                            <span class="badge <?= $user['role'] === 'Admin' ? 'bg-primary' : 'bg-secondary' ?>">
                                                <?= htmlspecialchars($user['role']) ?>
                                            </span>
                                        </td>
                                        <td><?= date('M d, Y', strtotime($user['created_at'])) ?></td>
                                        <?php if($user_role === 'Admin'): ?>
                                        <td>
                                            <a href="edit_user.php?id=<?= $user['id'] ?>" class="btn btn-sm btn-warning action-btn" title="Edit">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <?php if ($user['username'] !== $_SESSION['username']): ?>
                                            <a href="delete_user.php?id=<?= $user['id'] ?>" class="btn btn-sm btn-danger action-btn" title="Delete" onclick="return confirm('Are you sure you want to delete this user?')">
                                                <i class="bi bi-trash"></i>
                                            </a>
                                            <?php endif; ?>
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="bi bi-person" style="font-size: 2rem; color: #6c757d;"></i>
                                <p class="mt-2">No users found</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Subjects Tab -->
            <div class="tab-pane fade" id="subjects" role="tabpanel" aria-labelledby="subjects-tab">
                <div class="card dashboard-card">
                    <div class="card-header bg-warning text-dark d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-book-half"></i> Subject Management</h5>
                        <?php if($user_role === 'Admin'): ?>
                            <a href="addsubject.php" class="btn btn-dark btn-sm">
                                <i class="bi bi-plus-circle"></i> Add Subject
                            </a>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($all_subjects)): ?>
                        <div class="table-responsive">
                            <table class="table table-hover table-striped table-sm">
                                <thead>
                                    <tr>
                                        <th>Subject Name</th>
                                        <?php if($user_role === 'Admin'): ?>
                                        <th>Actions</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($all_subjects as $subject): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($subject['subject_name']) ?></td>
                                        <?php if($user_role === 'Admin'): ?>
                                        <td>
                                            <a href="edit_subject.php?id=<?= $subject['subject_id'] ?>" class="btn btn-sm btn-warning action-btn" title="Edit">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <a href="delete_subject.php?id=<?= $subject['subject_id'] ?>" class="btn btn-sm btn-danger action-btn" title="Delete" onclick="return confirm('Are you sure?')">
                                                <i class="bi bi-trash"></i>
                                            </a>
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="bi bi-book" style="font-size: 2rem; color: #6c757d;"></i>
                                <p class="mt-2">No subjects found</p>
                                <?php if($user_role === 'Admin'): ?>
                                    <a href="addsubject.php" class="btn btn-warning mt-2">
                                        <i class="bi bi-plus-circle"></i> Add New Subject
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>


            <!-- Finance Tab -->
                <div class="tab-pane fade" id="finance" role="tabpanel" aria-labelledby="finance-tab">
                    <div class="card dashboard-card">
                        <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="bi bi-cash-stack"></i> Finance Overview
                            </h5>
                            <a href="finance.php" class="btn btn-light btn-sm">
                                <i class="bi bi-gear"></i> Advanced Finance
                            </a>
                        </div>

                        <div class="card-body">
                            <!-- Finance Summary -->
                            <div class="row mb-4">
                                <div class="col-md-3">
                                    <div class="stat-card bg-primary">
                                        <i class="bi bi-cash"></i>
                                        <h5>Potential Fees</h5>
                                        <h3>UGX <?= number_format($finance_summary['potential_fees'], 0) ?></h3>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="stat-card bg-success">
                                        <i class="bi bi-check-circle"></i>
                                        <h5>Collected Fees</h5>
                                        <h3>UGX <?= number_format($finance_summary['collected_fees'], 0) ?></h3>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="stat-card bg-info">
                                        <i class="bi bi-arrow-up-circle"></i>
                                        <h5>Total Income</h5>
                                        <h3>UGX <?= number_format($finance_summary['total_income'], 0) ?></h3>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="stat-card bg-danger">
                                        <i class="bi bi-arrow-down-circle"></i>
                                        <h5>Total Expenses</h5>
                                        <h3>UGX <?= number_format($finance_summary['total_expenses'], 0) ?></h3>
                                    </div>
                                </div>
                            </div>

                            <!-- Finance Graph -->
                            <div class="row mb-4">
                                <div class="col-12">
                                    <div class="card dashboard-card">
                                        <div class="card-header">
                                            <h5 class="mb-0"><i class="bi bi-bar-chart"></i> Income vs Expenses</h5>
                                        </div>
                                        <div class="card-body">
                                            <canvas id="financeChart" width="100" height="50"></canvas>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Last Five Transactions -->
                            <div class="row">
                                <div class="col-12">
                                    <div class="card dashboard-card">
                                        <div class="card-header">
                                            <h5 class="mb-0"><i class="bi bi-clock-history"></i> Recent Transactions</h5>
                                        </div>
                                        <div class="card-body">
                                            <?php if (!empty($last_five_transactions)): ?>
                                                <div class="table-responsive">
                                                    <table class="table table-hover table-striped table-sm">
                                                        <thead>
                                                            <tr>
                                                                <th>Student</th>
                                                                <th>Type</th>
                                                                <th>Amount</th>
                                                                <th>Date</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach ($last_five_transactions as $finance): ?>
                                                                <tr>
                                                                    <td>
                                                                        <?= htmlspecialchars($finance['student_name'] ?? 'N/A') ?>
                                                                    </td>
                                                                    <td>
                                                                        <span class="badge 
                                                                            <?= $finance['type'] === 'Payment' ? 'bg-success' : 'bg-warning text-dark' ?>">
                                                                            <?= htmlspecialchars($finance['type']) ?>
                                                                        </span>
                                                                    </td>
                                                                    <td>
                                                                        UGX <?= number_format($finance['amount'], 0) ?>
                                                                    </td>
                                                                    <td>
                                                                        <?= date('M d, Y', strtotime($finance['transaction_date'])) ?>
                                                                    </td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            <?php else: ?>
                                                <div class="text-center py-4">
                                                    <i class="bi bi-cash" style="font-size: 2rem; color: #6c757d;"></i>
                                                    <p class="mt-2">No transactions found</p>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            <!-- Store Tab -->
            <div class="tab-pane fade" id="store" role="tabpanel" aria-labelledby="store-tab">
                <div class="card dashboard-card">
                    <div class="card-header bg-warning text-dark d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="bi bi-box-seam"></i> Store / Inventory Management
                        </h5>

                        <?php if ($user_role === 'Admin'): ?>
                            <a href="store.php" class="btn btn-dark btn-sm">
                                <i class="bi bi-plus-circle"></i> Add Item
                            </a>
                        <?php endif; ?>
                    </div>

                    <div class="card-body">
                        <?php if (!empty($all_store_items)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover table-striped table-sm">
                                    <thead>
                                        <tr>
                                            <th>Item Name</th>
                                            <th>Category</th>
                                            <th>Quantity</th>
                                            <th>Unit Price</th>
                                            <th>Status</th>
                                            <?php if ($user_role === 'Admin'): ?>
                                                <th>Actions</th>
                                            <?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($all_store_items as $item): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($item['item_name']) ?></td>
                                                <td><?= htmlspecialchars($item['category']) ?></td>
                                                <td>
                                                    <span class="badge <?= $item['quantity'] > 0 ? 'bg-success' : 'bg-danger' ?>">
                                                        <?= $item['quantity'] ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    UGX <?= number_format($item['unit_price'], 0) ?>
                                                </td>
                                                <td>
                                                    <?php if ($item['quantity'] <= 0): ?>
                                                        <span class="badge bg-danger">Out of Stock</span>
                                                    <?php elseif ($item['quantity'] < 10): ?>
                                                        <span class="badge bg-warning text-dark">Low Stock</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-success">In Stock</span>
                                                    <?php endif; ?>
                                                </td>

                                                <?php if ($user_role === 'Admin'): ?>
                                                    <td>
                                                        <a href="view_store.php?id=<?= $item['item_id'] ?>"
                                                        class="btn btn-sm btn-warning action-btn"
                                                        title="Edit">
                                                            <i class="bi bi-pencil"></i>
                                                        </a>

                                                        <a href="delete_store.php?id=<?= $item['item_id'] ?>"
                                                        class="btn btn-sm btn-danger action-btn"
                                                        title="Delete"
                                                        onclick="return confirm('Are you sure?')">
                                                            <i class="bi bi-trash"></i>
                                                        </a>
                                                    </td>
                                                <?php endif; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="bi bi-box" style="font-size: 2rem; color: #6c757d;"></i>
                                <p class="mt-2">No store items found</p>

                                <?php if ($user_role === 'Admin'): ?>
                                    <a href="store.php" class="btn btn-warning mt-2">
                                        <i class="bi bi-plus-circle"></i> Add First Item
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Exams Tab -->
            <div class="tab-pane fade" id="exams" role="tabpanel" aria-labelledby="exams-tab">
                <div class="card dashboard-card">
                    <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-clipboard2-data-fill"></i> Exam Management</h5>
                        <?php if($user_role === 'Admin'): ?>
                            <a href="exams.php" class="btn btn-light btn-sm">
                                <i class="bi bi-plus-circle"></i> Add Exam
                            </a>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($all_exams)): ?>
                        <div class="table-responsive">
                            <table class="table table-hover table-striped table-sm">
                                <thead>
                                    <tr>
                                        <th>Exam</th>
                                        <th>Subjects</th>
                                        <th>Date</th>
                                        <?php if($user_role === 'Admin'): ?>
                                        <th>Actions</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($all_exams as $exam): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($exam['exam_name']) ?></td>
                                        <td><?= htmlspecialchars($exam['subjects'] ?? 'No subjects') ?></td>
                                        <td><?= date('M d, Y', strtotime($exam['exam_date'])) ?></td>
                                        <?php if($user_role === 'Admin'): ?>
                                        <td>
                                            <a href="view_exams.php?id=<?= $exam['exam_id'] ?>" class="btn btn-sm btn-warning action-btn" title="Edit">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <a href="delete_exam.php?id=<?= $exam['exam_id'] ?>" class="btn btn-sm btn-danger action-btn" title="Delete" onclick="return confirm('Are you sure?')">
                                                <i class="bi bi-trash"></i>
                                            </a>
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="bi bi-clipboard" style="font-size: 2rem; color: #6c757d;"></i>
                                <p class="mt-2">No exams found</p>
                                <?php if($user_role === 'Admin'): ?>
                                    <a href="exams.php" class="btn btn-danger mt-2">
                                        <i class="bi bi-plus-circle"></i> Add New Exam
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/site.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Initialize Bootstrap tooltips
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl)
        });

        // Handle clicking on "View All" links in overview
        document.querySelectorAll('a[data-bs-toggle="pill"][href^="#"]').forEach(link => {
            link.addEventListener('click', function(event) {
                event.preventDefault();
                const targetTabId = this.getAttribute('href');
                const targetTab = document.querySelector(`button[data-bs-target="${targetTabId}"]`);
                if(targetTab) {
                    const tabInstance = new bootstrap.Tab(targetTab);
                    tabInstance.show();
                }
            });
        });

        // Optional: Add animation to stat cards on page load
        document.addEventListener('DOMContentLoaded', function() {
            const statCards = document.querySelectorAll('.stat-card');
            statCards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
                card.classList.add('animate__animated', 'animate__fadeInUp');
            });

            // Finance Chart
            const ctx = document.getElementById('financeChart').getContext('2d');
            const financeChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: ['Income', 'Expenses'],
                    datasets: [{
                        label: 'Amount (UGX)',
                        data: [<?= $finance_summary['total_income'] ?>, <?= $finance_summary['total_expenses'] ?>],
                        backgroundColor: [
                            'rgba(76, 201, 240, 0.6)',
                            'rgba(242, 5, 133, 0.6)'
                        ],
                        borderColor: [
                            'rgba(76, 201, 240, 1)',
                            'rgba(242, 5, 133, 1)'
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return 'UGX ' + value.toLocaleString();
                                }
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        }
                    }
                }
            });
        });
    </script>
</body>
</html>
<?php
// Close the database connection
if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
?>