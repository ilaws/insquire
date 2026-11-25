<?php

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireStudent();
verifyCsrf();

$isJsonRequest = (
    isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
);

$assignmentId = (int) ($_POST['assignment_id'] ?? 0);
if ($assignmentId <= 0) {
    if ($isJsonRequest) {
        jsonResponse([
            'success' => false,
            'error' => 'Missing assignment ID.'
        ], 400);
    }

    exit('Missing assignment ID.');
}

$userId = currentUserId();
$hasReturnedAt = hasTableColumn($pdo, 'submissions', 'returned_at');

$stmt = $pdo->prepare(
    'SELECT
        a.id,
        a.class_id,
        a.status AS assignment_status
    FROM assignments a
    INNER JOIN class_members cm
        ON cm.class_id = a.class_id
    WHERE a.id = ?
    AND cm.user_id = ?
    LIMIT 1'
);

$stmt->execute([
    $assignmentId,
    $userId
]);

$assignment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$assignment) {
    if ($isJsonRequest) {
        jsonResponse([
            'success' => false,
            'error' => 'You do not have access to this assignment.'
        ], 403);
    }

    http_response_code(403);
    exit('Access denied.');
}

if ($assignment['assignment_status'] !== 'published') {
    jsonResponse(['success' => false, 'error' => 'This assignment is closed.'], 409);
}

try {
    $essay = sanitizeEssayHtml((string) ($_POST['essay'] ?? ''));
} catch (Throwable $exception) {
    error_log('Unable to safely process formatted student writing: ' . $exception->getMessage());
    if ($isJsonRequest) {
        jsonResponse([
            'success' => false,
            'error' => 'Your writing could not be saved safely. Please try again or contact your teacher.'
        ], 500);
    }

    http_response_code(500);
    exit('Your writing could not be saved safely.');
}

$stmt = $pdo->prepare(
    'SELECT id, status, ' . ($hasReturnedAt ? 'returned_at' : 'NULL AS returned_at') . '
    FROM submissions
    WHERE assignment_id = ?
    AND student_id = ?
    LIMIT 1'
);

$stmt->execute([
    $assignmentId,
    $userId
]);

$submission = $stmt->fetch(PDO::FETCH_ASSOC);

if ($submission) {

    if (in_array($submission['status'], ['submitted', 'graded'], true) && empty($submission['returned_at'])) {
        jsonResponse(['success' => false, 'error' => 'This submission is locked.'], 409);
    }

    $stmt = $pdo->prepare(
        'UPDATE submissions
        SET essay = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
        AND student_id = ?'
    );

    $stmt->execute([
        $essay,
        (int) $submission['id'],
        $userId
    ]);

    $submissionId = (int) $submission['id'];

} else {

    $stmt = $pdo->prepare(
        'INSERT INTO submissions
        (
            assignment_id,
            student_id,
            essay,
            status
        )
        VALUES (?, ?, ?, ?)'
    );

    $stmt->execute([
        $assignmentId,
        $userId,
        $essay,
        'draft'
    ]);

    $submissionId = (int) $pdo->lastInsertId();
}

if ($isJsonRequest) {
    jsonResponse([
        'success' => true,
        'submission_id' => $submissionId,
        'saved_at' => gmdate('c')
    ]);
}

redirect(
    'assignment.php?id=' . $assignmentId . '&saved=1'
);
