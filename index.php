<?php
// Start output buffering to prevent header issues
ob_start();

// Error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Session configuration for PHP 8.4
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

// Debug: Show session status
error_log("Session started. Session ID: " . session_id());

// --- Database Connection with Error Display ---
$conn = null;
try {
    include 'config.php'; // This should set $conn
    
    // Check if connection was successful
    if (!$conn) {
        throw new Exception("Database connection failed - config.php did not create connection");
    }
    
    // Test connection with a simple query (replaces deprecated ping())
    if (!$conn->query("SELECT 1")) {
        throw new Exception("Database connection test failed: " . $conn->error);
    }
    
} catch (Exception $e) {
    die("<div style='padding: 20px; background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; border-radius: 5px; margin: 20px;'>
            <h3>Database Connection Error</h3>
            <p><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>
            <p>Please check your database configuration in config.php and ensure the database server is running.</p>
         </div>");
}

// --- Redirect if already logged in ---
if (isset($_SESSION['username'])) {
    // Check if role is set before using it
    if (isset($_SESSION['role'])) {
        // Redirect to the appropriate dashboard
        if ($_SESSION['role'] === 'Teacher' || $_SESSION['role'] === 'Accountant' || $_SESSION['role'] === 'Student') {
            header('Location: teacher_dashboard.php');
            exit();
        } elseif ($_SESSION['role'] === 'Admin') {
            header('Location: admin_dashboard.php');
            exit();
        }
    }
    // If username is set but role isn't, destroy session and continue to login
    session_destroy();
}

$error_message = ''; // Variable to hold login errors

// --- Handle Login Form Submission ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    // Debug log
    error_log("=== LOGIN ATTEMPT ===");
    error_log("Username: " . $username);
    error_log("Password length: " . strlen($password));

    // Basic validation
    if (empty($username) || empty($password)) {
        $error_message = "Please enter both username and password.";
        error_log("Validation failed: Empty fields");
    } else {
        // Prepare the SQL query using a prepared statement - matches your table structure
        $stmt = $conn->prepare("SELECT user_id, username, password, role, status FROM users WHERE username = ? LIMIT 1");

        if ($stmt) {
            $stmt->bind_param("s", $username);
            
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                
                error_log("Query executed. Rows found: " . $result->num_rows);
                
                if ($result->num_rows === 1) {
                    $user = $result->fetch_assoc();
                    $hashed_password = $user['password'];
                    $user_status = $user['status'];
                    
                    // Debug info
                    error_log("User found - ID: " . $user['user_id'] . ", Role: " . $user['role'] . ", Status: " . $user_status);
                    error_log("Password in DB: " . substr($hashed_password, 0, 50) . "...");
                    
                    // Check if user is active
                    if ($user_status !== 'Active') {
                        $error_message = "Your account is disabled. Please contact administrator.";
                        error_log("Login failed: Account is " . $user_status);
                    } else {
                        // Check password with password_verify (your passwords are already hashed with bcrypt)
                        if (password_verify($password, $hashed_password)) {
                            error_log("Authentication: password_verify SUCCESS");
                            
                            // Password is correct, set session variables
                            $_SESSION['user_id'] = $user['user_id'];
                            $_SESSION['username'] = $user['username'];
                            $_SESSION['role'] = $user['role'];
                            $_SESSION['status'] = $user['status'];
                            
                            error_log("Session variables set:");
                            error_log("- User ID: " . $_SESSION['user_id']);
                            error_log("- Username: " . $_SESSION['username']);
                            error_log("- Role: " . $_SESSION['role']);
                            error_log("- Status: " . $_SESSION['status']);
                            
                            // Update last login time if you add this column later
                            // For now, we'll skip this since the column doesn't exist
                            
                            // Redirect based on role
                            $redirect_url = '';
                            
                            if ($user['role'] == 'Admin') {
                                $redirect_url = 'admin_dashboard.php';
                            } elseif ($user['role'] == 'Teacher') {
                                $redirect_url = 'teacher_dashboard.php';
                            } elseif ($user['role'] == 'Accountant') {
                                $redirect_url = 'teacher_dashboard.php'; // Or create accountant_dashboard.php
                            } elseif ($user['role'] == 'Student') {
                                $redirect_url = 'teacher_dashboard.php'; // Or create student_dashboard.php
                            } else {
                                $error_message = "Login successful, but no dashboard defined for role: " . $user['role'];
                                error_log("No dashboard for role: " . $user['role']);
                            }
                            
                            if (!empty($redirect_url)) {
                                error_log("Redirecting to: " . $redirect_url);
                                // Force session write before redirect
                                session_write_close();
                                header("Location: $redirect_url");
                                exit();
                            }
                            
                        } else {
                            $error_message = "Invalid username or password.";
                            error_log("Password verification failed");
                            error_log("Submitted password hash (bcrypt): " . password_hash($password, PASSWORD_DEFAULT));
                        }
                    }
                } else {
                    $error_message = "Invalid username or password.";
                    error_log("No user found with username: " . $username);
                }
            } else {
                error_log("Database error executing login statement: " . $stmt->error);
                $error_message = "An error occurred during login. Please try again later.";
            }
            $stmt->close();
        } else {
            error_log("Database error preparing login statement: " . $conn->error);
            $error_message = "Database error preparing login statement.";
        }
    }
}

// If we get here, either no POST request or login failed
error_log("Login page loaded. Error message: " . $error_message);

// End output buffering and send output
ob_end_flush();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>The Shepherds Junior School Kamuli - Login</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #02333a 0%, #1a4a52 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
        }
        
        /* Optional subtle background pattern */
        body::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" opacity="0.1"><path d="M20 20 L80 20 L80 80 L20 80 Z" fill="none" stroke="white" stroke-width="2"/><circle cx="50" cy="50" r="15" fill="none" stroke="white" stroke-width="2"/></svg>') repeat;
            pointer-events: none;
        }
        
        .login-container {
            width: 100%;
            max-width: 420px;
            position: relative;
            z-index: 1;
            animation: fadeIn 0.5s ease-out;
        }
        
        @keyframes fadeIn {
            from { 
                opacity: 0; 
                transform: translateY(-20px); 
            }
            to { 
                opacity: 1; 
                transform: translateY(0); 
            }
        }
        
        .login-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
            overflow: hidden;
        }
        
        .card-header {
            background: #02333a;
            color: white;
            padding: 30px 30px 20px;
            text-align: center;
            border-bottom: 4px solid #4CAF50;
        }
        
        .school-logo {
            width: 100px;
            height: 100px;
            margin: 0 auto 15px;
            background: white;
            border-radius: 50%;
            padding: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        .school-logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            border-radius: 50%;
        }
        
        .school-name {
            font-size: 1.4rem;
            font-weight: 600;
            margin-bottom: 5px;
            line-height: 1.3;
        }
        
        .school-location {
            font-size: 1rem;
            opacity: 0.9;
            margin-bottom: 5px;
        }
        
        .portal-tag {
            background: #4CAF50;
            color: white;
            display: inline-block;
            padding: 5px 20px;
            border-radius: 30px;
            font-size: 0.9rem;
            font-weight: 500;
            margin-top: 10px;
        }
        
        .card-body {
            padding: 30px;
        }
        
        .form-label {
            color: #02333a;
            font-weight: 500;
            margin-bottom: 5px;
        }
        
        .input-group {
            margin-bottom: 5px;
        }
        
        .input-group-text {
            background: #f0f5f5;
            border: 2px solid #e1e5e9;
            border-right: none;
            border-radius: 10px 0 0 10px;
            color: #02333a;
        }
        
        .form-control {
            border: 2px solid #e1e5e9;
            border-left: none;
            border-radius: 0 10px 10px 0;
            padding: 12px 15px;
            font-size: 1rem;
            transition: all 0.3s;
        }
        
        .form-control:focus {
            border-color: #4CAF50;
            box-shadow: none;
            outline: none;
        }
        
        .form-control:focus + .input-group-text {
            border-color: #4CAF50;
        }
        
        .password-toggle {
            text-align: right;
            margin-bottom: 15px;
        }
        
        .password-toggle a {
            color: #02333a;
            text-decoration: none;
            font-size: 0.9rem;
        }
        
        .password-toggle a:hover {
            color: #4CAF50;
        }
        
        .btn-login {
            background: #02333a;
            color: white;
            border: none;
            padding: 14px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 1.1rem;
            width: 100%;
            transition: all 0.3s;
            margin-top: 10px;
        }
        
        .btn-login:hover {
            background: #4CAF50;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(76, 175, 80, 0.4);
        }
        
        .btn-login:active {
            transform: translateY(0);
        }
        
        .btn-login i {
            margin-right: 8px;
        }
        
        .btn-login.loading {
            position: relative;
            color: transparent;
        }
        
        .btn-login.loading::after {
            content: '';
            position: absolute;
            width: 20px;
            height: 20px;
            top: 50%;
            left: 50%;
            margin-left: -10px;
            margin-top: -10px;
            border: 3px solid white;
            border-radius: 50%;
            border-top-color: transparent;
            animation: spinner 0.8s linear infinite;
        }
        
        @keyframes spinner {
            to { transform: rotate(360deg); }
        }
        
        .alert {
            border-radius: 10px;
            border: none;
            padding: 12px 15px;
            margin-bottom: 20px;
        }
        
        .alert-danger {
            background: #ffebee;
            color: #c62828;
            border-left: 4px solid #c62828;
        }
        
        .alert-success {
            background: #e8f5e9;
            color: #2e7d32;
            border-left: 4px solid #2e7d32;
        }
        
        .alert-warning {
            background: #fff3e0;
            color: #ef6c00;
            border-left: 4px solid #ef6c00;
        }
        
        .back-link {
            text-align: center;
            margin-top: 20px;
        }
        
        .back-link a {
            color: white;
            text-decoration: none;
            font-size: 0.95rem;
            opacity: 0.9;
            transition: opacity 0.3s;
        }
        
        .back-link a:hover {
            opacity: 1;
            text-decoration: underline;
        }
        
        .back-link i {
            margin-right: 5px;
        }
        
        .secure-badge {
            text-align: center;
            margin-top: 20px;
            color: rgba(255,255,255,0.8);
            font-size: 0.85rem;
        }
        
        .secure-badge i {
            color: #4CAF50;
        }
        
        /* Debug info - hidden by default */
        .debug-info {
            position: fixed;
            bottom: 10px;
            right: 10px;
            background: rgba(0,0,0,0.8);
            color: white;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 12px;
            display: none;
            z-index: 9999;
        }
        
        /* Responsive */
        @media (max-width: 480px) {
            .card-header {
                padding: 20px 20px 15px;
            }
            
            .school-logo {
                width: 80px;
                height: 80px;
            }
            
            .school-name {
                font-size: 1.2rem;
            }
            
            .card-body {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <!-- Debug info (press Ctrl+D to show) -->
    <div class="debug-info" id="debugInfo">
        PHP <?= PHP_VERSION ?> | Session: <?= session_id() ?>
    </div>
    
    <div class="login-container">
        <div class="login-card">
            <!-- Card Header with School Branding -->
            <div class="card-header">
                <div class="school-logo">
                    <img src="./school photos/badge.jpg" alt="The Shepherds Junior School Logo" onerror="this.src='https://via.placeholder.com/100x100?text=Logo'">
                </div>
                <div class="school-name">THE SHEPHERDS JUNIOR SCHOOL</div>
                <div class="school-location">KAMULI</div>
                <div class="portal-tag">Staff Portal</div>
            </div>
            
            <!-- Card Body with Login Form -->
            <div class="card-body">
                <?php if (!empty($error_message)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($error_message) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_GET['logout']) && $_GET['logout'] == '1'): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="bi bi-check-circle-fill"></i> You have been successfully logged out.
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_GET['session_expired']) && $_GET['session_expired'] == '1'): ?>
                    <div class="alert alert-warning alert-dismissible fade show" role="alert">
                        <i class="bi bi-clock-history"></i> Your session has expired. Please login again.
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="" id="loginForm">
                    <div class="mb-3">
                        <label for="username" class="form-label">Username</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-person-fill"></i></span>
                            <input type="text" class="form-control" id="username" name="username" 
                                   placeholder="Enter your username" required autofocus 
                                   value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                        </div>
                    </div>
                    
                    <div class="mb-2">
                        <label for="password" class="form-label">Password</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                            <input type="password" class="form-control" id="password" name="password" 
                                   placeholder="Enter your password" required>
                        </div>
                    </div>
                    
                    <div class="password-toggle">
                        <a href="javascript:void(0)" onclick="togglePassword()">
                            <i class="bi bi-eye"></i> Show password
                        </a>
                    </div>
                    
                    <button type="submit" class="btn btn-login" id="loginBtn">
                        <i class="bi bi-box-arrow-in-right"></i> Login to Portal
                    </button>
                </form>
            </div>
        </div>
        
        <!-- Back to Home Link -->
        <div class="back-link">
            <a href="index.php"><i class="bi bi-arrow-left"></i> Back to Home</a>
        </div>
        
        <!-- Security Badge -->
        <div class="secure-badge">
            <i class="bi bi-shield-check"></i> Secure login system
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Focus on username field if empty
        document.addEventListener('DOMContentLoaded', function() {
            const usernameField = document.getElementById('username');
            if (usernameField && !usernameField.value) {
                usernameField.focus();
            }
            
            // Add form submission handler
            const loginForm = document.getElementById('loginForm');
            const loginBtn = document.getElementById('loginBtn');
            
            if (loginForm) {
                loginForm.addEventListener('submit', function() {
                    // Show loading state
                    loginBtn.classList.add('loading');
                    loginBtn.disabled = true;
                });
            }
        });
        
        // Toggle password visibility
        function togglePassword() {
            const passwordField = document.getElementById('password');
            const toggleLink = event.target.closest('a');
            const icon = toggleLink.querySelector('i');
            
            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                icon.className = 'bi bi-eye-slash';
                toggleLink.innerHTML = icon.outerHTML + ' Hide password';
            } else {
                passwordField.type = 'password';
                icon.className = 'bi bi-eye';
                toggleLink.innerHTML = icon.outerHTML + ' Show password';
            }
        }
        
        // Debug shortcut (Ctrl+D)
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'd') {
                e.preventDefault();
                const debugInfo = document.getElementById('debugInfo');
                debugInfo.style.display = debugInfo.style.display === 'none' ? 'block' : 'none';
            }
        });
        
        // Auto-fill test credentials for testing (Ctrl+T)
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 't') {
                e.preventDefault();
                document.getElementById('username').value = 'admin';
                document.getElementById('password').value = 'password';
                console.log('Test credentials filled');
            }
        });
    </script>
</body>
</html>