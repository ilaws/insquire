<?php

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireStudent();

$stmt = $pdo->prepare(
    'SELECT c.id AS class_id, c.name AS class_name, ' . (hasTableColumn($pdo, 'classes', 'show_final_average') ? 'c.show_final_average' : '1 AS show_final_average') . ',
            a.title, a.due_at, s.grade, s.status
     FROM classes c
     INNER JOIN class_members cm ON cm.class_id = c.id AND cm.user_id = ?
     INNER JOIN assignments a ON a.class_id = c.id AND a.status IN ("published", "closed")
     LEFT JOIN submissions s ON s.assignment_id = a.id AND s.student_id = ?
     ORDER BY c.name, a.due_at IS NULL, a.due_at, a.title'
);
$stmt->execute([currentUserId(), currentUserId()]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$classes = [];
foreach ($rows as $row) {
    $classId = (int) $row['class_id'];
    if (!isset($classes[$classId])) {
        $classes[$classId] = ['name' => $row['class_name'], 'show_final_average' => (int) $row['show_final_average'], 'grades' => []];
    }
    $classes[$classId]['grades'][] = $row;
}

$title = 'Grades';
require_once dirname(__DIR__) . '/includes/header.php';
?>
<div class="page-header"><div><h1>Grades</h1><p>Review your assignment grades by class.</p></div></div>
<?php if (!$classes): ?><div class="card"><p>No graded assignments are available yet.</p></div><?php endif; ?>
<?php foreach ($classes as $class):
    $graded = array_values(array_filter($class['grades'], fn($grade) => is_numeric($grade['grade'])));
    $average = $graded ? round(array_sum(array_map(fn($grade) => (float) $grade['grade'], $graded)) / count($graded), 2) : null;
?>
<section class="card">
    <h2><?= e($class['name']) ?></h2>
    <?php if ($class['show_final_average'] && $average !== null): ?><p class="grade-average"><strong>Final average:</strong> <?= e((string) $average) ?>%</p><?php elseif (!$class['show_final_average']): ?><p class="muted">Your teacher has hidden the final average.</p><?php endif; ?>
    <div class="table-wrap"><table><thead><tr><th>Assignment</th><th>Due</th><th>Status</th><th>Grade</th></tr></thead><tbody>
    <?php foreach ($class['grades'] as $grade): ?><tr><td><?= e($grade['title']) ?></td><td><?= $grade['due_at'] ? e(date('M j, Y', strtotime($grade['due_at']))) : 'No due date' ?></td><td><?= e(ucfirst($grade['status'] ?? 'Not started')) ?></td><td><?= is_numeric($grade['grade']) ? e((string) $grade['grade']) . '%' : 'Pending' ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
</section>
<?php endforeach; ?>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>