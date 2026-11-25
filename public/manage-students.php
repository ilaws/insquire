<?php

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireTeacher();

$classId = filter_input(
    INPUT_GET,
    'class_id',
    FILTER_VALIDATE_INT
);

if (!$classId) {
    http_response_code(404);
    exit('Class not found.');
}

$teacherId = currentUserId();

$class = getClassForTeacher(
    $pdo,
    $classId,
    $teacherId
);

if (!$class) {
    http_response_code(403);
    exit('You do not have permission to manage this class.');
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'remove_student') {

        $studentId = filter_input(
            INPUT_POST,
            'student_id',
            FILTER_VALIDATE_INT
        );

        if (!$studentId) {
            $error = 'Invalid student.';
        } else {

            $stmt = $pdo->prepare(
                'SELECT cm.id
                FROM class_members cm
                INNER JOIN users u
                    ON u.id = cm.user_id
                WHERE cm.class_id = ?
                    AND cm.user_id = ?
                    AND u.role = ?
                LIMIT 1'
            );

            $stmt->execute([
                $classId,
                $studentId,
                'student'
            ]);

            $membership = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$membership) {
                $error = 'That student is not enrolled in this class.';
            } else {

                $stmt = $pdo->prepare(
                    'DELETE FROM class_members
                    WHERE id = ?
                    AND class_id = ?'
                );

                $stmt->execute([
                    (int) $membership['id'],
                    $classId
                ]);

                $message = 'Student removed from the class.';
            }
        }
    }
}

$stmt = $pdo->prepare(
    'SELECT
        u.id,
        u.name,
        u.email,
        u.status,
        u.created_at,
        cm.joined_at,
        (
            SELECT COUNT(*)
            FROM submissions s
            INNER JOIN assignments a
                ON a.id = s.assignment_id
            WHERE s.student_id = u.id
                AND a.class_id = cm.class_id
                AND s.status = ?
        ) AS submitted_count,
        (
            SELECT COUNT(*)
            FROM submissions s
            INNER JOIN assignments a
                ON a.id = s.assignment_id
            WHERE s.student_id = u.id
                AND a.class_id = cm.class_id
        ) AS total_submission_count
    FROM class_members cm
    INNER JOIN users u
        ON u.id = cm.user_id
    WHERE cm.class_id = ?
        AND u.role = ?
    ORDER BY u.name ASC, u.email ASC'
);

$stmt->execute([
    'submitted',
    $classId,
    'student'
]);

$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare(
    'SELECT COUNT(*)
    FROM assignments
    WHERE class_id = ?'
);

$stmt->execute([$classId]);

$assignmentCount = (int) $stmt->fetchColumn();

$title = 'Manage Students - ' . $class['name'];

require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <p>
            <a href="class.php?id=<?= (int) $classId ?>">
                ← Back to Class
            </a>
        </p>

    <h1>Manage Students</h1>

    <p>
        <?= e($class['name']) ?>
    </p>
</div>

</div>

<?php if ($message !== ''): ?>

<div class="card">
    <p><?= e($message) ?></p>
</div>

<?php endif; ?>

<?php if ($error !== ''): ?>

<div class="card">
    <p><?= e($error) ?></p>
</div>

<?php endif; ?>

<div class="grid">

<div class="card">
    <h2><?= count($students) ?></h2>
    <p>Students Enrolled</p>
</div>

<div class="card">
    <h2><?= $assignmentCount ?></h2>
    <p>Assignments</p>
</div>

<div class="card">
    <h2><?= e($class['join_code']) ?></h2>
    <p>Class Join Code</p>
</div>

</div>

<div class="card">

<h2>Class Roster</h2>

<p>
    Students currently enrolled in this class are listed below.
</p>

<?php if ($students): ?>

    <div style="overflow-x: auto;">

        <table style="width: 100%; border-collapse: collapse;">

            <thead>
                <tr>
                    <th style="text-align: left; padding: 12px;">
                        Student
                    </th>

                    <th style="text-align: left; padding: 12px;">
                        Email
                    </th>

                    <th style="text-align: left; padding: 12px;">
                        Joined
                    </th>

                    <th style="text-align: center; padding: 12px;">
                        Submitted
                    </th>

                    <th style="text-align: right; padding: 12px;">
                        Actions
                    </th>
                </tr>
            </thead>

            <tbody>

                <?php foreach ($students as $student): ?>

                    <tr>

                        <td style="padding: 12px;">

                            <strong>
                                <?= e($student['name']) ?>
                            </strong>

                            <?php if ($student['status'] !== 'active'): ?>

                                <br>

                                <small>
                                    <?= e(ucfirst($student['status'])) ?>
                                </small>

                            <?php endif; ?>

                        </td>

                        <td style="padding: 12px;">
                            <?= e($student['email']) ?>
                        </td>

                        <td style="padding: 12px;">

                            <?= e(
                                date(
                                    'M j, Y',
                                    strtotime(
                                        $student['joined_at']
                                    )
                                )
                            ) ?>

                        </td>

                        <td style="padding: 12px; text-align: center;">

                            <?= (int) $student['submitted_count'] ?>

                        </td>

                        <td style="padding: 12px; text-align: right;">

                            <form
                                method="post"
                                onsubmit="return confirm('Remove this student from the class?');"
                                style="display: inline;"
                            >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="remove_student"
                                >

                                <input
                                    type="hidden"
                                    name="student_id"
                                    value="<?= (int) $student['id'] ?>"
                                >

                                <button
                                    type="submit"
                                    class="button"
                                >
                                    Remove
                                </button>

                            </form>

                        </td>

                    </tr>

                <?php endforeach; ?>

            </tbody>

        </table>

    </div>

<?php else: ?>

    <p>
        No students have joined this class yet.
    </p>

    <p>
        Share this join code with your students:
    </p>

    <div class="join-code">
        <?= e($class['join_code']) ?>
    </div>

<?php endif; ?>

</div>

<div class="card">

<h2>Class Information</h2>

<p>
    <strong>Class Name:</strong>
    <?= e($class['name']) ?>
</p>

<?php if (!empty($class['description'])): ?>

    <p>
        <strong>Description:</strong>
        <?= e($class['description']) ?>
    </p>

<?php endif; ?>

<p>
    <strong>Join Code:</strong>
    <?= e($class['join_code']) ?>
</p>

</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
