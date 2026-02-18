<?php
session_start();
if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'Teacher') {
    header('Location: index.php');
    exit();
}

include 'config.php';

$teacher_id         = $_SESSION['linked_teacher_id'];
$selected_class_id  = $_GET['class_id']   ?? null;
$selected_subject_id= $_GET['subject_id'] ?? null;
$error   = "";
$success = "";

/* -----------------------------------------------------------
   1.  Build sidebar: classes & subjects the teacher teaches
   ----------------------------------------------------------- */
$sidebar_sql = "
    SELECT DISTINCT
           c.class_id,
           c.class_name,
           s.subject_id,
           s.subject_name
    FROM teacher_class   tc
    JOIN class           c  ON tc.class_id   = c.class_id
    JOIN teacher_subject ts ON tc.teacher_id = ts.teacher_id
    JOIN subject         s  ON ts.subject_id = s.subject_id
    WHERE tc.teacher_id = ?
    ORDER BY c.class_name, s.subject_name
";
$sidebar_stmt = $conn->prepare($sidebar_sql);
$assigned_cs  = [];                          // [$class_id]['class_name'|'subjects'=>[subject_id=>subject_name]]
if ($sidebar_stmt) {
    $sidebar_stmt->bind_param("i", $teacher_id);
    $sidebar_stmt->execute();
    $res = $sidebar_stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $assigned_cs[$row['class_id']]['class_name']                     = $row['class_name'];
        $assigned_cs[$row['class_id']]['subjects'][$row['subject_id']]   = $row['subject_name'];
    }
    $sidebar_stmt->close();
}

/* -----------------------------------------------------------
   2.  Students in the chosen class (if any)
   ----------------------------------------------------------- */
$students_in_class   = [];
$selected_class_name = '';
$selected_subject_name = '';

if ($selected_class_id && $selected_subject_id) {

    /* Double-check the teacher really teaches BOTH the class & subject */
    if (!isset($assigned_cs[$selected_class_id]['subjects'][$selected_subject_id])) {
        $error = "You’re not assigned to teach that class / subject.";
    } else {

        $selected_class_name   = $assigned_cs[$selected_class_id]['class_name'];
        $selected_subject_name = $assigned_cs[$selected_class_id]['subjects'][$selected_subject_id];

        $stu_sql = "
            SELECT student_id, fname, lname
            FROM student
            WHERE class_id = ?
            ORDER BY lname, fname";
        $stu_stmt = $conn->prepare($stu_sql);
        if ($stu_stmt) {
            $stu_stmt->bind_param("i", $selected_class_id);
            $stu_stmt->execute();
            $students_in_class = $stu_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stu_stmt->close();
        }
    }
}

/* -----------------------------------------------------------
   3.  Pull available examinations (for dropdown)
   ----------------------------------------------------------- */
$examinations = [];
$exam_res = $conn->query("SELECT exam_id, exam_name FROM examination ORDER BY exam_name");
while ($row = $exam_res->fetch_assoc()) {
    $examinations[$row['exam_id']] = $row['exam_name'];
}

/* -----------------------------------------------------------
   4.  Handle form submission (upload marks)
   ----------------------------------------------------------- */
if (isset($_POST['add_marks'])) {
    $exam_id = $_POST['exam_id'];

    if (!$selected_class_id || !$selected_subject_id || !$exam_id) {
        $error = "Please select a class, subject, and examination first.";
    } else {

        /* Re-fetch student IDs in the class */
        $stu_stmt = $conn->prepare("SELECT student_id FROM student WHERE class_id = ?");
        $stu_stmt->bind_param("i", $selected_class_id);
        $stu_stmt->execute();
        $stu_ids = $stu_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stu_stmt->close();

        foreach ($stu_ids as $sid_row) {
            $sid           = $sid_row['student_id'];
            $marks_field   = 'marks_' . $sid;
            $marks_entered = $_POST[$marks_field] ?? '';

            /* skip blanks */
            if ($marks_entered === '') continue;

            /* validate numeric 0-100 */
            if (!is_numeric($marks_entered) || $marks_entered < 0 || $marks_entered > 100) {
                $error = "Invalid marks for student ID $sid (0-100 only).";
                break;
            }

            /* Does exam-subject combo exist? (exam_subjects table) */
            $es_stmt = $conn->prepare("SELECT id FROM exam_subjects WHERE subject_id = ? AND exam_id = ?");
            $es_stmt->bind_param("ii", $selected_subject_id, $exam_id);
            $es_stmt->execute();
            $es_row = $es_stmt->get_result()->fetch_assoc();
            $es_stmt->close();

            if (!$es_row) {
                $error = "Exam/subject not set up in exam_subjects.";
                break;
            }
            $es_id = $es_row['id'];

            /* Prevent duplicate marks */
            $dup_stmt = $conn->prepare("
                  SELECT 1
                  FROM marks
                  WHERE student_id = ? AND id = ?");
            $dup_stmt->bind_param("ii", $sid, $es_id);
            $dup_stmt->execute();
            $dup = $dup_stmt->get_result()->num_rows;
            $dup_stmt->close();

            if ($dup) {
                $error = "Marks already exist for student $sid in this exam.";
                break;
            }

            /* Insert marks */
            $ins_stmt = $conn->prepare("
                 INSERT INTO marks (student_id, id, marks_obtained)
                 VALUES (?, ?, ?)");
            $ins_stmt->bind_param("iid", $sid, $es_id, $marks_entered);
            $ins_stmt->execute();
            if ($ins_stmt->affected_rows <= 0) {
                $error = "Failed inserting marks for student $sid.";
                break;
            }
            $ins_stmt->close();
        }

        if ($error === "") $success = "Marks uploaded successfully!";
    }
}

$conn->close();
?>

<!-- ---------------  HTML (unchanged except for sidebar loop) ---------------->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>Enter Marks</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" />
    <style>
        body{background:#f8f9fa}
        .sidebar{background:#343a40;color:#fff;min-height:100vh;padding-top:20px}
        .sidebar a{color:#fff;display:block;padding:10px 20px;text-decoration:none}
        .sidebar a:hover,.sidebar .active{background:#495057}
        .sidebar ul{list-style:none;padding-left:20px}
        .sidebar ul li a{color:#ccc;padding:8px 20px}
        .sidebar ul li a:hover{color:#fff}
        .marks-input{width:80px}
    </style>
</head>
<body>
<div class="container-fluid">
  <div class="row">
    <!-- sidebar -->
    <nav class="col-md-3 col-lg-2 sidebar">
        <a class="nav-link" href="teacher_dashboard.php">
           <i class="bi bi-arrow-left-square-fill"></i> Dashboard
        </a>
        <?php foreach ($assigned_cs as $cid => $cdata): ?>
            <a class="nav-link" data-bs-toggle="collapse" href="#c<?=$cid?>"
               aria-expanded="<?=($cid==$selected_class_id)?'true':'false'?>">
               <i class="bi bi-mortarboard-fill"></i> <?=htmlspecialchars($cdata['class_name'])?>
            </a>
            <ul class="collapse <?=($cid==$selected_class_id)?'show':''?>" id="c<?=$cid?>">
            <?php foreach ($cdata['subjects'] ?? [] as $sid => $sname): ?>
                <li>
                  <a class="nav-link <?=($cid==$selected_class_id && $sid==$selected_subject_id)?'active':''?>"
                     href="?class_id=<?=$cid?>&subject_id=<?=$sid?>">
                     <i class="bi bi-file-earmark-text-fill"></i> <?=htmlspecialchars($sname)?>
                  </a>
                </li>
            <?php endforeach;?>
            </ul>
        <?php endforeach;?>
    </nav>

    <!-- main -->
    <main class="col-md-9 col-lg-10 px-md-4">
        <h1 class="h2 mt-3">Enter Marks</h1>

        <?php if($error):   echo "<div class='alert alert-danger'>$error</div>";   endif;?>
        <?php if($success): echo "<div class='alert alert-success'>$success</div>"; endif;?>

        <?php if($selected_class_id && $selected_subject_id && $error===''): ?>
            <h3><?=htmlspecialchars("$selected_subject_name — $selected_class_name")?></h3>
            <form method="post" class="mt-4">
                <input type="hidden" name="class_id"   value="<?=$selected_class_id?>">
                <input type="hidden" name="subject_id" value="<?=$selected_subject_id?>">

                <div class="mb-3">
                    <label class="form-label">Examination</label>
                    <select class="form-select" name="exam_id" required>
                        <option value="">-- Select Examination --</option>
                        <?php foreach ($examinations as $eid=>$ename): ?>
                           <option value="<?=$eid?>"><?=htmlspecialchars($ename)?></option>
                        <?php endforeach;?>
                    </select>
                </div>

                <?php if($students_in_class): ?>
                    <table class="table table-bordered">
                      <thead><tr><th>ID</th><th>Name</th><th>Marks</th></tr></thead>
                      <tbody>
                      <?php foreach($students_in_class as $stu): ?>
                        <tr>
                          <td><?=$stu['student_id']?></td>
                          <td><?=htmlspecialchars("{$stu['lname']}, {$stu['fname']}")?></td>
                          <td><input type="number" name="marks_<?=$stu['student_id']?>"
                                     class="form-control form-control-sm marks-input"
                                     min="0" max="100"></td>
                        </tr>
                      <?php endforeach;?>
                      </tbody>
                    </table>
                    <button class="btn btn-primary" name="add_marks">Upload Marks</button>
                <?php else: ?>
                    <p>No students found in this class.</p>
                <?php endif;?>
            </form>
        <?php else: ?>
            <p>Select a class and subject from the sidebar to begin.</p>
        <?php endif;?>
    </main>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
