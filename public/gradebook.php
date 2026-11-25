<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireTeacher();

$classId = filter_input(INPUT_GET, 'class_id', FILTER_VALIDATE_INT) ?: 0;
$teacherId = currentUserId();
$hasLatePenalty = hasTableColumn($pdo, 'assignments', 'late_penalty_percent');
$latePenaltySelect = $hasLatePenalty ? 'a.late_penalty_percent' : '0 AS late_penalty_percent';
$where = 'c.teacher_id = ?';
$params = [$teacherId];
if ($classId > 0) { $where .= ' AND c.id = ?'; $params[] = $classId; }
$stmt = $pdo->prepare(
    'SELECT a.id AS assignment_id, a.title, a.due_at, ' . $latePenaltySelect . ',
            c.id AS class_id, c.name AS class_name, u.name AS student_name,
            s.id AS submission_id, s.status, s.grade, s.submitted_at
     FROM assignments a
     INNER JOIN classes c ON c.id = a.class_id
     INNER JOIN class_members cm ON cm.class_id = c.id
     INNER JOIN users u ON u.id = cm.user_id AND u.role = "student"
     LEFT JOIN submissions s ON s.assignment_id = a.id AND s.student_id = u.id
     WHERE ' . $where . '
     ORDER BY c.name, a.due_at IS NULL, a.due_at, u.name'
);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$lessonWhere = 'c.teacher_id = ?';
$lessonParams = [$teacherId];
if ($classId > 0) { $lessonWhere .= ' AND c.id = ?'; $lessonParams[] = $classId; }
$lessonRows = [];
try {
    $lessonStmt = $pdo->prepare(
        'SELECT c.name AS class_name, l.title AS lesson_title, u.name AS student_name,
                lp.status, lp.score, la.due_at
         FROM lesson_assignments la
         INNER JOIN lessons l ON l.id = la.lesson_id
         INNER JOIN classes c ON c.id = la.class_id
         INNER JOIN class_members cm ON cm.class_id = c.id
         INNER JOIN users u ON u.id = cm.user_id AND u.role = "student"
         LEFT JOIN lesson_progress lp ON lp.lesson_assignment_id = la.id AND lp.student_id = u.id
         WHERE ' . $lessonWhere . '
         ORDER BY c.name, l.title, u.name'
    );
    $lessonStmt->execute($lessonParams);
    $lessonRows = $lessonStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $lessonRows = [];
}
$title = 'Gradebook';
require_once dirname(__DIR__) . '/includes/header.php';
?>
<div class="page-header"><div><h1>Gradebook</h1><p>Track every student, submission, and outstanding grade.</p></div></div>
<div class="card"><p><a class="button secondary" href="gradebook.php">All classes</a></p>
<?php if (!$rows): ?><p>No students or assignments match this view.</p><?php else: ?>
<div class="table-wrap"><table><thead><tr><th>Class</th><th>Assignment</th><th>Student</th><th>Due</th><th>Status</th><th>Grade</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $row):
    $late = $row['submitted_at'] && $row['due_at'] && strtotime($row['submitted_at']) > strtotime($row['due_at']);
    $status = $row['status'] ?: ($row['due_at'] && strtotime($row['due_at']) < time() ? 'missing' : 'not started');
    $displayGrade = $row['grade'];
    if ($late && is_numeric($displayGrade) && (float) $row['late_penalty_percent'] > 0) {
        $displayGrade = number_format((float) $displayGrade * (1 - ((float) $row['late_penalty_percent'] / 100)), 2);
    }
?>
<tr><td><?= e($row['class_name']) ?></td><td><?= e($row['title']) ?></td><td><?= e($row['student_name']) ?></td><td><?= $row['due_at'] ? e(date('M j', strtotime($row['due_at']))) : 'No due date' ?></td><td><span class="status-badge status-<?= e($status) ?>"><?= e(ucfirst($status)) ?><?= $late ? ' (late)' : '' ?></span></td><td><?= $displayGrade !== null && $displayGrade !== '' ? e((string) $displayGrade) : 'Pending' ?><?= $late && $row['late_penalty_percent'] > 0 ? ' <small>(penalty applied)</small>' : '' ?></td><td><?= $row['submission_id'] ? '<a href="teacher-submission.php?id=' . (int) $row['submission_id'] . '">Review</a>' : '-' ?></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php endif; ?></div>
<div class="card"><h2>Lesson Scores</h2>
<?php if (!$lessonRows): ?><p>No assigned lessons match this view.</p><?php else: ?>
<div class="table-wrap"><table><thead><tr><th>Class</th><th>Lesson</th><th>Student</th><th>Due</th><th>Status</th><th>Score</th></tr></thead><tbody>
<?php foreach ($lessonRows as $lr): $lrStatus = $lr['status'] ?: 'not_started'; ?>
<tr><td><?= e($lr['class_name']) ?></td><td><?= e($lr['lesson_title']) ?></td><td><?= e($lr['student_name']) ?></td><td><?= $lr['due_at'] ? e(date('M j', strtotime($lr['due_at']))) : 'No due date' ?></td><td><span class="status-badge status-<?= e(str_replace('_', '-', $lrStatus)) ?>"><?= e(ucfirst(str_replace('_', ' ', $lrStatus))) ?></span></td><td><?= $lr['score'] !== null ? e((string) $lr['score']) . '%' : 'Pending' ?></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php endif; ?></div>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
