<?php
include 'config.php';
session_start();
if (!isset($_SESSION['username'])) {
    header('Location: index.php');
    exit();
}

// Initialize variables
$exams = [];
$success = $error = '';

// Fetch all exams with their subjects
$sql = "
    SELECT e.exam_id, e.exam_name, e.exam_date,
           GROUP_CONCAT(s.subject_name SEPARATOR ', ') AS subjects
    FROM examination e
    LEFT JOIN exam_subjects es ON e.exam_id = es.exam_id
    LEFT JOIN subject s ON es.subject_id = s.subject_id
    GROUP BY e.exam_id, e.exam_name, e.exam_date
    ORDER BY e.exam_date DESC
";
$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $exams[] = $row;
    }
}

// Check for session messages
if (isset($_SESSION['message'])) {
    $success = $_SESSION['message'];
    unset($_SESSION['message']);
}

if (isset($_SESSION['error'])) {
    $error = $_SESSION['error'];
    unset($_SESSION['error']);
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View All Exams</title>
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

        /* Table Styles */
        .table-responsive {
            max-height: 600px;
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

        /* Responsive Adjustments */
        @media (max-width: 768px) {
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
                    <h1><i class="bi bi-clipboard-data"></i> View All Exams</h1>
                    <p class="mb-0">Manage and view all created examinations</p>
                </div>
                <div class="mt-2 mt-md-0 d-flex align-items-center">
                    <a href="admin_dashboard.php" class="btn btn-outline-light btn-sm me-2" title="Back to Dashboard">
                        <i class="bi bi-arrow-left"></i> Dashboard
                    </a>
                    <a href="exams.php" class="btn btn-outline-light btn-sm me-2" title="Add New Exam">
                        <i class="bi bi-plus-circle"></i> Add Exam
                    </a>
                    <a href="logout.php" class="btn btn-outline-light btn-sm" title="Logout">
                        <i class="bi bi-box-arrow-right"></i> Logout
                    </a>
                </div>
            </div>
        </div>
    </header>

    <div class="container">
        <div class="row">
            <div class="col-12">
                <div class="card dashboard-card">
                    <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-clipboard-data"></i> All Examinations</h5>
                        <span class="badge bg-light text-dark"><?php echo count($exams); ?> Exams</span>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($success)): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <i class="bi bi-check-circle"></i> <?php echo $success; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($error)): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="bi bi-exclamation-triangle"></i> <?php echo $error; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($exams)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover table-striped">
                                    <thead>
                                        <tr>
                                            <th><i class="bi bi-hash"></i> ID</th>
                                            <th><i class="bi bi-clipboard"></i> Exam Name</th>
                                            <th><i class="bi bi-calendar"></i> Date</th>
                                            <th><i class="bi bi-book"></i> Subjects</th>
                                            <th><i class="bi bi-gear"></i> Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($exams as $exam): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($exam['exam_id']); ?></td>
                                                <td><?php echo htmlspecialchars($exam['exam_name']); ?></td>
                                                <td><?php echo date('M d, Y', strtotime($exam['exam_date'])); ?></td>
                                                <td><?php echo htmlspecialchars($exam['subjects'] ?? 'No subjects assigned'); ?></td>
                                                <td>
                                                    <a href="edit_exam.php?id=<?php echo $exam['exam_id']; ?>" class="btn btn-sm btn-warning me-1" title="Edit Exam">
                                                        <i class="bi bi-pencil"></i> Edit
                                                    </a>
                                                    <a href="delete_exam.php?id=<?php echo $exam['exam_id']; ?>" class="btn btn-sm btn-danger" title="Delete Exam" onclick="return confirm('Are you sure you want to delete this exam? This action cannot be undone.')">
                                                        <i class="bi bi-trash"></i> Delete
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="bi bi-clipboard-x" style="font-size: 3rem; color: #6c757d;"></i>
                                <h4 class="mt-3 text-muted">No Exams Found</h4>
                                <p class="text-muted">There are no examinations created yet.</p>
                                <a href="exams.php" class="btn btn-primary mt-3">
                                    <i class="bi bi-plus-circle"></i> Create Your First Exam
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
