<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/personal-free-ad.php';

requirePersonalAccount();
$userId = currentUserId();

$error = '';
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = (string) ($_POST['action'] ?? '');
    $taskId = filter_input(INPUT_POST, 'task_id', FILTER_VALIDATE_INT) ?: 0;

    if ($action === 'create') {
        $taskTitle = trim((string) ($_POST['title'] ?? ''));
        $details = trim((string) ($_POST['details'] ?? ''));
        $dueInput = trim((string) ($_POST['due_at'] ?? ''));
        $dueAt = null;

        if ($taskTitle === '' || mb_strlen($taskTitle) > 180) {
            $error = 'Enter an assignment title of 1 to 180 characters.';
        } elseif ($dueInput !== '') {
            $parsedDueAt = DateTimeImmutable::createFromFormat('Y-m-d\\TH:i', $dueInput);
            $dateErrors = DateTimeImmutable::getLastErrors();
            if (
                !$parsedDueAt ||
                ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
            ) {
                $error = 'Enter a valid due date and time.';
            } else {
                $dueAt = $parsedDueAt->format('Y-m-d H:i:s');
            }
        }

        if ($error === '') {
            $stmt = $pdo->prepare(
                'INSERT INTO personal_tasks (user_id, title, details, due_at) VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$userId, $taskTitle, $details !== '' ? $details : null, $dueAt]);
            $message = 'Assignment added to your plan.';
        }
    } elseif (in_array($action, ['complete', 'reopen', 'delete'], true) && $taskId > 0) {
        if ($action === 'delete') {
            $stmt = $pdo->prepare('DELETE FROM personal_tasks WHERE id = ? AND user_id = ?');
            $stmt->execute([$taskId, $userId]);
            $message = $stmt->rowCount() ? 'Assignment deleted.' : 'Assignment not found.';
        } else {
            $status = $action === 'complete' ? 'completed' : 'planned';
            $stmt = $pdo->prepare('UPDATE personal_tasks SET status = ? WHERE id = ? AND user_id = ?');
            $stmt->execute([$status, $taskId, $userId]);
            $message = $stmt->rowCount() ? 'Assignment updated.' : 'Assignment not found.';
        }
    } else {
        $error = 'Choose a valid action.';
    }
}

$stmt = $pdo->prepare(
    'SELECT id, title, details, due_at, status
     FROM personal_tasks
     WHERE user_id = ?
     ORDER BY CASE WHEN status = "completed" THEN 1 ELSE 0 END,
              CASE WHEN due_at IS NULL THEN 1 ELSE 0 END,
              due_at ASC, id DESC'
);
$stmt->execute([$userId]);
$tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

$title = 'My Assignments';
require_once dirname(__DIR__) . '/includes/header.php';
?>
<div class="page-header">
    <div>
        <p class="eyebrow">Personal workspace</p>
        <h1>My Assignments</h1>
        <p>Keep assignment instructions and due dates together. Open an assignment for its own saved AI-coach conversation.</p>
    </div>
    <a class="button secondary" href="calendar.php">View calendar</a>
</div>

<?php renderPersonalFreeAd($pdo, $userId); ?>

<?php if ($error !== ''): ?><div class="error"><?= e($error) ?></div><?php endif; ?>
<?php if ($message !== ''): ?><div class="success"><?= e($message) ?></div><?php endif; ?>

<section class="card">
    <h2>Add an assignment</h2>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="action" value="create">
        <label for="task-title">Assignment title</label>
        <input id="task-title" name="title" maxlength="180" required>
        <label for="task-details">Instructions, notes, or next step</label>
        <textarea id="task-details" name="details" rows="3" maxlength="5000"></textarea>
        <label for="task-due">Due date and time (optional)</label>
        <input id="task-due" type="datetime-local" name="due_at">
        <button class="button" type="submit">Add assignment</button>
    </form>
</section>

<section class="card">
    <h2>Assignments</h2>
    <?php if (!$tasks): ?>
        <p>Add an assignment above to start. Each assignment gets its own notes, due date, and saved coach chat.</p>
    <?php else: ?>
        <div class="list">
            <?php foreach ($tasks as $task): ?>
                <article class="list-item">
                    <div>
                        <strong><?= e($task['title']) ?></strong>
                        <?php if (!empty($task['due_at'])): ?><div>Due <?= e(date('M j, Y g:i A', strtotime($task['due_at']))) ?></div><?php endif; ?>
                        <?php if (!empty($task['details'])): ?><p class="preline"><?= e($task['details']) ?></p><?php endif; ?>
                        <span class="status-badge"><?= e(ucfirst(str_replace('_', ' ', $task['status']))) ?></span>
                    </div>
                    <div class="personal-task-actions">
                        <a class="button" href="personal-assignment.php?id=<?= (int) $task['id'] ?>">Open assignment and chat</a>
                        <?php if ($task['status'] === 'completed'): ?>
                            <form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>"><input type="hidden" name="action" value="reopen"><button class="button secondary" type="submit">Reopen</button></form>
                        <?php else: ?>
                            <form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>"><input type="hidden" name="action" value="complete"><button class="button secondary" type="submit">Mark complete</button></form>
                        <?php endif; ?>
                        <form method="post" onsubmit="return confirm('Delete this assignment and its chat?');"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>"><input type="hidden" name="action" value="delete"><button class="button secondary" type="submit">Delete</button></form>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
