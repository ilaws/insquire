<?php

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/config/database.php';

requireLogin();

$userId = (int) currentUserId();
$sessionRole = currentUserRole();
if ($sessionRole === 'admin') {
    redirect('admin/index.php');
}
$role = currentAccountScope() === 'personal' ? 'personal' : $sessionRole;
if (!in_array($role, ['personal', 'student', 'teacher'], true)) {
    http_response_code(403);
    exit('Access denied.');
}
$title = 'Dashboard';

$classes = [];
$assignments = [];
$todoItems = [];
$gradeSummary = [];
$personalTasks = [];
$englishDiagnostics = [];
$englishSelfAttempt = null;
$hasFinalAverage = hasTableColumn($pdo, 'classes', 'show_final_average');

if ($role === 'personal') {
    $taskStmt = $pdo->prepare(
        'SELECT id, title, details, due_at, status
         FROM personal_tasks
         WHERE user_id = ? AND status <> "completed"
         ORDER BY CASE WHEN due_at IS NULL THEN 1 ELSE 0 END, due_at ASC, id DESC
         LIMIT 6'
    );
    $taskStmt->execute([$userId]);
    $personalTasks = $taskStmt->fetchAll(PDO::FETCH_ASSOC);
}

if ($role === 'student') {
    $hasReturnedAt = hasTableColumn($pdo, 'submissions', 'returned_at');
    $returnedSelect = $hasReturnedAt ? 's.returned_at' : 'NULL AS returned_at';
    $returnedFilter = $hasReturnedAt ? ' OR s.returned_at IS NOT NULL' : '';
    $stmt = $pdo->prepare("
        SELECT c.id, c.name, c.join_code
        FROM classes c
        INNER JOIN class_members cm ON cm.class_id = c.id
        WHERE cm.user_id = ?
        ORDER BY c.name ASC
    ");
    $stmt->execute([$userId]);
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT
            a.id,
            a.title,
            a.due_at,
            c.name AS class_name,
            s.status AS submission_status,
            s.submitted_at,
            {$returnedSelect}
        FROM assignments a
        INNER JOIN classes c ON c.id = a.class_id
        INNER JOIN class_members cm ON cm.class_id = c.id
        LEFT JOIN submissions s ON s.assignment_id = a.id AND s.student_id = ?
        WHERE cm.user_id = ?
        AND a.status = 'published'
        AND (s.status IS NULL OR s.status IN ('draft', 'returned'){$returnedFilter})
        ORDER BY
            CASE WHEN a.due_at IS NULL THEN 1 ELSE 0 END,
            a.due_at ASC,
            a.id DESC
        LIMIT 10
    ");
    $stmt->execute([$userId, $userId]);
    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $diagnosticStmt = $pdo->prepare(
        'SELECT da.id, da.title, da.due_at, da.status, c.name AS class_name,
                ea.id AS attempt_id, ea.score, ea.max_score, ea.cefr_level
         FROM english_diagnostic_assignments da
         INNER JOIN classes c ON c.id = da.class_id
         INNER JOIN class_members cm ON cm.class_id = c.id AND cm.user_id = ?
         LEFT JOIN english_diagnostic_attempts ea
            ON ea.assignment_id = da.id AND ea.student_id = ?
         WHERE (
             da.status = "open"
             AND (da.due_at IS NULL OR da.due_at >= UTC_TIMESTAMP())
         ) OR ea.id IS NOT NULL
         ORDER BY CASE WHEN da.due_at IS NULL THEN 1 ELSE 0 END, da.due_at, da.id DESC
         LIMIT 8'
    );
    $diagnosticStmt->execute([$userId, $userId]);
    $englishDiagnostics = $diagnosticStmt->fetchAll(PDO::FETCH_ASSOC);
    $selfAttemptStmt = $pdo->prepare(
        'SELECT id, score, max_score, cefr_level, completed_at
         FROM english_diagnostic_attempts
         WHERE assignment_id IS NULL AND student_id = ?
         ORDER BY completed_at DESC, id DESC LIMIT 1'
    );
    $selfAttemptStmt->execute([$userId]);
    $englishSelfAttempt = $selfAttemptStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $finalAverageSelect = $hasFinalAverage ? 'c.show_final_average' : '1 AS show_final_average';
    $gradeStmt = $pdo->prepare(
        'SELECT c.name AS class_name, ' . $finalAverageSelect . ', AVG(CAST(s.grade AS DECIMAL(5,2))) AS average, COUNT(s.grade) AS graded_count
         FROM classes c INNER JOIN class_members cm ON cm.class_id = c.id AND cm.user_id = ?
         INNER JOIN assignments a ON a.class_id = c.id AND a.status IN ("published", "closed")
         LEFT JOIN submissions s ON s.assignment_id = a.id AND s.student_id = ?
         GROUP BY c.id, c.name' . ($hasFinalAverage ? ', c.show_final_average' : '') . ' ORDER BY c.name'
    );
    $gradeStmt->execute([$userId, $userId]);
    $gradeSummary = $gradeStmt->fetchAll(PDO::FETCH_ASSOC);
}

if ($role === 'teacher') {
    $stmt = $pdo->prepare("
        SELECT id, name, join_code
        FROM classes
        WHERE teacher_id = ?
        ORDER BY name ASC
    ");
    $stmt->execute([$userId]);
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT
            a.id,
            a.title,
            a.due_at,
            c.name AS class_name
        FROM assignments a
        INNER JOIN classes c ON c.id = a.class_id
        WHERE c.teacher_id = ?
        ORDER BY
            CASE WHEN a.due_at IS NULL THEN 1 ELSE 0 END,
            a.due_at ASC,
            a.id DESC
        LIMIT 10
    ");
    $stmt->execute([$userId]);
    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $todoStmt = $pdo->prepare(
        'SELECT s.id, u.name AS student_name, a.title, c.name AS class_name
         FROM submissions s
         INNER JOIN users u ON u.id = s.student_id
         INNER JOIN assignments a ON a.id = s.assignment_id
         INNER JOIN classes c ON c.id = a.class_id
         WHERE c.teacher_id = ? AND s.status = "submitted"
         ORDER BY s.submitted_at ASC LIMIT 8'
    );
    $todoStmt->execute([$userId]);
    $todoItems = $todoStmt->fetchAll(PDO::FETCH_ASSOC);

}

require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="page-header<?= $role === 'teacher' ? ' dashboard-page-header' : '' ?>">
    <div>
        <?php if ($role === 'teacher'): ?>
            <p class="eyebrow dashboard-eyebrow">Teacher home</p>
            <h1>Welcome to your classroom</h1>
            <p>Plan what’s next, stay on top of student work, and keep your classes moving.</p>
        <?php elseif ($role === 'personal'): ?>
            <p class="eyebrow dashboard-eyebrow">Your personal workspace</p>
            <h1>Your assignments, in one place.</h1>
            <p>Keep prompts and due dates with each assignment, and continue its own writing-coach chat whenever you need help.</p>
        <?php else: ?>
            <h1>Dashboard</h1>
            <p>Welcome to your InsQuire dashboard.</p>
        <?php endif; ?>
    </div>
    <?php if ($role === 'teacher'): ?>
        <div class="dashboard-actions" aria-label="Create new">
            <a class="button" href="create-class.php">Create a Class</a>
            <a class="button secondary" href="create-assignment.php">Create an Assignment</a>
        </div>
    <?php endif; ?>
</div>

<?php if (!empty($_GET['lessons_paused'])): ?>
    <div class="status-message status-info" role="status">Lessons are temporarily unavailable.</div>
<?php endif; ?>

<?php if ($role === 'student'): ?>

    <div class="card dashboard-classes-shortcut">
        <h2>Classes</h2>
        <p><?= count($classes) ? 'View your classes and assignments.' : 'Join a class to see assignments from your teacher.' ?></p>
        <div class="dashboard-actions">
            <a class="button" href="classes.php">View classes</a>
            <?php if (!$classes): ?><a class="button secondary" href="join-class.php">Join a class</a><?php endif; ?>
        </div>
    </div>

    <div class="card" id="todo">
        <h2>Assignments</h2>

        <?php if (!$assignments): ?>
            <p>You don't have any assignments yet.</p>
        <?php else: ?>
            <div class="list">
                <?php foreach ($assignments as $assignment): ?>
                    <div class="list-item">
                        <div>
                            <strong><?= e($assignment['title']) ?></strong>
                            <div><?= e($assignment['class_name']) ?></div>

                            <?php if (!empty($assignment['due_at'])): ?>
                                <div>
                                    Due <?= e(date('M j, Y g:i A', strtotime($assignment['due_at']))) ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($assignment['submission_status'] === 'returned' || !empty($assignment['returned_at'])): ?><span class="status-badge status-returned">Returned for revision</span><?php elseif ($assignment['submission_status'] === 'graded'): ?><span class="status-badge status-graded">Graded</span><?php elseif ($assignment['submission_status'] === 'submitted' && $assignment['submitted_at'] && $assignment['due_at'] && strtotime($assignment['submitted_at']) > strtotime($assignment['due_at'])): ?><span class="status-badge status-late">Late</span><?php elseif ($assignment['submission_status'] === 'submitted'): ?><span class="status-badge status-submitted">Submitted</span><?php elseif ($assignment['due_at'] && strtotime($assignment['due_at']) < time()): ?><span class="status-badge status-missing">Missing</span><?php endif; ?>
                        </div>

                        <a class="button secondary" href="assignment.php?id=<?= (int) $assignment['id'] ?>">
                            <?= ($assignment['submission_status'] === 'returned' || !empty($assignment['returned_at'])) ? 'Resubmit Assignment' : 'Open' ?>
                        </a>
                    </div>
                                        <br>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <section class="dashboard-assignment-diagnostics">
        <div class="dashboard-section-heading">
            <div>
                <p class="eyebrow dashboard-eyebrow">ENGLISH SKILLS</p>
                <h3>English diagnostic</h3>
            </div>
            <a class="button secondary" href="english-diagnostic.php">Take a self-check</a>
        </div>
        <p>Try an informal check of grammar, vocabulary, and reading. It gives an approximate CEFR range, not a certificate or a complete measure of English.</p>
        <?php if ($englishSelfAttempt): ?>
            <p class="muted">Your latest self-check: <?= e($englishSelfAttempt['cefr_level']) ?> · <?= (int) $englishSelfAttempt['score'] ?>/<?= (int) $englishSelfAttempt['max_score'] ?> · <a href="english-diagnostic.php?attempt=<?= (int) $englishSelfAttempt['id'] ?>">View result</a></p>
        <?php endif; ?>
        <?php if ($englishDiagnostics): ?>
            <h3>Class diagnostics</h3>
            <div class="list">
                <?php foreach ($englishDiagnostics as $diagnostic): ?>
                    <div class="list-item">
                        <div>
                            <strong><?= e($diagnostic['title']) ?></strong>
                            <div><?= e($diagnostic['class_name']) ?></div>
                            <?php if ($diagnostic['attempt_id']): ?><div>Completed · <?= e($diagnostic['cefr_level']) ?> · <?= (int) $diagnostic['score'] ?>/<?= (int) $diagnostic['max_score'] ?></div>
                            <?php elseif (!empty($diagnostic['due_at'])): ?><div>Due <?= e(date('M j, Y g:i A', strtotime($diagnostic['due_at']))) ?></div><?php endif; ?>
                        </div>
                        <a class="button secondary" href="english-diagnostic.php?assignment_id=<?= (int) $diagnostic['id'] ?>"><?= $diagnostic['attempt_id'] ? 'View result' : 'Start diagnostic' ?></a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="muted">Your teacher has not assigned a class diagnostic that is currently open.</p>
        <?php endif; ?>
    </section>
    </div>

    <div class="card">
        <h2>Grades</h2>
        <?php if (!$gradeSummary): ?><p>Your grades will appear here after you join a class.</p><?php else: ?><div class="grid">
            <?php foreach ($gradeSummary as $summary): ?><div><h3><?= e($summary['class_name']) ?></h3><?php if (!$summary['show_final_average']): ?><p class="muted">Final average hidden by teacher.</p><?php elseif ($summary['average'] !== null): ?><p class="grade-average"><?= e((string) round((float) $summary['average'], 2)) ?>%</p><p class="muted"><?= (int) $summary['graded_count'] ?> graded assignment(s)</p><?php else: ?><p class="muted">No grades yet.</p><?php endif; ?><a href="grades.php">View grades</a></div><?php endforeach; ?>
        </div><?php endif; ?>
    </div>

<?php elseif ($role === 'personal'): ?>

    <section class="card personal-plan-card">
        <div class="dashboard-section-heading">
            <div>
                <p class="eyebrow dashboard-eyebrow">YOUR WORK</p>
                <h2>Assignments</h2>
            </div>
            <div class="dashboard-actions">
                <a class="button" href="personal-planner.php">Manage assignments</a>
                <a class="button secondary" href="calendar.php">View calendar</a>
            </div>
        </div>
        <?php if (!$personalTasks): ?>
            <div class="dashboard-empty"><p>Add an assignment to create a place for its notes, due date, and writing-coach chat.</p></div>
        <?php else: ?>
            <div class="personal-assignment-grid">
                <?php foreach ($personalTasks as $task): ?>
                    <article class="personal-assignment-card">
                        <div class="personal-assignment-card-heading">
                            <span class="eyebrow">ASSIGNMENT</span>
                            <span class="status-badge"><?= e(ucfirst(str_replace('_', ' ', $task['status']))) ?></span>
                        </div>
                        <h3><?= e($task['title']) ?></h3>
                        <?php if (!empty($task['due_at'])): ?><p class="muted">Due <?= e(date('M j, Y g:i A', strtotime($task['due_at']))) ?></p><?php endif; ?>
                        <?php if (!empty($task['details'])): ?><p class="muted"><?= e(mb_strimwidth((string) $task['details'], 0, 160, '…')) ?></p><?php else: ?><p class="muted">Add notes or instructions to give your coach context.</p><?php endif; ?>
                        <a class="button" href="personal-assignment.php?id=<?= (int) $task['id'] ?>">Open assignment and chat</a>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

<?php else: ?>

    <section class="card dashboard-classes-shortcut">
        <div class="dashboard-section-heading">
            <div>
                <p class="eyebrow dashboard-eyebrow">Your teaching space</p>
                <h2>Classes</h2>
            </div>
        </div>
        <p><?= count($classes) ? count($classes) . ' active ' . (count($classes) === 1 ? 'class' : 'classes') . '. Manage class work from the Classes page.' : 'Create a class to get started with your students.' ?></p>
        <div class="dashboard-actions">
            <a class="button" href="classes.php">View classes</a>
            <a class="button secondary" href="create-class.php">Create class</a>
        </div>
    </section>

    <div class="dashboard-work-grid">
        <section class="card dashboard-work-card">
            <div class="dashboard-section-heading">
                <div>
                    <p class="eyebrow dashboard-eyebrow">Needs your attention</p>
                    <h2>To-do</h2>
                </div>
                <a class="text-link" href="gradebook.php">Open gradebook</a>
            </div>
            <?php if (!$todoItems): ?>
                <div class="dashboard-empty"><p>Nothing needs grading right now. New submissions will appear here.</p></div>
            <?php else: ?>
                <div class="list dashboard-list">
                    <?php foreach ($todoItems as $item): ?>
                        <div class="list-item">
                            <div><strong><?= e($item['student_name']) ?></strong><div><?= e($item['title']) ?> · <?= e($item['class_name']) ?></div></div>
                            <a class="button secondary" href="teacher-submission.php?id=<?= (int) $item['id'] ?>">Review submission</a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="card dashboard-work-card">
            <div class="dashboard-section-heading">
                <div>
                    <p class="eyebrow dashboard-eyebrow">Latest activity</p>
                    <h2>Recent Assignments</h2>
                </div>
                <a class="text-link" href="create-assignment.php">Create new</a>
            </div>

            <?php if (!$assignments): ?>
                <div class="dashboard-empty"><p>You haven’t created any assignments yet.</p><a class="text-link" href="create-assignment.php">Create your first assignment</a></div>
            <?php else: ?>
                <div class="list dashboard-list">
                    <?php foreach ($assignments as $assignment): ?>
                        <div class="list-item">
                            <div>
                                <strong><?= e($assignment['title']) ?></strong>
                                <div><?= e($assignment['class_name']) ?></div>

                                <?php if (!empty($assignment['due_at'])): ?>
                                    <div>Due <?= e(date('M j, Y g:i A', strtotime($assignment['due_at']))) ?></div>
                                <?php endif; ?>
                            </div>

                            <a class="button secondary" href="teacher-assignment.php?id=<?= (int) $assignment['id'] ?>">Open assignment</a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>

<?php endif; ?>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>