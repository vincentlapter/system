<?php
session_start();
if (!isset($_SESSION['username'])) {
    header('Location: index.php');
    exit();
}

include 'config.php';

$student_id = intval($_GET['student_id'] ?? 0);

$query = "SELECT ft.*, t.term_name, t.academic_year 
          FROM finance_transactions ft
          JOIN terms t ON ft.term_id = t.term_id
          WHERE ft.student_id = ?
          ORDER BY ft.transaction_date DESC";
          
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$result = $stmt->get_result();

$total_paid = 0;
?>

<div class="table-responsive">
    <table class="table table-sm">
        <thead>
            <tr>
                <th>Date</th>
                <th>Term</th>
                <th>Amount</th>
                <th>Method</th>
                <th>Reference</th>
                <th>Source</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($row = $result->fetch_assoc()): 
                $total_paid += $row['amount'];
            ?>
                <tr>
                    <td><?php echo date('M d, Y', strtotime($row['transaction_date'])); ?></td>
                    <td><?php echo htmlspecialchars($row['term_name'] . ' ' . $row['academic_year']); ?></td>
                    <td><?php echo number_format($row['amount']); ?> UGX</td>
                    <td><?php echo htmlspecialchars($row['payment_method']); ?></td>
                    <td><small><?php echo htmlspecialchars($row['transaction_reference']); ?></small></td>
                    <td>
                        <span class="badge bg-<?php echo $row['source'] == 'SchoolPay' ? 'info' : 'secondary'; ?>">
                            <?php echo $row['source']; ?>
                        </span>
                    </td>
                </tr>
            <?php endwhile; ?>
        </tbody>
        <tfoot>
            <tr class="table-primary">
                <td colspan="2"><strong>Total Paid</strong></td>
                <td><strong><?php echo number_format($total_paid); ?> UGX</strong></td>
                <td colspan="3"></td>
            </tr>
        </tfoot>
    </table>
</div>

<?php
$stmt->close();
$conn->close();
?>