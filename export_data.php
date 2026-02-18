<?php
session_start();
if (!isset($_SESSION['username'])) {
    header('Location: index.php');
    exit();
}

include 'config.php';

$term_id = intval($_GET['term_id'] ?? 0);
$format = $_GET['format'] ?? 'csv';
$data_type = $_GET['data'] ?? 'transactions';

function exportCSV($data, $filename) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Add headers
    if (!empty($data)) {
        fputcsv($output, array_keys($data[0]));
    }
    
    // Add data
    foreach ($data as $row) {
        fputcsv($output, $row);
    }
    
    fclose($output);
}

function exportJSON($data, $filename) {
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . $filename . '.json"');
    
    echo json_encode($data, JSON_PRETTY_PRINT);
}

function exportPDF($data, $filename) {
    // Note: For PDF export, you would need a library like TCPDF or Dompdf
    // This is a simplified example
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '.pdf"');
    
    // Generate simple PDF content (in production, use a proper PDF library)
    echo "%PDF-1.4\n";
    echo "%¥±ë\n";
    echo "1 0 obj\n";
    echo "<< /Type /Catalog /Pages 2 0 R >>\n";
    echo "endobj\n";
    echo "2 0 obj\n";
    echo "<< /Type /Pages /Kids [3 0 R] /Count 1 >>\n";
    echo "endobj\n";
    echo "3 0 obj\n";
    echo "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R >>\n";
    echo "endobj\n";
    echo "4 0 obj\n";
    echo "<< /Length 44 >>\n";
    echo "stream\n";
    echo "BT\n";
    echo "/F1 12 Tf\n";
    echo "72 720 Td\n";
    echo "(Data Export) Tj\n";
    echo "ET\n";
    echo "endstream\n";
    echo "endobj\n";
    echo "xref\n";
    echo "0 5\n";
    echo "0000000000 65535 f \n";
    echo "0000000010 00000 n \n";
    echo "0000000053 00000 n \n";
    echo "0000000102 00000 n \n";
    echo "0000000173 00000 n \n";
    echo "trailer\n";
    echo "<< /Size 5 /Root 1 0 R >>\n";
    echo "startxref\n";
    echo "221\n";
    echo "%%EOF\n";
}

// Fetch data based on type
switch ($data_type) {
    case 'transactions':
        $query = "SELECT ft.transaction_date, s.fname, s.lname, c.class_name, 
                  ft.amount, ft.payment_method, ft.transaction_reference, ft.source, ft.status
                  FROM finance_transactions ft
                  LEFT JOIN student s ON ft.student_id = s.student_id
                  LEFT JOIN class c ON s.class_id = c.class_id
                  WHERE ft.term_id = ?
                  ORDER BY ft.transaction_date DESC";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $term_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        $filename = 'transactions-' . date('Y-m-d');
        break;
        
    case 'student_balances':
        // Get all students with their balances
        $query = "SELECT s.student_id, s.fname, s.lname, s.registration_number, c.class_name,
                  COALESCE(fs.total_amount, 0) as total_fees,
                  COALESCE(b.amount_awarded, 0) as bursary,
                  COALESCE(p.total_paid, 0) as paid_amount,
                  (COALESCE(fs.total_amount, 0) - COALESCE(b.amount_awarded, 0) - COALESCE(p.total_paid, 0)) as balance
                  FROM student s
                  JOIN class c ON s.class_id = c.class_id
                  LEFT JOIN fees_structure fs ON c.class_id = fs.class_id AND fs.term_id = ?
                  LEFT JOIN bursaries b ON s.student_id = b.student_id AND b.term_id = ?
                  LEFT JOIN (
                      SELECT student_id, SUM(amount) as total_paid 
                      FROM finance_transactions 
                      WHERE term_id = ? AND status = 'Completed'
                      GROUP BY student_id
                  ) p ON s.student_id = p.student_id
                  ORDER BY c.class_name, s.lname, s.fname";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("iii", $term_id, $term_id, $term_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        $filename = 'student-balances-' . date('Y-m-d');
        break;
        
    case 'bursaries':
        $query = "SELECT b.*, s.fname, s.lname, c.class_name, t.term_name, t.academic_year
                  FROM bursaries b
                  JOIN student s ON b.student_id = s.student_id
                  JOIN class c ON s.class_id = c.class_id
                  JOIN terms t ON b.term_id = t.term_id
                  WHERE b.term_id = ?
                  ORDER BY b.awarded_date DESC";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $term_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        $filename = 'bursaries-' . date('Y-m-d');
        break;
        
    case 'salaries':
        $query = "SELECT s.*, st.fname, st.lname, st.role, t.term_name, t.academic_year
                  FROM salaries s
                  JOIN staff st ON s.staff_id = st.staff_id
                  JOIN terms t ON s.term_id = t.term_id
                  WHERE s.term_id = ?
                  ORDER BY s.status, st.lname, st.fname";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $term_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        $filename = 'salaries-' . date('Y-m-d');
        break;
        
    case 'fees_structure':
        $query = "SELECT fs.*, c.class_name, t.term_name, t.academic_year
                  FROM fees_structure fs
                  JOIN class c ON fs.class_id = c.class_id
                  JOIN terms t ON fs.term_id = t.term_id
                  WHERE fs.term_id = ?
                  ORDER BY c.class_name";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $term_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        $filename = 'fees-structure-' . date('Y-m-d');
        break;
}

// Export based on format
if (!empty($data)) {
    switch ($format) {
        case 'csv':
            exportCSV($data, $filename);
            break;
        case 'json':
            exportJSON($data, $filename);
            break;
        case 'pdf':
            exportPDF($data, $filename);
            break;
    }
} else {
    echo "No data to export.";
}

$conn->close();
?>