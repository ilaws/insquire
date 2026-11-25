<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireStudent();

$stmt = $pdo->prepare(
    'SELECT a.id, a.title, a.due_at, c.name AS class_name,
            s.status, s.grade, s.submitted_at, s.teacher_feedback
     FROM submissions s
     INNER JOIN assignments a ON a.id = s.assignment_id
     INNER JOIN classes c ON c.id = a.class_id
     WHERE s.student_id = ? AND s.status IN ("submitted", "graded", "returned")
     ORDER BY COALESCE(s.submitted_at, s.updated_at) DESC'
);
$stmt->execute([currentUserId()]);
$assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
$title = 'Past Assignments';
require_once dirname(__DIR__) . '/includes/header.php';
?>
<div class="page-header"><div><h1>Past Assignments</h1><p>Submitted work, grades, and teacher feedback in one place.</p></div></div>
<div class="card">
<?php if (!$assignments): ?>
    <p>You have no submitted assignments yet.</p>
<?php else: ?>
    <div class="table-wrap"><table><thead><tr><th>Assignment</th><th>Class</th><th>Submitted</th><th>Status</th><th>Grade</th><th></th></tr></thead><tbody>
    <?php foreach ($assignments as $assignment): ?>
        <tr>
            <td><?= e($assignment['title']) ?></td>
            <td><?= e($assignment['class_name']) ?></td>
            <td><?= $assignment['submitted_at'] ? e(date('M j, Y', strtotime($assignment['submitted_at']))) : 'Not submitted' ?></td>
            <td><span class="status-badge status-<?= e($assignment['status']) ?>"><?= e(ucfirst($assignment['status'])) ?></span></td>
            <td><?= $assignment['grade'] !== null && $assignment['grade'] !== '' ? e($assignment['grade']) : 'Pending' ?></td>
            <td><a href="assignment.php?id=<?= (int) $assignment['id'] ?>">View</a></td>
        </tr>
    <?php endforeach; ?>
    </tbody></table></div>
<?php endif; ?>
</div>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
