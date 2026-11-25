<?php

require_once dirname(__DIR__) . '/_bootstrap.php';
require_once dirname(__DIR__, 4) . '/includes/canvas-publishing.php';

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? '');
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    apiRespond(['error' => 'Method not allowed.'], 405);
}
apiRequireMethod($method);
$user = apiRequireUser(['teacher']);
$teacherId = (int) $user['id'];

if ($method === 'GET') {
    $connectionStmt = $pdo->prepare(
        'SELECT canvas_user_id, assignment_read_enabled, expires_at
         FROM canvas_oauth_connections
         WHERE teacher_id = ?
         LIMIT 1'
    );
    $connectionStmt->execute([$teacherId]);
    $connection = $connectionStmt->fetch(PDO::FETCH_ASSOC);
    $courses = canvasLinkedCourses($pdo, $teacherId);
    $classesStmt = $pdo->prepare(
        'SELECT c.id, c.name, c.institution_id,
                (SELECT COUNT(*) FROM class_members cm WHERE cm.class_id = c.id) AS student_count
         FROM classes c
         WHERE c.teacher_id = ? AND c.status = "active"
           AND (c.institution_id IS NULL OR EXISTS (
               SELECT 1 FROM institution_members im
               WHERE im.institution_id = c.institution_id AND im.user_id = ?
                 AND im.role IN ("admin", "teacher")
           ))
         ORDER BY c.name'
    );
    $classesStmt->execute([$teacherId, $teacherId]);
    $classes = $classesStmt->fetchAll(PDO::FETCH_ASSOC);
    $result = [
        'oauth_configured' => canvasOAuthReady(),
        'connected' => (bool) $connection,
        'canvas_user_id' => $connection ? (string) $connection['canvas_user_id'] : null,
        'assignment_read_enabled' => $connection ? (bool) $connection['assignment_read_enabled'] : false,
        'linked_courses' => array_map(
            static fn(array $course): array => [
                'class_id' => (int) $course['class_id'],
                'class_name' => (string) $course['class_name'],
                'institution_id' => $course['institution_id'] !== null ? (int) $course['institution_id'] : null,
                'canvas_course_id' => $course['canvas_course_id']
            ],
            $courses
        ),
        'classes' => array_map(
            static fn(array $class): array => [
                'id' => (int) $class['id'],
                'name' => (string) $class['name'],
                'institution_id' => $class['institution_id'] !== null ? (int) $class['institution_id'] : null,
                'student_count' => (int) $class['student_count']
            ],
            $classes
        ),
        'canvas_classes' => array_values(array_unique(array_map(
            static fn(array $course): int => (int) $course['class_id'],
            $courses
        )))
    ];
    $state = $_GET['state'] ?? '';
    if (is_string($state) && $state !== '') {
        if (!preg_match('/\A[a-f0-9]{64}\z/', $state)) {
            apiRespond(['error' => 'Select a valid Canvas authorization request.'], 422);
        }
        $stateStmt = $pdo->prepare(
            'SELECT status, error_message
             FROM canvas_native_oauth_states
             WHERE state_hash = ? AND teacher_id = ?
             LIMIT 1'
        );
        $stateStmt->execute([hash('sha256', $state), $teacherId]);
        $stateRow = $stateStmt->fetch(PDO::FETCH_ASSOC);
        if (!$stateRow) {
            apiRespond(['error' => 'Canvas authorization request not found.'], 404);
        }
        $result['authorization_status'] = (string) $stateRow['status'];
        $result['authorization_error'] = $stateRow['error_message'];
    }
    apiRespond($result);
}

apiVerifyCsrf();
$input = apiReadJson();
$action = (string) ($input['action'] ?? '');
if ($action === 'disconnect') {
    $pdo->prepare('DELETE FROM canvas_oauth_connections WHERE teacher_id = ?')->execute([$teacherId]);
    unset($_SESSION['canvas_oauth']);
    apiRespond(['success' => true]);
}
if ($action === 'merge_classes') {
    $sourceClassId = filter_var(
        $input['canvas_class_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );
    $destinationClassId = filter_var(
        $input['destination_class_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );
    if ($sourceClassId === false || $destinationClassId === false
        || ($input['confirm_merge'] ?? false) !== true) {
        apiRespond(['error' => 'Choose both classes and confirm the class link and assignment sync.'], 422);
    }
    try {
        $result = canvasSyncAndMergeClasses($pdo, $teacherId, $sourceClassId, $destinationClassId);
        apiRespond([
            'success' => true,
            'assignments_created' => $result['created'],
            'assignments_updated' => $result['updated']
        ]);
    } catch (PDOException $exception) {
        error_log('Canvas class sync and link failed: ' . $exception->getMessage());
        apiRespond([
            'error' => 'Canvas assignments may have synced into the source class, but class linking was not completed. Refresh and retry.'
        ], 500);
    } catch (RuntimeException $exception) {
        apiRespond(['error' => $exception->getMessage()], 409);
    } catch (Throwable $exception) {
        error_log('Canvas class sync and link failed: ' . $exception->getMessage());
        apiRespond(['error' => 'Canvas assignment sync or class linking failed. No class merge was completed.'], 500);
    }
}
if ($action !== 'connect') {
    apiRespond(['error' => 'Choose a valid Canvas action.'], 422);
}
if (!canvasOAuthReady()) {
    apiRespond(['error' => 'Canvas OAuth is not configured on this server. Ask your administrator to complete the Canvas setup.'], 503);
}
if (!canvasTeacherHasLinkedCourses($pdo, $teacherId)) {
    apiRespond([
        'error' => 'First open InsQuire from your Canvas course and link it to an InsQuire class. Then reconnect Canvas here.'
    ], 409);
}

$state = bin2hex(random_bytes(32));
$includeAssignmentRead = filter_var(
    $input['include_assignment_read'] ?? false,
    FILTER_VALIDATE_BOOLEAN
);
$stateStmt = $pdo->prepare(
    'INSERT INTO canvas_native_oauth_states
     (state_hash, teacher_id, assignment_read_enabled, status, expires_at)
     VALUES (?, ?, ?, "pending", ?)'
);
$stateStmt->execute([
    hash('sha256', $state),
    $teacherId,
    $includeAssignmentRead ? 1 : 0,
    gmdate('Y-m-d H:i:s', time() + 600)
]);

apiRespond([
    'success' => true,
    'state' => $state,
    'authorization_url' => canvasOauthStartUrl($state, $includeAssignmentRead)
]);
