<?php
session_start();
// Ensure the user is logged in and has appropriate permissions
if (!isset($_SESSION['username'])) {
    header('Location: index.php');
    exit();
}

// Check if user has finance access (Administrator or Accountant role)
if ($_SESSION['role'] !== 'Administrator' && $_SESSION['role'] !== 'Accountant') {
    header('Location: admin_dashboard.php');
    exit();
}

include 'config.php';

// Initialize variables
$current_term = null;
$previous_term = null;
$classes = [];
$students = [];
$staff_members = [];
$class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
$student_id = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;

// Increase execution time for long-running operations
set_time_limit(300);

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Function to check and fix database table structure
function checkAndFixFinanceTables($conn) {
    $errors = [];
    
    // Check if finance_transactions table exists and has correct structure
    $table_check = $conn->query("SHOW TABLES LIKE 'finance_transactions'");
    if (!$table_check || $table_check->num_rows == 0) {
        // Table doesn't exist, create it
        $create_table = "CREATE TABLE IF NOT EXISTS `finance_transactions` (
            `transaction_id` int(11) NOT NULL AUTO_INCREMENT,
            `student_id` int(11) DEFAULT NULL,
            `term_id` int(11) NOT NULL,
            `amount` decimal(10,2) NOT NULL,
            `payment_method` varchar(50) DEFAULT NULL,
            `transaction_reference` varchar(100) NOT NULL,
            `transaction_date` datetime NOT NULL,
            `source` enum('SchoolPay','Manual') NOT NULL DEFAULT 'SchoolPay',
            `synced_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            `status` enum('Completed','Pending','Failed') DEFAULT 'Completed',
            `student_registration_number` varchar(50) DEFAULT NULL,
            `student_name` varchar(100) DEFAULT NULL,
            `student_class` varchar(50) DEFAULT NULL,
            `source_payment_channel` varchar(100) DEFAULT NULL,
            `source_channel_transaction_id` varchar(100) DEFAULT NULL,
            `settlement_bank_code` varchar(50) DEFAULT NULL,
            `source_channel_trans_detail` text DEFAULT NULL,
            PRIMARY KEY (`transaction_id`),
            UNIQUE KEY `transaction_reference` (`transaction_reference`),
            KEY `student_id` (`student_id`),
            KEY `term_id` (`term_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        if (!$conn->query($create_table)) {
            $errors[] = "Failed to create finance_transactions table: " . $conn->error;
        } else {
            error_log("Created finance_transactions table");
        }
    } else {
        // Table exists, check for required columns
        $columns_to_check = [
            'source_payment_channel' => "ADD COLUMN source_payment_channel varchar(100) DEFAULT NULL",
            'source_channel_transaction_id' => "ADD COLUMN source_channel_transaction_id varchar(100) DEFAULT NULL",
            'settlement_bank_code' => "ADD COLUMN settlement_bank_code varchar(50) DEFAULT NULL",
            'source_channel_trans_detail' => "ADD COLUMN source_channel_trans_detail text DEFAULT NULL"
        ];
        
        foreach ($columns_to_check as $column => $sql) {
            $check = $conn->query("SHOW COLUMNS FROM finance_transactions LIKE '$column'");
            if (!$check || $check->num_rows == 0) {
                if (!$conn->query("ALTER TABLE finance_transactions $sql")) {
                    $errors[] = "Failed to add $column column: " . $conn->error;
                } else {
                    error_log("Added $column column to finance_transactions table");
                }
            }
        }
    }
    
    return $errors;
}

// Check and fix database tables
$table_errors = checkAndFixFinanceTables($conn);
if (!empty($table_errors)) {
    foreach ($table_errors as $error) {
        error_log("Table error: " . $error);
    }
}

// Function to get active term
function getActiveTerm($conn) {
    $query = "SELECT * FROM terms WHERE status = 'Active' ORDER BY start_date DESC LIMIT 1";
    $result = $conn->query($query);
    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    return null;
}

// Function to get previous term based on current term
function getPreviousTerm($conn, $current_term) {
    if (!$current_term) return null;
    
    $query = "SELECT * FROM terms 
              WHERE end_date < ? AND status = 'Inactive'
              ORDER BY end_date DESC LIMIT 1";
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param("s", $current_term['start_date']);
        $stmt->execute();
        $result = $stmt->get_result();
        $term = $result->fetch_assoc();
        $stmt->close();
        return $term;
    }
    return null;
}

// Get current and previous terms
$current_term = getActiveTerm($conn);
if ($current_term) {
    $previous_term = getPreviousTerm($conn, $current_term);
}

// Function to generate SchoolPay API hash
function generateSchoolPayHash($date = null) {
    if (!defined('SCHOOLPAY_SCHOOL_CODE') || !defined('SCHOOLPAY_PASSWORD')) {
        error_log("SchoolPay configuration constants not defined");
        return '';
    }
    
    $date = $date ?: date('Y-m-d');
    $hash_string = SCHOOLPAY_SCHOOL_CODE . $date . SCHOOLPAY_PASSWORD;
    return md5($hash_string);
}

// Function to fetch transactions from SchoolPay API for a single day
function fetchSchoolPayTransactions($date) {
    if (!defined('SCHOOLPAY_BASE_URL') || !defined('SCHOOLPAY_SCHOOL_CODE')) {
        error_log("SchoolPay configuration not found");
        return [];
    }
    
    $hash = generateSchoolPayHash($date);
    if (empty($hash)) {
        return [];
    }
    
    $url = SCHOOLPAY_BASE_URL . "SyncSchoolTransactions/" . 
           SCHOOLPAY_SCHOOL_CODE . "/" . $date . "/" . $hash;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'Content-Type: application/json'
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($http_code == 200 && $response) {
        $data = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            if (isset($data['returnCode']) && $data['returnCode'] == 0) {
                if (isset($data['transactions']) && is_array($data['transactions'])) {
                    return $data['transactions'];
                } else {
                    error_log("No transactions array found for $date");
                    return [];
                }
            } else {
                $error_msg = isset($data['returnMessage']) ? $data['returnMessage'] : 'Unknown error';
                error_log("SchoolPay API Error for $date (Code {$data['returnCode']}): $error_msg");
                return [];
            }
        } else {
            error_log("JSON Parse Error for $date: " . json_last_error_msg());
            return [];
        }
    } else {
        error_log("HTTP Error for $date: Code $http_code - $error");
        return [];
    }
}

// Function to fetch transactions for a date range
function fetchSchoolPayTransactionsRange($start_date, $end_date, $batch_size = 10) {
    $all_transactions = [];
    
    $start = new DateTime($start_date);
    $end = new DateTime($end_date);
    $current = clone $start;
    
    $interval = $start->diff($end);
    $total_days = $interval->days + 1;
    
    error_log("Starting fetch for $total_days days ($start_date to $end_date)");
    
    $days_processed = 0;
    
    while ($current <= $end) {
        $date_str = $current->format('Y-m-d');
        $transactions = fetchSchoolPayTransactions($date_str);
        
        if (!empty($transactions) && is_array($transactions)) {
            $all_transactions = array_merge($all_transactions, $transactions);
        }
        
        $current->modify('+1 day');
        $days_processed++;
        
        // Small delay to avoid overwhelming API
        usleep(100000);
        
        if ($days_processed % $batch_size == 0) {
            error_log("Processed $days_processed/$total_days days");
        }
    }
    
    error_log("Completed fetch: Processed $days_processed days, found " . count($all_transactions) . " transactions");
    return $all_transactions;
}

// Helper function for simple transaction insert
function saveTransactionSimple($conn, $student_id, $term_id, $amount, 
                              $reference, $date, $student_name, $status = 'Completed') {
    
    $simple_query = "INSERT INTO finance_transactions (
                    student_id, term_id, amount, transaction_reference, 
                    transaction_date, source, status, student_name
                  ) VALUES (?, ?, ?, ?, ?, 'SchoolPay', ?, ?)";
    
    $simple_stmt = $conn->prepare($simple_query);
    
    if (!$simple_stmt) {
        error_log("Prepare failed for simple insert: " . $conn->error);
        return ['status' => 'error', 'message' => 'Database prepare failed: ' . $conn->error];
    }
    
    // Handle NULL student_id
    if ($student_id === null || $student_id == 0) {
        $student_id = null;
    }
    
    $simple_stmt->bind_param("iidssss", 
        $student_id, 
        $term_id, 
        $amount, 
        $reference, 
        $date, 
        $status, 
        $student_name
    );
    
    if ($simple_stmt->execute()) {
        $insert_id = $simple_stmt->insert_id;
        $simple_stmt->close();
        return ['status' => 'success', 'message' => 'Transaction saved', 'id' => $insert_id];
    } else {
        $error = $simple_stmt->error;
        $simple_stmt->close();
        error_log("Simple insert failed: " . $error);
        return ['status' => 'error', 'message' => 'Failed to save transaction: ' . $error];
    }
}

// Function to save transaction to database
function saveTransaction($conn, $transaction_data, $term_id) {
    if (!is_array($transaction_data)) {
        return ['status' => 'error', 'message' => 'Invalid transaction data'];
    }
    
    // Extract transaction reference
    $transaction_reference = '';
    if (isset($transaction_data['schoolpayReceiptNumber'])) {
        $transaction_reference = $transaction_data['schoolpayReceiptNumber'];
    } elseif (isset($transaction_data['receiptNumber'])) {
        $transaction_reference = $transaction_data['receiptNumber'];
    } elseif (isset($transaction_data['transactionId'])) {
        $transaction_reference = $transaction_data['transactionId'];
    } else {
        $transaction_reference = uniqid('SCHPAY_');
    }
    
    // Clean reference
    $transaction_reference = substr(trim($transaction_reference), 0, 100);
    
    // Check if transaction already exists
    $check_query = "SELECT transaction_id FROM finance_transactions WHERE transaction_reference = ?";
    $check_stmt = $conn->prepare($check_query);
    
    if (!$check_stmt) {
        return ['status' => 'error', 'message' => 'Database prepare failed: ' . $conn->error];
    }
    
    $check_stmt->bind_param("s", $transaction_reference);
    $check_stmt->execute();
    $check_stmt->store_result();
    
    if ($check_stmt->num_rows > 0) {
        $check_stmt->close();
        return ['status' => 'duplicate', 'message' => 'Transaction already exists'];
    }
    $check_stmt->close();
    
    // Try to find student
    $student_id = null;
    $reg_no = $transaction_data['studentRegistrationNumber'] ?? '';
    $payment_code = $transaction_data['studentPaymentCode'] ?? '';
    $student_name = $transaction_data['studentName'] ?? '';
    
    if (!empty($reg_no) || !empty($payment_code)) {
        $student_query = "SELECT student_id FROM student WHERE registration_number = ? OR payment_code = ? LIMIT 1";
        $student_stmt = $conn->prepare($student_query);
        
        if ($student_stmt) {
            $student_stmt->bind_param("ss", $reg_no, $payment_code);
            $student_stmt->execute();
            $student_result = $student_stmt->get_result();
            if ($student_row = $student_result->fetch_assoc()) {
                $student_id = $student_row['student_id'];
            }
            $student_stmt->close();
        }
    }
    
    // Extract data
    $amount = floatval($transaction_data['amount'] ?? 0);
    $payment_method = $transaction_data['sourcePaymentChannel'] ?? 
                     $transaction_data['paymentMethod'] ?? 'Unknown';
    $payment_method = substr(trim($payment_method), 0, 50);
    
    $transaction_date = $transaction_data['paymentDateAndTime'] ?? 
                       $transaction_data['paymentDate'] ?? date('Y-m-d H:i:s');
    
    // Ensure proper date format
    if (strlen($transaction_date) == 10) {
        $transaction_date .= ' 00:00:00';
    }
    
    $status = $transaction_data['transactionCompletionStatus'] ?? 
              $transaction_data['status'] ?? 'Completed';
    
    // Validate status
    $valid_statuses = ['Completed', 'Pending', 'Failed'];
    if (!in_array($status, $valid_statuses)) {
        $status = 'Completed';
    }
    
    $student_class = $transaction_data['studentClass'] ?? '';
    $channel = $transaction_data['sourcePaymentChannel'] ?? '';
    $channel_id = $transaction_data['sourceChannelTransactionId'] ?? '';
    $bank_code = $transaction_data['settlementBankCode'] ?? '';
    $trans_detail = $transaction_data['sourceChannelTransDetail'] ?? '';
    
    // Try complex insert
    $query = "INSERT INTO finance_transactions (
                student_id, term_id, amount, payment_method, 
                transaction_reference, transaction_date, source, status,
                student_registration_number, student_name, student_class,
                source_payment_channel, source_channel_transaction_id,
                settlement_bank_code, source_channel_trans_detail
              ) VALUES (?, ?, ?, ?, ?, ?, 'SchoolPay', ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $conn->prepare($query);
    
    if ($stmt) {
        // Handle NULL student_id for bind_param
        if ($student_id === null || $student_id == 0) {
            $student_id = null;
        }
        
        $stmt->bind_param(
            "iidsssssssssss", 
            $student_id, 
            $term_id, 
            $amount, 
            $payment_method, 
            $transaction_reference, 
            $transaction_date, 
            $status, 
            $reg_no, 
            $student_name, 
            $student_class,
            $channel, 
            $channel_id, 
            $bank_code, 
            $trans_detail
        );
        
        if ($stmt->execute()) {
            $insert_id = $stmt->insert_id;
            $stmt->close();
            
            // Update student balance if student was found
            if ($student_id) {
                updateStudentBalance($conn, $student_id, $term_id);
            }
            
            return ['status' => 'success', 'message' => 'Transaction saved', 'id' => $insert_id];
        } else {
            $error = $stmt->error;
            $stmt->close();
            
            // Fall back to simple insert
            return saveTransactionSimple($conn, $student_id, $term_id, $amount, 
                                        $transaction_reference, $transaction_date, 
                                        $student_name, $status);
        }
    } else {
        // Try simple insert
        return saveTransactionSimple($conn, $student_id, $term_id, $amount, 
                                    $transaction_reference, $transaction_date, 
                                    $student_name, $status);
    }
}

// Function to update student balance
function updateStudentBalance($conn, $student_id, $term_id) {
    // For now, just log the update
    error_log("Balance updated for student $student_id, term $term_id");
    return true;
}



// Add this function - place it with your other functions
function syncStudentsFromTransactions($conn) {
    $result = [
        'status' => 'success',
        'students_synced' => 0,
        'message' => '',
        'details' => []
    ];
    
    try {
        // Start transaction
        $conn->begin_transaction();
        
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
            $result['students_synced'] = 0;
            $conn->commit();
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
                
                $result['details'][] = [
                    'reg_number' => $reg_number,
                    'name' => $student_name,
                    'class' => $student_class,
                    'student_id' => $student_id
                ];
            }
            
            $insert_stmt->close();
        }
        
        // Commit transaction
        $conn->commit();
        
        $result['message'] = "Successfully synced {$result['students_synced']} new students";
        
    } catch (Exception $e) {
        // Rollback on error
        $conn->rollback();
        
        $result['status'] = 'error';
        $result['message'] = 'Error syncing students: ' . $e->getMessage();
        $result['students_synced'] = 0;
    }
    
    return $result;
}



// Function to sync previous term transactions
function syncPreviousTermTransactions($conn, $previous_term, $batch_size = 10) {
    if (!$previous_term) {
        return ['error' => 'No previous term found'];
    }
    
    $start_date = $previous_term['start_date'];
    $end_date = $previous_term['end_date'];
    
    error_log("Starting sync for previous term: $start_date to $end_date");
    
    $all_transactions = fetchSchoolPayTransactionsRange($start_date, $end_date, $batch_size);
    
    if (isset($all_transactions['error'])) {
        return $all_transactions;
    }
    
    if (!is_array($all_transactions)) {
        return ['error' => 'Invalid transactions data received from API'];
    }
    
    $results = [
        'term_name' => $previous_term['term_name'] . ' ' . $previous_term['academic_year'],
        'date_range' => "$start_date to $end_date",
        'total_found' => count($all_transactions),
        'saved' => 0,
        'duplicates' => 0,
        'errors' => 0
    ];
    
    // Save each transaction
    foreach ($all_transactions as $transaction) {
        $save_result = saveTransaction($conn, $transaction, $previous_term['term_id']);
        
        if ($save_result['status'] == 'success') {
            $results['saved']++;
        } elseif ($save_result['status'] == 'duplicate') {
            $results['duplicates']++;
        } else {
            $results['errors']++;
        }
    }
    
    // Calculate success rate
    $results['success_rate'] = $results['total_found'] > 0 ? 
        round(($results['saved'] / $results['total_found']) * 100, 2) : 0;
    
    error_log("Sync completed: Saved {$results['saved']} of {$results['total_found']} transactions");
    
    return $results;
}

// Function to sync current term transactions
function syncCurrentTermTransactions($conn, $current_term, $days_back = 7, $batch_size = 10) {
    if (!$current_term) {
        return ['error' => 'No current term found'];
    }
    
    $end_date = date('Y-m-d');
    $start_date = date('Y-m-d', strtotime("-$days_back days"));
    
    // Ensure we don't fetch beyond term start
    if ($start_date < $current_term['start_date']) {
        $start_date = $current_term['start_date'];
    }
    
    error_log("Starting current term sync: $start_date to $end_date");
    
    $transactions = fetchSchoolPayTransactionsRange($start_date, $end_date, $batch_size);
    
    if (isset($transactions['error'])) {
        return $transactions;
    }
    
    if (!is_array($transactions)) {
        return ['error' => 'Invalid transactions data received from API'];
    }
    
    $results = [
        'sync_type' => 'current_term',
        'date_range' => "$start_date to $end_date",
        'days_back' => $days_back,
        'total_found' => count($transactions),
        'saved' => 0,
        'duplicates' => 0,
        'errors' => 0
    ];
    
    foreach ($transactions as $transaction) {
        $save_result = saveTransaction($conn, $transaction, $current_term['term_id']);
        
        if ($save_result['status'] == 'success') {
            $results['saved']++;
        } elseif ($save_result['status'] == 'duplicate') {
            $results['duplicates']++;
        } else {
            $results['errors']++;
        }
    }
    
    $results['success_rate'] = $results['total_found'] > 0 ? 
        round(($results['saved'] / $results['total_found']) * 100, 2) : 0;
    
    error_log("Current term sync completed: Saved {$results['saved']} of {$results['total_found']} transactions");
    
    return $results;
}

// Function to calculate student fees balance
function getStudentBalance($conn, $student_id, $term_id) {
    // Get total fees for student's class
    $total_fees = 0;
    $fees_query = "SELECT fs.total_amount 
                   FROM fees_structure fs
                   JOIN student s ON fs.class_id = s.class_id
                   WHERE s.student_id = ? AND fs.term_id = ?";
    $fees_stmt = $conn->prepare($fees_query);
    if ($fees_stmt) {
        $fees_stmt->bind_param("ii", $student_id, $term_id);
        $fees_stmt->execute();
        $fees_result = $fees_stmt->get_result();
        if ($fees_data = $fees_result->fetch_assoc()) {
            $total_fees = floatval($fees_data['total_amount']);
        }
        $fees_stmt->close();
    }
    
    // Get bursary amount
    $bursary_amount = 0;
    $bursary_query = "SELECT amount_awarded FROM bursaries 
                      WHERE student_id = ? AND term_id = ? AND status = 'Active'";
    $bursary_stmt = $conn->prepare($bursary_query);
    if ($bursary_stmt) {
        $bursary_stmt->bind_param("ii", $student_id, $term_id);
        $bursary_stmt->execute();
        $bursary_result = $bursary_stmt->get_result();
        if ($bursary_data = $bursary_result->fetch_assoc()) {
            $bursary_amount = floatval($bursary_data['amount_awarded']);
        }
        $bursary_stmt->close();
    }
    
    // Get total payments
    $total_paid = 0;
    $payments_query = "SELECT COALESCE(SUM(amount), 0) as total_paid 
                       FROM finance_transactions 
                       WHERE student_id = ? AND term_id = ? AND status = 'Completed'";
    $payments_stmt = $conn->prepare($payments_query);
    if ($payments_stmt) {
        $payments_stmt->bind_param("ii", $student_id, $term_id);
        $payments_stmt->execute();
        $payments_result = $payments_stmt->get_result();
        if ($payments_data = $payments_result->fetch_assoc()) {
            $total_paid = floatval($payments_data['total_paid']);
        }
        $payments_stmt->close();
    }
    
    // Calculate balance
    $net_fees = $total_fees - $bursary_amount;
    $balance = $net_fees - $total_paid;
    
    return [
        'total_fees' => $total_fees,
        'bursary' => $bursary_amount,
        'net_fees' => $net_fees,
        'paid' => $total_paid,
        'balance' => $balance,
        'percentage_paid' => $net_fees > 0 ? round(($total_paid / $net_fees) * 100, 2) : 100
    ];
}

// Function to get class financial summary
function getClassFinancialSummary($conn, $class_id, $term_id) {
    $summary = [
        'total_students' => 0,
        'total_fees_expected' => 0,
        'total_bursaries' => 0,
        'total_paid' => 0,
        'fully_paid' => 0,
        'partially_paid' => 0,
        'not_paid' => 0,
        'students' => []
    ];
    
    // Get all students in class
    $students_query = "SELECT s.student_id, s.fname, s.lname, s.registration_number
                       FROM student s 
                       WHERE s.class_id = ?";
    $students_stmt = $conn->prepare($students_query);
    if ($students_stmt) {
        $students_stmt->bind_param("i", $class_id);
        $students_stmt->execute();
        $students_result = $students_stmt->get_result();
        
        // Get class fees
        $class_fees = 0;
        $fees_query = "SELECT total_amount FROM fees_structure 
                       WHERE class_id = ? AND term_id = ?";
        $fees_stmt = $conn->prepare($fees_query);
        if ($fees_stmt) {
            $fees_stmt->bind_param("ii", $class_id, $term_id);
            $fees_stmt->execute();
            $fees_result = $fees_stmt->get_result();
            if ($fees_data = $fees_result->fetch_assoc()) {
                $class_fees = floatval($fees_data['total_amount']);
            }
            $fees_stmt->close();
        }
        
        while ($student = $students_result->fetch_assoc()) {
            $balance_info = getStudentBalance($conn, $student['student_id'], $term_id);
            
            $student_data = [
                'id' => $student['student_id'],
                'name' => $student['fname'] . ' ' . $student['lname'],
                'registration' => $student['registration_number'],
                'total_fees' => $balance_info['total_fees'],
                'bursary' => $balance_info['bursary'],
                'net_fees' => $balance_info['net_fees'],
                'paid' => $balance_info['paid'],
                'balance' => $balance_info['balance'],
                'percentage_paid' => $balance_info['percentage_paid'],
                'status' => $balance_info['balance'] <= 0 ? 'Fully Paid' : 
                           ($balance_info['paid'] > 0 ? 'Partially Paid' : 'Not Paid')
            ];
            
            $summary['students'][] = $student_data;
            $summary['total_students']++;
            $summary['total_fees_expected'] += $balance_info['total_fees'];
            $summary['total_bursaries'] += $balance_info['bursary'];
            $summary['total_paid'] += $balance_info['paid'];
            
            if ($balance_info['balance'] <= 0) {
                $summary['fully_paid']++;
            } elseif ($balance_info['paid'] > 0) {
                $summary['partially_paid']++;
            } else {
                $summary['not_paid']++;
            }
        }
        
        $students_stmt->close();
    }
    
    return $summary;
}

// Function to get term financial summary
function getTermFinancialSummary($conn, $term_id) {
    $summary = [
        'total_collections' => 0,
        'total_expected' => 0,
        'total_bursaries' => 0,
        'classes' => [],
        'payment_methods' => [],
        'daily_collections' => []
    ];
    
    // Get total collections and group by payment method and date
    $collections_query = "SELECT COALESCE(SUM(amount), 0) as total, 
                         payment_method, DATE(transaction_date) as date
                         FROM finance_transactions 
                         WHERE term_id = ? AND status = 'Completed'
                         GROUP BY payment_method, DATE(transaction_date)";
    $collections_stmt = $conn->prepare($collections_query);
    if ($collections_stmt) {
        $collections_stmt->bind_param("i", $term_id);
        $collections_stmt->execute();
        $collections_result = $collections_stmt->get_result();
        
        while ($row = $collections_result->fetch_assoc()) {
            $total = floatval($row['total']);
            $summary['total_collections'] += $total;
            
            // Group by payment method
            $method = $row['payment_method'] ?? 'Unknown';
            if (!isset($summary['payment_methods'][$method])) {
                $summary['payment_methods'][$method] = 0;
            }
            $summary['payment_methods'][$method] += $total;
            
            // Group by date
            $date = $row['date'];
            if (!isset($summary['daily_collections'][$date])) {
                $summary['daily_collections'][$date] = 0;
            }
            $summary['daily_collections'][$date] += $total;
        }
        $collections_stmt->close();
    }
    
    // Get all classes and their expected fees
    $classes_query = "SELECT c.class_id, c.class_name, 
                     COALESCE(fs.total_amount, 0) as fee_amount,
                     (SELECT COUNT(*) FROM student s WHERE s.class_id = c.class_id) as student_count
                     FROM class c
                     LEFT JOIN fees_structure fs ON c.class_id = fs.class_id AND fs.term_id = ?
                     ORDER BY c.class_name";
    $classes_stmt = $conn->prepare($classes_query);
    if ($classes_stmt) {
        $classes_stmt->bind_param("i", $term_id);
        $classes_stmt->execute();
        $classes_result = $classes_stmt->get_result();
        
        while ($class = $classes_result->fetch_assoc()) {
            $class_summary = getClassFinancialSummary($conn, $class['class_id'], $term_id);
            $summary['classes'][] = [
                'class_id' => $class['class_id'],
                'class_name' => $class['class_name'],
                'student_count' => intval($class['student_count']),
                'fee_amount' => floatval($class['fee_amount']),
                'total_expected' => $class_summary['total_fees_expected'],
                'total_paid' => $class_summary['total_paid'],
                'fully_paid' => $class_summary['fully_paid'],
                'partially_paid' => $class_summary['partially_paid'],
                'not_paid' => $class_summary['not_paid']
            ];
            $summary['total_expected'] += $class_summary['total_fees_expected'];
        }
        $classes_stmt->close();
    }
    
    return $summary;
}

// Handle API actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // validate CSRF
    if (!hash_equals(
            $_SESSION['csrf_token'] ?? '',
            $_POST['csrf_token'] ?? ''
        )) {
        die('Invalid CSRF token');
    }
    $action = $_POST['action'] ?? '';
    
    if ($action === 'sync_previous_term' && $previous_term) {
        $batch_size = intval($_POST['batch_size'] ?? 10);
        $result = syncPreviousTermTransactions($conn, $previous_term, $batch_size);
        $_SESSION['sync_result'] = $result;
        header('Location: finance.php?sync=previous');
        exit();
    }
    
    if ($action === 'sync_current_term' && $current_term) {
        $days_back = intval($_POST['days_back'] ?? 7);
        $batch_size = intval($_POST['batch_size'] ?? 10);
        $result = syncCurrentTermTransactions($conn, $current_term, $days_back, $batch_size);
        $_SESSION['sync_result'] = $result;
        header('Location: finance.php?sync=current');
        exit();
    }
    
    if ($action === 'save_fees_structure') {
        $class_id = intval($_POST['class_id']);
        $term_id = intval($_POST['term_id']);
        $total_amount = floatval($_POST['total_amount']);
        $bursary_allowed = isset($_POST['bursary_allowed']) ? 1 : 0;
        $description = $conn->real_escape_string($_POST['description'] ?? '');
        
        // Check if record exists
        $check_query = "SELECT fee_id FROM fees_structure WHERE class_id = ? AND term_id = ?";
        $check_stmt = $conn->prepare($check_query);
        $exists = false;
        
        if ($check_stmt) {
            $check_stmt->bind_param("ii", $class_id, $term_id);
            $check_stmt->execute();
            $check_stmt->store_result();
            $exists = $check_stmt->num_rows > 0;
            $check_stmt->close();
        }
        
        if ($exists) {
            $query = "UPDATE fees_structure SET 
                     total_amount = ?, 
                     bursary_allowed = ?,
                     description = ?,
                     updated_at = CURRENT_TIMESTAMP
                     WHERE class_id = ? AND term_id = ?";
            $stmt = $conn->prepare($query);
            if ($stmt) {
                $stmt->bind_param("disii", $total_amount, $bursary_allowed, $description, $class_id, $term_id);
            }
        } else {
            $query = "INSERT INTO fees_structure 
                     (class_id, term_id, total_amount, bursary_allowed, description) 
                     VALUES (?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($query);
            if ($stmt) {
                $stmt->bind_param("iidis", $class_id, $term_id, $total_amount, $bursary_allowed, $description);
            }
        }
        
        if ($stmt && $stmt->execute()) {
            $_SESSION['message'] = ['type' => 'success', 'text' => 'Fees structure saved successfully'];
        } else {
            $_SESSION['message'] = ['type' => 'danger', 'text' => 'Failed to save fees structure: ' . ($stmt ? $stmt->error : $conn->error)];
        }
        
        if ($stmt) $stmt->close();
        header('Location: finance.php?tab=fees');
        exit();
    }
    
    if ($action === 'award_bursary') {
        $student_id = intval($_POST['student_id']);
        $term_id = intval($_POST['term_id']);
        $amount_awarded = floatval($_POST['amount_awarded']);
        $reason = $conn->real_escape_string($_POST['reason'] ?? '');
        $awarded_by = $_SESSION['user_id'] ?? 1;
        
        $query = "INSERT INTO bursaries 
                 (student_id, term_id, amount_awarded, reason, awarded_by, awarded_date) 
                 VALUES (?, ?, ?, ?, ?, CURDATE())
                 ON DUPLICATE KEY UPDATE 
                 amount_awarded = VALUES(amount_awarded),
                 reason = VALUES(reason),
                 awarded_by = VALUES(awarded_by),
                 awarded_date = VALUES(awarded_date)";
        
        $stmt = $conn->prepare($query);
        if ($stmt) {
            $stmt->bind_param("iidsi", $student_id, $term_id, $amount_awarded, $reason, $awarded_by);
            
            if ($stmt->execute()) {
                $_SESSION['message'] = ['type' => 'success', 'text' => 'Bursary awarded successfully'];
            } else {
                $_SESSION['message'] = ['type' => 'danger', 'text' => 'Failed to award bursary: ' . $stmt->error];
            }
            $stmt->close();
        } else {
            $_SESSION['message'] = ['type' => 'danger', 'text' => 'Database error: ' . $conn->error];
        }
        
        header('Location: finance.php?tab=bursaries');
        exit();
    }
    
    if ($action === 'add_staff_salary') {
        $staff_id = intval($_POST['staff_id']);
        $staff_type = $conn->real_escape_string($_POST['staff_type']);
        $term_id = intval($_POST['term_id']);
        $amount = floatval($_POST['amount']);
        $notes = $conn->real_escape_string($_POST['notes'] ?? '');
        
        $query = "INSERT INTO salaries 
                 (staff_id, staff_type, term_id, amount, notes) 
                 VALUES (?, ?, ?, ?, ?)";
        
        $stmt = $conn->prepare($query);
        if ($stmt) {
            $stmt->bind_param("isids", $staff_id, $staff_type, $term_id, $amount, $notes);
            
            if ($stmt->execute()) {
                $_SESSION['message'] = ['type' => 'success', 'text' => 'Salary record added successfully'];
            } else {
                $_SESSION['message'] = ['type' => 'danger', 'text' => 'Failed to add salary record: ' . $stmt->error];
            }
            $stmt->close();
        } else {
            $_SESSION['message'] = ['type' => 'danger', 'text' => 'Database error: ' . $conn->error];
        }
        
        header('Location: finance.php?tab=salaries');
        exit();
    }
    
    if ($action === 'mark_salary_paid') {
        $salary_id = intval($_POST['salary_id']);
        $payment_date = $conn->real_escape_string($_POST['payment_date'] ?? date('Y-m-d'));
        $payment_method = $conn->real_escape_string($_POST['payment_method'] ?? 'Bank Transfer');
        $payment_reference = $conn->real_escape_string($_POST['payment_reference'] ?? '');
        
        $query = "UPDATE salaries SET 
                 status = 'Paid',
                 payment_date = ?,
                 payment_method = ?,
                 payment_reference = ?
                 WHERE salary_id = ?";
        
        $stmt = $conn->prepare($query);
        if ($stmt) {
            $stmt->bind_param("sssi", $payment_date, $payment_method, $payment_reference, $salary_id);
            
            if ($stmt->execute()) {
                $_SESSION['message'] = ['type' => 'success', 'text' => 'Salary marked as paid'];
            } else {
                $_SESSION['message'] = ['type' => 'danger', 'text' => 'Failed to update salary: ' . $stmt->error];
            }
            $stmt->close();
        } else {
            $_SESSION['message'] = ['type' => 'danger', 'text' => 'Database error: ' . $conn->error];
        }
        
        header('Location: finance.php?tab=salaries');
        exit();
    }
}

// Fetch data for display using the main connection
$classes = [];
$classes_query = "SELECT class_id, class_name FROM class ORDER BY class_name";
$classes_result = $conn->query($classes_query);
if ($classes_result) {
    while ($row = $classes_result->fetch_assoc()) {
        $classes[] = $row;
    }
}

$all_terms = [];
$terms_query = "SELECT * FROM terms ORDER BY start_date DESC";
$terms_result = $conn->query($terms_query);
if ($terms_result) {
    while ($row = $terms_result->fetch_assoc()) {
        $all_terms[] = $row;
    }
}

$students = [];
$students_query = "SELECT s.student_id, s.fname, s.lname, s.registration_number, c.class_id, c.class_name 
                   FROM student s 
                   JOIN class c ON s.class_id = c.class_id 
                   ORDER BY s.lname, s.fname";
$students_result = $conn->query($students_query);
if ($students_result) {
    while ($row = $students_result->fetch_assoc()) {
        $students[] = $row;
    }
}

// Fetch staff
$staff_members = [];
$staff_query = "SELECT * FROM staff WHERE status = 'Active' ORDER BY lname, fname";
$staff_result = $conn->query($staff_query);
if ($staff_result) {
    while ($row = $staff_result->fetch_assoc()) {
        $staff_members[] = $row;
    }
}

// Fetch salaries
$salaries = [];
if ($current_term) {
    $salaries_query = "SELECT s.*, st.fname, st.lname, st.role, t.term_name
                      FROM salaries s
                      JOIN staff st ON s.staff_id = st.staff_id
                      JOIN terms t ON s.term_id = t.term_id
                      WHERE s.term_id = ?
                      ORDER BY s.status, st.lname, st.fname";
    $salaries_stmt = $conn->prepare($salaries_query);
    if ($salaries_stmt) {
        $salaries_stmt->bind_param("i", $current_term['term_id']);
        $salaries_stmt->execute();
        $salaries_result = $salaries_stmt->get_result();
        while ($row = $salaries_result->fetch_assoc()) {
            $salaries[] = $row;
        }
        $salaries_stmt->close();
    }
}

// Get financial summaries if term is selected
$selected_term_id = isset($_GET['term_id']) ? intval($_GET['term_id']) : ($current_term['term_id'] ?? null);
$term_summary = null;
$class_summaries = [];

if ($selected_term_id) {
    $term_summary = getTermFinancialSummary($conn, $selected_term_id);
    
    // Get summary for each class
    foreach ($classes as $class) {
        $class_summaries[$class['class_id']] = getClassFinancialSummary($conn, $class['class_id'], $selected_term_id);
    }
}

// DO NOT close the connection here - it will be used in the HTML section below
// $conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="stylesheet" href="assets/css/site.css">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Finance Management - School Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <!-- Chart.js for graphs -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            /* colors aligned with login gradient (#3a7bd5 -> #00d2ff) */
            --primary-color: #3a7bd5;
            --secondary-color: #00d2ff;
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

        .nav-tabs .nav-link {
            color: var(--primary-color);
            border: none;
            font-weight: 500;
        }

        .nav-tabs .nav-link.active {
            color: var(--primary-color);
            border-bottom: 3px solid var(--primary-color);
            background-color: transparent;
        }

        .card {
            border-radius: 12px;
            border: none;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }

        .card-header {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-bottom: 1px solid #dee2e6;
            font-weight: 600;
            color: var(--primary-color);
        }

        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .btn-primary:hover {
            background-color: var(--secondary-color);
            border-color: var(--secondary-color);
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 0.9rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .table th {
            background-color: #f8f9fa;
            border-bottom: 2px solid #dee2e6;
            font-weight: 600;
            color: var(--primary-color);
        }

        .badge-paid {
            background-color: #2ecc71;
            color: white;
        }

        .badge-pending {
            background-color: #f39c12;
            color: white;
        }

        .badge-partial {
            background-color: #3498db;
            color: white;
        }

        .progress {
            height: 8px;
            border-radius: 4px;
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

        .form-control, .form-select {
            border-radius: 8px;
            border: 1px solid #dee2e6;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(67, 97, 238, 0.25);
        }

        .alert {
            border-radius: 8px;
            border: none;
        }

        .sync-status {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .sync-success {
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }

        .sync-warning {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
        }

        .sync-error {
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }

        .term-selector {
            background-color:  #7f93e9bb;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 2fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .summary-item {
            background: white;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
            border-left: 4px solid var(--primary-color);
        }

        .summary-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-color);
        }

        .summary-label {
            font-size: 0.85rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        #syncProgress {
            transition: all 0.3s ease;
        }

        @media (max-width: 768px) {
            .summary-grid {
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            }
            
            .stat-value {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <header class="dashboard-header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center flex-wrap">
                <div>
                    <h1><i class="bi bi-cash-stack"></i> Finance Management</h1>
                    <p class="mb-0">Manage fees, payments, salaries, and SchoolPay integration</p>
                </div>
                <div class="mt-2 mt-md-0">
                    <a href="admin_dashboard.php" class="btn btn-outline-light btn-sm">
                        <i class="bi bi-arrow-left"></i> Back to Dashboard
                    </a>
                </div>
            </div>
        </div>
    </header>

    <div class="container">
        <!-- Display messages -->
        <?php if (isset($_SESSION['message'])): ?>
            <div class="alert alert-<?php echo $_SESSION['message']['type']; ?> alert-dismissible fade show">
                <?php echo $_SESSION['message']['text']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['message']); ?>
        <?php endif; ?>

        <!-- Display sync results -->
        <?php if (isset($_SESSION['sync_result'])): ?>
            <?php $result = $_SESSION['sync_result']; ?>
            <div class="sync-status <?php echo isset($result['error']) ? 'sync-error' : 'sync-success'; ?>">
                <h5><i class="bi bi-sync"></i> Sync Results</h5>
                <?php if (isset($result['error'])): ?>
                    <p><strong>Error:</strong> <?php echo htmlspecialchars($result['error']); ?></p>
                <?php else: ?>
                    <?php if (isset($result['term_name'])): ?>
                        <p><strong>Term:</strong> <?php echo htmlspecialchars($result['term_name']); ?></p>
                    <?php endif; ?>
                    <p><strong>Date Range:</strong> <?php echo htmlspecialchars($result['date_range']); ?></p>
                    <?php if (isset($result['days_in_term'])): ?>
                        <p><strong>Days in Term:</strong> <?php echo $result['days_in_term']; ?></p>
                    <?php endif; ?>
                    <?php if (isset($result['days_back'])): ?>
                        <p><strong>Days Back:</strong> <?php echo $result['days_back']; ?></p>
                    <?php endif; ?>
                    <p><strong>Total Found:</strong> <?php echo $result['total_found']; ?> transactions</p>
                    <p><strong>Saved:</strong> <?php echo $result['saved']; ?> transactions</p>
                    <p><strong>Duplicates Skipped:</strong> <?php echo $result['duplicates']; ?> transactions</p>
                    <p><strong>Errors:</strong> <?php echo $result['errors']; ?> transactions</p>
                    <p><strong>Success Rate:</strong> <?php echo $result['success_rate']; ?>%</p>
                <?php endif; ?>
            </div>
            <?php unset($_SESSION['sync_result']); ?>
        <?php endif; ?>



        
        <!-- Sync Progress Indicator -->
        <div id="syncProgress" style="display: none;">
            <div class="alert alert-info">
                <h5><i class="bi bi-sync"></i> Sync in Progress</h5>
                <div class="progress" style="height: 20px;">
                    <div id="progressBar" class="progress-bar progress-bar-striped progress-bar-animated" 
                         role="progressbar" style="width: 0%;">0%</div>
                </div>
                <p id="progressText" class="mt-2">Initializing...</p>
                <p id="progressDetails" class="small text-muted"></p>
            </div>
        </div>

        <!-- Term Information -->
        <div class="term-selector">
            <div class="row">
                <div class="col-md-6">
                    <h5>Term Information</h5>
                    <?php if ($current_term): ?>
                        <p><strong>Current Term:</strong> <?php echo htmlspecialchars($current_term['term_name']); ?> 
                        (<?php echo htmlspecialchars($current_term['academic_year']); ?>)</p>
                        <p><strong>Date Range:</strong> <?php echo date('M d, Y', strtotime($current_term['start_date'])); ?> 
                        to <?php echo date('M d, Y', strtotime($current_term['end_date'])); ?></p>
                    <?php else: ?>
                        <p class="text-danger"><strong>No active term found. Please set an active term in the system.</strong></p>
                    <?php endif; ?>
                    
                    <?php if ($previous_term): ?>
                        <p><strong>Previous Term:</strong> <?php echo htmlspecialchars($previous_term['term_name']); ?> 
                        (<?php echo htmlspecialchars($previous_term['academic_year']); ?>)</p>
                    <?php endif; ?>
                </div>
                <div class="col-md-6">
                    <form method="POST" id="syncForm" class="row g-2">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <div class="col-md-4">
                            <label class="form-label">Batch Size (Days)</label>
                            <select class="form-select form-select-sm" name="batch_size" id="batch_size">
                                <option value="5">5 days</option>
                                <option value="10" selected>10 days</option>
                                <option value="15">15 days</option>
                                <option value="20">20 days</option>
                                <option value="30">30 days</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Current Term (Days Back)</label>
                            <select class="form-select form-select-sm" name="days_back" id="days_back">
                                <option value="1">1 day</option>
                                <option value="3">3 days</option>
                                <option value="7" selected>7 days</option>
                                <option value="14">14 days</option>
                                <option value="30">30 days</option>
                            </select>
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <div class="d-flex gap-2">
                                <?php if ($previous_term): ?>
                                    <button type="button" class="btn btn-warning btn-sm" onclick="startSyncWithProgress('previous')">
                                        <i class="bi bi-cloud-download"></i> Sync Previous Term
                                    </button>
                                <?php endif; ?>
                                
                                <?php if ($current_term): ?>
                                    <button type="button" class="btn btn-success btn-sm" onclick="startSyncWithProgress('current')">
                                        <i class="bi bi-cloud-arrow-down"></i> Sync Current Term
                                    </button>
                                <?php endif; ?>
                                
                                <!-- Add this sync students button -->
                                <button type="button" class="btn btn-info btn-sm sync-students-btn" onclick="syncStudents(event)">
                                    <i class="bi bi-people-fill"></i> Sync Students
                                </button>
                            </div>

                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Navigation Tabs -->
        <ul class="nav nav-tabs mb-4" id="financeTabs">
            <li class="nav-item">
                <a class="nav-link active" data-bs-toggle="tab" href="#dashboard">Dashboard</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#fees">Fees Structure</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#students">Student Balances</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#bursaries">Bursaries</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#salaries">Salaries</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#transactions">Transactions</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#reports">Reports</a>
            </li>
        </ul>

        <!-- Tab Content -->
        <div class="tab-content" id="financeTabContent">
            
            <!-- Dashboard Tab -->
            <div class="tab-pane fade show active" id="dashboard">
                <?php if ($term_summary): ?>
                    <div class="summary-grid">
                        <div class="summary-item">
                            <div class="summary-value"><?php echo number_format($term_summary['total_collections']); ?> UGX</div>
                            <div class="summary-label">Total Collections</div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-value"><?php echo number_format($term_summary['total_expected']); ?> UGX</div>
                            <div class="summary-label">Total Expected</div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-value"><?php echo number_format($term_summary['total_bursaries']); ?> UGX</div>
                            <div class="summary-label">Total Bursaries</div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-value"><?php echo count($term_summary['classes'] ?? []); ?></div>
                            <div class="summary-label">Classes</div>
                        </div>
                    </div>

                    <!-- Payment Methods Breakdown -->
                    <?php if (!empty($term_summary['payment_methods'])): ?>
                        <div class="card">
                            <div class="card-header">
                                <i class="bi bi-credit-card"></i> Payment Methods Breakdown
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <?php foreach ($term_summary['payment_methods'] as $method => $amount): ?>
                                        <div class="col-md-3 mb-3">
                                            <div class="stat-card">
                                                <div class="stat-value"><?php echo number_format($amount); ?> UGX</div>
                                                <div class="stat-label"><?php echo htmlspecialchars($method); ?></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <!-- Charts for payment method and daily collections -->
                        <div class="row mt-4">
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header"><i class="bi bi-pie-chart"></i> Payment Method Chart</div>
                                    <div class="card-body">
                                        <canvas id="paymentMethodChart"></canvas>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header"><i class="bi bi-bar-chart"></i> Daily Collections</div>
                                    <div class="card-body">
                                        <canvas id="dailyCollectionChart"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Class-wise Summary -->
                    <?php if (!empty($term_summary['classes'])): ?>
                        <div class="card">
                            <div class="card-header">
                                <i class="bi bi-building"></i> Class-wise Financial Summary
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Class</th>
                                                <th>Students</th>
                                                <th>Fee Amount</th>
                                                <th>Total Expected</th>
                                                <th>Total Paid</th>
                                                <th>Fully Paid</th>
                                                <th>Partially Paid</th>
                                                <th>Not Paid</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($term_summary['classes'] as $class): ?>
                                                <tr style="cursor: pointer;" onclick="window.location='finance.php?tab=students&class_id=<?php echo $class['class_id']; ?>'">
                                                    <td><?php echo htmlspecialchars($class['class_name']); ?></td>
                                                    <td><?php echo $class['student_count']; ?></td>
                                                    <td><?php echo number_format($class['fee_amount']); ?> UGX</td>
                                                    <td><?php echo number_format($class['total_expected']); ?> UGX</td>
                                                    <td><?php echo number_format($class['total_paid']); ?> UGX</td>
                                                    <td><span class="badge bg-success"><?php echo $class['fully_paid']; ?></span></td>
                                                    <td><span class="badge bg-warning"><?php echo $class['partially_paid']; ?></span></td>
                                                    <td><span class="badge bg-danger"><?php echo $class['not_paid']; ?></span></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> Select a term to view financial summary.
                    </div>
                <?php endif; ?>
            </div>

            <!-- Fees Structure Tab -->
            <div class="tab-pane fade" id="fees">
                <div class="row">
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-header">
                                <i class="bi bi-plus-circle"></i> Add/Edit Fees Structure
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="action" value="save_fees_structure">
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Class</label>
                                        <select class="form-select" name="class_id" required>
                                            <option value="">Select Class...</option>
                                            <?php foreach ($classes as $class): ?>
                                                <option value="<?php echo $class['class_id']; ?>">
                                                    <?php echo htmlspecialchars($class['class_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Term</label>
                                        <select class="form-select" name="term_id" required>
                                            <option value="">Select Term...</option>
                                            <?php foreach ($all_terms as $term): ?>
                                                <option value="<?php echo $term['term_id']; ?>">
                                                    <?php echo htmlspecialchars($term['term_name'] . ' ' . $term['academic_year']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Total Amount (UGX)</label>
                                        <input type="number" class="form-control" name="total_amount" required step="0.01" min="0">
                                    </div>
                                    
                                    <div class="mb-3 form-check">
                                        <input type="checkbox" class="form-check-input" name="bursary_allowed" id="bursary_allowed" value="1" checked>
                                        <label class="form-check-label" for="bursary_allowed">Allow bursary for this class</label>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Description (Optional)</label>
                                        <textarea class="form-control" name="description" rows="3"></textarea>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="bi bi-save"></i> Save Fees Structure
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-8">
                        <div class="card">
                            <div class="card-header">
                                <i class="bi bi-list"></i> Current Fees Structure
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Class</th>
                                                <th>Term</th>
                                                <th>Amount (UGX)</th>
                                                <th>Bursary Allowed</th>
                                                <th>Last Updated</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            // Use the existing connection, don't create a new one
                                            $fees_query = "SELECT fs.*, c.class_name, t.term_name, t.academic_year 
                                                         FROM fees_structure fs
                                                         JOIN class c ON fs.class_id = c.class_id
                                                         JOIN terms t ON fs.term_id = t.term_id
                                                         ORDER BY t.start_date DESC, c.class_name";
                                            $fees_result = $conn->query($fees_query);
                                            
                                            if ($fees_result && $fees_result->num_rows > 0) {
                                                while ($row = $fees_result->fetch_assoc()): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($row['class_name']); ?></td>
                                                        <td><?php echo htmlspecialchars($row['term_name'] . ' ' . $row['academic_year']); ?></td>
                                                        <td><?php echo number_format($row['total_amount']); ?></td>
                                                        <td>
                                                            <?php if ($row['bursary_allowed']): ?>
                                                                <span class="badge bg-success">Yes</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-secondary">No</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><?php echo date('M d, Y', strtotime($row['updated_at'] ?? $row['created_at'])); ?></td>
                                                    </tr>
                                                <?php endwhile;
                                            } else {
                                                echo '<tr><td colspan="5" class="text-center">No fees structure found</td></tr>';
                                            }
                                            ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Student Balances Tab -->
            <div class="tab-pane fade" id="students">
                <div class="card">
                    <div class="card-header">
                        <i class="bi bi-people"></i> Student Fee Balances
                        <div class="float-end">
                            <select class="form-select form-select-sm w-auto d-inline" onchange="filterStudents(this.value)">
                                <option value="">All Classes</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo $class['class_id']; ?>">
                                        <?php echo htmlspecialchars($class['class_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover" id="studentsTable">
                                <thead>
                                    <tr>
                                        <th>Student Name</th>
                                        <th>Class</th>
                                        <th>Registration</th>
                                        <th>Total Fees</th>
                                        <th>Bursary</th>
                                        <th>Net Fees</th>
                                        <th>Paid</th>
                                        <th>Balance</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    // Use the existing connection
                                    foreach ($students as $student): 
                                        if ($selected_term_id) {
                                            $balance_info = getStudentBalance($conn, $student['student_id'], $selected_term_id);
                                        } else {
                                            $balance_info = ['total_fees' => 0, 'bursary' => 0, 'net_fees' => 0, 'paid' => 0, 'balance' => 0, 'percentage_paid' => 0];
                                        }
                                    ?>
                                        <tr data-class="<?php echo $student['class_id']; ?>">
                                            <td><?php echo htmlspecialchars($student['fname'] . ' ' . $student['lname']); ?></td>
                                            <td><?php echo htmlspecialchars($student['class_name']); ?></td>
                                            <td><?php echo htmlspecialchars($student['registration_number']); ?></td>
                                            <td><?php echo number_format($balance_info['total_fees']); ?> UGX</td>
                                            <td><?php echo number_format($balance_info['bursary']); ?> UGX</td>
                                            <td><?php echo number_format($balance_info['net_fees']); ?> UGX</td>
                                            <td><?php echo number_format($balance_info['paid']); ?> UGX</td>
                                            <td>
                                                <strong class="<?php echo $balance_info['balance'] > 0 ? 'text-danger' : 'text-success'; ?>">
                                                    <?php echo number_format($balance_info['balance']); ?> UGX
                                                </strong>
                                            </td>
                                            <td>
                                                <?php if ($balance_info['balance'] <= 0): ?>
                                                    <span class="badge bg-success">Fully Paid</span>
                                                <?php elseif ($balance_info['paid'] > 0): ?>
                                                    <span class="badge bg-warning">Partially Paid (<?php echo $balance_info['percentage_paid']; ?>%)</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger">Not Paid</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-outline-info" onclick="viewStudentTransactions(<?php echo $student['student_id']; ?>)"> <!-- modal -->
                                                    <i class="bi bi-receipt"></i>
                                                </button>
                                                <a href="view_student_transactions.php?student_id=<?php echo $student['student_id']; ?>" target="_blank" class="btn btn-sm btn-outline-primary ms-1" title="Open in new tab">
                                                    <i class="bi bi-box-arrow-up-right"></i>
                                                </a>
                                                <button class="btn btn-sm btn-outline-warning" onclick="awardBursary(<?php echo $student['student_id']; ?>, '<?php echo htmlspecialchars($student['fname'] . ' ' . $student['lname']); ?>')">
                                                    <i class="bi bi-gift"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bursaries Tab -->
            <div class="tab-pane fade" id="bursaries">
                <div class="row">
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-header">
                                <i class="bi bi-gift"></i> Award Bursary
                            </div>
                            <div class="card-body">
                                <form method="POST" id="bursaryForm">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="action" value="award_bursary">
                                    <input type="hidden" name="student_id" id="bursary_student_id">
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Student</label>
                                        <input type="text" class="form-control" id="bursary_student_name" readonly>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Term</label>
                                        <select class="form-select" name="term_id" required>
                                            <?php foreach ($all_terms as $term): ?>
                                                <option value="<?php echo $term['term_id']; ?>" <?php echo ($selected_term_id == $term['term_id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($term['term_name'] . ' ' . $term['academic_year']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Amount Awarded (UGX)</label>
                                        <input type="number" class="form-control" name="amount_awarded" required step="0.01" min="0">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Reason</label>
                                        <textarea class="form-control" name="reason" rows="3" required></textarea>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-warning w-100">
                                        <i class="bi bi-gift"></i> Award Bursary
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-8">
                        <div class="card">
                            <div class="card-header">
                                <i class="bi bi-list-check"></i> Awarded Bursaries
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Student</th>
                                                <th>Class</th>
                                                <th>Term</th>
                                                <th>Amount</th>
                                                <th>Reason</th>
                                                <th>Awarded Date</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            // Use the existing connection
                                            $bursaries_query = "SELECT b.*, s.fname, s.lname, c.class_name, t.term_name, t.academic_year 
                                                              FROM bursaries b
                                                              JOIN student s ON b.student_id = s.student_id
                                                              JOIN class c ON s.class_id = c.class_id
                                                              JOIN terms t ON b.term_id = t.term_id
                                                              ORDER BY b.awarded_date DESC";
                                            $bursaries_result = $conn->query($bursaries_query);
                                            
                                            if ($bursaries_result && $bursaries_result->num_rows > 0) {
                                                while ($row = $bursaries_result->fetch_assoc()): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($row['fname'] . ' ' . $row['lname']); ?></td>
                                                        <td><?php echo htmlspecialchars($row['class_name']); ?></td>
                                                        <td><?php echo htmlspecialchars($row['term_name'] . ' ' . $row['academic_year']); ?></td>
                                                        <td><?php echo number_format($row['amount_awarded']); ?> UGX</td>
                                                        <td><?php echo htmlspecialchars($row['reason']); ?></td>
                                                        <td><?php echo date('M d, Y', strtotime($row['awarded_date'])); ?></td>
                                                        <td>
                                                            <span class="badge bg-<?php echo $row['status'] == 'Active' ? 'success' : 'secondary'; ?>">
                                                                <?php echo $row['status']; ?>
                                                            </span>
                                                        </td>
                                                    </tr>
                                                <?php endwhile;
                                            } else {
                                                echo '<tr><td colspan="7" class="text-center">No bursaries found</td></tr>';
                                            }
                                            ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Salaries Tab -->
            <div class="tab-pane fade" id="salaries">
                <div class="row">
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-header">
                                <i class="bi bi-cash"></i> Add Salary Record
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="action" value="add_staff_salary">
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Staff Member</label>
                                        <select class="form-select" name="staff_id" required>
                                            <option value="">Select Staff...</option>
                                            <?php foreach ($staff_members as $staff): ?>
                                                <option value="<?php echo $staff['staff_id']; ?>">
                                                    <?php echo htmlspecialchars($staff['fname'] . ' ' . $staff['lname'] . ' - ' . $staff['role']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Staff Type</label>
                                        <select class="form-select" name="staff_type" required>
                                            <option value="Teaching">Teaching Staff</option>
                                            <option value="Non-Teaching">Non-Teaching Staff</option>
                                        </select>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Term</label>
                                        <select class="form-select" name="term_id" required>
                                            <?php foreach ($all_terms as $term): ?>
                                                <option value="<?php echo $term['term_id']; ?>">
                                                    <?php echo htmlspecialchars($term['term_name'] . ' ' . $term['academic_year']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Amount (UGX)</label>
                                        <input type="number" class="form-control" name="amount" required step="0.01" min="0">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Notes (Optional)</label>
                                        <textarea class="form-control" name="notes" rows="2"></textarea>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="bi bi-save"></i> Add Salary Record
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-8">
                        <div class="card">
                            <div class="card-header">
                                <i class="bi bi-list"></i> Salary Records
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Staff Name</th>
                                                <th>Role</th>
                                                <th>Term</th>
                                                <th>Amount</th>
                                                <th>Status</th>
                                                <th>Payment Date</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            // Use the existing connection
                                            foreach ($salaries as $salary): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($salary['fname'] . ' ' . $salary['lname']); ?></td>
                                                    <td><?php echo htmlspecialchars($salary['role']); ?></td>
                                                    <td><?php echo htmlspecialchars($salary['term_name']); ?></td>
                                                    <td><?php echo number_format($salary['amount']); ?> UGX</td>
                                                    <td>
                                                        <span class="badge bg-<?php 
                                                            switch($salary['status']) {
                                                                case 'Paid': echo 'success'; break;
                                                                case 'Pending': echo 'warning'; break;
                                                                case 'Partially Paid': echo 'info'; break;
                                                                default: echo 'secondary';
                                                            }
                                                        ?>">
                                                            <?php echo $salary['status']; ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php echo $salary['payment_date'] ? date('M d, Y', strtotime($salary['payment_date'])) : 'Not Paid'; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($salary['status'] !== 'Paid'): ?>
                                                            <button class="btn btn-sm btn-success" onclick="markSalaryPaid(<?php echo $salary['salary_id']; ?>)">
                                                                <i class="bi bi-check-circle"></i> Mark Paid
                                                            </button>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Transactions Tab -->
            <div class="tab-pane fade" id="transactions">
                <div class="card">
                    <div class="card-header">
                        <i class="bi bi-receipt"></i> Recent Transactions
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Date & Time</th>
                                        <th>Student</th>
                                        <th>Class</th>
                                        <th>Amount</th>
                                        <th>Payment Method</th>
                                        <th>Reference</th>
                                        <th>Source</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    // Use the existing connection
                                    $transactions_query = "SELECT ft.*, s.fname, s.lname, c.class_name 
                                                         FROM finance_transactions ft
                                                         LEFT JOIN student s ON ft.student_id = s.student_id
                                                         LEFT JOIN class c ON s.class_id = c.class_id
                                                         ORDER BY ft.transaction_date DESC 
                                                         LIMIT 50";
                                    $transactions_result = $conn->query($transactions_query);
                                    
                                    if ($transactions_result && $transactions_result->num_rows > 0) {
                                        while ($row = $transactions_result->fetch_assoc()): ?>
                                            <tr>
                                                <td><?php echo date('M d, Y H:i', strtotime($row['transaction_date'])); ?></td>
                                                <td>
                                                    <?php if ($row['student_id'] && $row['fname']): ?>
                                                        <?php echo htmlspecialchars($row['fname'] . ' ' . $row['lname']); ?>
                                                    <?php else: ?>
                                                        <?php echo htmlspecialchars($row['student_name'] ?? 'Unknown'); ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($row['class_name'] ?? $row['student_class'] ?? 'Unknown'); ?></td>
                                                <td><?php echo number_format($row['amount']); ?> UGX</td>
                                                <td><?php echo htmlspecialchars($row['payment_method'] ?? 'Unknown'); ?></td>
                                                <td><code><?php echo htmlspecialchars(substr($row['transaction_reference'], 0, 20)); ?></code></td>
                                                <td>
                                                    <span class="badge bg-<?php echo $row['source'] == 'SchoolPay' ? 'info' : 'secondary'; ?>">
                                                        <?php echo $row['source']; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php echo $row['status'] == 'Completed' ? 'success' : ($row['status'] == 'Pending' ? 'warning' : 'danger'); ?>">
                                                        <?php echo $row['status']; ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endwhile;
                                    } else {
                                        echo '<tr><td colspan="8" class="text-center">No transactions found</td></tr>';
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Reports Tab -->
            <div class="tab-pane fade" id="reports">
                <div class="row">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <i class="bi bi-bar-chart"></i> Generate Reports
                            </div>
                            <div class="card-body">
                                <div class="list-group">
                                    <a href="javascript:void(0)" onclick="generateReport('class_summary')" class="list-group-item list-group-item-action">
                                        <i class="bi bi-building"></i> Class-wise Financial Summary
                                    </a>
                                    <a href="javascript:void(0)" onclick="generateReport('term_summary')" class="list-group-item list-group-item-action">
                                        <i class="bi bi-calendar"></i> Term Financial Report
                                    </a>
                                    <a href="javascript:void(0)" onclick="generateReport('outstanding_balances')" class="list-group-item list-group-item-action">
                                        <i class="bi bi-exclamation-triangle"></i> Outstanding Balances Report
                                    </a>
                                    <a href="javascript:void(0)" onclick="generateReport('bursary_report')" class="list-group-item list-group-item-action">
                                        <i class="bi bi-gift"></i> Bursary Awards Report
                                    </a>
                                    <a href="javascript:void(0)" onclick="generateReport('salary_report')" class="list-group-item list-group-item-action">
                                        <i class="bi bi-cash-stack"></i> Salary Payments Report
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <i class="bi bi-download"></i> Export Data
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label class="form-label">Select Term</label>
                                    <select class="form-select" id="export_term">
                                        <?php foreach ($all_terms as $term): ?>
                                            <option value="<?php echo $term['term_id']; ?>">
                                                <?php echo htmlspecialchars($term['term_name'] . ' ' . $term['academic_year']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Export Format</label>
                                    <select class="form-select" id="export_format">
                                        <option value="csv">CSV (Excel)</option>
                                        <option value="pdf">PDF Document</option>
                                        <option value="json">JSON Data</option>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Data to Export</label>
                                    <select class="form-select" id="export_data">
                                        <option value="transactions">Transactions</option>
                                        <option value="student_balances">Student Balances</option>
                                        <option value="bursaries">Bursaries</option>
                                        <option value="salaries">Salaries</option>
                                        <option value="fees_structure">Fees Structure</option>
                                    </select>
                                </div>
                                
                                <button class="btn btn-primary w-100" onclick="exportData()">
                                    <i class="bi bi-download"></i> Export Data
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Report Preview Area -->
                <div class="card mt-4" id="reportPreview" style="display: none;">
                    <div class="card-header">
                        <i class="bi bi-eye"></i> Report Preview
                        <div class="float-end">
                            <button class="btn btn-sm btn-primary" onclick="printReport()">
                                <i class="bi bi-printer"></i> Print
                            </button>
                            <button class="btn btn-sm btn-success" onclick="downloadReport()">
                                <i class="bi bi-download"></i> Download
                            </button>
                        </div>
                    </div>
                    <div class="card-body" id="reportContent">
                        <!-- Report content will be loaded here -->
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modals -->
    
    <!-- Mark Salary Paid Modal -->
    <div class="modal fade" id="markPaidModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Mark Salary as Paid</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="markPaidForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="mark_salary_paid">
                    <input type="hidden" name="salary_id" id="modal_salary_id">
                    
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Payment Date</label>
                            <input type="date" class="form-control" name="payment_date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Payment Method</label>
                            <select class="form-select" name="payment_method" required>
                                <option value="Bank Transfer">Bank Transfer</option>
                                <option value="Mobile Money">Mobile Money</option>
                                <option value="Cash">Cash</option>
                                <option value="Check">Check</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Payment Reference</label>
                            <input type="text" class="form-control" name="payment_reference" placeholder="e.g., Bank Transfer Ref #">
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Mark as Paid</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Student Transactions Modal -->
    <div class="modal fade" id="studentTransactionsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Student Transactions</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="studentTransactionsContent">
                    Loading...
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Tab functionality
        const urlParams = new URLSearchParams(window.location.search);
        const activeTab = urlParams.get('tab');
        if (activeTab) {
            const tabTrigger = document.querySelector(`[data-bs-target="#${activeTab}"]`);
            if (tabTrigger) {
                new bootstrap.Tab(tabTrigger).show();
            }
        }

        // Filter students by class
        function filterStudents(classId) {
            const rows = document.querySelectorAll('#studentsTable tbody tr');
            rows.forEach(row => {
                if (!classId || row.getAttribute('data-class') == classId) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        // Award bursary
        function awardBursary(studentId, studentName) {
            document.getElementById('bursary_student_id').value = studentId;
            document.getElementById('bursary_student_name').value = studentName;
            
            // Switch to bursaries tab
            new bootstrap.Tab(document.querySelector('[data-bs-target="#bursaries"]')).show();
            
            // Scroll to form
            setTimeout(() => {
                document.getElementById('bursaryForm').scrollIntoView({ behavior: 'smooth' });
            }, 100);
        }

        // Mark salary as paid
        function markSalaryPaid(salaryId) {
            document.getElementById('modal_salary_id').value = salaryId;
            const modal = new bootstrap.Modal(document.getElementById('markPaidModal'));
            modal.show();
        }

        // View student transactions
        function viewStudentTransactions(studentId) {
            fetch(`get_student_transactions.php?student_id=${studentId}`)
                .then(response => {
                    if (!response.ok) throw new Error('Network response was not ok');
                    return response.text();
                })
                .then(data => {
                    document.getElementById('studentTransactionsContent').innerHTML = data;
                    const modal = new bootstrap.Modal(document.getElementById('studentTransactionsModal'));
                    modal.show();
                })
                .catch(error => {
                    document.getElementById('studentTransactionsContent').innerHTML = 
                        '<div class="alert alert-danger">Error loading transactions: ' + error.message + '</div>';
                    const modal = new bootstrap.Modal(document.getElementById('studentTransactionsModal'));
                    modal.show();
                });
        }

        // Sync with progress indicator
        function startSyncWithProgress(type) {
            const progressDiv = document.getElementById('syncProgress');
            const progressBar = document.getElementById('progressBar');
            const progressText = document.getElementById('progressText');
            const progressDetails = document.getElementById('progressDetails');
            
            progressDiv.style.display = 'block';
            progressBar.style.width = '0%';
            progressBar.textContent = '0%';
            progressText.textContent = 'Starting sync...';
            progressDetails.textContent = '';
            
            // Get form values
            const batchSize = document.getElementById('batch_size').value;
            const daysBack = document.getElementById('days_back').value;
            
            // Create form for submission
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = type === 'previous' ? 'sync_previous_term' : 'sync_current_term';
            
            const batchInput = document.createElement('input');
            batchInput.type = 'hidden';
            batchInput.name = 'batch_size';
            batchInput.value = batchSize;
            
            form.appendChild(actionInput);
            form.appendChild(batchInput);
            
            if (type === 'current') {
                const daysInput = document.createElement('input');
                daysInput.type = 'hidden';
                daysInput.name = 'days_back';
                daysInput.value = daysBack;
                form.appendChild(daysInput);
            }
            
            document.body.appendChild(form);
            
            // Show progress animation
            let progress = 0;
            const progressInterval = setInterval(() => {
                progress += 1;
                if (progress <= 90) {
                    progressBar.style.width = progress + '%';
                    progressBar.textContent = progress + '%';
                    
                    if (progress < 30) {
                        progressText.textContent = 'Initializing sync...';
                    } else if (progress < 60) {
                        progressText.textContent = 'Fetching transactions...';
                    } else {
                        progressText.textContent = 'Saving to database...';
                    }
                    
                    progressDetails.textContent = `Processed approximately ${Math.round(progress/10)} days...`;
                }
            }, 500);
            
            // Submit form
            form.submit();
            
            // Clear interval after 2 minutes (safety)
            setTimeout(() => {
                clearInterval(progressInterval);
            }, 120000);
        }

        // Generate reports
        function generateReport(type) {
            const termId = <?php echo $selected_term_id ?: 'null'; ?>;
            
            fetch(`generate_report.php?type=${type}&term_id=${termId}`)
                .then(response => {
                    if (!response.ok) throw new Error('Network response was not ok');
                    return response.text();
                })
                .then(data => {
                    document.getElementById('reportContent').innerHTML = data;
                    document.getElementById('reportPreview').style.display = 'block';
                    document.getElementById('reportPreview').scrollIntoView({ behavior: 'smooth' });
                })
                .catch(error => {
                    document.getElementById('reportContent').innerHTML = 
                        '<div class="alert alert-danger">Error generating report: ' + error.message + '</div>';
                    document.getElementById('reportPreview').style.display = 'block';
                    document.getElementById('reportPreview').scrollIntoView({ behavior: 'smooth' });
                });
        }

        // Export data
        function exportData() {
            const termId = document.getElementById('export_term').value;
            const format = document.getElementById('export_format').value;
            const dataType = document.getElementById('export_data').value;
            
            const url = `export_data.php?term_id=${termId}&format=${format}&data=${dataType}`;
            window.open(url, '_blank');
        }

        // Print report with header banner matching school colors
        function printReport() {
            const content = document.getElementById('reportContent').innerHTML;
            const printWindow = window.open('', '_blank');
            
            printWindow.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Finance Report</title>
                    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
                    <style>
                        body { font-family: 'Poppins', sans-serif; margin: 0; }
                        .report-header {
                            background: linear-gradient(135deg, #3a7bd5, #00d2ff);
                            color: white;
                            padding: 20px;
                            text-align: center;
                        }
                        .report-content { margin: 20px; }
                        @media print {
                            .no-print { display: none !important; }
                            body { margin: 0; }
                        }
                    </style>
                </head>
                <body>
                    <div class="report-header"><h2>School Finance Report</h2></div>
                    <div class="report-content">
                        ${content}
                    </div>
                    <script>
                        window.onload = function() {
                            window.print();
                            setTimeout(() => { window.close(); }, 500);
                        }
                    <\/script>
                </body>
                </html>
            `);
            
            printWindow.document.close();
        }
        
        // initialize charts using data from PHP
        (function(){
            const pmLabels = <?php echo json_encode(array_keys($term_summary['payment_methods'] ?? [])); ?>;
            const pmData = <?php echo json_encode(array_values($term_summary['payment_methods'] ?? [])); ?>;
            if (pmLabels.length && document.getElementById('paymentMethodChart')) {
                new Chart(document.getElementById('paymentMethodChart').getContext('2d'), {
                    type: 'pie',
                    data: {
                        labels: pmLabels,
                        datasets: [{ data: pmData, backgroundColor: ['#3a7bd5','#00d2ff','#4cc9f0','#4895ef','#f8961e','#f72585'] }]
                    }
                });
            }
            const dailyLabels = <?php echo json_encode(array_keys($term_summary['daily_collections'] ?? [])); ?>;
            const dailyData = <?php echo json_encode(array_values($term_summary['daily_collections'] ?? [])); ?>;
            if (dailyLabels.length && document.getElementById('dailyCollectionChart')) {
                new Chart(document.getElementById('dailyCollectionChart').getContext('2d'), {
                    type: 'line',
                    data: {
                        labels: dailyLabels,
                        datasets: [{ label: 'Collections', data: dailyData, backgroundColor: 'rgba(58,123,213,0.2)', borderColor: '#3a7bd5', fill: true }]
                    },
                    options: { scales: { y: { beginAtZero: true } } }
                });
            }
        })();

        // trigger automatic daily sync (non‑blocking)
        fetch('sync_daily.php')
            .then(resp => resp.json())
            .then(data => console.log('Daily sync completed', data))
            .catch(err => console.error('Daily sync error', err));



            function syncStudents(event) {
                // Show loading state on button
                const button = event.target;
                const originalHTML = button.innerHTML;
                button.innerHTML = '<i class="bi bi-hourglass-split"></i> Syncing...';
                button.disabled = true;
                
                // Make AJAX call
                fetch('sync_daily.php?task=students')
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok: ' + response.status);
                    }
                    return response.json();
                })
                .then(data => {
                    // Reset button
                    button.innerHTML = originalHTML;
                    button.disabled = false;
                    
                    // Show alert based on result
                    if (data.status === 'success') {
                        alert('✅ ' + data.message + 
                            '\n\nStudents synced: ' + data.students_synced +
                            (data.stats ? '\nTotal students: ' + data.stats.total_students : ''));
                        
                        // Reload page to update stats if sync was successful
                        if (data.students_synced > 0) {
                            setTimeout(() => {
                                location.reload();
                            }, 1500);
                        }
                    } else {
                        alert('❌ ' + data.message);
                    }
                })
                .catch(error => {
                    // Reset button
                    button.innerHTML = originalHTML;
                    button.disabled = false;
                    
                    // Show error alert
                    alert('❌ Error: ' + error.message);
                    console.error('Sync error:', error);
                });
            }

        // Download report
        function downloadReport() {
            const content = document.getElementById('reportContent').innerHTML;
            const blob = new Blob([content], { type: 'text/html' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'finance-report-' + new Date().toISOString().split('T')[0] + '.html';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        }

        // Auto-refresh every 5 minutes
        setInterval(() => {
            if (document.visibilityState === 'visible') {
                console.log('Auto-refresh check...');
            }
        }, 300000);

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+S for sync
            if (e.ctrlKey && e.key === 's') {
                e.preventDefault();
                startSyncWithProgress('current');
            }
            
            // Ctrl+F for fees
            if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                new bootstrap.Tab(document.querySelector('[data-bs-target="#fees"]')).show();
            }
            
            // Ctrl+R for reports
            if (e.ctrlKey && e.key === 'r') {
                e.preventDefault();
                new bootstrap.Tab(document.querySelector('[data-bs-target="#reports"]')).show();
            }
        });
    </script>
    <script src="assets/js/site.js"></script>
</body>
</html>

<?php
// Close the connection at the very end of the file
if (isset($conn) && $conn) {
    $conn->close();
}
?>