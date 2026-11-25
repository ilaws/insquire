<?php

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireTeacher();

$classId = filter_input(INPUT_GET, 'class_id', FILTER_VALIDATE_INT) ?: 0;
if (!hasTableColumn($pdo, 'classes', 'show_final_average')) {
    http_response_code(503);
    exit('Grading settings are not installed yet. Run database/migrations.sql, then try again.');
}
$stmt = $pdo->prepare('SELECT id, name, show_final_average FROM classes WHERE id = ? AND teacher_id = ? LIMIT 1');
$stmt->execute([$classId, currentUserId()]);
$class = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$class) {
    http_response_code(404);
    exit('Class not found.');
}

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $showFinalAverage = isset($_POST['show_final_average']) ? 1 : 0;
    $update = $pdo->prepare('UPDATE classes SET show_final_average = ? WHERE id = ? AND teacher_id = ?');
    $update->execute([$showFinalAverage, $classId, currentUserId()]);
    $class['show_final_average'] = $showFinalAverage;
    $message = 'Grading settings saved.';
}

$title = 'Grading Settings';
require_once dirname(__DIR__) . '/includes/header.php';
?>
<div class="page-header"><div><p><a href="class.php?id=<?= (int) $classId ?>">Back to <?= e($class['name']) ?></a></p><h1>Grading Settings</h1></div></div>
<?php if ($message): ?><div class="success"><?= e($message) ?></div><?php endif; ?>
<form method="post" class="card">
    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
    <h2><?= e($class['name']) ?></h2>
    <label class="checkbox-label"><input type="checkbox" name="show_final_average" value="1" <?= !empty($class['show_final_average']) ? 'checked' : '' ?>><span>Show students their final assignment average</span></label>
    <p class="muted">When disabled, students can still see individual grades but the overall average is hidden.</p>
    <button class="button" type="submit">Save Grading Settings</button>
</form>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>