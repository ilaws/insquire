<?php

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/canvas-publishing.php';

requireTeacher();

$title = 'Publish to Canvas';
$pageNoIndex = true;
$error = (string) ($_SESSION['canvas_publish_error'] ?? '');
$notice = (string) ($_SESSION['canvas_publish_notice'] ?? '');
unset($_SESSION['canvas_publish_error'], $_SESSION['canvas_publish_notice']);
$canvasUserId = (string) ($_SESSION['canvas_oauth']['canvas_user_id'] ?? '');
$connected = $canvasUserId !== '';
$courses = [];
$selectedCourse = null;
$modules = [];
$assignments = [];
$canvasAssignments = [];
$importedCanvasAssignments = [];
$assignmentReadEnabled = !empty($_SESSION['canvas_oauth']['assignment_read_enabled']);
$classLoadFailed = false;

if ($connected) {
    try {
        $courses = canvasLinkedCourses($pdo, currentUserId());
    } catch (Throwable $exception) {
        $error = $error !== '' ? $error : $exception->getMessage();
        $classLoadFailed = true;
    }
}

$classId = filter_input(INPUT_GET, 'class_id', FILTER_VALIDATE_INT);
$courseKey = trim((string) ($_GET['course_key'] ?? ''));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $classId = filter_var($_POST['class_id'] ?? null, FILTER_VALIDATE_INT);
    $courseKey = trim((string) ($_POST['course_key'] ?? ''));
}
if ($courseKey !== '') {
    if (!preg_match('/\A[a-f0-9]{64}\z/', $courseKey)) {
        $error = 'Choose a valid Canvas-linked course.';
    } else {
        foreach ($courses as $course) {
            if (hash_equals((string) $course['course_key'], $courseKey)) {
                $selectedCourse = $course;
                break;
            }
        }
    }
} elseif ($classId) {
    foreach ($courses as $course) {
        if ((int) $course['class_id'] === $classId) {
            $selectedCourse = $course;
            break;
        }
    }
    if ($selectedCourse) {
        $courseKey = (string) $selectedCourse['course_key'];
    }
}
if ($courseKey !== '' || $classId) {
    if (!$selectedCourse) {
        $error = $error !== '' ? $error : 'Choose a Canvas-linked class that belongs to your teacher account.';
    } else {
        $classId = (int) $selectedCourse['class_id'];
    }
    if ($selectedCourse && empty($selectedCourse['canvas_course_id'])) {
        $error = 'This Canvas course launch did not include a numeric course ID, so InsQuire cannot load its modules or assignments. '
            . 'Ask your Canvas administrator to add the Developer Key custom field '
            . 'insquire_canvas_course_id=$Canvas.course.id, then open InsQuire from this course again.';
        $classLoadFailed = true;
    } elseif ($selectedCourse && !$classLoadFailed) {
        try {
            $accessToken = canvasOAuthAccessToken();
            $modules = canvasListModules($accessToken, (string) $selectedCourse['canvas_course_id']);
            $importedCanvasAssignments = canvasImportedAssignments($pdo, $selectedCourse);
            if ($assignmentReadEnabled) {
                $canvasAssignments = canvasListAssignments($accessToken, (string) $selectedCourse['canvas_course_id']);
            }
            $assignmentStmt = $pdo->prepare(
                'SELECT id, title, prompt, instructions, due_at
                 FROM assignments
                 WHERE class_id = ? AND status = "published"
                 ORDER BY created_at DESC, id DESC'
            );
            $assignmentStmt->execute([$classId]);
            $assignments = $assignmentStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $exception) {
            $error = $error !== '' ? $error : $exception->getMessage();
            $classLoadFailed = true;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error && ($_POST['action'] ?? '') === 'import_canvas_assignment') {
    $canvasAssignmentId = filter_var($_POST['canvas_assignment_id'] ?? null, FILTER_VALIDATE_INT);
    $canvasAssignment = null;
    foreach ($canvasAssignments as $candidate) {
        if ((int) ($candidate['id'] ?? 0) === $canvasAssignmentId) {
            $canvasAssignment = $candidate;
            break;
        }
    }
    if (!$selectedCourse || !$canvasAssignmentId || !$canvasAssignment) {
        $error = 'Select a valid assignment from the selected Canvas course.';
    } else {
        try {
            $created = canvasImportAssignment($pdo, $selectedCourse, $canvasAssignment);
            $_SESSION['canvas_publish_notice'] = $created
                ? 'Canvas assignment imported into InsQuire.'
                : 'Canvas assignment synced to InsQuire.';
            header('Location: canvas-publish.php?course_key=' . rawurlencode((string) $selectedCourse['course_key']));
            exit;
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error && ($_POST['action'] ?? '') !== 'import_canvas_assignment') {
    $moduleId = filter_var($_POST['module_id'] ?? null, FILTER_VALIDATE_INT);
    $assignmentId = filter_var($_POST['assignment_id'] ?? null, FILTER_VALIDATE_INT);
    $moduleExists = false;
    foreach ($modules as $module) {
        if (filter_var($module['id'] ?? null, FILTER_VALIDATE_INT) === $moduleId) {
            $moduleExists = true;
            break;
        }
    }
    if (!$moduleId || !$assignmentId || !$moduleExists) {
        $error = 'Select a valid published assignment and Canvas module.';
    } else {
        $assignmentStmt = $pdo->prepare(
            'SELECT id, title, prompt, instructions, due_at
             FROM assignments
             WHERE id = ? AND class_id = ? AND status = "published"
             LIMIT 1'
        );
        $assignmentStmt->execute([$assignmentId, $classId]);
        $assignment = $assignmentStmt->fetch(PDO::FETCH_ASSOC);
        if (!$assignment) {
            $error = 'That assignment is not published in the selected InsQuire class.';
        } else {
            try {
                $created = canvasPublishAssignment(
                    $pdo,
                    $selectedCourse,
                    (int) $assignmentId,
                    (int) $moduleId,
                    $assignment,
                    canvasOAuthAccessToken()
                );
                $_SESSION['canvas_publish_notice'] = $created
                    ? 'Assignment published to the selected Canvas module.'
                    : 'This assignment is already published to the selected Canvas module.';
                header('Location: canvas-publish.php?course_key=' . rawurlencode((string) $selectedCourse['course_key']));
                exit;
            } catch (Throwable $exception) {
                $error = $exception->getMessage();
            }
        }
    }
}

require_once dirname(__DIR__) . '/includes/header.php';
?>

<h1>Publish to Canvas</h1>
<div class="card">
    <p>Import Canvas assignments into InsQuire, or publish InsQuire assignments into a Canvas module.</p>

    <?php if ($error !== ''): ?>
        <div class="error"><?= e($error) ?></div>
    <?php endif; ?>
    <?php if ($notice !== ''): ?>
        <div class="success"><?= e($notice) ?></div>
    <?php endif; ?>

    <?php if (!canvasOAuthReady()): ?>
        <p>Canvas publishing is not configured yet. Ask your administrator to add the Canvas OAuth client ID, client secret, and callback URL in the server's private configuration.</p>
    <?php elseif (!$connected): ?>
        <p>Connect your Canvas account to load your linked courses and modules.</p>
        <form method="post" action="canvas-oauth-start.php">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <button class="button" type="submit">Connect Canvas</button>
        </form>
        <form method="post" action="canvas-oauth-start.php">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="include_assignment_read" value="1">
            <button class="button secondary" type="submit">Connect Canvas and enable assignment import</button>
        </form>
        <p class="muted">Use the Canvas account you use to launch InsQuire from your course.</p>
    <?php else: ?>
        <form method="post" action="canvas-oauth-start.php">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <button class="button" type="submit">Reconnect Canvas</button>
        </form>
        <?php if (!$assignmentReadEnabled): ?>
            <form method="post" action="canvas-oauth-start.php">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="include_assignment_read" value="1">
                <button class="button secondary" type="submit">Reconnect and enable assignment import</button>
            </form>
        <?php endif; ?>
        <?php if ($courses === []): ?>
        <p>No Canvas-linked classes were found for this teacher account. Open InsQuire from the Canvas course first, then return here.</p>
        <?php else: ?>
        <form method="get" action="canvas-publish.php">
            <label for="canvas-class">Canvas-linked class</label>
            <select id="canvas-class" name="course_key" required>
                <option value="">Choose a class</option>
                <?php foreach ($courses as $course): ?>
                    <option value="<?= e((string) $course['course_key']) ?>"<?= $courseKey === (string) $course['course_key'] ? ' selected' : '' ?>>
                        <?= e((string) $course['class_name']) ?> | Canvas <?= e((string) ($course['canvas_course_id'] ?: $course['context_id'])) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button class="button" type="submit">Load class</button>
        </form>

        <?php if ($selectedCourse): ?>
            <?php if ($classLoadFailed): ?>
                <p>Canvas class details could not be loaded. Review the error above and try again.</p>
            <?php else: ?>
                <h2>Import from Canvas</h2>
                <?php if (!$assignmentReadEnabled): ?>
                    <p>Canvas assignment import needs permission for <code>GET /api/v1/courses/:course_id/assignments</code>. Ask your Canvas administrator to allow that exact API scope on the OAuth Developer Key, then use **Reconnect and enable assignment import** above. The regular Canvas publishing connection does not need this extra permission.</p>
                <?php elseif ($canvasAssignments === []): ?>
                    <p>No assignments were found in this Canvas course.</p>
                <?php else: ?>
                    <form method="post" action="canvas-publish.php?course_key=<?= rawurlencode((string) $selectedCourse['course_key']) ?>">
                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                        <input type="hidden" name="class_id" value="<?= (int) $classId ?>">
                        <input type="hidden" name="course_key" value="<?= e((string) $selectedCourse['course_key']) ?>">
                        <input type="hidden" name="action" value="import_canvas_assignment">
                        <label for="canvas-import-assignment">Canvas assignment</label>
                        <select id="canvas-import-assignment" name="canvas_assignment_id" required>
                            <option value="">Choose an assignment</option>
                            <?php foreach ($canvasAssignments as $canvasAssignment): ?>
                                <?php
                                $canvasAssignmentId = (string) ($canvasAssignment['id'] ?? '');
                                $importedAt = $importedCanvasAssignments[$canvasAssignmentId] ?? null;
                                $assignmentName = trim((string) ($canvasAssignment['name'] ?? 'Untitled assignment'));
                                if ($importedAt !== null) {
                                    $assignmentName .= ' (sync existing)';
                                } elseif (empty($canvasAssignment['published'])) {
                                    $assignmentName .= ' (unpublished; imports as draft)';
                                }
                                ?>
                                <option value="<?= e($canvasAssignmentId) ?>"><?= e($assignmentName) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="button" type="submit">Import / sync to InsQuire</button>
                    </form>
                <?php endif; ?>

                <h2>Publish to Canvas</h2>
                <?php if ($assignments === []): ?>
                    <p>This class has no published InsQuire assignments yet.
                        <a href="create-assignment.php?class_id=<?= (int) $classId ?>">Create an assignment</a>
                        in this class, then return here.
                    </p>
                <?php elseif ($modules === []): ?>
                    <p>This Canvas course has no modules. Create a module in Canvas, then reload this class.</p>
                <?php else: ?>
                <form method="post" action="canvas-publish.php?course_key=<?= rawurlencode((string) $selectedCourse['course_key']) ?>">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="class_id" value="<?= (int) $classId ?>">
                    <input type="hidden" name="course_key" value="<?= e((string) $selectedCourse['course_key']) ?>">
                    <input type="hidden" name="action" value="publish">

                    <label for="canvas-assignment">InsQuire assignment</label>
                    <select id="canvas-assignment" name="assignment_id" required>
                        <option value="">Choose an assignment</option>
                        <?php foreach ($assignments as $assignment): ?>
                            <option value="<?= (int) $assignment['id'] ?>"><?= e((string) $assignment['title']) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label for="canvas-module">Canvas module</label>
                    <select id="canvas-module" name="module_id" required>
                        <option value="">Choose a module</option>
                        <?php foreach ($modules as $module): ?>
                            <option value="<?= (int) ($module['id'] ?? 0) ?>"><?= e((string) ($module['name'] ?? 'Untitled module')) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="button" type="submit">Publish assignment</button>
                </form>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
