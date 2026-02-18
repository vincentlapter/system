<?php
session_start();
if (!isset($_SESSION['username'])) {
    header('Location: index.php');
    exit();
}

include 'config.php';

$type = $_GET['type'] ?? '';
$term_id = intval($_GET['term_id'] ?? 0);

function getTermSummary($conn, $term_id) {
    $query = "SELECT t.*, 
              (SELECT COALESCE(SUM(ft.amount), 0) FROM finance_transactions ft WHERE ft.term_id = t.term_id) as total_collections,
              (SELECT COUNT(DISTINCT ft.student_id) FROM finance_transactions ft WHERE ft.term_id = t.term_id) as students_paid
              FROM terms t WHERE t.term_id = ?";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $term_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

function getClassSummary($conn, $term_id) {
    $query = "SELECT c.class_id, c.class_name,
              COUNT(DISTINCT s.student_id) as total_students,
              COALESCE(fs.total_amount, 0) as fee_amount,
              (SELECT COUNT(DISTINCT ft.student_id) FROM finance_transactions ft 
               JOIN student s2 ON ft.student_id = s2.student_id 
               WHERE s2.class_id = c.class_id AND ft.term_id = ?) as paid_students,
              (SELECT COALESCE(SUM(ft.amount), 0) FROM finance_transactions ft 
               JOIN student s2 ON ft.student_id = s2.student_id 
               WHERE s2.class_id = c.class_id AND ft.term_id = ?) as total_paid
              FROM class c
              LEFT JOIN student s ON c.class_id = s.class_id
              LEFT JOIN fees_structure fs ON c.class_id = fs.class_id AND fs.term_id = ?
              GROUP BY c.class_id, c.class_name, fs.total_amount
              ORDER BY c.class_name";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("iii", $term_id, $term_id, $term_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $classes = [];
    while ($row = $result->fetch_assoc()) {
        $classes[] = $row;
    }
    
    return $classes;
}

switch ($type) {
    case 'class_summary':
        $term = getTermSummary($conn, $term_id);
        $classes = getClassSummary($conn, $term_id);
        ?>
        <h4>Class-wise Financial Summary</h4>
        <p><strong>Term:</strong> <?php echo htmlspecialchars($term['term_name'] . ' ' . $term['academic_year']); ?></p>
        <p><strong>Generated:</strong> <?php echo date('F j, Y \a\t g:i A'); ?></p>
        
        <table class="table table-bordered">
            <thead class="table-light">
                <tr>
                    <th>Class</th>
                    <th>Total Students</th>
                    <th>Fee Amount</th>
                    <th>Students Paid</th>
                    <th>Total Paid</th>
                    <th>% Paid</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($classes as $class): 
                    $percentage = $class['total_students'] > 0 ? round(($class['paid_students'] / $class['total_students']) * 100, 2) : 0;
                ?>
                    <tr>
                        <td><?php echo htmlspecialchars($class['class_name']); ?></td>
                        <td><?php echo $class['total_students']; ?></td>
                        <td><?php echo number_format($class['fee_amount']); ?> UGX</td>
                        <td><?php echo $class['paid_students']; ?></td>
                        <td><?php echo number_format($class['total_paid']); ?> UGX</td>
                        <td>
                            <div class="progress" style="height: 20px;">
                                <div class="progress-bar" role="progressbar" style="width: <?php echo $percentage; ?>%;">
                                    <?php echo $percentage; ?>%
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
        break;
        
    case 'term_summary':
        $term = getTermSummary($conn, $term_id);
        ?>
        <h4>Term Financial Summary</h4>
        <div class="row">
            <div class="col-md-6">
                <div class="card mb-3">
                    <div class="card-body">
                        <h5 class="card-title">Term Information</h5>
                        <p><strong>Term Name:</strong> <?php echo htmlspecialchars($term['term_name']); ?></p>
                        <p><strong>Academic Year:</strong> <?php echo htmlspecialchars($term['academic_year']); ?></p>
                        <p><strong>Date Range:</strong> <?php echo date('M d, Y', strtotime($term['start_date'])); ?> to <?php echo date('M d, Y', strtotime($term['end_date'])); ?></p>
                        <p><strong>Status:</strong> <?php echo $term['status']; ?></p>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card mb-3">
                    <div class="card-body">
                        <h5 class="card-title">Financial Summary</h5>
                        <p><strong>Total Collections:</strong> <?php echo number_format($term['total_collections']); ?> UGX</p>
                        <p><strong>Students Paid:</strong> <?php echo $term['students_paid']; ?></p>
                        <p><strong>Generated:</strong> <?php echo date('F j, Y \a\t g:i A'); ?></p>
                    </div>
                </div>
            </div>
        </div>
        <?php
        break;
        
    default:
        echo '<div class="alert alert-info">Select a report type to generate.</div>';
        break;
}

$conn->close();
?>