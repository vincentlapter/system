<?php
session_start();
// Ensure the user is logged in as an Administrator
if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'Administrator') {
    header('Location: index.php');
    exit();
}

include 'config.php';

$selected_class_id = isset($_GET['class_id']) ? $_GET['class_id'] : null;

// Fetch all classes
$classes_query = "SELECT class_id, class_name FROM class ORDER BY class_name";
$classes_stmt = $conn->prepare($classes_query);
$assigned_classes = [];
if ($classes_stmt) {
    $classes_stmt->execute();
    $classes_result = $classes_stmt->get_result();
    while ($row = $classes_result->fetch_assoc()) {
        $assigned_classes[$row['class_id']] = $row['class_name'];
    }
    $classes_stmt->close();
}

// Fetch grading scheme
$grading_scheme = [];
$grade_query = "SELECT * FROM grading_scheme ORDER BY min_mark DESC";
$grade_stmt = $conn->prepare($grade_query);
if ($grade_stmt) {
    $grade_stmt->execute();
    $grade_result = $grade_stmt->get_result();
    while ($row = $grade_result->fetch_assoc()) {
        $grading_scheme[] = $row;
    }
    $grade_stmt->close();
}

$class_students_marks = [];
$selected_class_name = '';

if ($selected_class_id) {
    // Fetch students in the selected class
    $students_query = "SELECT DISTINCT student_id, fname, lname FROM student WHERE class_id = ? ORDER BY lname, fname";
    $students_stmt = $conn->prepare($students_query);
    $students = [];
    if ($students_stmt) {
        $students_stmt->bind_param("i", $selected_class_id);
        $students_stmt->execute();
        $students_result = $students_stmt->get_result();
        while ($student = $students_result->fetch_assoc()) {
            $students[$student['student_id']] = $student['fname'] . ' ' . $student['lname'];
            $class_students_marks[$student['student_id']] = [
                'name' => $student['fname'] . ' ' . $student['lname'],
                'subjects' => []
            ];
        }
        $students_stmt->close();
    }

    // Fetch all subjects
    $subjects_query = "SELECT subject_id, subject_name FROM subject ORDER BY subject_name";
    $subjects_stmt = $conn->prepare($subjects_query);
    $all_subjects = [];
    if ($subjects_stmt) {
        $subjects_stmt->execute();
        $subjects_result = $subjects_stmt->get_result();
        while ($subject = $subjects_result->fetch_assoc()) {
            $all_subjects[$subject['subject_id']] = $subject['subject_name'];
        }
        $subjects_stmt->close();
    }

    // Fetch marks and calculate grades
    $marks_query = "SELECT m.student_id, s.subject_name, MAX(m.marks_obtained) as marks_obtained
                    FROM marks m
                    JOIN exam_subjects es ON m.id = es.id
                    JOIN subject s ON es.subject_id = s.subject_id
                    JOIN student st ON m.student_id = st.student_id
                    WHERE st.class_id = ?
                    GROUP BY m.student_id, s.subject_name";
    $marks_stmt = $conn->prepare($marks_query);
    if ($marks_stmt) {
        $marks_stmt->bind_param("i", $selected_class_id);
        $marks_stmt->execute();
        $marks_result = $marks_stmt->get_result();
        while ($mark = $marks_result->fetch_assoc()) {
            $student_id = $mark['student_id'];
            $subject_name = $mark['subject_name'];
            $marks_obtained = $mark['marks_obtained'];
            
            // Determine grade
            $grade = 'N/A';
            foreach ($grading_scheme as $scheme) {
                if ($marks_obtained >= $scheme['min_mark'] && $marks_obtained <= $scheme['max_mark']) {
                    $grade = $scheme['grade_name'];
                    break;
                }
            }
            
            $class_students_marks[$student_id]['subjects'][$subject_name] = [
                'marks' => $marks_obtained,
                'grade' => $grade
            ];
        }
        $marks_stmt->close();
    }

    // Calculate average marks and overall grade for each student
    foreach ($class_students_marks as $student_id => &$student_data) {
        $total_marks = 0;
        $subject_count = 0;
        
        foreach ($all_subjects as $subject_name) {
            if (isset($student_data['subjects'][$subject_name])) {
                $total_marks += $student_data['subjects'][$subject_name]['marks'];
                $subject_count++;
            }
        }
        
        $student_data['average'] = ($subject_count > 0) ? round($total_marks / $subject_count, 2) : 'N/A';
        
        // Determine overall grade
        $student_data['overall_grade'] = 'N/A';
        if ($student_data['average'] !== 'N/A') {
            foreach ($grading_scheme as $scheme) {
                if ($student_data['average'] >= $scheme['min_mark'] && $student_data['average'] <= $scheme['max_mark']) {
                    $student_data['overall_grade'] = $scheme['grade_name'];
                    break;
                }
            }
        }
    }

    $selected_class_name = $assigned_classes[$selected_class_id] ?? '';
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="stylesheet" href="assets/css/site.css">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Class Assessment Sheets - School Management System</title>
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
            --sidebar-width: 280px;
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            background-color: #f5f7fa;
            color: #333;
        }
        
        /* Sidebar Styles */
        .sidebar {
            background: linear-gradient(180deg, var(--secondary-color), var(--primary-color));
            color: white;
            min-height: 100vh;
            padding: 20px 0;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
            position: sticky;
            top: 0;
        }
        
        .sidebar-header {
            padding: 0 20px 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            margin-bottom: 20px;
        }
        
        .sidebar .nav-link {
            color: rgba(255,255,255,0.8);
            padding: 12px 25px;
            margin: 2px 0;
            border-radius: 0 30px 30px 0;
            transition: all 0.3s ease;
            font-weight: 500;
        }
        
        .sidebar .nav-link:hover {
            color: white;
            background-color: rgba(255,255,255,0.1);
            transform: translateX(5px);
        }
        
        .sidebar .nav-link.active {
            background-color: white;
            color: var(--primary-color);
            font-weight: 600;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .sidebar .nav-link i {
            margin-right: 10px;
            font-size: 1.1rem;
        }
        
        /* Main Content Styles */
        .main-content {
            padding: 30px;
        }
        
        .page-header {
            background-color: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            border-bottom: 3px solid var(--primary-color);
        }
        
        .page-title {
            color: var(--secondary-color);
            font-weight: 600;
            margin-bottom: 0;
        }
        
        .class-title {
            color: var(--dark-color);
            font-weight: 500;
            margin-bottom: 0;
        }
        
        /* Action Buttons */
        .action-buttons {
            background-color: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .btn-success {
            background-color: var(--success-color);
            border-color: var(--success-color);
        }
        
        /* Table Styles */
        .table-container {
            background-color: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            overflow: hidden;
        }
        
        .table {
            margin-bottom: 0;
        }
        
        .table thead th {
            background-color: var(--primary-color);
            color: white;
            border-bottom: none;
            font-weight: 500;
            padding: 15px;
            position: sticky;
            top: 0;
        }
        
        .table tbody tr:nth-child(even) {
            background-color: rgba(67, 97, 238, 0.05);
        }
        
        .table tbody tr:hover {
            background-color: rgba(67, 97, 238, 0.1);
        }
        
        .table td {
            padding: 12px 15px;
            vertical-align: middle;
        }
        
        /* Grade Badges */
        .grade-badge {
            padding: 5px 10px;
            border-radius: 4px;
            font-weight: 500;
            font-size: 0.85rem;
        }
        
        .grade-A { background-color: #2ecc71; color: white; }
        .grade-B { background-color: #3498db; color: white; }
        .grade-C { background-color: #f39c12; color: white; }
        .grade-D { background-color: #e74c3c; color: white; }
        .grade-F { background-color: #7f8c8d; color: white; }
        
        /* Responsive Adjustments */
        @media (max-width: 768px) {
            .sidebar {
                position: static;
                min-height: auto;
            }
            
            .main-content {
                padding: 20px 15px;
            }
            
            .table-responsive {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
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
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar Navigation -->
            <nav class="col-md-3 col-lg-2 d-md-block sidebar">
                <div class="sidebar-header">
                    <h5>School Management</h5>
                </div>
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link" href="admin_dashboard.php">
                            <i class="bi bi-arrow-left-circle"></i> Back to Dashboard
                        </a>
                    </li>
                    <li class="nav-item mt-3">
                        <h6 class="px-3 text-uppercase text-white-50">Classes</h6>
                    </li>
                    <?php if (!empty($assigned_classes)): ?>
                        <?php foreach ($assigned_classes as $class_id => $class_name): ?>
                            <li class="nav-item">
                                <a class="nav-link <?php echo ($selected_class_id == $class_id) ? 'active' : ''; ?>" href="?class_id=<?php echo $class_id; ?>">
                                    <i class="bi bi-mortarboard"></i> <?php echo htmlspecialchars($class_name); ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <li class="nav-item">
                            <span class="nav-link text-white-50">No classes available</span>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>

            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 main-content">
                <div class="page-header d-flex justify-content-between align-items-center">
                    <div>
                        <h1 class="page-title">Class Assessment Sheet</h1>
                        <?php if ($selected_class_name): ?>
                            <h3 class="class-title mt-2">
                                <i class="bi bi-people-fill"></i> <?php echo htmlspecialchars($selected_class_name); ?>
                            </h3>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($selected_class_id): ?>
                    <div class="action-buttons">
                        <div class="d-flex flex-wrap gap-2">
                            <a href="report_card.php?class_id=<?php echo $selected_class_id; ?>" class="btn btn-success">
                                <i class="bi bi-file-earmark-pdf-fill"></i> Generate Report Cards
                            </a>
                        </div>
                    </div>

                    <?php if (!empty($class_students_marks)): ?>
                        <div class="table-container">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Student Name</th>
                                            <?php if (!empty($all_subjects)): ?>
                                                <?php foreach ($all_subjects as $subject_name): ?>
                                                    <th class="text-center"><?php echo htmlspecialchars($subject_name); ?></th>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                            <th class="text-center">Average</th>
                                            <th class="text-center">Overall Grade</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $i = 1; ?>
                                        <?php foreach ($class_students_marks as $student_id => $student_data): ?>
                                            <tr>
                                                <td><?php echo $i++; ?></td>
                                                <td><?php echo htmlspecialchars($student_data['name']); ?></td>
                                                <?php if (!empty($all_subjects)): ?>
                                                    <?php foreach ($all_subjects as $subject_name): ?>
                                                        <td class="text-center">
                                                            <?php if (isset($student_data['subjects'][$subject_name])): ?>
                                                                <div class="d-flex flex-column">
                                                                    <span><?php echo $student_data['subjects'][$subject_name]['marks']; ?></span>
                                                                    <span class="grade-badge grade-<?php echo substr($student_data['subjects'][$subject_name]['grade'], 0, 1); ?>">
                                                                        <?php echo $student_data['subjects'][$subject_name]['grade']; ?>
                                                                    </span>
                                                                </div>
                                                            <?php else: ?>
                                                                <span class="text-muted">N/A</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                                <td class="text-center fw-bold">
                                                    <?php echo $student_data['average']; ?>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($student_data['overall_grade'] !== 'N/A'): ?>
                                                        <span class="grade-badge grade-<?php echo substr($student_data['overall_grade'], 0, 1); ?>">
                                                            <?php echo $student_data['overall_grade']; ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-muted">N/A</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle-fill"></i> No students or marks found for the selected class.
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="alert alert-primary">
                        <i class="bi bi-info-circle-fill"></i> Please select a class from the sidebar to view the assessment sheet.
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>