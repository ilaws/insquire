<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
redirect('dashboard.php?lessons_paused=1');
requireTeacher();

$lessonId = filter_input(INPUT_GET, 'lesson_id', FILTER_VALIDATE_INT) ?: 0;
$stmt = $pdo->prepare(
    'SELECT la.id, l.title, c.name AS class_name, u.name AS student_name,
            lp.status, lp.score, lp.updated_at
     FROM lesson_assignments la
     INNER JOIN lessons l ON l.id = la.lesson_id
     INNER JOIN classes c ON c.id = la.class_id AND c.teacher_id = ?
     INNER JOIN class_members cm ON cm.class_id = c.id
     INNER JOIN users u ON u.id = cm.user_id AND u.role = "student"
     LEFT JOIN lesson_progress lp ON lp.lesson_assignment_id = la.id AND lp.student_id = u.id
     WHERE (? = 0 OR la.lesson_id = ?)
     ORDER BY c.name, l.title, u.name'
);
$stmt->execute([currentUserId(), $lessonId, $lessonId]);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
$title = 'Lesson Results';
require_once dirname(__DIR__) . '/includes/header.php';
?>
<h1>Lesson Results</h1>
<p>See student completion and scores for assigned interactive lessons.</p>
<div class="card">
<?php if (!$results): ?><p>No students or assigned lessons match this view.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>Class</th><th>Lesson</th><th>Student</th><th>Status</th><th>Score</th><th>Updated</th></tr></thead><tbody>
<?php foreach ($results as $result): ?><tr><td><?= e($result['class_name']) ?></td><td><?= e($result['title']) ?></td><td><?= e($result['student_name']) ?></td><td><?= e(ucfirst($result['status'] ?? 'not started')) ?></td><td><?= $result['score'] !== null ? e((string) $result['score']) . '%' : 'Not attempted' ?></td><td><?= $result['updated_at'] ? e(date('M j, Y g:i A', strtotime($result['updated_at']))) : '-' ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></div>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
