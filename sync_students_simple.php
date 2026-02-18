<?php
// sync_students_simple.php

// Turn on error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start output buffering to catch any stray output
ob_start();

session_start();

// Check if config exists
if (!file_exists('config.php')) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Config file not found']);
    exit;
}

require_once 'config.php';

// Verify config variables are defined (using your existing variable names)
if (!isset($host) || !isset($user) || !isset($pass) || !isset($dbname)) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Database configuration incomplete']);
    exit;
}

// Database connection with error handling
try {
    $conn = new mysqli($host, $user, $pass, $dbname);
    
    if ($conn->connect_error) {
        throw new Exception('Database connection failed: ' . $conn->connect_error);
    }
    
    // Set charset
    $conn->set_charset("utf8mb4");
    
} catch (Exception $e) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}

// Simple sync function
function syncStudentsSimple($conn) {
    $result = [
        'status' => 'success',
        'students_synced' => 0,
        'message' => ''
    ];
    
    try {
        // Get distinct students from finance transactions without student_id
        $sql = "
            SELECT DISTINCT 
                ft.student_registration_number as reg_number,
                ft.student_name,
                ft.student_class
            FROM finance_transactions ft
            WHERE ft.student_registration_number IS NOT NULL
            AND ft.student_registration_number != ''
            AND ft.student_name IS NOT NULL
            AND ft.student_name != ''
            AND ft.student_id IS NULL
            AND NOT EXISTS (
                SELECT 1 FROM student s 
                WHERE s.registration_number = ft.student_registration_number
            )
        ";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $students_result = $stmt->get_result();
        
        $new_students = [];
        while ($row = $students_result->fetch_assoc()) {
            $new_students[] = $row;
        }
        $stmt->close();
        
        if (empty($new_students)) {
            $result['message'] = 'No new students to sync';
            return $result;
        }
        
        // Process each new student
        foreach ($new_students as $student) {
            $reg_number = $student['reg_number'];
            $student_name = trim($student['student_name']);
            $student_class = trim($student['student_class']);
            
            // Parse name
            $name_parts = explode(' ', $student_name);
            $first_name = $name_parts[0] ?? '';
            $last_name = count($name_parts) > 1 ? implode(' ', array_slice($name_parts, 1)) : $first_name;
            
            // Find class_id
            $class_id = null;
            if (!empty($student_class)) {
                $class_sql = "SELECT class_id FROM class WHERE class_name = ?";
                $class_stmt = $conn->prepare($class_sql);
                $class_stmt->bind_param("s", $student_class);
                $class_stmt->execute();
                $class_result = $class_stmt->get_result();
                if ($class_row = $class_result->fetch_assoc()) {
                    $class_id = $class_row['class_id'];
                }
                $class_stmt->close();
            }
            
            // Insert student
            $insert_sql = "
                INSERT INTO student (
                    fname, 
                    lname, 
                    dob, 
                    gender, 
                    class_id,
                    registration_number,
                    payment_code
                ) VALUES (?, ?, '2000-01-01', 'Male', ?, ?, ?)
            ";
            
            $insert_stmt = $conn->prepare($insert_sql);
            $insert_stmt->bind_param("ssiss", 
                $first_name, 
                $last_name, 
                $class_id,
                $reg_number,
                $reg_number
            );
            
            if ($insert_stmt->execute()) {
                $student_id = $insert_stmt->insert_id;
                $result['students_synced']++;
                
                // Link transactions to this student
                $update_transactions = "
                    UPDATE finance_transactions 
                    SET student_id = ?
                    WHERE student_registration_number = ?
                    AND student_id IS NULL
                ";
                
                $update_stmt = $conn->prepare($update_transactions);
                $update_stmt->bind_param("is", $student_id, $reg_number);
                $update_stmt->execute();
                $update_stmt->close();
            }
            
            $insert_stmt->close();
        }
        
        $result['message'] = "Successfully synced {$result['students_synced']} new students";
        
    } catch (Exception $e) {
        $result['status'] = 'error';
        $result['message'] = 'Error: ' . $e->getMessage();
    }
    
    return $result;
}

// Execute sync
try {
    $sync_result = syncStudentsSimple($conn);
    
    // Get updated statistics
    $stats = [];
    $sql = "SELECT COUNT(*) as total_students FROM student";
    $stats_result = $conn->query($sql);
    if ($stats_result) {
        $row = $stats_result->fetch_assoc();
        $stats['total_students'] = $row['total_students'] ?? 0;
    }
    
    $sql = "SELECT COUNT(DISTINCT student_registration_number) as pending_sync 
            FROM finance_transactions 
            WHERE student_registration_number IS NOT NULL 
            AND student_registration_number != ''
            AND student_id IS NULL";
    $stats_result = $conn->query($sql);
    if ($stats_result) {
        $row = $stats_result->fetch_assoc();
        $stats['pending_sync'] = $row['pending_sync'] ?? 0;
    }
    
    $conn->close();
    
    // Add stats to result
    $sync_result['stats'] = $stats;
    
} catch (Exception $e) {
    $sync_result = [
        'status' => 'error',
        'message' => 'Unexpected error: ' . $e->getMessage()
    ];
}

// Clear any output buffer
ob_end_clean();

// Send JSON response
header('Content-Type: application/json');
echo json_encode($sync_result);
exit;
?>