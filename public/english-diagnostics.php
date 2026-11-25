<?php

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireTeacher();

$teacherId = currentUserId();
$message = '';
$error = '';
$classId = filter_input(INPUT_GET, 'class_id', FILTER_VALIDATE_INT) ?: 0;
$reportId = filter_input(INPUT_GET, 'assignment_id', FILTER_VALIDATE_INT) ?: 0;

$classStmt = $pdo->prepare(
    'SELECT id, name FROM classes WHERE teacher_id = ? ORDER BY name'
);
$classStmt->execute([$teacherId]);
$classes = $classStmt->fetchAll(PDO::FETCH_ASSOC);
$classIds = array_map(static fn(array $class): int => (int) $class['id'], $classes);
if ($classId > 0 && !in_array($classId, $classIds, true)) {
    http_response_code(403);
    exit('You do not have permission to assign a diagnostic to this class.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = (string) ($_POST['action'] ?? 'assign');
    if ($action === 'toggle-status') {
        $diagnosticId = filter_input(INPUT_POST, 'assignment_id', FILTER_VALIDATE_INT) ?: 0;
        $newStatus = (string) ($_POST['status'] ?? '');
        if ($diagnosticId < 1 || !in_array($newStatus, ['open', 'closed'], true)) {
            $error = 'Choose a valid diagnostic and status.';
        } else {
            $updateStmt = $pdo->prepare(
                'UPDATE english_diagnostic_assignments
                 SET status = ?
                 WHERE id = ? AND teacher_id = ?'
            );
            $updateStmt->execute([$newStatus, $diagnosticId, $teacherId]);
            if ($updateStmt->rowCount() !== 1) {
                $error = 'That diagnostic could not be updated.';
            } else {
                redirect('english-diagnostics.php?assignment_id=' . $diagnosticId . '&updated=1');
            }
        }
    } else {
        $classId = filter_input(INPUT_POST, 'class_id', FILTER_VALIDATE_INT) ?: 0;
        $classTitle = trim((string) ($_POST['title'] ?? ''));
        $dueInput = trim((string) ($_POST['due_at'] ?? ''));
        if ($classTitle === '') {
            $classTitle = 'English skills diagnostic';
        }
        if (mb_strlen($classTitle) > 180) {
            $error = 'The diagnostic title must be 180 characters or fewer.';
        } elseif (!in_array($classId, $classIds, true)) {
            $error = 'Choose one of your classes.';
        }

        $dueAt = null;
        if ($error === '' && $dueInput !== '') {
            $parsedDueAt = DateTime::createFromFormat('Y-m-d\TH:i', $dueInput);
            if (!$parsedDueAt) {
                $error = 'Enter a valid due date and time.';
            } else {
                $dueAt = $parsedDueAt->format('Y-m-d H:i:s');
            }
        }

        if ($error === '') {
            $createStmt = $pdo->prepare(
                'INSERT INTO english_diagnostic_assignments (class_id, teacher_id, title, due_at)
                 VALUES (?, ?, ?, ?)'
            );
            $createStmt->execute([$classId, $teacherId, $classTitle, $dueAt]);
            redirect('english-diagnostics.php?class_id=' . $classId . '&created=1');
        }
    }
}

if (isset($_GET['created'])) {
    $message = 'The diagnostic is assigned. Students in the selected class can now complete it from their dashboard.';
} elseif (isset($_GET['updated'])) {
    $message = 'The diagnostic status has been updated.';
}

$reportAssignment = null;
$results = [];
if ($reportId > 0) {
    $reportStmt = $pdo->prepare(
        'SELECT da.id, da.title, da.due_at, da.status, c.id AS class_id, c.name AS class_name
         FROM english_diagnostic_assignments da
         INNER JOIN classes c ON c.id = da.class_id
         WHERE da.id = ? AND da.teacher_id = ?
         LIMIT 1'
    );
    $reportStmt->execute([$reportId, $teacherId]);
    $reportAssignment = $reportStmt->fetch(PDO::FETCH_ASSOC);
    if (!$reportAssignment) {
        http_response_code(404);
        exit('Diagnostic assignment not found.');
    }
    $resultStmt = $pdo->prepare(
        'SELECT u.name AS student_name, ea.score, ea.max_score, ea.cefr_level, ea.skill_scores, ea.completed_at
         FROM english_diagnostic_attempts ea
         INNER JOIN users u ON u.id = ea.student_id
         WHERE ea.assignment_id = ?
         ORDER BY u.name'
    );
    $resultStmt->execute([$reportId]);
    $results = $resultStmt->fetchAll(PDO::FETCH_ASSOC);
    $classId = (int) $reportAssignment['class_id'];
}

$assignmentStmt = $pdo->prepare(
    'SELECT da.id, da.title, da.due_at, da.status, c.id AS class_id, c.name AS class_name,
            COUNT(ea.id) AS completed_count
     FROM english_diagnostic_assignments da
     INNER JOIN classes c ON c.id = da.class_id
     LEFT JOIN english_diagnostic_attempts ea ON ea.assignment_id = da.id
     WHERE da.teacher_id = ?
     GROUP BY da.id, da.title, da.due_at, da.status, c.id, c.name
     ORDER BY da.created_at DESC, da.id DESC'
);
$assignmentStmt->execute([$teacherId]);
$diagnostics = $assignmentStmt->fetchAll(PDO::FETCH_ASSOC);

$title = 'English diagnostics';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <p class="eyebrow">EDUCATION · ASSESSMENT</p>
        <h1>English diagnostics</h1>
        <p>Assign a short, no-AI-cost English skills check and review each student’s approximate CEFR result.</p>
    </div>
</div>

<?php if ($message !== ''): ?><p class="alert alert-success" role="status"><?= e($message) ?></p><?php endif; ?>
<?php if ($error !== ''): ?><p class="alert alert-error" role="alert"><?= e($error) ?></p><?php endif; ?>

<section class="card">
    <h2>Assign a diagnostic</h2>
    <p>The 25-question check covers grammar, vocabulary, and reading. Results are advisory and should not be used as the sole basis for grades or placement.</p>
    <?php if (!$classes): ?>
        <p>Create a class before assigning a diagnostic.</p>
        <a class="button" href="create-class.php">Create a class</a>
    <?php else: ?>
        <form method="post" class="grid">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <label for="diagnostic-class">Class
                <select id="diagnostic-class" name="class_id" required>
                    <option value="">Choose a class</option>
                    <?php foreach ($classes as $class): ?>
                        <option value="<?= (int) $class['id'] ?>" <?= $classId === (int) $class['id'] ? 'selected' : '' ?>><?= e($class['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label for="diagnostic-title">Title
                <input id="diagnostic-title" name="title" maxlength="180" value="English skills diagnostic">
            </label>
            <label for="diagnostic-due">Due date (optional)
                <input id="diagnostic-due" name="due_at" type="datetime-local">
            </label>
            <div><button class="button" type="submit">Assign diagnostic</button></div>
        </form>
    <?php endif; ?>
</section>

<?php if ($reportAssignment): ?>
    <section class="card">
        <h2><?= e($reportAssignment['title']) ?> · <?= e($reportAssignment['class_name']) ?></h2>
        <p><?= count($results) ?> completed attempt(s). Results are visible only to you and the student.</p>
        <?php if (!$results): ?>
            <p>No students have completed this diagnostic yet.</p>
        <?php else: ?>
            <div class="table-wrap"><table>
                <thead><tr><th>Student</th><th>Estimated CEFR</th><th>Score</th><th>Completed</th><th>Skills</th></tr></thead>
                <tbody>
                    <?php foreach ($results as $result): ?>
                        <?php $skills = json_decode((string) $result['skill_scores'], true); ?>
                        <tr>
                            <th scope="row"><?= e($result['student_name']) ?></th>
                            <td><?= e($result['cefr_level']) ?></td>
                            <td><?= (int) $result['score'] ?> / <?= (int) $result['max_score'] ?></td>
                            <td><?= e(date('M j, Y g:i A', strtotime($result['completed_at']))) ?></td>
                            <td>
                                <?php if (is_array($skills)): ?>
                                    <?php foreach ($skills as $skill => $values): ?>
                                        <?= e((string) $skill) ?>: <?= (int) ($values['correct'] ?? 0) ?>/<?= (int) ($values['total'] ?? 0) ?><br>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<section class="card">
    <h2>Assigned diagnostics</h2>
    <?php if (!$diagnostics): ?>
        <p>No diagnostics have been assigned yet.</p>
    <?php else: ?>
        <div class="table-wrap"><table>
            <thead><tr><th>Class</th><th>Diagnostic</th><th>Status</th><th>Due</th><th>Completed</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($diagnostics as $diagnostic): ?>
                    <tr>
                        <td><?= e($diagnostic['class_name']) ?></td>
                        <th scope="row"><?= e($diagnostic['title']) ?></th>
                        <td><?= e(ucfirst($diagnostic['status'])) ?></td>
                        <td><?= $diagnostic['due_at'] ? e(date('M j, Y g:i A', strtotime($diagnostic['due_at']))) : 'No due date' ?></td>
                        <td><?= (int) $diagnostic['completed_count'] ?></td>
                        <td>
                            <a href="english-diagnostics.php?assignment_id=<?= (int) $diagnostic['id'] ?>">View results</a>
                            <form method="post" style="display:inline">
                                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                <input type="hidden" name="action" value="toggle-status">
                                <input type="hidden" name="assignment_id" value="<?= (int) $diagnostic['id'] ?>">
                                <input type="hidden" name="status" value="<?= $diagnostic['status'] === 'open' ? 'closed' : 'open' ?>">
                                <button class="button secondary" type="submit"><?= $diagnostic['status'] === 'open' ? 'Close' : 'Reopen' ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>

<p class="muted"><a href="dashboard.php">Back to dashboard</a></p>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
