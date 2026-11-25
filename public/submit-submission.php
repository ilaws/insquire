<?php

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/csrf.php';

requireStudent();
verifyCsrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

$assignmentId = filter_input(
    INPUT_POST,
    'assignment_id',
    FILTER_VALIDATE_INT
);

if (!$assignmentId) {
    http_response_code(400);
    exit('Invalid assignment ID.');
}

$studentId = currentUserId();
$hasAllowLate = hasTableColumn($pdo, 'assignments', 'allow_late');
$hasReturnedAt = hasTableColumn($pdo, 'submissions', 'returned_at');

$lateColumn = $hasAllowLate ? 'a.allow_late' : '1 AS allow_late';

/*
 * Verify the student belongs to the class
 * and the assignment is available.
 */
$stmt = $pdo->prepare(
    'SELECT
        a.id,
        a.status,
        ' . $lateColumn . ',
        a.due_at
    FROM assignments a
    INNER JOIN class_members cm
        ON cm.class_id = a.class_id
    WHERE a.id = ?
        AND cm.user_id = ?
    LIMIT 1'
);

$stmt->execute([
    $assignmentId,
    $studentId
]);

$assignment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$assignment) {
    http_response_code(403);
    exit('Access denied.');
}

if ($assignment['status'] !== 'published') {
    redirect(
        'assignment.php?id=' . $assignmentId .
        '&error=' . urlencode(
            'This assignment is not currently available for submission.'
        )
    );
}

if (!$assignment['allow_late'] && $assignment['due_at'] && strtotime($assignment['due_at']) < time()) {
    redirect('assignment.php?id=' . $assignmentId . '&error=' . urlencode('This assignment no longer accepts late submissions.'));
}

/*
 * Find the student's submission.
 */
$stmt = $pdo->prepare(
    'SELECT
        id,
        essay,
        status,
        ' . ($hasReturnedAt ? 'returned_at' : 'NULL AS returned_at') . '
    FROM submissions
    WHERE assignment_id = ?
        AND student_id = ?
    LIMIT 1'
);

$stmt->execute([
    $assignmentId,
    $studentId
]);

$submission = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$submission) {
    redirect(
        'assignment.php?id=' . $assignmentId .
        '&error=' . urlencode(
            'Please save your writing before submitting.'
        )
    );
}

if (in_array($submission['status'], ['submitted', 'graded'], true) && empty($submission['returned_at'])) {
    redirect(
        'assignment.php?id=' . $assignmentId .
        '&submitted=1'
    );
}

$essay = essayPlainText((string) $submission['essay']);

if ($essay === '') {
    redirect(
        'assignment.php?id=' . $assignmentId .
        '&error=' . urlencode(
            'You cannot submit an empty assignment.'
        )
    );
}

/*
 * Mark the assignment as officially submitted.
 */
$stmt = $pdo->prepare(
    'UPDATE submissions
    SET
        status = ?,
        submitted_at = NOW()' . ($hasReturnedAt ? ', returned_at = NULL' : '') . '
    WHERE id = ?
        AND student_id = ?'
);

$stmt->execute([
    'submitted',
    (int) $submission['id'],
    $studentId
]);

redirect(
    'assignment.php?id=' . $assignmentId .
    '&submitted=1'
);
