<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/functions.php';
redirect('dashboard.php?lessons_paused=1');
requireTeacher();

$state = trim($_GET['state'] ?? '');
$grade = trim($_GET['grade'] ?? '');
$skill = trim($_GET['skill'] ?? '');
$classId = filter_input(INPUT_GET, 'class_id', FILTER_VALIDATE_INT) ?: 0;
$error = '';
$message = '';

try {
    $pdo->query('SELECT 1 FROM lessons LIMIT 1');
} catch (Throwable $e) {
    $title = 'Lesson Library';
    require_once dirname(__DIR__) . '/includes/header.php';
    echo '<h1>Lesson Library</h1><div class="card"><p>Lessons are not installed on this server yet. Run database/migrations.sql, then reload this page.</p><p><small>Database message: ' . e($e->getMessage()) . '</small></p></div>';
    require_once dirname(__DIR__) . '/includes/footer.php';
    exit;
}

$classesStmt = $pdo->prepare('SELECT id, name FROM classes WHERE teacher_id = ? ORDER BY name');
$classesStmt->execute([currentUserId()]);
$classes = $classesStmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $lessonId = filter_input(INPUT_POST, 'lesson_id', FILTER_VALIDATE_INT);
    $targetClassId = filter_input(INPUT_POST, 'class_id', FILTER_VALIDATE_INT);
    $dueAt = trim($_POST['due_at'] ?? '');

    if (!$lessonId || !$targetClassId) {
        $error = 'Choose a lesson and class.';
    } else {
        $ownership = $pdo->prepare('SELECT id FROM classes WHERE id = ? AND teacher_id = ? LIMIT 1');
        $ownership->execute([$targetClassId, currentUserId()]);
        $lessonCheck = $pdo->prepare(
            'SELECT id
             FROM lessons
             WHERE id = ?
               AND ((created_by IS NULL AND is_public = 1) OR created_by = ?)
             LIMIT 1'
        );
        $lessonCheck->execute([$lessonId, currentUserId()]);
        if (!$ownership->fetch() || !$lessonCheck->fetch()) {
            $error = 'That lesson or class is not available.';
        } else {
            $stmt = $pdo->prepare('INSERT INTO lesson_assignments (lesson_id, class_id, assigned_by, due_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE due_at = VALUES(due_at)');
            $stmt->execute([$lessonId, $targetClassId, currentUserId(), $dueAt !== '' ? $dueAt : null]);
            $message = 'Lesson assigned.';
        }
    }
}

$states = $pdo->query('SELECT DISTINCT state_code, state_name FROM lesson_standards ORDER BY state_name')->fetchAll(PDO::FETCH_ASSOC);
$grades = $pdo->query('SELECT DISTINCT grade_level FROM lesson_standards ORDER BY grade_level')->fetchAll(PDO::FETCH_COLUMN);
$skills = $pdo->query('SELECT DISTINCT skill FROM lesson_standards ORDER BY skill')->fetchAll(PDO::FETCH_COLUMN);

$sql = 'SELECT l.id, l.title, l.description, l.objective, l.video_url, l.created_by, ls.state_code, ls.state_name, ls.grade_level, ls.skill, ls.standard_code, ls.standard_title FROM lessons l LEFT JOIN lesson_standards ls ON ls.id = l.standard_id WHERE ((l.created_by IS NULL AND l.is_public = 1) OR l.created_by = ?)';
$params = [currentUserId()];
if ($state !== '') { $sql .= ' AND ls.state_code = ?'; $params[] = $state; }
if ($grade !== '') { $sql .= ' AND ls.grade_level = ?'; $params[] = $grade; }
if ($skill !== '') { $sql .= ' AND ls.skill = ?'; $params[] = $skill; }
$sql .= ' ORDER BY ls.state_name, ls.grade_level, l.title';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$lessons = $stmt->fetchAll(PDO::FETCH_ASSOC);
$standardLessons = array_values(array_filter($lessons, fn($l) => ($l['state_code'] ?? '') !== 'SKILL'));
$skillLessons = array_values(array_filter($lessons, fn($l) => ($l['state_code'] ?? '') === 'SKILL'));

$renderLessonCard = function (array $lesson) use ($classes, $classId): void {
    $isSkill = ($lesson['state_code'] ?? '') === 'SKILL';
    ?>
    <article class="card lesson-card <?= $isSkill ? 'skill-card' : '' ?>">
        <h3><?= e($lesson['title']) ?></h3>
        <p><?= e($lesson['description']) ?></p>
        <div class="lesson-tags">
            <?php if (!empty($lesson['video_url'])): ?><span class="tag">▶ Video</span><?php endif; ?>
            <?php if ($isSkill): ?>
                <span class="tag tag-skill">Skill: <?= e($lesson['skill'] ?? '') ?></span>
            <?php else: ?>
                <span class="tag"><?= e($lesson['state_name'] ?? 'General') ?></span>
                <span class="tag">Grades <?= e($lesson['grade_level'] ?? 'All') ?></span>
                <span class="tag tag-skill"><?= e($lesson['skill'] ?? '') ?></span>
                <span class="tag tag-standard"><?= e($lesson['standard_code'] ?? '') ?></span>
            <?php endif; ?>
        </div>
        <?php if (!$isSkill && $lesson['standard_title']): ?><p><small><?= e($lesson['standard_title']) ?></small></p><?php endif; ?>
        <form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="lesson_id" value="<?= (int) $lesson['id'] ?>"><label>Assign to class<select name="class_id" required><option value="">Choose class</option><?php foreach ($classes as $class): ?><option value="<?= (int) $class['id'] ?>" <?= $classId === (int) $class['id'] ? 'selected' : '' ?>><?= e($class['name']) ?></option><?php endforeach; ?></select></label><label>Due date<input type="datetime-local" name="due_at"></label><button class="button" type="submit">Assign Lesson</button></form>
        <p><a href="lesson-results.php?lesson_id=<?= (int) $lesson['id'] ?>">View Student Results</a></p>
    </article>
    <?php
};
$title = 'Lesson Library';
require_once dirname(__DIR__) . '/includes/header.php';
?>
<h1>Lesson Library</h1>
<p>Browse shared lessons and your own teaching materials. Lessons you create are private to your account until you assign them to a class.</p>
<p><a class="button" href="create-lesson.php">Create Your Own Lesson</a></p>
<?php if ($error): ?><div class="error"><?= e($error) ?></div><?php endif; ?>
<?php if ($message): ?><div class="success"><?= e($message) ?></div><?php endif; ?>
<div class="card">
<form method="get" class="grid">
<label>State<select name="state"><option value="">All states</option><?php foreach ($states as $item): ?><option value="<?= e($item['state_code']) ?>" <?= $state === $item['state_code'] ? 'selected' : '' ?>><?= e($item['state_name']) ?></option><?php endforeach; ?></select></label>
<label>Grade level<select name="grade"><option value="">All grades</option><?php foreach ($grades as $item): ?><option value="<?= e($item) ?>" <?= $grade === $item ? 'selected' : '' ?>><?= e($item) ?></option><?php endforeach; ?></select></label>
<label>Skill<select name="skill"><option value="">All skills</option><?php foreach ($skills as $item): ?><option value="<?= e($item) ?>" <?= $skill === $item ? 'selected' : '' ?>><?= e($item) ?></option><?php endforeach; ?></select></label>
<button class="button" type="submit">Filter Lessons</button>
</form>
</div>
<?php if ($standardLessons): ?>
<h2 class="library-section-title">Standards-Aligned Lessons</h2>
<div class="grid">
<?php foreach ($standardLessons as $lesson) { $renderLessonCard($lesson); } ?>
</div>
<?php endif; ?>
<?php if ($skillLessons): ?>
<h2 class="library-section-title">Skills Library <small>(not tied to a specific state standard)</small></h2>
<div class="grid">
<?php foreach ($skillLessons as $lesson) { $renderLessonCard($lesson); } ?>
</div>
<?php endif; ?>
<?php if (!$lessons): ?><div class="card"><p>No lessons match those filters.</p></div><?php endif; ?>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
