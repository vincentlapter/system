<?php 
include 'config.php';
session_start();
if (!isset($_SESSION['username'])) {
    header('Location: index.php');
    exit();
}

// Initialize variables
$success = $error = '';
$subjects = [];

// Fetch subjects for checkboxes
$sql = "SELECT s.subject_id, s.subject_name FROM subject s ORDER BY s.subject_name";
$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $subjects[] = $row;
    }
}


// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $exam_name = $conn->real_escape_string($_POST['exam_name']);
    $exam_date = $conn->real_escape_string($_POST['exam_date']);
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // First insert the exam (without subject_id)
        $stmt = $conn->prepare("INSERT INTO examination (exam_name, exam_date) VALUES (?, ?)");
        $stmt->bind_param("ss", $exam_name, $exam_date);
        $stmt->execute();
        $exam_id = $conn->insert_id;
        $stmt->close();
        
        // Then insert selected subjects into exam_subjects
        if (!empty($_POST['subjects'])) {
            $subjects_stmt = $conn->prepare("INSERT INTO exam_subjects (exam_id, subject_id) VALUES (?, ?)");
            
            foreach ($_POST['subjects'] as $subject_id) {
                $subject_id = (int)$subject_id; // Ensure it's an integer
                $subjects_stmt->bind_param("ii", $exam_id, $subject_id);
                $subjects_stmt->execute();
            }
            $subjects_stmt->close();
        }
        
        $conn->commit();
        $success = "Exam and subjects added successfully!";
    } catch (Exception $e) {
        $conn->rollback();
        $error = "Error adding exam: " . $e->getMessage();
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Exam</title>
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
                    <h1><i class="bi bi-clipboard-data"></i> Add New Exam</h1>
                    <p class="mb-0">Create a new examination</p>
                </div>
                <div class="mt-2 mt-md-0 d-flex align-items-center">
                    <a href="admin_dashboard.php" class="btn btn-outline-light btn-sm me-2" title="Back to Dashboard">
                        <i class="bi bi-arrow-left"></i> Dashboard
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
            <div class="col-lg-8">
                <div class="card dashboard-card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-plus-circle"></i> Exam Details</h5>
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

                        <form method="post">
                            <div class="mb-3">
                                <label for="exam_name" class="form-label">Exam Name:</label>
                                <input type="text" class="form-control" id="exam_name" name="exam_name" required>
                            </div>

                            <div class="mb-3">
                                <label for="exam_date" class="form-label">Exam Date:</label>
                                <input type="date" class="form-control" id="exam_date" name="exam_date" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Subjects:</label>
                                <?php if (!empty($subjects)): ?>
                                    <div class="row">
                                        <?php foreach ($subjects as $subject): ?>
                                            <div class="col-md-6 col-lg-4">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox"
                                                           id="subject_<?php echo $subject['subject_id']; ?>"
                                                           name="subjects[]"
                                                           value="<?php echo $subject['subject_id']; ?>">
                                                    <label class="form-check-label" for="subject_<?php echo $subject['subject_id']; ?>">
                                                        <?php echo htmlspecialchars($subject['subject_name']); ?>
                                                    </label>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-warning">
                                        <i class="bi bi-exclamation-triangle"></i> No subjects available. Please add subjects first.
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="d-flex justify-content-between align-items-center">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-plus-circle"></i> Add Exam
                                </button>
                                <a href="view_exams.php" class="btn btn-secondary">
                                    <i class="bi bi-eye"></i> View All Exams
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
