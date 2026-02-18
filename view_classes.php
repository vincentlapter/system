<?php
session_start();
include 'config.php';

// Check authentication
if (!isset($_SESSION['username'])) {
    header('Location: index.php');
    exit();
}

// Get user role from session
$user_role = $_SESSION['role'] ?? 'Guest';

// Fetch classes with teacher names and student counts
$classes = [];
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
        $classes[] = $row;
    }
}

// Calculate statistics
$total_classes = count($classes);
$total_students = array_sum(array_column($classes, 'student_count'));
$assigned_classes = count(array_filter($classes, fn($c) => !empty($c['teacher_name'])));
$unassigned_classes = $total_classes - $assigned_classes;

// Prepare data for chart
$class_names = array_column($classes, 'class_name');
$student_counts = array_column($classes, 'student_count');

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Classes | School Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

        /* Search and Filter */
        .search-container {
            background-color: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            margin-bottom: 25px;
        }

        /* Chart Container */
        .chart-container {
            position: relative;
            height: 400px;
            width: 100%;
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

        /* Status Colors */
        .bg-primary { background-color: var(--primary-color) !important; }
        .bg-secondary { background-color: var(--secondary-color) !important; }
        .bg-success { background-color: var(--success-color) !important; }
        .bg-info { background-color: var(--info-color) !important; }
        .bg-warning { background-color: var(--warning-color) !important; }
        .bg-danger { background-color: var(--danger-color) !important; }
    </style>
</head>
<body>
    <header class="dashboard-header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center flex-wrap">
                <div>
                    <h1><i class="bi bi-building"></i> Class Management</h1>
                    <p class="mb-0">View and manage all classes</p>
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
        <!-- Quick Stats Row -->
        <div class="row mb-4 g-3">
            <div class="col-6 col-md-3">
                <div class="stat-card bg-primary">
                    <i class="bi bi-building"></i>
                    <h5>Total Classes</h5>
                    <h3><?= $total_classes ?></h3>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card bg-success">
                    <i class="bi bi-people-fill"></i>
                    <h5>Total Students</h5>
                    <h3><?= $total_students ?></h3>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card bg-info">
                    <i class="bi bi-person-check"></i>
                    <h5>Assigned Teachers</h5>
                    <h3><?= $assigned_classes ?></h3>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card bg-warning">
                    <i class="bi bi-person-x"></i>
                    <h5>Unassigned</h5>
                    <h3><?= $unassigned_classes ?></h3>
                </div>
            </div>
        </div>

        <!-- Search and Filter -->
        <div class="search-container">
            <div class="row g-3">
                <div class="col-md-6">
                    <input type="text" class="form-control" id="searchInput" placeholder="Search classes...">
                </div>
                <div class="col-md-4">
                    <select class="form-select" id="teacherFilter">
                        <option value="">All Teachers</option>
                        <option value="assigned">Assigned Only</option>
                        <option value="unassigned">Unassigned Only</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button class="btn btn-primary w-100" onclick="resetFilters()">
                        <i class="bi bi-arrow-clockwise"></i> Reset
                    </button>
                </div>
            </div>
        </div>

        <!-- Classes Table and Chart -->
        <div class="row">
            <div class="col-lg-8 mb-4">
                <div class="dashboard-card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-list-ul"></i> Classes List</h5>
                        <?php if ($user_role === 'Administrator'): ?>
                            <a href="add_class.php" class="btn btn-primary btn-sm">
                                <i class="bi bi-plus-circle"></i> Add Class
                            </a>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($classes)): ?>
                        <div class="table-responsive">
                            <table class="table table-hover" id="classesTable">
                                <thead>
                                    <tr>
                                        <th>Class Name</th>
                                        <th>Class Teacher</th>
                                        <th>Students</th>
                                        <?php if ($user_role === 'Administrator'): ?>
                                        <th>Actions</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($classes as $class): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($class['class_name']) ?></td>
                                        <td>
                                            <?php if (!empty($class['teacher_name'])): ?>
                                                <span class="badge bg-success">
                                                    <i class="bi bi-person-check"></i> <?= htmlspecialchars($class['teacher_name']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-warning">
                                                    <i class="bi bi-person-x"></i> Unassigned
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-info">
                                                <i class="bi bi-people"></i> <?= $class['student_count'] ?>
                                            </span>
                                        </td>
                                        <?php if ($user_role === 'Administrator'): ?>
                                        <td>
                                            <a href="edit_class.php?id=<?= $class['class_id'] ?>" class="btn btn-sm btn-warning action-btn" title="Edit">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <a href="delete_class.php?id=<?= $class['class_id'] ?>" class="btn btn-sm btn-danger action-btn" title="Delete" onclick="return confirm('Are you sure?')">
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
                                <i class="bi bi-building" style="font-size: 3rem; color: #6c757d;"></i>
                                <p class="mt-2">No classes found</p>
                                <?php if ($user_role === 'Administrator'): ?>
                                    <a href="add_class.php" class="btn btn-primary">
                                        <i class="bi bi-plus-circle"></i> Add New Class
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-4 mb-4">
                <div class="dashboard-card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-bar-chart"></i> Student Distribution</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="studentChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Chart.js for student distribution
        const ctx = document.getElementById('studentChart').getContext('2d');
        const studentChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?= json_encode($class_names) ?>,
                datasets: [{
                    label: 'Number of Students',
                    data: <?= json_encode($student_counts) ?>,
                    backgroundColor: 'rgba(67, 97, 238, 0.6)',
                    borderColor: 'rgba(67, 97, 238, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
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

        // Search and Filter functionality
        document.getElementById('searchInput').addEventListener('keyup', filterTable);
        document.getElementById('teacherFilter').addEventListener('change', filterTable);

        function filterTable() {
            const searchValue = document.getElementById('searchInput').value.toLowerCase();
            const teacherFilter = document.getElementById('teacherFilter').value;
            const table = document.getElementById('classesTable');
            const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');

            for (let i = 0; i < rows.length; i++) {
                const className = rows[i].getElementsByTagName('td')[0].textContent.toLowerCase();
                const teacherCell = rows[i].getElementsByTagName('td')[1];
                const hasTeacher = teacherCell.querySelector('.badge').classList.contains('bg-success');

                let showRow = true;

                // Search filter
                if (searchValue && !className.includes(searchValue)) {
                    showRow = false;
                }

                // Teacher filter
                if (teacherFilter === 'assigned' && !hasTeacher) {
                    showRow = false;
                } else if (teacherFilter === 'unassigned' && hasTeacher) {
                    showRow = false;
                }

                rows[i].style.display = showRow ? '' : 'none';
            }
        }

        function resetFilters() {
            document.getElementById('searchInput').value = '';
            document.getElementById('teacherFilter').value = '';
            filterTable();
        }
    </script>
</body>
</html>
