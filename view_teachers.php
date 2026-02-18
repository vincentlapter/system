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

// Fetch teachers with subject names
$teachers = [];
$sql_teachers = "
    SELECT t.teacher_id, t.fname, t.lname, t.dob, t.contact, t.gender, t.subject, s.subject_name
    FROM teacher t
    LEFT JOIN subject s ON t.subject_id = s.subject_id
    ORDER BY t.fname, t.lname
";
$result_teachers = $conn->query($sql_teachers);
if ($result_teachers) {
    while ($row = $result_teachers->fetch_assoc()) {
        $teachers[] = $row;
    }
}

// Calculate statistics
$total_teachers = count($teachers);
$male_teachers = count(array_filter($teachers, fn($t) => $t['gender'] === 'Male'));
$female_teachers = count(array_filter($teachers, fn($t) => $t['gender'] === 'Female'));

// Prepare data for charts
$gender_data = [
    'Male' => $male_teachers,
    'Female' => $female_teachers
];

$subject_counts = [];
foreach ($teachers as $teacher) {
    $subject = $teacher['subject_name'] ?? 'No Subject';
    $subject_counts[$subject] = ($subject_counts[$subject] ?? 0) + 1;
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="stylesheet" href="assets/css/site.css">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Teachers | School Management System</title>
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
                    <h1><i class="bi bi-person-badge"></i> Teacher Management</h1>
                    <p class="mb-0">View and manage all teachers</p>
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
            <div class="col-6 col-md-4">
                <div class="stat-card bg-primary">
                    <i class="bi bi-person-badge"></i>
                    <h5>Total Teachers</h5>
                    <h3><?= $total_teachers ?></h3>
                </div>
            </div>
            <div class="col-6 col-md-4">
                <div class="stat-card bg-info">
                    <i class="bi bi-gender-male"></i>
                    <h5>Male Teachers</h5>
                    <h3><?= $male_teachers ?></h3>
                </div>
            </div>
            <div class="col-6 col-md-4">
                <div class="stat-card bg-danger">
                    <i class="bi bi-gender-female"></i>
                    <h5>Female Teachers</h5>
                    <h3><?= $female_teachers ?></h3>
                </div>
            </div>
        </div>

        <!-- Search and Filter -->
        <div class="search-container">
            <div class="row g-3">
                <div class="col-md-6">
                    <input type="text" class="form-control" id="searchInput" placeholder="Search teachers...">
                </div>
                <div class="col-md-3">
                    <select class="form-select" id="genderFilter">
                        <option value="">All Genders</option>
                        <option value="Male">Male</option>
                        <option value="Female">Female</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <button class="btn btn-primary w-100" onclick="resetFilters()">
                        <i class="bi bi-arrow-clockwise"></i> Reset
                    </button>
                </div>
            </div>
        </div>

        <!-- Teachers Table and Charts -->
        <div class="row">
            <div class="col-lg-8 mb-4">
                <div class="dashboard-card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-list-ul"></i> Teachers List</h5>
                        <?php if ($user_role === 'Administrator'): ?>
                            <a href="teacher_form.php" class="btn btn-primary btn-sm">
                                <i class="bi bi-plus-circle"></i> Add Teacher
                            </a>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($teachers)): ?>
                        <div class="table-responsive">
                            <table class="table table-hover" id="teachersTable">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Contact</th>
                                        <th>Gender</th>
                                        <th>Subject</th>
                                        <?php if ($user_role === 'Administrator'): ?>
                                        <th>Actions</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($teachers as $teacher): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($teacher['fname'] . ' ' . $teacher['lname']) ?></td>
                                        <td><?= htmlspecialchars($teacher['contact'] ?? 'N/A') ?></td>
                                        <td>
                                            <span class="badge <?= $teacher['gender'] === 'Male' ? 'bg-primary' : 'bg-danger' ?>">
                                                <i class="bi bi-<?= $teacher['gender'] === 'Male' ? 'gender-male' : 'gender-female' ?>"></i>
                                                <?= htmlspecialchars($teacher['gender']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-success">
                                                <i class="bi bi-book"></i>
                                                <?= htmlspecialchars($teacher['subject_name'] ?? $teacher['subject'] ?? 'N/A') ?>
                                            </span>
                                        </td>
                                        <?php if ($user_role === 'Administrator'): ?>
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
                                <i class="bi bi-person-badge" style="font-size: 3rem; color: #6c757d;"></i>
                                <p class="mt-2">No teachers found</p>
                                <?php if ($user_role === 'Administrator'): ?>
                                    <a href="teacher_form.php" class="btn btn-primary">
                                        <i class="bi bi-plus-circle"></i> Add New Teacher
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
                        <h5 class="mb-0"><i class="bi bi-pie-chart"></i> Gender Distribution</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="genderChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Pie chart for gender distribution
        const ctx = document.getElementById('genderChart').getContext('2d');
        const genderChart = new Chart(ctx, {
            type: 'pie',
            data: {
                labels: ['Male', 'Female'],
                datasets: [{
                    data: [<?= $male_teachers ?>, <?= $female_teachers ?>],
                    backgroundColor: [
                        'rgba(67, 97, 238, 0.8)',
                        'rgba(247, 37, 133, 0.8)'
                    ],
                    borderColor: [
                        'rgba(67, 97, 238, 1)',
                        'rgba(247, 37, 133, 1)'
                    ],
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 20,
                            usePointStyle: true
                        }
                    }
                }
            }
        });

        // Search and Filter functionality
        document.getElementById('searchInput').addEventListener('keyup', filterTable);
        document.getElementById('genderFilter').addEventListener('change', filterTable);

        function filterTable() {
            const searchValue = document.getElementById('searchInput').value.toLowerCase();
            const genderFilter = document.getElementById('genderFilter').value;
            const table = document.getElementById('teachersTable');
            const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');

            for (let i = 0; i < rows.length; i++) {
                const name = rows[i].getElementsByTagName('td')[0].textContent.toLowerCase();
                const gender = rows[i].getElementsByTagName('td')[2].textContent.trim();

                let showRow = true;

                // Search filter
                if (searchValue && !name.includes(searchValue)) {
                    showRow = false;
                }

                // Gender filter
                if (genderFilter && !gender.includes(genderFilter)) {
                    showRow = false;
                }

                rows[i].style.display = showRow ? '' : 'none';
            }
        }

        function resetFilters() {
            document.getElementById('searchInput').value = '';
            document.getElementById('genderFilter').value = '';
            filterTable();
        }
    </script>
</body>
</html>
