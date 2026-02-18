<?php
session_start();
// Ensure the user is logged in
if (!isset($_SESSION['username'])) {
    header('Location: index.php');
    exit();
}

include 'config.php';

// Initialize variables
$classes = [];
$reports = [];
$selected_class_name = '';
$selected_student_name = '';
$selected_student_display = '';
$class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
$student_id = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;

// Fetch all classes for dropdown
$classes_query = "SELECT class_id, class_name FROM class ORDER BY class_name";
$classes_stmt = $conn->prepare($classes_query);
if ($classes_stmt) {
    $classes_stmt->execute();
    $classes_result = $classes_stmt->get_result();
    while ($row = $classes_result->fetch_assoc()) {
        $classes[] = $row;
    }
    $classes_stmt->close();
}

// Handle AJAX student search
if (isset($_GET['search_students'])) {
    $search_term = isset($_GET['q']) ? trim($_GET['q']) : '';
    $search_results = [];

    if (!empty($search_term)) {
        $search_query = "SELECT s.student_id, s.fname, s.lname, c.class_name
                        FROM student s
                        JOIN class c ON s.class_id = c.class_id
                        WHERE CONCAT(s.fname, ' ', s.lname) LIKE ? 
                        OR s.fname LIKE ? 
                        OR s.lname LIKE ? 
                        OR c.class_name LIKE ?
                        ORDER BY s.lname, s.fname
                        LIMIT 10";
        $search_stmt = $conn->prepare($search_query);
        if ($search_stmt) {
            $like_term = '%' . $search_term . '%';
            $search_stmt->bind_param("ssss", $like_term, $like_term, $like_term, $like_term);
            $search_stmt->execute();
            $search_result = $search_stmt->get_result();
            while ($row = $search_result->fetch_assoc()) {
                $search_results[] = $row;
            }
            $search_stmt->close();
        }
    }

    header('Content-Type: application/json');
    echo json_encode($search_results);
    exit();
}

// Get selected student details for search input
if ($student_id > 0) {
    $student_display_query = "SELECT s.fname, s.lname, c.class_name FROM student s JOIN class c ON s.class_id = c.class_id WHERE s.student_id = ?";
    $student_display_stmt = $conn->prepare($student_display_query);
    if ($student_display_stmt) {
        $student_display_stmt->bind_param("i", $student_id);
        $student_display_stmt->execute();
        $student_display_result = $student_display_stmt->get_result();
        $student_display_data = $student_display_result->fetch_assoc();
        if ($student_display_data) {
            $selected_student_display = $student_display_data['fname'] . ' ' . $student_display_data['lname'] . ' (' . $student_display_data['class_name'] . ')';
        }
        $student_display_stmt->close();
    }
}

// Fetch grading scheme with remarks
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

// Generate reports based on selection
if ($class_id > 0) {
    // Get class name first
    $class_name_query = "SELECT class_name FROM class WHERE class_id = ?";
    $class_name_stmt = $conn->prepare($class_name_query);
    if ($class_name_stmt) {
        $class_name_stmt->bind_param("i", $class_id);
        $class_name_stmt->execute();
        $class_name_result = $class_name_stmt->get_result();
        $class_data = $class_name_result->fetch_assoc();
        $selected_class_name = $class_data['class_name'] ?? '';
        $class_name_stmt->close();
    }
    
    // Generate reports for all students in the class
    $students_query = "SELECT s.student_id, s.fname, s.lname, c.class_name
                       FROM student s
                       JOIN class c ON s.class_id = c.class_id
                       WHERE s.class_id = ?
                       ORDER BY s.lname, s.fname";
    $students_stmt = $conn->prepare($students_query);
    if ($students_stmt) {
        $students_stmt->bind_param("i", $class_id);
        $students_stmt->execute();
        $students_result = $students_stmt->get_result();
        while ($student = $students_result->fetch_assoc()) {
            $reports[] = generate_student_report($conn, $student['student_id'], $grading_scheme);
        }
        $students_stmt->close();
    }
} elseif ($student_id > 0) {
    // Generate report for a single student
    $report = generate_student_report($conn, $student_id, $grading_scheme);
    if ($report) {
        $reports[] = $report;
        $selected_student_name = $report['student']['fname'] . ' ' . $report['student']['lname'];
    }
}

function generate_student_report($conn, $student_id, $grading_scheme) {
    // Fetch student details
    $student_query = "SELECT s.fname, s.lname, s.dob, s.gender, c.class_name
                      FROM student s
                      JOIN class c ON s.class_id = c.class_id
                      WHERE s.student_id = ?";
    $student_stmt = $conn->prepare($student_query);
    $student_data = [];
    if ($student_stmt) {
        $student_stmt->bind_param("i", $student_id);
        if (!$student_stmt->execute()) {
            return null;
        }
        $student_result = $student_stmt->get_result();
        $student_data = $student_result->fetch_assoc();
        $student_stmt->close();
        
        if (!$student_data) {
            return null;
        }
    }

    // Fetch marks
    $marks_query = "SELECT sub.subject_name, MAX(m.marks_obtained) as marks_obtained
                    FROM marks m
                    JOIN subject sub ON m.subject_id = sub.subject_id
                    WHERE m.student_id = ?
                    GROUP BY sub.subject_name";
    $marks_stmt = $conn->prepare($marks_query);
    $marks = [];
    if ($marks_stmt) {
        $marks_stmt->bind_param("i", $student_id);
        $marks_stmt->execute();
        $marks_result = $marks_stmt->get_result();
        while ($mark = $marks_result->fetch_assoc()) {
            $marks[] = $mark;
        }
        $marks_stmt->close();
    }

    // Calculate grades and remarks
    $total_marks = 0;
    $subject_count = count($marks);
    
    foreach ($marks as &$mark) {
        $mark['grade'] = 'N/A';
        $mark['remarks'] = '';
        
        foreach ($grading_scheme as $scheme) {
            if ($mark['marks_obtained'] >= $scheme['min_mark'] && $mark['marks_obtained'] <= $scheme['max_mark']) {
                $mark['grade'] = $scheme['grade_name'];
                $mark['remarks'] = $scheme['remarks'] ?? '';
                break;
            }
        }
        $total_marks += $mark['marks_obtained'];
    }

    $average = ($subject_count > 0) ? round($total_marks / $subject_count, 2) : 0;
    $overall_grade = 'N/A';
    $overall_remarks = '';
    
    foreach ($grading_scheme as $scheme) {
        if ($average >= $scheme['min_mark'] && $average <= $scheme['max_mark']) {
            $overall_grade = $scheme['grade_name'];
            $overall_remarks = $scheme['remarks'] ?? '';
            break;
        }
    }

    return [
        'student' => $student_data,
        'marks' => $marks,
        'average' => $average,
        'overall_grade' => $overall_grade,
        'overall_remarks' => $overall_remarks
    ];
}

// Don't close the connection here if you need it later in the script
// $conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="stylesheet" href="assets/css/site.css">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report Cards - School Management System</title>
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

        .dashboard-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 15px 0;
            margin-bottom: 25px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .dashboard-header h1 {
            font-weight: 600;
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
        }

        .selection-card {
            background-color: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            padding: 25px;
            margin-bottom: 25px;
        }

        .form-select, .btn {
            border-radius: 8px;
        }

        .btn-generate {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            padding: 10px 20px;
            font-weight: 500;
        }

        .btn-generate:hover {
            background-color: var(--secondary-color);
            border-color: var(--secondary-color);
        }

        @media print {
            .no-print { display: none !important; }
            .report-card { page-break-after: always; }
            body { background: white !important; }
        }

        .report-card {
            border: 2px solid #000;
            margin: 20px 0;
            padding: 20px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .header {
            text-align: center;
            border-bottom: 2px solid #000;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }

        .student-info {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .student-info > div {
            flex: 1;
            min-width: 200px;
        }

        .marks-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            background: white;
        }

        .marks-table th {
            background-color: var(--primary-color);
            color: white;
            border: 1px solid #000;
            padding: 12px;
            text-align: center;
            font-weight: 600;
        }

        .marks-table td {
            border: 1px solid #000;
            padding: 10px;
            text-align: center;
        }

        .marks-table tbody tr:nth-child(even) {
            background-color: rgba(67, 97, 238, 0.05);
        }

        .summary {
            text-align: center;
            font-weight: bold;
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-top: 20px;
        }

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

        .alert {
            border-radius: 8px;
            border: none;
        }

        .btn-print {
            background-color: var(--success-color);
            border-color: var(--success-color);
            color: white;
        }

        .btn-print:hover {
            background-color: #3bb3d4;
            border-color: #3bb3d4;
        }

        .btn-preview {
            background-color: var(--info-color);
            border-color: var(--info-color);
            color: white;
        }

        .btn-preview:hover {
            background-color: #3a86ff;
            border-color: #3a86ff;
        }

        .btn-export {
            background-color: var(--warning-color);
            border-color: var(--warning-color);
            color: white;
        }

        .btn-export:hover {
            background-color: #fb8500;
            border-color: #fb8500;
        }

        .report-actions {
            background-color: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
        }

        .action-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .modal-content {
            border-radius: 12px;
            border: none;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }

        .modal-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border-radius: 12px 12px 0 0;
            border-bottom: none;
        }

        .modal-body {
            max-height: 70vh;
            overflow-y: auto;
        }

        .report-preview {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }

        .school-logo {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2rem;
            margin: 0 auto 15px;
        }

        .report-header {
            text-align: center;
            border-bottom: 3px solid var(--primary-color);
            padding-bottom: 15px;
            margin-bottom: 25px;
        }

        .student-details {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 25px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .detail-item {
            text-align: center;
        }

        .detail-label {
            font-weight: 600;
            color: var(--primary-color);
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }

        .detail-value {
            font-size: 1.1rem;
            color: var(--dark-color);
            font-weight: 500;
        }

        .performance-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .performance-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border-left: 4px solid var(--primary-color);
        }

        .performance-card.average {
            border-left-color: var(--success-color);
        }

        .performance-card.grade {
            border-left-color: var(--warning-color);
        }

        .performance-card.remarks {
            border-left-color: var(--info-color);
        }

        .performance-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .performance-label {
            font-size: 0.9rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .enhanced-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .enhanced-table th {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .enhanced-table td {
            padding: 15px;
            border-bottom: 1px solid #dee2e6;
        }

        .enhanced-table tbody tr:hover {
            background-color: rgba(67, 97, 238, 0.05);
        }

        .enhanced-table .subject-name {
            font-weight: 600;
            color: var(--dark-color);
        }

        .enhanced-table .marks-value {
            font-weight: 700;
            font-size: 1.1rem;
            color: var(--primary-color);
        }

        .enhanced-table .remarks-text {
            font-style: italic;
            color: #6c757d;
        }

        .certificate-footer {
            text-align: center;
            margin-top: 30px;
            padding: 20px;
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 8px;
            border-top: 2px solid var(--primary-color);
        }

        .signature-line {
            border-top: 1px solid #6c757d;
            width: 200px;
            margin: 20px auto 10px;
        }

        .signature-text {
            font-size: 0.9rem;
            color: #6c757d;
        }

        .print-options {
            background: white;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            border: 1px solid #dee2e6;
        }

        .print-options h6 {
            margin-bottom: 10px;
            color: var(--primary-color);
        }

        .loading-spinner {
            display: none;
            text-align: center;
            padding: 20px;
        }

        .spinner-border-sm {
            width: 1rem;
            height: 1rem;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .fade-in-up {
            animation: fadeInUp 0.5s ease-out;
        }

        /* Search suggestions */
        #student_suggestions {
            border: 1px solid #dee2e6;
            border-radius: 0.375rem;
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
            background-color: white;
            z-index: 1000;
            max-height: 200px;
            overflow-y: auto;
            display: none;
        }

        .suggestion-item {
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .suggestion-item:hover {
            background-color: #f8f9fa;
        }

        @media (max-width: 768px) {
            .student-info {
                flex-direction: column;
            }

            .marks-table {
                font-size: 0.9rem;
            }

            .marks-table th, .marks-table td {
                padding: 8px;
            }
        }
    </style>
</head>
<body>
    <header class="dashboard-header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center flex-wrap">
                <div>
                    <h1><i class="bi bi-file-earmark-pdf"></i> Report Cards</h1>
                    <p class="mb-0">Generate and download student report cards</p>
                </div>
                <div class="mt-2 mt-md-0 d-flex align-items-center">
                    <a href="admin_dashboard.php" class="btn btn-outline-light btn-sm me-2">
                        <i class="bi bi-arrow-left"></i> Back to Dashboard
                    </a>
                    <?php if (!empty($reports)): ?>
                        <button onclick="window.print()" class="btn btn-print btn-sm no-print">
                            <i class="bi bi-printer"></i> Print
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </header>

    <div class="container">
        <!-- Selection Form -->
        <div class="selection-card no-print">
            <h5 class="mb-3"><i class="bi bi-gear"></i> Select Report Type</h5>
            <form method="GET" action="report_card.php" class="row g-3" id="reportForm">
                <div class="col-md-5">
                    <label for="class_id" class="form-label">Select Class (for all students in class)</label>
                    <select class="form-select" id="class_id" name="class_id">
                        <option value="">Choose a class...</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['class_id']; ?>" <?php echo ($class_id == $class['class_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['class_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-5">
                    <label for="student_search" class="form-label">Or Search Individual Student</label>
                    <div class="position-relative">
                        <input type="text" class="form-control" id="student_search" name="student_search"
                               placeholder="Type student name or class..." autocomplete="off"
                               value="<?php echo htmlspecialchars($selected_student_display); ?>">
                        <input type="hidden" id="student_id" name="student_id" value="<?php echo $student_id; ?>">
                        <div id="student_suggestions"></div>
                    </div>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-generate w-100" id="generateBtn">
                        <i class="bi bi-file-earmark-pdf"></i> Generate
                    </button>
                </div>
            </form>
        </div>

        <!-- Report Actions -->
        <?php if (!empty($reports)): ?>
            <div class="report-actions no-print">
                <div class="d-flex justify-content-between align-items-center flex-wrap">
                    <div>
                        <h5 class="mb-0">
                            <i class="bi bi-info-circle"></i>
                            <?php if ($selected_class_name): ?>
                                Report Cards for <strong><?php echo htmlspecialchars($selected_class_name); ?></strong>
                                (<?php echo count($reports); ?> students)
                            <?php elseif ($selected_student_name): ?>
                                Report Card for <strong><?php echo htmlspecialchars($selected_student_name); ?></strong>
                            <?php endif; ?>
                        </h5>
                        <small class="text-muted">Generated on <?php echo date('F j, Y \a\t g:i A'); ?></small>
                    </div>
                    <div class="action-buttons">
                        <button type="button" class="btn btn-preview" onclick="showPreview()">
                            <i class="bi bi-eye"></i> Preview
                        </button>
                        <button type="button" class="btn btn-export" onclick="exportToPDF()">
                            <i class="bi bi-file-earmark-pdf"></i> Export PDF
                        </button>
                        <button onclick="window.print()" class="btn btn-print">
                            <i class="bi bi-printer"></i> Print
                        </button>
                    </div>
                </div>
            </div>

            <!-- Preview Modal -->
            <div class="modal fade" id="previewModal" tabindex="-1" aria-labelledby="previewModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-xl">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="previewModalLabel">
                                <i class="bi bi-eye"></i> Report Card Preview
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="print-options">
                                <h6><i class="bi bi-gear"></i> Print Options</h6>
                                <div class="row">
                                    <div class="col-md-4">
                                        <label class="form-check">
                                            <input type="checkbox" class="form-check-input" id="includeLogo" checked>
                                            <span class="form-check-label">Include School Logo</span>
                                        </label>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-check">
                                            <input type="checkbox" class="form-check-input" id="includeSignature" checked>
                                            <span class="form-check-label">Include Signature Line</span>
                                        </label>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-check">
                                            <input type="checkbox" class="form-check-input" id="includeWatermark" checked>
                                            <span class="form-check-label">Include Watermark</span>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <div class="loading-spinner" id="previewSpinner">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                                <p class="mt-2">Generating preview...</p>
                            </div>

                            <div id="previewContent">
                                <?php foreach ($reports as $index => $report): ?>
                                    <?php if ($report && isset($report['student'])): ?>
                                        <div class="report-preview <?php echo $index > 0 ? 'mt-4' : ''; ?>">
                                            <div class="school-logo">
                                                <i class="bi bi-mortarboard-fill"></i>
                                            </div>

                                            <div class="report-header">
                                                <h1 class="mb-2">Academic Report Card</h1>
                                                <h3 class="text-muted">School Management System</h3>
                                                <p class="mb-0">Academic Year <?php echo date('Y'); ?></p>
                                            </div>

                                            <div class="student-details">
                                                <div class="detail-item">
                                                    <div class="detail-label">Student Name</div>
                                                    <div class="detail-value"><?php echo htmlspecialchars($report['student']['fname'] . ' ' . $report['student']['lname']); ?></div>
                                                </div>
                                                <div class="detail-item">
                                                    <div class="detail-label">Class</div>
                                                    <div class="detail-value"><?php echo htmlspecialchars($report['student']['class_name']); ?></div>
                                                </div>
                                                <div class="detail-item">
                                                    <div class="detail-label">Date of Birth</div>
                                                    <div class="detail-value"><?php echo htmlspecialchars($report['student']['dob']); ?></div>
                                                </div>
                                                <div class="detail-item">
                                                    <div class="detail-label">Gender</div>
                                                    <div class="detail-value"><?php echo htmlspecialchars($report['student']['gender']); ?></div>
                                                </div>
                                                <div class="detail-item">
                                                    <div class="detail-label">Report Date</div>
                                                    <div class="detail-value"><?php echo date('M d, Y'); ?></div>
                                                </div>
                                                <div class="detail-item">
                                                    <div class="detail-label">Academic Year</div>
                                                    <div class="detail-value"><?php echo date('Y'); ?></div>
                                                </div>
                                            </div>

                                            <div class="performance-summary">
                                                <div class="performance-card average">
                                                    <div class="performance-value"><?php echo htmlspecialchars($report['average']); ?>%</div>
                                                    <div class="performance-label">Average Marks</div>
                                                </div>
                                                <div class="performance-card grade">
                                                    <div class="performance-value">
                                                        <?php 
                                                        $grade_class = '';
                                                        if (isset($report['overall_grade']) && !empty($report['overall_grade'])) {
                                                            $first_char = substr($report['overall_grade'], 0, 1);
                                                            $grade_class = 'grade-' . $first_char;
                                                        }
                                                        ?>
                                                        <span class="grade-badge <?php echo $grade_class; ?>">
                                                            <?php echo htmlspecialchars($report['overall_grade']); ?>
                                                        </span>
                                                    </div>
                                                    <div class="performance-label">Overall Grade</div>
                                                </div>
                                                <div class="performance-card remarks">
                                                    <div class="performance-value" style="font-size: 1rem;"><?php echo htmlspecialchars($report['overall_remarks']); ?></div>
                                                    <div class="performance-label">Remarks</div>
                                                </div>
                                            </div>

                                            <?php if (!empty($report['marks'])): ?>
                                                <table class="enhanced-table">
                                                    <thead>
                                                        <tr>
                                                            <th>Subject</th>
                                                            <th>Marks Obtained</th>
                                                            <th>Grade</th>
                                                            <th>Remarks</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($report['marks'] as $mark): ?>
                                                            <tr>
                                                                <td class="subject-name"><?php echo htmlspecialchars($mark['subject_name']); ?></td>
                                                                <td class="marks-value"><?php echo htmlspecialchars($mark['marks_obtained']); ?></td>
                                                                <td>
                                                                    <?php 
                                                                    $subject_grade_class = '';
                                                                    if (isset($mark['grade']) && !empty($mark['grade'])) {
                                                                        $first_char = substr($mark['grade'], 0, 1);
                                                                        $subject_grade_class = 'grade-' . $first_char;
                                                                    }
                                                                    ?>
                                                                    <span class="grade-badge <?php echo $subject_grade_class; ?>">
                                                                        <?php echo htmlspecialchars($mark['grade']); ?>
                                                                    </span>
                                                                </td>
                                                                <td class="remarks-text"><?php echo htmlspecialchars($mark['remarks']); ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            <?php else: ?>
                                                <div class="alert alert-warning">
                                                    <i class="bi bi-exclamation-triangle"></i> No marks data available for this student.
                                                </div>
                                            <?php endif; ?>

                                            <div class="certificate-footer">
                                                <p class="mb-3">This report card is issued as an official record of the student's academic performance.</p>
                                                <div class="signature-line"></div>
                                                <div class="signature-text">Principal/Teacher Signature</div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                <i class="bi bi-x-circle"></i> Close
                            </button>
                            <button type="button" class="btn btn-export" onclick="exportToPDF()">
                                <i class="bi bi-file-earmark-pdf"></i> Export PDF
                            </button>
                            <button type="button" class="btn btn-print" onclick="printPreview()">
                                <i class="bi bi-printer"></i> Print
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Report Display -->
            <div class="mb-3 no-print">
                <div class="alert alert-success">
                    <i class="bi bi-check-circle"></i>
                    <?php if ($selected_class_name): ?>
                        Successfully generated report cards for <strong><?php echo htmlspecialchars($selected_class_name); ?></strong>
                        (<?php echo count($reports); ?> students)
                    <?php elseif ($selected_student_name): ?>
                        Successfully generated report card for <strong><?php echo htmlspecialchars($selected_student_name); ?></strong>
                    <?php endif; ?>
                </div>
            </div>

            <?php foreach ($reports as $report): ?>
                <?php if ($report && isset($report['student'])): ?>
                    <div class="report-card">
                        <div class="header">
                            <h2>School Management System</h2>
                            <h3>Report Card</h3>
                        </div>

                        <div class="student-info">
                            <div>
                                <strong>Name:</strong> <?php echo htmlspecialchars($report['student']['fname'] . ' ' . $report['student']['lname']); ?><br>
                                <strong>Class:</strong> <?php echo htmlspecialchars($report['student']['class_name']); ?><br>
                                <strong>Date of Birth:</strong> <?php echo htmlspecialchars($report['student']['dob']); ?><br>
                                <strong>Gender:</strong> <?php echo htmlspecialchars($report['student']['gender']); ?>
                            </div>
                            <div>
                                <strong>Report Date:</strong> <?php echo date('Y-m-d'); ?><br>
                                <strong>Academic Year:</strong> <?php echo date('Y'); ?>
                            </div>
                        </div>

                        <?php if (!empty($report['marks'])): ?>
                            <table class="marks-table">
                                <thead>
                                    <tr>
                                        <th>Subject</th>
                                        <th>Marks Obtained</th>
                                        <th>Grade</th>
                                        <th>Remarks</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($report['marks'] as $mark): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($mark['subject_name']); ?></td>
                                            <td><?php echo htmlspecialchars($mark['marks_obtained']); ?></td>
                                            <td>
                                                <?php 
                                                $subject_grade_class = '';
                                                if (isset($mark['grade']) && !empty($mark['grade'])) {
                                                    $first_char = substr($mark['grade'], 0, 1);
                                                    $subject_grade_class = 'grade-' . $first_char;
                                                }
                                                ?>
                                                <span class="grade-badge <?php echo $subject_grade_class; ?>">
                                                    <?php echo htmlspecialchars($mark['grade']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($mark['remarks']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle"></i> No marks data available for this student.
                            </div>
                        <?php endif; ?>

                        <div class="summary">
                            <div class="row">
                                <div class="col-md-4">
                                    <p><strong>Average Marks:</strong> <?php echo htmlspecialchars($report['average']); ?>%</p>
                                </div>
                                <div class="col-md-4">
                                    <p><strong>Overall Grade:</strong>
                                        <?php 
                                        $grade_class = '';
                                        if (isset($report['overall_grade']) && !empty($report['overall_grade'])) {
                                            $first_char = substr($report['overall_grade'], 0, 1);
                                            $grade_class = 'grade-' . $first_char;
                                        }
                                        ?>
                                        <span class="grade-badge <?php echo $grade_class; ?>">
                                            <?php echo htmlspecialchars($report['overall_grade']); ?>
                                        </span>
                                    </p>
                                </div>
                                <div class="col-md-4">
                                    <p><strong>Overall Remarks:</strong> <?php echo htmlspecialchars($report['overall_remarks']); ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php elseif (isset($_GET['class_id']) && $class_id > 0): ?>
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle"></i> No reports found. Please check if the selected class has students with marks data.
            </div>
        <?php elseif (isset($_GET['student_id']) && $student_id > 0): ?>
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle"></i> No report found for the selected student. Please check if the student has marks data.
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Student search functionality
        const studentSearch = document.getElementById('student_search');
        const studentSuggestions = document.getElementById('student_suggestions');
        const studentIdInput = document.getElementById('student_id');
        const classSelect = document.getElementById('class_id');
        const generateBtn = document.getElementById('generateBtn');
        let searchTimeout;

        // Auto-submit form when class selection changes
        classSelect.addEventListener('change', function() {
            if (this.value) {
                studentIdInput.value = '';
                studentSearch.value = '';
                this.form.submit();
            }
        });

        // Student search input handler
        studentSearch.addEventListener('input', function() {
            const query = this.value.trim();
            clearTimeout(searchTimeout);

            if (query.length < 2) {
                studentSuggestions.style.display = 'none';
                studentSuggestions.innerHTML = '';
                return;
            }

            searchTimeout = setTimeout(() => {
                fetch(`report_card.php?search_students=1&q=${encodeURIComponent(query)}`)
                    .then(response => response.json())
                    .then(data => {
                        displaySuggestions(data);
                    })
                    .catch(error => {
                        console.error('Search error:', error);
                        showToast('Error searching for students', 'danger');
                    });
            }, 300);
        });

        // Hide suggestions when clicking outside
        document.addEventListener('click', function(e) {
            if (e.target.id !== 'student_search' && !studentSuggestions.contains(e.target)) {
                studentSuggestions.style.display = 'none';
            }
        });

        function displaySuggestions(students) {
            studentSuggestions.innerHTML = '';

            if (students.length === 0) {
                const noResult = document.createElement('div');
                noResult.className = 'p-2 text-muted';
                noResult.textContent = 'No students found';
                studentSuggestions.appendChild(noResult);
            } else {
                students.forEach(student => {
                    const suggestion = document.createElement('div');
                    suggestion.className = 'p-2 border-bottom suggestion-item';
                    suggestion.style.cursor = 'pointer';
                    suggestion.innerHTML = `
                        <strong>${student.fname} ${student.lname}</strong><br>
                        <small class="text-muted">${student.class_name}</small>
                    `;
                    
                    suggestion.addEventListener('click', function() {
                        studentSearch.value = `${student.fname} ${student.lname} (${student.class_name})`;
                        studentIdInput.value = student.student_id;
                        studentSuggestions.style.display = 'none';
                        classSelect.value = '';
                        // Auto-submit the form
                        generateBtn.click();
                    });
                    
                    studentSuggestions.appendChild(suggestion);
                });
            }

            studentSuggestions.style.display = students.length > 0 ? 'block' : 'none';
        }

        // Clear student selection when search is cleared
        studentSearch.addEventListener('keydown', function(e) {
            if (e.key === 'Backspace' || e.key === 'Delete') {
                if (this.value.length <= 1) {
                    studentIdInput.value = '';
                }
            }
        });

        // Form submission validation
        document.getElementById('reportForm').addEventListener('submit', function(e) {
            if (!classSelect.value && !studentIdInput.value) {
                e.preventDefault();
                showToast('Please select either a class or a student', 'warning');
                return false;
            }
        });

        // Preview functionality
        function showPreview() {
            const modal = new bootstrap.Modal(document.getElementById('previewModal'));
            const spinner = document.getElementById('previewSpinner');
            const content = document.getElementById('previewContent');

            // Show loading spinner
            spinner.style.display = 'block';
            content.style.display = 'none';

            // Simulate loading time for better UX
            setTimeout(() => {
                spinner.style.display = 'none';
                content.style.display = 'block';
                // Add fade-in animation
                content.classList.add('fade-in-up');
            }, 500);

            modal.show();
        }

        // Export to PDF functionality
        function exportToPDF() {
            // Show loading
            const btn = event.target.closest('button');
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Exporting...';
            btn.disabled = true;

            // Create a print-friendly version
            const printContent = document.getElementById('previewContent').innerHTML;
            const printWindow = window.open('', '_blank');

            printWindow.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Report Card Export</title>
                    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
                    <style>
                        body { font-family: 'Poppins', sans-serif; margin: 20px; }
                        .report-preview { page-break-after: always; margin-bottom: 30px; }
                        .no-print { display: none !important; }
                        @media print {
                            body { margin: 0; }
                            .report-preview { page-break-after: always; }
                        }
                    </style>
                </head>
                <body>
                    ${printContent}
                    <script>
                        window.onload = function() {
                            window.print();
                            setTimeout(function() { window.close(); }, 1000);
                        }
                    <\/script>
                </body>
                </html>
            `);

            printWindow.document.close();

            // Reset button
            btn.innerHTML = originalText;
            btn.disabled = false;

            // Show success message
            showToast('PDF export initiated. Use browser print to save as PDF.', 'success');
        }

        // Print preview functionality
        function printPreview() {
            const printContent = document.getElementById('previewContent').innerHTML;
            const printWindow = window.open('', '_blank');

            printWindow.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Report Card Print Preview</title>
                    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
                    <style>
                        body { font-family: 'Poppins', sans-serif; margin: 20px; }
                        .report-preview { page-break-after: always; margin-bottom: 30px; }
                        .no-print { display: none !important; }
                        @media print {
                            body { margin: 0; }
                            .report-preview { page-break-after: always; }
                        }
                    </style>
                </head>
                <body>
                    ${printContent}
                    <script>
                        window.onload = function() {
                            window.print();
                        }
                    <\/script>
                </body>
                </html>
            `);

            printWindow.document.close();
        }

        // Toast notification function
        function showToast(message, type = 'info') {
            // Create toast container if it doesn't exist
            let toastContainer = document.querySelector('.toast-container');
            if (!toastContainer) {
                toastContainer = document.createElement('div');
                toastContainer.className = 'toast-container position-fixed top-0 end-0 p-3';
                toastContainer.style.zIndex = '9999';
                document.body.appendChild(toastContainer);
            }

            const toastId = 'toast-' + Date.now();
            const toast = document.createElement('div');
            toast.id = toastId;
            toast.className = `toast align-items-center text-white bg-${type} border-0`;
            toast.setAttribute('role', 'alert');
            toast.setAttribute('aria-live', 'assertive');
            toast.setAttribute('aria-atomic', 'true');

            toast.innerHTML = `
                <div class="d-flex">
                    <div class="toast-body">${message}</div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                </div>
            `;

            toastContainer.appendChild(toast);

            const bsToast = new bootstrap.Toast(toast, { delay: 3000 });
            bsToast.show();

            // Remove toast after it's hidden
            toast.addEventListener('hidden.bs.toast', () => {
                toast.remove();
            });
        }

        // Print options functionality
        document.addEventListener('DOMContentLoaded', function() {
            const includeLogo = document.getElementById('includeLogo');
            const includeSignature = document.getElementById('includeSignature');
            const includeWatermark = document.getElementById('includeWatermark');

            if (includeLogo) {
                includeLogo.addEventListener('change', function() {
                    const logos = document.querySelectorAll('.school-logo');
                    logos.forEach(logo => {
                        logo.style.display = this.checked ? 'flex' : 'none';
                    });
                });
            }

            if (includeSignature) {
                includeSignature.addEventListener('change', function() {
                    const signatures = document.querySelectorAll('.certificate-footer');
                    signatures.forEach(sig => {
                        sig.style.display = this.checked ? 'block' : 'none';
                    });
                });
            }

            if (includeWatermark) {
                includeWatermark.addEventListener('change', function() {
                    showToast('Watermark option will be applied during PDF export.', 'info');
                });
            }
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+P for print
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                if (document.getElementById('previewModal').classList.contains('show')) {
                    printPreview();
                } else {
                    window.print();
                }
            }

            // Ctrl+E for export
            if (e.ctrlKey && e.key === 'e') {
                e.preventDefault();
                exportToPDF();
            }

            // Ctrl+Shift+P for preview
            if (e.ctrlKey && e.shiftKey && e.key === 'P') {
                e.preventDefault();
                showPreview();
            }
        });
    </script>
    <script src="assets/js/site.js"></script>
</body>
</html>
<?php
// Close connection at the very end
if (isset($conn)) {
    $conn->close();
}
?>