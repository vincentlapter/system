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

// Get user role from session
$user_role = $_SESSION['role'] ?? 'Guest';

// Check if user is Administrator
$is_admin = ($user_role === 'Administrator');

// Initialize variables
$success = $error = '';
$terms = [];
$edit_mode = false;
$current_term = null;
$term_id = isset($_GET['edit']) ? intval($_GET['edit']) : 0;

// Handle delete action
if (isset($_GET['delete']) && $is_admin) {
    $delete_id = intval($_GET['delete']);
    
    // Check if term has associated records
    $check_queries = [
        "SELECT COUNT(*) as count FROM fees_structure WHERE term_id = ?",
        "SELECT COUNT(*) as count FROM finance_transactions WHERE term_id = ?",
        "SELECT COUNT(*) as count FROM bursaries WHERE term_id = ?",
        "SELECT COUNT(*) as count FROM salaries WHERE term_id = ?"
    ];
    
    $has_associations = false;
    foreach ($check_queries as $query) {
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $delete_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();
        $stmt->close();
        
        if ($data['count'] > 0) {
            $has_associations = true;
            break;
        }
    }
    
    if ($has_associations) {
        $_SESSION['error'] = "Cannot delete term. It has associated records in the system.";
    } else {
        $delete_stmt = $conn->prepare("DELETE FROM terms WHERE term_id = ?");
        $delete_stmt->bind_param("i", $delete_id);
        
        if ($delete_stmt->execute()) {
            $_SESSION['success'] = "Term deleted successfully!";
        } else {
            $_SESSION['error'] = "Error deleting term: " . $delete_stmt->error;
        }
        $delete_stmt->close();
    }
    header('Location: manage_terms.php');
    exit();
}

// Handle form submission for add/edit
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $is_admin) {
    $term_name = $conn->real_escape_string(trim($_POST['term_name']));
    $start_date = $conn->real_escape_string($_POST['start_date']);
    $end_date = $conn->real_escape_string($_POST['end_date']);
    $academic_year = $conn->real_escape_string(trim($_POST['academic_year']));
    $status = $conn->real_escape_string($_POST['status']);
    
    // Validate required fields
    if (empty($term_name) || empty($start_date) || empty($end_date) || empty($academic_year)) {
        $error = "All fields are required.";
    } 
    // Validate dates
    elseif (strtotime($start_date) >= strtotime($end_date)) {
        $error = "Start date must be before end date.";
    } 
    // Validate academic year format
    elseif (!preg_match('/^\d{4}-\d{4}$/', $academic_year)) {
        $error = "Academic year must be in format YYYY-YYYY (e.g., 2024-2025).";
    } else {
        // Check if editing or adding
        if (isset($_POST['term_id']) && !empty($_POST['term_id'])) {
            // Edit existing term
            $term_id = intval($_POST['term_id']);
            
            // If setting this term as Active, set all others to Inactive
            if ($status === 'Active') {
                $update_stmt = $conn->prepare("UPDATE terms SET status = 'Inactive' WHERE term_id != ?");
                $update_stmt->bind_param("i", $term_id);
                $update_stmt->execute();
                $update_stmt->close();
            }
            
            $stmt = $conn->prepare("UPDATE terms SET term_name = ?, start_date = ?, end_date = ?, academic_year = ?, status = ? WHERE term_id = ?");
            $stmt->bind_param("sssssi", $term_name, $start_date, $end_date, $academic_year, $status, $term_id);
            
            if ($stmt->execute()) {
                $_SESSION['success'] = "Term updated successfully!";
            } else {
                $_SESSION['error'] = "Error updating term: " . $stmt->error;
            }
            $stmt->close();
        } else {
            // Add new term
            // If setting this term as Active, set all others to Inactive
            if ($status === 'Active') {
                $update_stmt = $conn->prepare("UPDATE terms SET status = 'Inactive' WHERE status = 'Active'");
                $update_stmt->execute();
                $update_stmt->close();
            }
            
            $stmt = $conn->prepare("INSERT INTO terms (term_name, start_date, end_date, academic_year, status) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sssss", $term_name, $start_date, $end_date, $academic_year, $status);
            
            if ($stmt->execute()) {
                $_SESSION['success'] = "Term added successfully!";
            } else {
                $_SESSION['error'] = "Error adding term: " . $stmt->error;
            }
            $stmt->close();
        }
        
        header('Location: manage_terms.php');
        exit();
    }
}

// Check if we're in edit mode
if ($term_id > 0 && $is_admin) {
    $stmt = $conn->prepare("SELECT * FROM terms WHERE term_id = ?");
    $stmt->bind_param("i", $term_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $current_term = $result->fetch_assoc();
        $edit_mode = true;
    }
    $stmt->close();
}

// Fetch all terms
$sql = "SELECT * FROM terms ORDER BY start_date DESC";
$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $terms[] = $row;
    }
}

// Check if no terms exist
$no_terms = empty($terms);

// Display session messages
if (isset($_SESSION['success'])) {
    $success = $_SESSION['success'];
    unset($_SESSION['success']);
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
    <link rel="stylesheet" href="assets/css/site.css">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Academic Terms Management</title>
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
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-bottom: 1px solid rgba(0,0,0,0.1);
        }

        .btn {
            border-radius: 8px;
            font-weight: 500;
            padding: 8px 20px;
            transition: all 0.3s ease;
            border: none;
        }

        .btn-primary { 
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
        }
        
        .btn-warning { 
            background: linear-gradient(135deg, #ffc107, #ff9800);
            color: white;
        }
        
        .btn-danger { 
            background: linear-gradient(135deg, var(--danger-color), #e91e63);
            color: white;
        }
        
        .btn-info { 
            background: linear-gradient(135deg, var(--info-color), #2196f3);
            color: white;
        }
        
        .btn-success { 
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
        }

        .btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 7px 14px rgba(0,0,0,0.15);
        }

        .btn-sm {
            padding: 5px 15px;
            font-size: 0.875rem;
        }

        .action-btn {
            width: 35px;
            height: 35px;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            margin: 2px;
        }

        .table th {
            background-color: #f8f9fa;
            font-weight: 600;
            color: var(--primary-color);
            border-top: none;
            padding: 15px 12px;
        }

        .table td {
            padding: 12px;
            vertical-align: middle;
        }

        .badge {
            font-weight: 500;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
        }

        .status-active { 
            background: linear-gradient(135deg, var(--success-color), #2ecc71);
            color: white;
        }
        
        .status-inactive { 
            background: linear-gradient(135deg, var(--secondary-color), #6c757d);
            color: white;
        }

        .modal-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border-radius: 12px 12px 0 0;
        }

        .form-control, .form-select {
            border-radius: 8px;
            border: 1px solid #dee2e6;
            padding: 10px 15px;
            transition: all 0.3s ease;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(67, 97, 238, 0.25);
            transform: translateY(-1px);
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }

        .empty-state-icon {
            font-size: 4rem;
            color: #dee2e6;
            margin-bottom: 20px;
        }

        .empty-state-title {
            font-size: 1.5rem;
            color: #6c757d;
            margin-bottom: 10px;
        }

        .empty-state-message {
            color: #adb5bd;
            margin-bottom: 30px;
        }

        .highlight-card {
            border: 2px solid var(--primary-color);
            box-shadow: 0 8px 25px rgba(67, 97, 238, 0.15);
        }

        .floating-action-btn {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            box-shadow: 0 6px 20px rgba(67, 97, 238, 0.3);
            z-index: 1000;
            transition: all 0.3s ease;
        }

        .floating-action-btn:hover {
            transform: scale(1.1);
            box-shadow: 0 8px 25px rgba(67, 97, 238, 0.4);
        }

        @media (max-width: 768px) {
            .floating-action-btn {
                bottom: 20px;
                right: 20px;
                width: 50px;
                height: 50px;
                font-size: 1.25rem;
            }
        }
    </style>
</head>
<body>
    <header class="dashboard-header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center flex-wrap">
                <div>
                    <h1><i class="bi bi-calendar-range"></i> Academic Terms Management</h1>
                    <p class="mb-0">Manage academic terms and periods</p>
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
        <!-- Display Messages -->
        <?php if (!empty($success)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <div class="d-flex align-items-center">
                    <i class="bi bi-check-circle-fill me-2" style="font-size: 1.2rem;"></i>
                    <div><?php echo $success; ?></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <div class="d-flex align-items-center">
                    <i class="bi bi-exclamation-triangle-fill me-2" style="font-size: 1.2rem;"></i>
                    <div><?php echo $error; ?></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <!-- If no terms exist, show empty state -->
            <?php if ($no_terms): ?>
                <div class="col-12">
                    <div class="card dashboard-card highlight-card">
                        <div class="card-body">
                            <div class="empty-state">
                                <div class="empty-state-icon">
                                    <i class="bi bi-calendar-x"></i>
                                </div>
                                <h3 class="empty-state-title">No Academic Terms Found</h3>
                                <p class="empty-state-message">
                                    You haven't created any academic terms yet. Terms are essential for organizing your school's academic calendar, fees, and schedules.
                                </p>
                                
                                <?php if ($is_admin): ?>
                                    <div class="row justify-content-center">
                                        <div class="col-md-6 col-lg-4">
                                            <div class="card mb-4">
                                                <div class="card-body text-center">
                                                    <h5 class="card-title text-primary">Create Your First Term</h5>
                                                    <p class="card-text small text-muted">Start by adding an academic term to organize your school year</p>
                                                    <button type="button" class="btn btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#addTermModal">
                                                        <i class="bi bi-plus-circle me-2"></i>Create First Term
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <p class="text-muted">Please contact an administrator to create academic terms.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <!-- Normal layout with terms -->
                <!-- Add/Edit Term Form (Only for admins) -->
                <?php if ($is_admin): ?>
                <div class="col-lg-4 mb-4">
                    <div class="card dashboard-card <?php echo $edit_mode ? 'highlight-card' : ''; ?>">
                        <div class="card-header <?php echo $edit_mode ? 'bg-warning' : 'bg-primary text-white'; ?>">
                            <h5 class="mb-0">
                                <i class="bi <?php echo $edit_mode ? 'bi-pencil' : 'bi-plus-circle'; ?> me-2"></i> 
                                <?php echo $edit_mode ? 'Edit Term' : 'Add New Term'; ?>
                            </h5>
                        </div>
                        <div class="card-body">
                            <form method="post" id="termForm">
                                <?php if ($edit_mode): ?>
                                    <input type="hidden" name="term_id" value="<?php echo $current_term['term_id']; ?>">
                                <?php endif; ?>
                                
                                <div class="mb-3">
                                    <label for="term_name" class="form-label">Term Name *</label>
                                    <input type="text" class="form-control" id="term_name" name="term_name" required
                                           value="<?php echo $edit_mode ? htmlspecialchars($current_term['term_name']) : ''; ?>"
                                           placeholder="e.g., Fall Semester 2024">
                                    <small class="text-muted">A descriptive name for the term</small>
                                </div>

                                <div class="mb-3">
                                    <label for="academic_year" class="form-label">Academic Year *</label>
                                    <input type="text" class="form-control" id="academic_year" name="academic_year" required
                                           value="<?php echo $edit_mode ? htmlspecialchars($current_term['academic_year']) : ''; ?>"
                                           placeholder="e.g., 2024-2025" pattern="\d{4}-\d{4}">
                                    <small class="text-muted">Format: YYYY-YYYY</small>
                                </div>

                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label for="start_date" class="form-label">Start Date *</label>
                                        <input type="date" class="form-control" id="start_date" name="start_date" required
                                               value="<?php echo $edit_mode ? $current_term['start_date'] : ''; ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label for="end_date" class="form-label">End Date *</label>
                                        <input type="date" class="form-control" id="end_date" name="end_date" required
                                               value="<?php echo $edit_mode ? $current_term['end_date'] : ''; ?>">
                                    </div>
                                </div>

                                <div class="mb-4">
                                    <label for="status" class="form-label">Status *</label>
                                    <select class="form-select" id="status" name="status" required>
                                        <option value="Active" <?php echo ($edit_mode && $current_term['status'] === 'Active') ? 'selected' : ''; ?>>Active</option>
                                        <option value="Inactive" <?php echo ($edit_mode && $current_term['status'] === 'Inactive') ? 'selected' : ''; ?>>Inactive</option>
                                    </select>
                                    <small class="text-muted">Only one term can be active at a time</small>
                                </div>

                                <div class="d-grid gap-2">
                                    <button type="submit" class="btn <?php echo $edit_mode ? 'btn-warning' : 'btn-primary'; ?>">
                                        <i class="bi <?php echo $edit_mode ? 'bi-check-circle' : 'bi-plus-circle'; ?> me-2"></i> 
                                        <?php echo $edit_mode ? 'Update Term' : 'Add New Term'; ?>
                                    </button>
                                    
                                    <?php if ($edit_mode): ?>
                                        <a href="manage_terms.php" class="btn btn-outline-secondary">
                                            <i class="bi bi-x-circle me-2"></i> Cancel Edit
                                        </a>
                                    <?php else: ?>
                                        <button type="reset" class="btn btn-outline-secondary">
                                            <i class="bi bi-arrow-clockwise me-2"></i> Reset Form
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Terms List -->
                <div class="col-lg-<?php echo $is_admin ? '8' : '12'; ?>">
                    <div class="card dashboard-card">
                        <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="mb-0"><i class="bi bi-calendar-range me-2"></i> Academic Terms</h5>
                                <small class="opacity-75">Total: <?php echo count($terms); ?> term(s)</small>
                            </div>
                            <?php if ($is_admin && !$edit_mode): ?>
                                <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#addTermModal">
                                    <i class="bi bi-plus-circle me-1"></i> Quick Add
                                </button>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Term Name</th>
                                            <th>Academic Year</th>
                                            <th>Duration</th>
                                            <th>Status</th>
                                            <?php if ($is_admin): ?>
                                            <th class="text-center">Actions</th>
                                            <?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($terms as $term): 
                                            $start_date = new DateTime($term['start_date']);
                                            $end_date = new DateTime($term['end_date']);
                                            $duration = $start_date->diff($end_date)->days;
                                            
                                            // Format dates nicely
                                            $start_formatted = $start_date->format('M d, Y');
                                            $end_formatted = $end_date->format('M d, Y');
                                        ?>
                                        <tr class="<?php echo ($term['status'] === 'Active') ? 'table-success' : ''; ?>">
                                            <td>
                                                <div class="fw-bold"><?php echo htmlspecialchars($term['term_name']); ?></div>
                                                <small class="text-muted"><?php echo $start_formatted; ?> - <?php echo $end_formatted; ?></small>
                                            </td>
                                            <td class="fw-medium"><?php echo htmlspecialchars($term['academic_year']); ?></td>
                                            <td>
                                                <span class="badge bg-light text-dark">
                                                    <?php echo $duration; ?> days
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge status-<?php echo strtolower($term['status']); ?>">
                                                    <i class="bi <?php echo ($term['status'] === 'Active') ? 'bi-check-circle' : 'bi-pause-circle'; ?> me-1"></i>
                                                    <?php echo htmlspecialchars($term['status']); ?>
                                                </span>
                                            </td>
                                            <?php if ($is_admin): ?>
                                            <td>
                                                <div class="d-flex justify-content-center">
                                                    <a href="manage_terms.php?edit=<?php echo $term['term_id']; ?>" 
                                                       class="btn btn-warning action-btn" title="Edit">
                                                        <i class="bi bi-pencil"></i>
                                                    </a>
                                                    <button class="btn btn-danger action-btn delete-btn" 
                                                            data-id="<?php echo $term['term_id']; ?>"
                                                            data-name="<?php echo htmlspecialchars($term['term_name']); ?>"
                                                            title="Delete">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                            <?php endif; ?>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Summary Statistics -->
                    <?php if (!empty($terms)): ?>
                    <div class="row mt-4">
                        <div class="col-md-4">
                            <div class="card dashboard-card">
                                <div class="card-body text-center">
                                    <div class="text-primary mb-2">
                                        <i class="bi bi-calendar-week" style="font-size: 2rem;"></i>
                                    </div>
                                    <h4 class="mb-1"><?php echo count($terms); ?></h4>
                                    <p class="text-muted mb-0">Total Terms</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card dashboard-card">
                                <div class="card-body text-center">
                                    <div class="text-success mb-2">
                                        <i class="bi bi-check-circle" style="font-size: 2rem;"></i>
                                    </div>
                                    <?php
                                    $active_terms = array_filter($terms, function($term) {
                                        return $term['status'] === 'Active';
                                    });
                                    ?>
                                    <h4 class="mb-1"><?php echo count($active_terms); ?></h4>
                                    <p class="text-muted mb-0">Active Terms</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card dashboard-card">
                                <div class="card-body text-center">
                                    <div class="text-info mb-2">
                                        <i class="bi bi-calendar-check" style="font-size: 2rem;"></i>
                                    </div>
                                    <?php
                                    $current_year = date('Y');
                                    $current_year_terms = array_filter($terms, function($term) use ($current_year) {
                                        return strpos($term['academic_year'], $current_year) !== false;
                                    });
                                    ?>
                                    <h4 class="mb-1"><?php echo count($current_year_terms); ?></h4>
                                    <p class="text-muted mb-0">Current Year Terms</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Quick Add Modal (for empty state) -->
    <div class="modal fade" id="addTermModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-plus-circle me-2"></i>Create New Academic Term
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="post" id="quickTermForm">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="modal_term_name" class="form-label">Term Name *</label>
                                <input type="text" class="form-control" id="modal_term_name" name="term_name" required
                                       placeholder="e.g., Fall Semester 2024">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="modal_academic_year" class="form-label">Academic Year *</label>
                                <input type="text" class="form-control" id="modal_academic_year" name="academic_year" required
                                       placeholder="e.g., 2024-2025" pattern="\d{4}-\d{4}">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="modal_start_date" class="form-label">Start Date *</label>
                                <input type="date" class="form-control" id="modal_start_date" name="start_date" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="modal_end_date" class="form-label">End Date *</label>
                                <input type="date" class="form-control" id="modal_end_date" name="end_date" required>
                            </div>
                        </div>
                        <div class="mb-4">
                            <label for="modal_status" class="form-label">Status *</label>
                            <select class="form-select" id="modal_status" name="status" required>
                                <option value="Active">Active</option>
                                <option value="Inactive">Inactive</option>
                            </select>
                        </div>
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-plus-circle me-2"></i>Create Term
                            </button>
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                                <i class="bi bi-x-circle me-2"></i>Cancel
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-exclamation-triangle text-danger me-2"></i>Confirm Delete
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-4">
                        <i class="bi bi-trash text-danger" style="font-size: 3rem;"></i>
                    </div>
                    <h6 class="text-center mb-3">Are you sure you want to delete this term?</h6>
                    <p class="text-center">
                        Term: <strong><span id="deleteTermName"></span></strong>
                    </p>
                    <div class="alert alert-warning">
                        <i class="bi bi-info-circle me-2"></i>
                        <small>This action cannot be undone. Associated data may be affected.</small>
                    </div>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-2"></i>Cancel
                    </button>
                    <a href="#" id="confirmDeleteBtn" class="btn btn-danger">
                        <i class="bi bi-trash me-2"></i>Delete Term
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Floating Action Button (Mobile/Quick Add) -->
    <?php if ($is_admin && !$no_terms && !$edit_mode): ?>
        <button type="button" class="floating-action-btn" data-bs-toggle="modal" data-bs-target="#addTermModal" title="Add New Term">
            <i class="bi bi-plus-lg"></i>
        </button>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-fill dates for quick add modal
            const today = new Date();
            const startDateInput = document.getElementById('modal_start_date');
            const endDateInput = document.getElementById('modal_end_date');
            
            if (startDateInput && !startDateInput.value) {
                startDateInput.value = today.toISOString().split('T')[0];
                
                const endDate = new Date(today);
                endDate.setDate(endDate.getDate() + 90); // Default 90-day term
                if (endDateInput) {
                    endDateInput.value = endDate.toISOString().split('T')[0];
                }
            }
            
            // Auto-fill academic year
            const academicYearInput = document.getElementById('modal_academic_year');
            if (academicYearInput && !academicYearInput.value) {
                const year = today.getFullYear();
                academicYearInput.value = `${year}-${year + 1}`;
            }
            
            // Same for main form if no terms exist
            const mainStartDate = document.getElementById('start_date');
            const mainEndDate = document.getElementById('end_date');
            const mainAcademicYear = document.getElementById('academic_year');
            
            if (mainStartDate && !mainStartDate.value) {
                mainStartDate.value = today.toISOString().split('T')[0];
                
                if (mainEndDate && !mainEndDate.value) {
                    const endDate = new Date(today);
                    endDate.setDate(endDate.getDate() + 90);
                    mainEndDate.value = endDate.toISOString().split('T')[0];
                }
                
                if (mainAcademicYear && !mainAcademicYear.value) {
                    const year = today.getFullYear();
                    mainAcademicYear.value = `${year}-${year + 1}`;
                }
            }
            
            // Form validation
            const termForm = document.getElementById('termForm');
            if (termForm) {
                termForm.addEventListener('submit', function(e) {
                    const startDate = document.getElementById('start_date').value;
                    const endDate = document.getElementById('end_date').value;
                    
                    if (startDate && endDate && new Date(startDate) >= new Date(endDate)) {
                        e.preventDefault();
                        alert('Start date must be before end date.');
                        return false;
                    }
                });
            }
            
            // Quick form validation
            const quickTermForm = document.getElementById('quickTermForm');
            if (quickTermForm) {
                quickTermForm.addEventListener('submit', function(e) {
                    const startDate = document.getElementById('modal_start_date').value;
                    const endDate = document.getElementById('modal_end_date').value;
                    
                    if (startDate && endDate && new Date(startDate) >= new Date(endDate)) {
                        e.preventDefault();
                        alert('Start date must be before end date.');
                        return false;
                    }
                    
                    const academicYear = document.getElementById('modal_academic_year').value;
                    const yearPattern = /^\d{4}-\d{4}$/;
                    if (!yearPattern.test(academicYear)) {
                        e.preventDefault();
                        alert('Academic year must be in format YYYY-YYYY (e.g., 2024-2025).');
                        return false;
                    }
                });
            }
            
            // Delete confirmation
            const deleteButtons = document.querySelectorAll('.delete-btn');
            const deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
            const deleteTermName = document.getElementById('deleteTermName');
            const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');
            
            deleteButtons.forEach(button => {
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    const termId = this.getAttribute('data-id');
                    const termName = this.getAttribute('data-name');
                    
                    deleteTermName.textContent = termName;
                    confirmDeleteBtn.href = `manage_terms.php?delete=${termId}`;
                    deleteModal.show();
                });
            });
            
            // Auto-close modals on submit
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                const form = modal.querySelector('form');
                if (form) {
                    form.addEventListener('submit', function() {
                        const modalInstance = bootstrap.Modal.getInstance(modal);
                        if (modalInstance) {
                            modalInstance.hide();
                        }
                    });
                }
            });
            
            // Show quick add modal if no terms
            <?php if ($no_terms && $is_admin): ?>
                const addTermModal = new bootstrap.Modal(document.getElementById('addTermModal'));
                addTermModal.show();
            <?php endif; ?>
        });
    </script>
</body>
</html>