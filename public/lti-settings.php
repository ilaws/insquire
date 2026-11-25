<?php

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/canvas-publishing.php';

requireTeacher();

$userId = currentUserId();
$error = (string) ($_SESSION['lti_settings_error'] ?? '');
$notice = (string) ($_SESSION['lti_settings_notice'] ?? '');
unset($_SESSION['lti_settings_error'], $_SESSION['lti_settings_notice']);
$canvasClassId = 0;
$destinationId = 0;
$syncedCreated = 0;
$syncedUpdated = 0;
$syncStage = 'Canvas assignment sync';
$canvasConnected = !empty($_SESSION['canvas_oauth']['canvas_user_id']);
$assignmentReadEnabled = !empty($_SESSION['canvas_oauth']['assignment_read_enabled']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $canvasClassId = filter_var($_POST['canvas_class_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
    $destinationId = filter_var($_POST['destination_class_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;

    try {
        if ($canvasClassId < 1 || $destinationId < 1 || $canvasClassId === $destinationId) {
            throw new RuntimeException('Choose a Canvas-linked class and a different InsQuire class to keep.');
        }
        if (($_POST['confirm_merge'] ?? '') !== '1') {
            throw new RuntimeException('Confirm that you want to move this Canvas course into the InsQuire class and remove its temporary class.');
        }

        if (!canvasOAuthReady()) {
            throw new RuntimeException('Canvas OAuth is not configured on this server. Ask your administrator to finish the Canvas setup first.');
        }
        if (!$assignmentReadEnabled) {
            throw new RuntimeException('Connect or reconnect Canvas with assignment import enabled before syncing the class.');
        }

        $preflightStmt = $pdo->prepare(
            'SELECT id, teacher_id, institution_id
             FROM classes
             WHERE id IN (?, ?)'
        );
        $preflightStmt->execute([$canvasClassId, $destinationId]);
        $preflightClasses = $preflightStmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($preflightClasses) !== 2) {
            throw new RuntimeException('One or both selected classes could not be found.');
        }
        $preflightById = [];
        foreach ($preflightClasses as $class) {
            if ((int) $class['teacher_id'] !== $userId) {
                throw new RuntimeException('You can link only classes that you own.');
            }
            $preflightById[(int) $class['id']] = $class;
        }
        if (
            (string) ($preflightById[$canvasClassId]['institution_id'] ?? '')
            !== (string) ($preflightById[$destinationId]['institution_id'] ?? '')
        ) {
            throw new RuntimeException('Classes must belong to the same institution to be linked.');
        }
        $courseStmt = $pdo->prepare(
            'SELECT lc.course_key, lc.issuer, lc.deployment_id, lc.context_id,
                    lc.canvas_course_id, lc.class_id, lc.teacher_user_id
             FROM lti_courses lc
             INNER JOIN classes c ON c.id = lc.class_id
             WHERE lc.class_id = ? AND lc.teacher_user_id = ? AND c.teacher_id = ?
             ORDER BY lc.id'
        );
        $courseStmt->execute([$canvasClassId, $userId, $userId]);
        $canvasCourses = $courseStmt->fetchAll(PDO::FETCH_ASSOC);
        if ($canvasCourses === []) {
            throw new RuntimeException('Choose a class linked to a Canvas course as the source.');
        }
        foreach ($canvasCourses as $canvasCourse) {
            if (empty($canvasCourse['canvas_course_id'])) {
                throw new RuntimeException(
                    'A linked Canvas course has no numeric course ID. Add the custom field '
                    . 'insquire_canvas_course_id=$Canvas.course.id to the Canvas Developer Key and relaunch.'
                );
            }
        }

        $accessToken = canvasOAuthAccessToken();
        $syncStage = 'Canvas assignment sync preflight';
        $assignmentsByCourse = [];
        foreach ($canvasCourses as $canvasCourse) {
            $canvasAssignments = canvasListAssignments(
                $accessToken,
                (string) $canvasCourse['canvas_course_id']
            );
            foreach ($canvasAssignments as $canvasAssignment) {
                if (
                    !is_array($canvasAssignment)
                    || !filter_var($canvasAssignment['id'] ?? null, FILTER_VALIDATE_INT)
                    || trim((string) ($canvasAssignment['name'] ?? '')) === ''
                ) {
                    throw new RuntimeException('Canvas returned an assignment without a valid ID or title.');
                }
            }
            $assignmentsByCourse[] = [$canvasCourse, $canvasAssignments];
        }

        $syncStage = 'Canvas assignment import';
        foreach ($assignmentsByCourse as [$canvasCourse, $canvasAssignments]) {
            foreach ($canvasAssignments as $canvasAssignment) {
                if (canvasImportAssignment($pdo, $canvasCourse, $canvasAssignment)) {
                    $syncedCreated++;
                } else {
                    $syncedUpdated++;
                }
            }
        }

        $syncStage = 'Canvas class linking';
        $pdo->beginTransaction();
        $classIds = [$canvasClassId, $destinationId];
        sort($classIds, SORT_NUMERIC);
        $classStmt = $pdo->prepare(
            'SELECT id, teacher_id, institution_id
             FROM classes
             WHERE id IN (?, ?)
             ORDER BY id
             FOR UPDATE'
        );
        $classStmt->execute($classIds);
        $lockedClasses = $classStmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($lockedClasses) !== 2) {
            throw new RuntimeException('One or both selected classes could not be found.');
        }
        foreach ($lockedClasses as $class) {
            if ((int) $class['teacher_id'] !== $userId) {
                throw new RuntimeException('You can merge only classes that you own.');
            }
        }
        $institutionIds = array_map(
            static fn (array $class): string => (string) ($class['institution_id'] ?? ''),
            $lockedClasses
        );
        if ($institutionIds[0] !== $institutionIds[1]) {
            throw new RuntimeException('Classes must belong to the same institution to be merged.');
        }
        $canvasLinkCheck = $pdo->prepare(
            'SELECT 1 FROM lti_courses WHERE class_id = ? AND teacher_user_id = ? LIMIT 1'
        );
        $canvasLinkCheck->execute([$canvasClassId, $userId]);
        if (!$canvasLinkCheck->fetchColumn()) {
            throw new RuntimeException('Choose a class linked to a Canvas course as the source.');
        }

        $mergeMembers = $pdo->prepare(
            'INSERT IGNORE INTO class_members (class_id, user_id, joined_at)
             SELECT ?, user_id, joined_at
             FROM class_members
             WHERE class_id = ?'
        );
        $mergeMembers->execute([$destinationId, $canvasClassId]);
        $deleteSourceMembers = $pdo->prepare('DELETE FROM class_members WHERE class_id = ?');
        $deleteSourceMembers->execute([$canvasClassId]);

        $mergeLessonProgress = $pdo->prepare(
            'INSERT INTO lesson_progress
                (lesson_assignment_id, student_id, response, score, status, completed_at, updated_at)
             SELECT ?, source_progress.student_id, source_progress.response, source_progress.score,
                    source_progress.status, source_progress.completed_at, source_progress.updated_at
             FROM lesson_progress source_progress
             WHERE source_progress.lesson_assignment_id = ?
             ON DUPLICATE KEY UPDATE
                response = IF(
                    (VALUES(status) = "completed" AND status <> "completed")
                    OR (VALUES(updated_at) > updated_at
                        AND (VALUES(status) = status OR status <> "completed")),
                    VALUES(response), response
                ),
                score = IF(
                    (VALUES(status) = "completed" AND status <> "completed")
                    OR (VALUES(updated_at) > updated_at
                        AND (VALUES(status) = status OR status <> "completed")),
                    VALUES(score), score
                ),
                completed_at = IF(
                    (VALUES(status) = "completed" AND status <> "completed")
                    OR (VALUES(updated_at) > updated_at
                        AND (VALUES(status) = status OR status <> "completed")),
                    VALUES(completed_at), completed_at
                ),
                status = IF(
                    (VALUES(status) = "completed" AND status <> "completed")
                    OR (VALUES(updated_at) > updated_at
                        AND (VALUES(status) = status OR status <> "completed")),
                    VALUES(status), status
                ),
                updated_at = GREATEST(updated_at, VALUES(updated_at))'
        );
        $mergeDuplicateLessons = $pdo->prepare(
            'SELECT source_assignment.id AS source_assignment_id,
                    destination_assignment.id AS destination_assignment_id
             FROM lesson_assignments source_assignment
             INNER JOIN lesson_assignments destination_assignment
                ON destination_assignment.lesson_id = source_assignment.lesson_id
               AND destination_assignment.class_id = ?
             WHERE source_assignment.class_id = ?'
        );
        $mergeDuplicateLessons->execute([$destinationId, $canvasClassId]);
        foreach ($mergeDuplicateLessons->fetchAll(PDO::FETCH_ASSOC) as $lessonAssignment) {
            $mergeLessonProgress->execute([
                (int) $lessonAssignment['destination_assignment_id'],
                (int) $lessonAssignment['source_assignment_id']
            ]);
        }
        $mergeLessonDates = $pdo->prepare(
            'UPDATE lesson_assignments source_assignment
             INNER JOIN lesson_assignments destination_assignment
                ON destination_assignment.lesson_id = source_assignment.lesson_id
               AND destination_assignment.class_id = ?
             SET destination_assignment.due_at = COALESCE(
                    destination_assignment.due_at,
                    source_assignment.due_at
                 )
             WHERE source_assignment.class_id = ?'
        );
        $mergeLessonDates->execute([$destinationId, $canvasClassId]);
        $deleteDuplicateLessons = $pdo->prepare(
            'DELETE source_assignment
             FROM lesson_assignments source_assignment
             INNER JOIN lesson_assignments destination_assignment
                ON destination_assignment.lesson_id = source_assignment.lesson_id
               AND destination_assignment.class_id = ?
             WHERE source_assignment.class_id = ?'
        );
        $deleteDuplicateLessons->execute([$destinationId, $canvasClassId]);

        foreach ([
            'class_messages',
            'class_announcements',
            'assignments',
            'english_diagnostic_assignments',
            'lesson_assignments',
            'lti_courses',
            'canvas_published_assignments',
            'canvas_imported_assignments',
            'notifications'
        ] as $table) {
            $moveRecords = $pdo->prepare("UPDATE {$table} SET class_id = ? WHERE class_id = ?");
            $moveRecords->execute([$destinationId, $canvasClassId]);
        }

        $deleteSourceClass = $pdo->prepare('DELETE FROM classes WHERE id = ? AND teacher_id = ?');
        $deleteSourceClass->execute([$canvasClassId, $userId]);
        if ($deleteSourceClass->rowCount() !== 1) {
            throw new RuntimeException('The temporary Canvas class could not be removed. No changes were saved.');
        }

        $pdo->commit();
        $_SESSION['lti_settings_notice'] = 'Canvas course linked and all assignments synced: '
            . $syncedCreated . ' new, ' . $syncedUpdated . ' updated.';
        redirect('lti-settings.php#link-course');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($exception instanceof PDOException) {
            error_log($syncStage . ' failed: ' . $exception->getMessage());
            $error = $syncStage . ' failed. ' . $syncedCreated . ' new and ' . $syncedUpdated
                . ' updated assignments were synced; the classes were not linked. Retry to finish.';
        } elseif ($exception instanceof RuntimeException) {
            $error = $exception->getMessage();
            if ($syncedCreated + $syncedUpdated > 0) {
                $error .= ' So far, ' . $syncedCreated . ' new and ' . $syncedUpdated
                    . ' updated assignments were synced into the temporary Canvas class. Retry to finish linking.';
            }
        } else {
            error_log($syncStage . ' failed: ' . $exception->getMessage());
            $error = $syncStage . ' failed. ' . $syncedCreated . ' new and ' . $syncedUpdated
                . ' updated assignments were synced; the classes were not linked. Retry to finish.';
        }
    }
}

$classesStmt = $pdo->prepare(
    'SELECT c.id, c.name,
            (SELECT COUNT(*) FROM class_members cm WHERE cm.class_id = c.id) AS student_count,
            (
                SELECT GROUP_CONCAT(
                    DISTINCT COALESCE(
                        NULLIF(lc.canvas_course_id, ""),
                        CONCAT("Canvas context ", lc.context_id)
                    )
                    ORDER BY lc.context_id
                    SEPARATOR ", "
                )
                FROM lti_courses lc
                WHERE lc.class_id = c.id AND lc.teacher_user_id = ?
            ) AS linked_canvas_courses
     FROM classes c
     WHERE c.teacher_id = ?
     ORDER BY c.name, c.id'
);
$classesStmt->execute([$userId, $userId]);
$classes = $classesStmt->fetchAll(PDO::FETCH_ASSOC);
$canvasClassesStmt = $pdo->prepare(
    'SELECT c.id, c.name, GROUP_CONCAT(
        DISTINCT COALESCE(
            NULLIF(lc.canvas_course_id, ""),
            CONCAT("Canvas context ", lc.context_id)
        )
        ORDER BY lc.context_id
        SEPARATOR ", "
     ) AS canvas_courses
     FROM classes c
     INNER JOIN lti_courses lc ON lc.class_id = c.id
     WHERE c.teacher_id = ? AND lc.teacher_user_id = ?
     GROUP BY c.id, c.name
     ORDER BY c.name, c.id'
);
$canvasClassesStmt->execute([$userId, $userId]);
$canvasClasses = $canvasClassesStmt->fetchAll(PDO::FETCH_ASSOC);

$title = 'LTI settings';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="card small-card">
    <h1>LTI settings</h1>
    <p>Manage your Canvas connection and course synchronization here.</p>

    <?php if ($error !== ''): ?>
        <div class="error" role="alert"><?= e($error) ?></div>
    <?php endif; ?>
    <?php if ($notice !== ''): ?>
        <div class="success" role="status"><?= e($notice) ?></div>
    <?php endif; ?>

    <h2 id="link-course">Link a Canvas course to an InsQuire class</h2>
    <p>If opening InsQuire from Canvas created a separate class, select that Canvas-linked class and the existing InsQuire class you want to keep. The destination keeps its name, join code, and settings.</p>
    <p>Before linking, <strong>all assignments from every Canvas course connected to the temporary class are imported or updated</strong>, including titles, instructions, rubrics, due dates, and published/draft status. Then students, messages, announcements, lesson progress, diagnostics, notifications, submissions, and Canvas links move to the destination class. If both classes have the same lesson, completed progress takes precedence; otherwise the newest saved progress is kept. Both classes must belong to you and the same institution. This cannot be undone.</p>

    <?php if (!canvasOAuthReady()): ?>
        <p class="error">Canvas assignment sync is not configured. Ask your administrator to set up Canvas OAuth using the instructions below.</p>
    <?php elseif (!$canvasConnected || !$assignmentReadEnabled): ?>
        <p>To sync assignments, connect Canvas and approve the assignment-read permission. Your Canvas administrator must first allow <code>url:GET|/api/v1/courses/:course_id/assignments</code> on the OAuth Developer Key.</p>
        <form method="post" action="canvas-oauth-start.php">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="include_assignment_read" value="1">
            <input type="hidden" name="return_to_settings" value="1">
            <button class="button secondary" type="submit"><?= $canvasConnected ? 'Reconnect Canvas with assignment sync' : 'Connect Canvas with assignment sync' ?></button>
        </form>
    <?php else: ?>
        <p>Canvas account connected with assignment-read access. All Canvas assignments will be imported or updated before the class link is changed.</p>
    <?php endif; ?>

    <?php if (!$canvasClasses || count($classes) < 2): ?>
        <p>First open InsQuire from Canvas as the course teacher. If you use a regular InsQuire teacher account, link that account to your Canvas teacher account before continuing. Then this page will show the temporary Canvas-linked class. You also need the existing InsQuire class you want to keep.</p>
    <?php else: ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

            <label for="canvas-class">Canvas course class (temporary class that will be removed)</label>
            <select id="canvas-class" name="canvas_class_id" required>
                <option value="">Choose the Canvas-linked class</option>
                <?php foreach ($canvasClasses as $class): ?>
                    <option value="<?= (int) $class['id'] ?>"<?= $canvasClassId === (int) $class['id'] ? ' selected' : '' ?>>
                        <?= e((string) $class['name']) ?> (class #<?= (int) $class['id'] ?>) | Canvas <?= e((string) $class['canvas_courses']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="destination-class">Existing InsQuire class to keep and link</label>
            <select id="destination-class" name="destination_class_id" required>
                <option value="">Choose the InsQuire class</option>
                <?php foreach ($classes as $class): ?>
                    <option value="<?= (int) $class['id'] ?>"<?= $destinationId === (int) $class['id'] ? ' selected' : '' ?>>
                        <?= e((string) $class['name']) ?>
                        (class #<?= (int) $class['id'] ?>, <?= (int) $class['student_count'] ?> students<?= !empty($class['linked_canvas_courses']) ? ', Canvas ' . e((string) $class['linked_canvas_courses']) : '' ?>)
                    </option>
                <?php endforeach; ?>
            </select>

            <label>
                <input type="checkbox" name="confirm_merge" value="1" required>
                I understand that all Canvas assignments will sync, then the temporary Canvas-linked class will be removed after it is connected to the InsQuire class.
            </label>
            <button class="button" type="submit"<?= !canvasOAuthReady() || !$canvasConnected || !$assignmentReadEnabled ? ' disabled' : '' ?>>Sync class and all assignments</button>
        </form>
    <?php endif; ?>

    <hr>
    <h2 id="link-account">Link your teacher account</h2>
    <p>Use this if Canvas created a separate teacher login and you want to use your regular InsQuire teacher account. Generate a one-time code from the regular account, then redeem it while signed in through Canvas.</p>
    <a class="button secondary" href="lti-account-link.php">Link Canvas teacher account</a>

    <hr>
    <h2 id="assignments">Assignments and Canvas authorization</h2>
    <p>Use the separate publishing page to publish InsQuire assignments to a Canvas module or import individual Canvas assignments. The <strong>Sync class and all assignments</strong> action above imports or updates every assignment in the selected Canvas-linked class before linking it to your existing InsQuire class.</p>
    <a class="button secondary" href="canvas-publish.php">Publish or import assignments</a>

    <hr>
    <h2 id="setup">Canvas setup instructions</h2>
    <details>
        <summary>Show Developer Key and permission setup</summary>
        <ol>
            <li>In Canvas, open <strong>Admin → Developer Keys</strong> and create or edit the LTI 1.3 Developer Key for InsQuire.</li>
            <li>Set the OIDC initiation URL to <code>https://ed.insquire.org/lti-login.php</code>, the Target Link URI to <code>https://ed.insquire.org/lti-launch.php</code>, and the Public JWK URL to <code>https://ed.insquire.org/lti-jwks.php</code>.</li>
            <li>Enable Course Navigation and Assignment Selection / Deep Linking. Enable Assignment and Grade Services line-item access and score submission, plus read-only Names and Role Provisioning Services for the course roster.</li>
            <li>Under <strong>Custom Fields</strong>, add exactly <code>insquire_canvas_course_id=$Canvas.course.id</code>. This sends the numeric Canvas course ID needed for modules and assignments. Save the Developer Key.</li>
            <li>Install the key in the Canvas account or course using its <strong>Client ID</strong>. Add the resulting deployment ID to the server-private InsQuire configuration. Then launch InsQuire from each Canvas course once as its teacher.</li>
            <li>For assignment publishing, configure a Canvas OAuth/API Developer Key with redirect URI <code>https://ed.insquire.org/canvas-oauth-callback.php</code> and allow <code>GET /api/v1/users/self</code>, <code>GET /api/v1/courses/:course_id/modules</code>, <code>POST /api/v1/courses/:course_id/assignments</code>, and <code>POST /api/v1/courses/:course_id/modules/:module_id/items</code>. Store its client secret in server-private configuration, never in the web root.</li>
            <li>For class linking with assignment sync or individual Canvas assignment imports, additionally allow <code>url:GET|/api/v1/courses/:course_id/assignments</code>. On this LTI settings page, choose <strong>Connect Canvas with assignment sync</strong> or <strong>Reconnect Canvas with assignment sync</strong> and approve it. This permission is not needed just to publish InsQuire assignments to Canvas.</li>
        </ol>
        <p>Keep the RSA private signing key and all OAuth secrets out of the repository and public web directory. If a linked course has no Canvas modules, check its course-ID custom field and relaunch from Canvas.</p>
    </details>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
