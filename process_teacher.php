<?php
include 'config.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $fname = $_POST['fname'];
    $lname = $_POST['lname'];
    $dob = $_POST['dob'];
    $contact = $_POST['contact'];
    $gender = $_POST['gender'];
    $subject_id = $_POST['subject_id'];
    $subject = $_POST['subject'];

    $sql = "INSERT INTO teacher (fname, lname, dob, contact, gender, subject, subject_id) VALUES (?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);

    if ($stmt) {
        $stmt->bind_param("ssssssi", $fname, $lname, $dob, $contact, $gender, $subject, $subject_id);
        if ($stmt->execute()) {
            ?>
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>Success - School Management System</title>
                <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
                <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
                <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
                <style>
                    body {
                        font-family: 'Poppins', sans-serif;
                        background-color: #f5f7fa;
                        display: flex;
                        justify-content: center;
                        align-items: center;
                        height: 100vh;
                        margin: 0;
                    }
                    .success-card {
                        background: white;
                        border-radius: 15px;
                        box-shadow: 0 10px 30px rgba(0,0,0,0.1);
                        padding: 3rem;
                        text-align: center;
                        max-width: 500px;
                        width: 100%;
                    }
                    .success-icon {
                        font-size: 4rem;
                        color: #28a745;
                        margin-bottom: 1rem;
                    }
                    .btn-custom {
                        background: linear-gradient(135deg, #4361ee, #3a0ca3);
                        border: none;
                        border-radius: 8px;
                        padding: 12px 30px;
                        font-weight: 500;
                        color: white;
                        text-decoration: none;
                        display: inline-block;
                        transition: all 0.3s ease;
                    }
                    .btn-custom:hover {
                        transform: translateY(-2px);
                        box-shadow: 0 5px 15px rgba(67, 97, 238, 0.4);
                        color: white;
                    }
                </style>
            </head>
            <body>
                <div class="success-card">
                    <div class="success-icon">
                        <i class="bi bi-check-circle-fill"></i>
                    </div>
                    <h2 class="mb-3">Success!</h2>
                    <p class="mb-4">Teacher has been added successfully to the system.</p>
                    <a href="view_teachers.php" class="btn-custom">
                        <i class="bi bi-arrow-left me-2"></i>View Teachers
                    </a>
                </div>
                <script>
                    setTimeout(function() {
                        window.location.href = 'view_teachers.php';
                    }, 3000);
                </script>
            </body>
            </html>
            <?php
            exit();
        } else {
            echo "Error: " . $stmt->error;
        }
        $stmt->close();
    } else {
        echo "Error preparing statement: " . $conn->error;
    }
}

$conn->close();
?>
