<?php

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
requireEducationHost();
if (currentAccountScope() !== 'education' || !in_array(currentUserRole(), ['student', 'teacher'], true)) {
    http_response_code(403);
    exit('Access denied.');
}

$classId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$classId) {
    http_response_code(404);
    exit('Class not found.');
}

$stmt = $pdo->prepare(
    'SELECT
        c.*,
        u.name AS teacher_name
     FROM classes c
     INNER JOIN users u ON u.id = c.teacher_id
     WHERE c.id = ?
     LIMIT 1'
);

$stmt->execute([$classId]);

$class = $stmt->fetch();

if (!$class) {
    http_response_code(404);
    exit('Class not found.');
}

if (currentUserRole() === 'student') {
    if (!studentInClass($pdo, $classId, currentUserId())) {
        http_response_code(403);
        exit('You are not enrolled in this class.');
    }
} else {
    $teacherAccess = $pdo->prepare(
        'SELECT 1 FROM classes c
         WHERE c.id = ? AND c.teacher_id = ?
           AND (c.institution_id IS NULL OR EXISTS (
               SELECT 1 FROM institution_members im
               WHERE im.institution_id = c.institution_id AND im.user_id = ?
                 AND im.role IN ("admin","teacher")
           ))
         LIMIT 1'
    );
    $teacherAccess->execute([$classId, currentUserId(), currentUserId()]);
    if (!$teacherAccess->fetchColumn()) {
        http_response_code(403);
        exit('Access denied.');
    }
}

$assignmentSql =
    'SELECT a.id, a.title, a.prompt, a.due_at, a.status, a.created_at, s.status AS submission_status, s.grade
    FROM assignments a
    LEFT JOIN submissions s ON s.assignment_id = a.id AND s.student_id = ?';
$assignmentParams = [currentUserRole() === 'student' ? currentUserId() : 0, $classId];

if (currentUserRole() === 'student') {
    $assignmentSql .=
                ' WHERE a.class_id = ?
                     AND a.status = "published"';
} else {
    $assignmentSql .= ' WHERE a.class_id = ?';
}

$assignmentSql .= ' ORDER BY a.created_at DESC';
$stmt = $pdo->prepare($assignmentSql);

$stmt->execute($assignmentParams);

$assignments = $stmt->fetchAll();

$title = $class['name'];

require_once dirname(__DIR__) . '/includes/header.php';
?>

<section class="page-header class-page-heading">
    <div>
        <p class="eyebrow">Class workspace</p>
        <h1><?= e($class['name']) ?></h1>
        <?php if (!empty($class['description'])): ?><p><?= e($class['description']) ?></p><?php endif; ?>
        <?php if (!empty($class['grade_level'])): ?><p class="muted">Grade level: <?= e($class['grade_level']) ?></p><?php endif; ?>
    </div>
    <?php if (currentUserRole() === 'teacher'): ?>
        <div class="class-actions">
            <a class="button" href="create-assignment.php?class_id=<?= (int) $classId ?>">Create assignment</a>
            <a class="button secondary" href="manage-students.php?class_id=<?= (int) $classId ?>">Students</a>
            <details class="class-tools">
                <summary>Class tools</summary>
                <div class="class-tools-panel">
                    <p>Join code <strong class="join-code"><?= e($class['join_code']) ?></strong></p>
                    <a href="grading-settings.php?class_id=<?= (int) $classId ?>">Grading settings</a>
                    <a href="english-diagnostics.php?class_id=<?= (int) $classId ?>">Assign English diagnostic</a>
                    <a href="messages.php?class_id=<?= (int) $classId ?>">Messages and announcements</a>
                </div>
            </details>
        </div>
    <?php else: ?>
        <a class="button secondary" href="messages.php?class_id=<?= (int) $classId ?>">Message teacher</a>
    <?php endif; ?>
</section>

<section class="class-content-section" aria-labelledby="class-assignments-title">
    <div class="section-heading">
        <div>
            <p class="eyebrow">Coursework</p>
            <h2 id="class-assignments-title">Assignments</h2>
        </div>
        <span class="muted"><?= count($assignments) ?> <?= count($assignments) === 1 ? 'assignment' : 'assignments' ?></span>
    </div>
    <?php if (!$assignments): ?>
        <div class="card class-empty-state"><p>No assignments have been posted for this class yet.</p></div>
    <?php else: ?>
        <div class="class-assignment-list">
            <?php foreach ($assignments as $assignment): ?>
                <article class="class-assignment-item">
                    <div class="class-assignment-copy">
                        <h3><?= e($assignment['title']) ?></h3>
                        <?php if (!empty($assignment['prompt'])): ?><p><?= e(mb_strimwidth((string) $assignment['prompt'], 0, 220, '…')) ?></p><?php endif; ?>
                        <?php if ($assignment['due_at']): ?><p class="muted">Due <?= e(date('M j, Y g:i A', strtotime($assignment['due_at']))) ?></p><?php endif; ?>
                        <?php if (currentUserRole() === 'student' && !empty($assignment['submission_status'])): ?>
                            <p><span class="status-badge status-<?= e($assignment['submission_status']) ?>"><?= e(ucfirst($assignment['submission_status'])) ?></span><?php if ($assignment['grade'] !== null): ?> · Grade: <?= e((string) $assignment['grade']) ?>%<?php endif; ?></p>
                        <?php endif; ?>
                    </div>
                    <a class="button secondary" href="<?= currentUserRole() === 'teacher' ? 'teacher-assignment.php' : 'assignment.php' ?>?id=<?= (int) $assignment['id'] ?>">
                        <?= currentUserRole() === 'teacher' ? 'Review work' : 'Open assignment' ?>
                    </a>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
