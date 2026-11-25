<?php

require_once dirname(__DIR__) . '/_bootstrap.php';

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? '');
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    apiRespond(['error' => 'Method not allowed.'], 405);
}
apiRequireMethod($method);
$user = apiRequireUser(['teacher']);
$teacherId = (int) $user['id'];

if ($method === 'GET') {
    $classId = isset($_GET['class_id'])
        ? filter_var($_GET['class_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
        : null;
    if ($classId === false) {
        apiRespond(['error' => 'Select a valid class.'], 422);
    }

    $allowLateColumn = hasTableColumn($pdo, 'assignments', 'allow_late')
        ? 'a.allow_late'
        : '1 AS allow_late';
    $sql = 'SELECT a.id, a.class_id, a.title, a.prompt, a.instructions, a.rubric_text,
                   a.due_at, ' . $allowLateColumn . ', a.status, c.name AS class_name,
                   (SELECT COUNT(*) FROM submissions s
                    WHERE s.assignment_id = a.id AND s.status = "submitted") AS pending_submissions
            FROM assignments a
            INNER JOIN classes c ON c.id = a.class_id
            WHERE c.teacher_id = ?
              AND (c.institution_id IS NULL OR EXISTS (
                  SELECT 1 FROM institution_members im
                  WHERE im.institution_id = c.institution_id AND im.user_id = ?
                    AND im.role IN ("admin", "teacher")
              ))';
    $params = [$teacherId, $teacherId];
    if ($classId !== null) {
        $sql .= ' AND c.id = ?';
        $params[] = $classId;
    }
    $sql .= ' ORDER BY a.updated_at DESC, a.id DESC LIMIT 250';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($assignments as &$assignment) {
        $assignment['id'] = (int) $assignment['id'];
        $assignment['class_id'] = (int) $assignment['class_id'];
        $assignment['allow_late'] = (bool) $assignment['allow_late'];
        $assignment['pending_submissions'] = (int) $assignment['pending_submissions'];
    }
    unset($assignment);
    apiRespond(['assignments' => $assignments]);
}

apiVerifyCsrf();
$input = apiReadJson();
$action = (string) ($input['action'] ?? 'create');
$classId = filter_var(
    $input['class_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$title = trim((string) ($input['title'] ?? ''));
$prompt = trim((string) ($input['prompt'] ?? ''));
$instructions = trim((string) ($input['instructions'] ?? ''));
$rubric = trim((string) ($input['rubric_text'] ?? ''));
$dueAtInput = trim((string) ($input['due_at'] ?? ''));
$allowLate = filter_var($input['allow_late'] ?? true, FILTER_VALIDATE_BOOLEAN);
$status = (string) ($input['status'] ?? 'published');
$assignmentId = filter_var(
    $input['assignment_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if (!in_array($action, ['create', 'update'], true)
    || ($action === 'update' && ($assignmentId === false || $assignmentId === null))
    || $classId === false || $title === '' || mb_strlen($title) > 200 || $prompt === ''
    || mb_strlen($prompt) > 50000 || mb_strlen($instructions) > 50000 || mb_strlen($rubric) > 100000) {
    apiRespond(['error' => 'Choose a class and provide a title and prompt within the allowed lengths.'], 422);
}
if (!in_array($status, ['draft', 'published', 'closed'], true)) {
    apiRespond(['error' => 'Choose a valid assignment status.'], 422);
}

$dueAt = null;
if ($dueAtInput !== '') {
    $parsedDueAt = DateTime::createFromFormat('!Y-m-d\TH:i', $dueAtInput);
    $dateErrors = DateTime::getLastErrors();
    if (!$parsedDueAt || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
        apiRespond(['error' => 'Enter a valid due date and time.'], 422);
    }
    $dueAt = $parsedDueAt->format('Y-m-d H:i:s');
}

$classStmt = $pdo->prepare(
    'SELECT c.id FROM classes c
     WHERE c.id = ? AND c.teacher_id = ? AND c.status = "active"
       AND (c.institution_id IS NULL OR EXISTS (
           SELECT 1 FROM institution_members im
           WHERE im.institution_id = c.institution_id AND im.user_id = ?
             AND im.role IN ("admin", "teacher")
       ))
     LIMIT 1'
);
$classStmt->execute([$classId, $teacherId, $teacherId]);
if (!$classStmt->fetchColumn()) {
    apiRespond(['error' => 'Class not found or unavailable.'], 404);
}

$assignmentValues = [
    'class_id' => $classId,
    'title' => $title,
    'prompt' => $prompt,
    'instructions' => $instructions !== '' ? $instructions : null,
    'rubric_text' => $rubric !== '' ? $rubric : null,
    'due_at' => $dueAt,
    'status' => $status
];
if (hasTableColumn($pdo, 'assignments', 'allow_late')) {
    $assignmentValues['allow_late'] = $allowLate ? 1 : 0;
}
if (hasTableColumn($pdo, 'assignments', 'late_penalty_percent') && $action === 'create') {
    $assignmentValues['late_penalty_percent'] = 0;
}

if ($action === 'update') {
    $assignmentOwnerStmt = $pdo->prepare(
        'SELECT a.id FROM assignments a
         INNER JOIN classes c ON c.id = a.class_id
         WHERE a.id = ? AND a.class_id = ? AND c.teacher_id = ?
           AND (c.institution_id IS NULL OR EXISTS (
               SELECT 1 FROM institution_members im
               WHERE im.institution_id = c.institution_id AND im.user_id = ?
                 AND im.role IN ("admin", "teacher")
           ))
         LIMIT 1'
    );
    $assignmentOwnerStmt->execute([$assignmentId, $classId, $teacherId, $teacherId]);
    if (!$assignmentOwnerStmt->fetchColumn()) {
        apiRespond(['error' => 'Assignment not found in this class.'], 404);
    }
    $updates = [];
    $values = [];
    foreach ($assignmentValues as $column => $value) {
        $updates[] = $column . ' = ?';
        $values[] = $value;
    }
    $values[] = $assignmentId;
    $updateStmt = $pdo->prepare('UPDATE assignments SET ' . implode(', ', $updates) . ' WHERE id = ?');
    $updateStmt->execute($values);
    apiRespond(['success' => true, 'assignment_id' => (int) $assignmentId]);
}

$columns = array_keys($assignmentValues);
$values = array_values($assignmentValues);
$placeholders = array_fill(0, count($columns), '?');
$stmt = $pdo->prepare(
    'INSERT INTO assignments (' . implode(', ', $columns) . ')
     VALUES (' . implode(', ', $placeholders) . ')'
);
$stmt->execute($values);

apiRespond(['success' => true, 'assignment_id' => (int) $pdo->lastInsertId()], 201);
