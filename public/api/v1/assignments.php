<?php

require_once __DIR__ . '/_bootstrap.php';

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? '');
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    apiRespond(['error' => 'Method not allowed.'], 405);
}
apiRequireMethod($method);
$user = apiRequireUser(['student']);
$userId = (int) $user['id'];

if ($method === 'GET') {
    $assignmentId = array_key_exists('id', $_GET)
        ? filter_var($_GET['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
        : null;
    $hasReturnedAt = hasTableColumn($pdo, 'submissions', 'returned_at');
    $hasGrade = hasTableColumn($pdo, 'submissions', 'grade');
    $returnedAtColumn = $hasReturnedAt ? 's.returned_at' : 'NULL AS returned_at';
    $gradeColumn = $hasGrade ? 's.grade' : 'NULL AS grade';

    $sql =
        'SELECT a.id, a.class_id, a.title, a.prompt, a.instructions, a.rubric_text,
                a.due_at, a.allow_late, a.status AS assignment_status,
                c.name AS class_name,
                s.id AS submission_id, s.essay, s.status AS submission_status,
                s.submitted_at, ' . $returnedAtColumn . ', ' . $gradeColumn . '
         FROM assignments a
         INNER JOIN classes c ON c.id = a.class_id
         INNER JOIN class_members cm ON cm.class_id = c.id AND cm.user_id = ?
         LEFT JOIN submissions s ON s.assignment_id = a.id AND s.student_id = ?
         WHERE a.status = "published"';
    $params = [$userId, $userId];

    if ($assignmentId !== false && $assignmentId !== null) {
        $sql .= ' AND a.id = ?';
        $params[] = $assignmentId;
    }
    $sql .= ' ORDER BY CASE WHEN a.due_at IS NULL THEN 1 ELSE 0 END, a.due_at, a.id DESC';
    if ($assignmentId === false) {
        apiRespond(['error' => 'Select a valid assignment.'], 422);
    }
    if ($assignmentId === null) {
        $sql .= ' LIMIT 200';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($assignments as &$assignment) {
        $assignment['id'] = (int) $assignment['id'];
        $assignment['class_id'] = (int) $assignment['class_id'];
        $assignment['allow_late'] = (bool) $assignment['allow_late'];
        $assignment['submission_id'] = $assignment['submission_id'] !== null
            ? (int) $assignment['submission_id']
            : null;
        $assignment['essay_text'] = essayPlainText((string) ($assignment['essay'] ?? ''));
    }
    unset($assignment);

    if ($assignmentId !== null) {
        if ($assignments === []) {
            apiRespond(['error' => 'Assignment not found or unavailable.'], 404);
        }
        apiRespond(['assignment' => $assignments[0]]);
    }

    apiRespond(['assignments' => $assignments]);
}

apiVerifyCsrf();
$input = apiReadJson();
$assignmentId = filter_var(
    $input['assignment_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$action = (string) ($input['action'] ?? '');
$essayInput = $input['essay'] ?? null;

if ($assignmentId === false || !in_array($action, ['save', 'submit'], true)) {
    apiRespond(['error' => 'Select an assignment and a valid action.'], 422);
}
if (!is_string($essayInput) || mb_strlen($essayInput) > 500000) {
    apiRespond(['error' => 'Writing must be text and cannot exceed 500,000 characters.'], 422);
}

$assignmentStmt = $pdo->prepare(
    'SELECT a.id, a.status, a.due_at, a.allow_late
     FROM assignments a
     INNER JOIN class_members cm ON cm.class_id = a.class_id AND cm.user_id = ?
     WHERE a.id = ?
     LIMIT 1'
);
$assignmentStmt->execute([$userId, $assignmentId]);
$assignment = $assignmentStmt->fetch(PDO::FETCH_ASSOC);
if (!$assignment || $assignment['status'] !== 'published') {
    apiRespond(['error' => 'This assignment is unavailable.'], 404);
}

try {
    $essay = sanitizeEssayHtml($essayInput);
} catch (Throwable $exception) {
    error_log('Unable to safely process native-app student writing: ' . $exception->getMessage());
    apiRespond(['error' => 'Your writing could not be saved safely. Please try again.'], 500);
}
$hasReturnedAt = hasTableColumn($pdo, 'submissions', 'returned_at');
$returnedAtColumn = $hasReturnedAt ? 'returned_at' : 'NULL AS returned_at';
$submissionStmt = $pdo->prepare(
    'SELECT id, status, ' . $returnedAtColumn . '
     FROM submissions
     WHERE assignment_id = ? AND student_id = ?
     LIMIT 1'
);
$submissionStmt->execute([$assignmentId, $userId]);
$submission = $submissionStmt->fetch(PDO::FETCH_ASSOC);

if ($submission && in_array($submission['status'], ['submitted', 'graded'], true)
    && empty($submission['returned_at'])) {
    apiRespond(['error' => 'This submission is locked.'], 409);
}

if ($action === 'submit') {
    if (trim(essayPlainText($essay)) === '') {
        apiRespond(['error' => 'You cannot submit an empty assignment.'], 422);
    }
    if (
        empty($assignment['allow_late'])
        && !empty($assignment['due_at'])
        && strtotime((string) $assignment['due_at']) < time()
    ) {
        apiRespond(['error' => 'This assignment no longer accepts late submissions.'], 409);
    }
}

if ($submission) {
    $updates = ['essay = ?', 'updated_at = CURRENT_TIMESTAMP'];
    $params = [$essay];
    if ($action === 'submit') {
        $updates[] = 'status = "submitted"';
        $updates[] = 'submitted_at = NOW()';
        if ($hasReturnedAt) {
            $updates[] = 'returned_at = NULL';
        }
    }
    $params[] = (int) $submission['id'];
    $save = $pdo->prepare(
        'UPDATE submissions SET ' . implode(', ', $updates) . '
         WHERE id = ? AND student_id = ?'
    );
    $params[] = $userId;
    $save->execute($params);
    $submissionId = (int) $submission['id'];
} else {
    $status = $action === 'submit' ? 'submitted' : 'draft';
    $submittedAt = $action === 'submit' ? 'NOW()' : 'NULL';
    $save = $pdo->prepare(
        'INSERT INTO submissions (assignment_id, student_id, essay, status, submitted_at)
         VALUES (?, ?, ?, ?, ' . $submittedAt . ')'
    );
    $save->execute([$assignmentId, $userId, $essay, $status]);
    $submissionId = (int) $pdo->lastInsertId();
}

apiRespond([
    'success' => true,
    'submission_id' => $submissionId,
    'submission_status' => $action === 'submit' ? 'submitted' : 'draft'
]);
