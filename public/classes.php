<?php

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
requireEducationHost();
$role = currentUserRole();
if (!in_array($role, ['student', 'teacher'], true)) {
    http_response_code(403);
    exit('Access denied.');
}

if ($role === 'teacher') {
    $stmt = $pdo->prepare(
        'SELECT c.*, COUNT(cm.user_id) AS student_count
         FROM classes c
         LEFT JOIN class_members cm ON cm.class_id = c.id
         LEFT JOIN institution_members im
            ON im.institution_id = c.institution_id AND im.user_id = ?
         WHERE c.teacher_id = ?
           AND (c.institution_id IS NULL OR im.role IN ("admin","teacher"))
         GROUP BY c.id
         ORDER BY c.created_at DESC'
    );
    $stmt->execute([currentUserId(), currentUserId()]);
} else {
    $stmt = $pdo->prepare(
        'SELECT c.*, NULL AS student_count
         FROM classes c
         INNER JOIN class_members cm ON cm.class_id = c.id
         WHERE cm.user_id = ?
         ORDER BY c.name'
    );
    $stmt->execute([currentUserId()]);
}
$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
$notice = (string) ($_SESSION['class_merge_notice'] ?? '');
unset($_SESSION['class_merge_notice']);

$title = 'Classes';

require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <p class="eyebrow">Education workspace</p>
        <h1>Your classes</h1>
        <p><?= $role === 'teacher' ? 'Open a class to manage assignments, lessons, and students.' : 'Open a class to see its assignments and lessons.' ?></p>
    </div>
    <?php if ($role === 'teacher'): ?>
        <div class="class-actions">
            <a class="button" href="create-class.php">Create class</a>
        </div>
    <?php else: ?>
        <a class="button" href="join-class.php">Join a class</a>
    <?php endif; ?>
</div>

<?php if ($notice !== ''): ?>
    <div class="success" role="status"><?= e($notice) ?></div>
<?php endif; ?>

<div class="grid">

    <?php foreach ($classes as $class): ?>
        <div class="card">
            <h2><?= e($class['name']) ?></h2>
            <?php if ($role === 'teacher'): ?>
                <?php if (!empty($class['description'])): ?><p><?= e($class['description']) ?></p><?php endif; ?>
                <p>Join code: <strong class="join-code"><?= e($class['join_code']) ?></strong></p>
                <p><?= (int) $class['student_count'] ?> students</p>
            <?php else: ?>
                <p><?= e($class['description'] ?? '') ?></p>
            <?php endif; ?>
            <a class="button secondary" href="class.php?id=<?= (int) $class['id'] ?>">
                Open class
            </a>
        </div>
    <?php endforeach; ?>

</div>

<?php if (!$classes): ?>
    <section class="card dashboard-empty">
        <p><?= $role === 'teacher' ? 'You haven’t created a class yet.' : 'You haven’t joined a class yet.' ?></p>
        <a class="button" href="<?= $role === 'teacher' ? 'create-class.php' : 'join-class.php' ?>"><?= $role === 'teacher' ? 'Create your first class' : 'Join a class' ?></a>
    </section>
<?php endif; ?>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
