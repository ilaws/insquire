<?php

require_once dirname(__DIR__) . '/_bootstrap.php';
require_once dirname(__DIR__, 4) . '/includes/lti.php';

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? '');
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    apiRespond(['error' => 'Method not allowed.'], 405);
}
apiRequireMethod($method);
$user = apiRequireUser(['teacher']);
$teacherId = (int) $user['id'];

$hasGrade = hasTableColumn($pdo, 'submissions', 'grade');
$hasFeedback = hasTableColumn($pdo, 'submissions', 'teacher_feedback');
$hasReturnedAt = hasTableColumn($pdo, 'submissions', 'returned_at');
$hasGradedAt = hasTableColumn($pdo, 'submissions', 'graded_at');
$gradeColumn = $hasGrade ? 's.grade' : 'NULL AS grade';
$feedbackColumn = $hasFeedback ? 's.teacher_feedback' : 'NULL AS teacher_feedback';
$returnedColumn = $hasReturnedAt ? 's.returned_at' : 'NULL AS returned_at';

if ($method === 'GET') {
    $submissionId = isset($_GET['id'])
        ? filter_var($_GET['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
        : null;
    if ($submissionId === false) {
        apiRespond(['error' => 'Select a valid submission.'], 422);
    }

    $sql = 'SELECT s.id, s.assignment_id, s.essay, s.status, s.submitted_at,
                   ' . $gradeColumn . ', ' . $feedbackColumn . ', ' . $returnedColumn . ',
                   a.title AS assignment_title, a.prompt AS assignment_prompt,
                   a.instructions AS assignment_instructions, a.rubric_text,
                   c.id AS class_id, c.name AS class_name,
                   u.id AS student_id, u.name AS student_name
            FROM submissions s
            INNER JOIN assignments a ON a.id = s.assignment_id
            INNER JOIN classes c ON c.id = a.class_id
            INNER JOIN users u ON u.id = s.student_id
            WHERE c.teacher_id = ?
              AND (c.institution_id IS NULL OR EXISTS (
                  SELECT 1 FROM institution_members im
                  WHERE im.institution_id = c.institution_id AND im.user_id = ?
                    AND im.role IN ("admin", "teacher")
              ))';
    $params = [$teacherId, $teacherId];
    if ($submissionId !== null) {
        $sql .= ' AND s.id = ?';
        $params[] = $submissionId;
    }
    $sql .= ' ORDER BY CASE WHEN s.status = "submitted" THEN 0 ELSE 1 END,
              s.submitted_at DESC, s.id DESC';
    if ($submissionId === null) {
        $sql .= ' LIMIT 250';
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($submissions as &$submission) {
        foreach (['id', 'assignment_id', 'class_id', 'student_id'] as $field) {
            $submission[$field] = (int) $submission[$field];
        }
        $submission['essay_text'] = essayPlainText((string) $submission['essay']);
    }
    unset($submission);

    if ($submissionId !== null) {
        if ($submissions === []) {
            apiRespond(['error' => 'Submission not found.'], 404);
        }
        apiRespond(['submission' => $submissions[0]]);
    }
    apiRespond(['submissions' => $submissions]);
}

apiVerifyCsrf();
$input = apiReadJson();
$submissionId = filter_var(
    $input['submission_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$action = (string) ($input['action'] ?? '');
if ($submissionId === false || !in_array($action, ['grade', 'return'], true)) {
    apiRespond(['error' => 'Select a submission and a valid action.'], 422);
}

$ownershipStmt = $pdo->prepare(
    'SELECT s.id FROM submissions s
     INNER JOIN assignments a ON a.id = s.assignment_id
     INNER JOIN classes c ON c.id = a.class_id
     WHERE s.id = ? AND c.teacher_id = ?
       AND (c.institution_id IS NULL OR EXISTS (
           SELECT 1 FROM institution_members im
           WHERE im.institution_id = c.institution_id AND im.user_id = ?
             AND im.role IN ("admin", "teacher")
       ))
     LIMIT 1'
);
$ownershipStmt->execute([$submissionId, $teacherId, $teacherId]);
if (!$ownershipStmt->fetchColumn()) {
    apiRespond(['error' => 'Submission not found.'], 404);
}

$feedback = trim((string) ($input['teacher_feedback'] ?? ''));
if (mb_strlen($feedback) > 10000 || teacherFeedbackContainsProfanity($feedback)) {
    apiRespond(['error' => 'Feedback must be under 10,000 characters and free of profanity.'], 422);
}

$updates = [];
$values = [];
if ($hasFeedback) {
    $updates[] = 'teacher_feedback = ?';
    $values[] = $feedback;
}
if ($action === 'grade') {
    $grade = $input['grade'] ?? null;
    if (!$hasGrade || !is_numeric($grade) || (float) $grade < 0 || (float) $grade > 100) {
        apiRespond(['error' => 'Enter a grade from 0 to 100.'], 422);
    }
    $updates[] = 'grade = ?';
    $values[] = (string) $grade;
    $updates[] = 'status = "graded"';
    if ($hasGradedAt) {
        $updates[] = 'graded_at = CURRENT_TIMESTAMP';
    }
} else {
    $updates[] = 'status = "returned"';
    if ($hasReturnedAt) {
        $updates[] = 'returned_at = CURRENT_TIMESTAMP';
    }
    if ($hasGradedAt) {
        $updates[] = 'graded_at = CURRENT_TIMESTAMP';
    }
}
$values[] = $submissionId;
$updateStmt = $pdo->prepare('UPDATE submissions SET ' . implode(', ', $updates) . ' WHERE id = ?');
$updateStmt->execute($values);

$notice = null;
$warning = null;
if ($action === 'grade' && $hasGrade) {
    try {
        $notice = ltiPostGradeForSubmission($pdo, (int) $submissionId);
    } catch (Throwable $exception) {
        error_log('Canvas grade passback failed after native teacher grading: ' . $exception->getMessage());
        $warning = 'The grade was saved in InsQuire, but it could not be sent to Canvas.';
    }
}

apiRespond([
    'success' => true,
    'status' => $action === 'grade' ? 'graded' : 'returned',
    'notice' => $notice,
    'warning' => $warning
]);
