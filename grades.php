<?php
include 'config.php';

// Start output buffering to prevent header issues
ob_start();

// Error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Session configuration
ini_set('session.use_cookies', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);

// Try to find a writable session directory
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
if (!isset($_SESSION['username'])) {
    header('Location: index.php');
    exit();
}
// Ensure the user is logged in as an Administrator
if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'Administrator') {
    header('Location: index.php');
    exit();
}



// Handle form submissions for adding/editing grading schemes
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_scheme'])) {
        $grade_name = trim($_POST['grade_name']);
        $min_mark = (int)$_POST['min_mark'];
        $max_mark = (int)$_POST['max_mark'];

        if (empty($grade_name) || $min_mark < 0 || $max_mark < 0 || $min_mark > $max_mark) {
            $message = 'Invalid input. Please check the grade name and mark ranges.';
            $message_type = 'danger';
        } else {
            $stmt = $conn->prepare("INSERT INTO grading_scheme (grade_name, min_mark, max_mark) VALUES (?, ?, ?)");
            $stmt->bind_param("sii", $grade_name, $min_mark, $max_mark);
            if ($stmt->execute()) {
                $message = 'Grading scheme added successfully.';
                $message_type = 'success';
            } else {
                $message = 'Error adding grading scheme.';
                $message_type = 'danger';
            }
            $stmt->close();
        }
    } elseif (isset($_POST['edit_scheme'])) {
        $scheme_id = (int)$_POST['scheme_id'];
        $grade_name = trim($_POST['grade_name']);
        $min_mark = (int)$_POST['min_mark'];
        $max_mark = (int)$_POST['max_mark'];

        if (empty($grade_name) || $min_mark < 0 || $max_mark < 0 || $min_mark > $max_mark) {
            $message = 'Invalid input. Please check the grade name and mark ranges.';
            $message_type = 'danger';
        } else {
            $stmt = $conn->prepare("UPDATE grading_scheme SET grade_name = ?, min_mark = ?, max_mark = ? WHERE id = ?");
            $stmt->bind_param("siii", $grade_name, $min_mark, $max_mark, $scheme_id);
            if ($stmt->execute()) {
                $message = 'Grading scheme updated successfully.';
                $message_type = 'success';
            } else {
                $message = 'Error updating grading scheme.';
                $message_type = 'danger';
            }
            $stmt->close();
        }
    }
}

// Handle delete request
if (isset($_GET['delete'])) {
    $scheme_id = (int)$_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM grading_scheme WHERE id = ?");
    $stmt->bind_param("i", $scheme_id);
    if ($stmt->execute()) {
        $message = 'Grading scheme deleted successfully.';
        $message_type = 'success';
    } else {
        $message = 'Error deleting grading scheme.';
        $message_type = 'danger';
    }
    $stmt->close();
}

// Fetch all grading schemes
$schemes = [];
$result = $conn->query("SELECT * FROM grading_scheme ORDER BY min_mark DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $schemes[] = $row;
    }
    $result->close();
}

// Fetch scheme for editing if edit_id is set
$edit_scheme = null;
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM grading_scheme WHERE id = ?");
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $edit_scheme = $result->fetch_assoc();
    }
    $stmt->close();
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="stylesheet" href="assets/css/site.css">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Grading Scheme Management - School Management System</title>
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

        .page-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 20px 0;
            margin-bottom: 30px;
            border-radius: 0 0 15px 15px;
        }

        .page-title {
            font-weight: 600;
            margin-bottom: 0;
        }

        .card {
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            border: none;
            margin-bottom: 25px;
        }

        .card-header {
            background-color: var(--primary-color);
            color: white;
            border-radius: 12px 12px 0 0 !important;
            font-weight: 600;
            padding: 15px 20px;
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

        .btn {
            border-radius: 6px;
            font-weight: 500;
            padding: 8px 15px;
        }

        .action-btn {
            margin-right: 5px;
            padding: 0.25rem 0.5rem;
            font-size: 0.8rem;
            border-radius: 4px;
        }

        .form-control {
            border-radius: 6px;
        }

        .alert {
            border-radius: 8px;
        }

        /* Grade preview styles */
        .grade-preview {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: 500;
            font-size: 0.85rem;
            margin-left: 10px;
        }

        .grade-A { background-color: #2ecc71; color: white; }
        .grade-B { background-color: #3498db; color: white; }
        .grade-C { background-color: #f39c12; color: white; }
        .grade-D { background-color: #e74c3c; color: white; }
        .grade-F { background-color: #7f8c8d; color: white; }
    </style>
</head>
<body>
    <header class="page-header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="page-title"><i class="bi bi-bar-chart-line-fill"></i> Grading Scheme Management</h1>
                    <p class="mb-0">Manage grade ranges and letter grades for student assessments</p>
                </div>
                <a href="admin_dashboard.php" class="btn btn-outline-light">
                    <i class="bi bi-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>
    </header>

    <div class="container">
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                <i class="bi bi-info-circle-fill"></i> <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-lg-4 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-plus-circle"></i> <?php echo $edit_scheme ? 'Edit' : 'Add'; ?> Grading Scheme</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <?php if ($edit_scheme): ?>
                                <input type="hidden" name="scheme_id" value="<?php echo $edit_scheme['id']; ?>">
                            <?php endif; ?>

                            <div class="mb-3">
                                <label for="grade_name" class="form-label">Grade Letter</label>
                                <input type="text" class="form-control" id="grade_name" name="grade_name"
                                       value="<?php echo htmlspecialchars($edit_scheme['grade_name'] ?? ''); ?>"
                                       placeholder="e.g., A, B+, B" required maxlength="5">
                            </div>

                            <div class="mb-3">
                                <label for="min_mark" class="form-label">Minimum Mark</label>
                                <input type="number" class="form-control" id="min_mark" name="min_mark"
                                       value="<?php echo $edit_scheme['min_mark'] ?? ''; ?>"
                                       min="0" max="100" required>
                            </div>

                            <div class="mb-3">
                                <label for="max_mark" class="form-label">Maximum Mark</label>
                                <input type="number" class="form-control" id="max_mark" name="max_mark"
                                       value="<?php echo $edit_scheme['max_mark'] ?? ''; ?>"
                                       min="0" max="100" required>
                            </div>

                            <div class="d-grid">
                                <button type="submit" name="<?php echo $edit_scheme ? 'edit_scheme' : 'add_scheme'; ?>"
                                        class="btn btn-primary">
                                    <i class="bi bi-check-circle"></i> <?php echo $edit_scheme ? 'Update' : 'Add'; ?> Scheme
                                </button>
                                <?php if ($edit_scheme): ?>
                                    <a href="grades.php" class="btn btn-secondary mt-2">Cancel Edit</a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-list-ul"></i> Current Grading Schemes</h5>
                        <span class="badge bg-light text-dark"><?php echo count($schemes); ?> schemes</span>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($schemes)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Grade</th>
                                            <th>Mark Range</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($schemes as $scheme): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($scheme['grade_name']); ?></strong>
                                                    <span class="grade-preview grade-<?php echo substr($scheme['grade_name'], 0, 1); ?>">
                                                        <?php echo htmlspecialchars($scheme['grade_name']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo $scheme['min_mark']; ?> - <?php echo $scheme['max_mark']; ?> marks</td>
                                                <td>
                                                    <a href="grades.php?edit=<?php echo $scheme['id']; ?>"
                                                       class="btn btn-sm btn-warning action-btn" title="Edit">
                                                        <i class="bi bi-pencil"></i>
                                                    </a>
                                                    <a href="grades.php?delete=<?php echo $scheme['id']; ?>"
                                                       class="btn btn-sm btn-danger action-btn"
                                                       title="Delete"
                                                       onclick="return confirm('Are you sure you want to delete this grading scheme?')">
                                                        <i class="bi bi-trash"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="bi bi-bar-chart-line" style="font-size: 3rem; color: #6c757d;"></i>
                                <h5 class="mt-3">No Grading Schemes Found</h5>
                                <p class="text-muted">Add your first grading scheme using the form on the left.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Form validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const minMark = parseInt(document.getElementById('min_mark').value);
            const maxMark = parseInt(document.getElementById('max_mark').value);

            if (minMark > maxMark) {
                e.preventDefault();
                alert('Minimum mark cannot be greater than maximum mark.');
            }
        });
    </script>
</body>
</html>
