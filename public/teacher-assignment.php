<?php

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireTeacher();

$assignmentId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT
);

if (!$assignmentId) {
    http_response_code(404);
    exit('Assignment not found.');
}

$teacherId = (int) currentUserId();

$stmt = $pdo->prepare(
    'SELECT
        a.*,
        c.name AS class_name
    FROM assignments a
    INNER JOIN classes c
        ON c.id = a.class_id
    WHERE a.id = ?
        AND c.teacher_id = ?
    LIMIT 1'
);

$stmt->execute([
    $assignmentId,
    $teacherId
]);

$assignment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$assignment) {
    http_response_code(403);
    exit('You do not have permission to view this assignment.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $newStatus = ($_POST['action'] ?? '') === 'close' ? 'closed' : 'published';
    $stmt = $pdo->prepare('UPDATE assignments SET status = ? WHERE id = ?');
    $stmt->execute([$newStatus, $assignmentId]);
    $assignment['status'] = $newStatus;
}

/*
|--------------------------------------------------------------------------
| Get students, submissions, and latest AI feedback
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare(
    'SELECT
        u.id AS student_id,
        u.name,
        u.email,

        s.id AS submission_id,
        s.essay,
        s.status AS submission_status,
        s.submitted_at,
        s.created_at AS submission_created_at,
        s.updated_at AS submission_updated_at,

        af.id AS feedback_id,
        af.feedback AS ai_feedback,
        af.created_at AS feedback_created_at

    FROM class_members cm

    INNER JOIN users u
        ON u.id = cm.user_id

    LEFT JOIN submissions s
        ON s.assignment_id = ?
        AND s.student_id = u.id

    LEFT JOIN ai_feedback af
        ON af.id = (
            SELECT af2.id
            FROM ai_feedback af2
            WHERE af2.submission_id = s.id
            ORDER BY af2.created_at DESC, af2.id DESC
            LIMIT 1
        )

    WHERE cm.class_id = ?
        AND u.role = ?

    ORDER BY
        CASE
            WHEN s.status = "submitted" THEN 0
            WHEN s.status = "draft" THEN 1
            ELSE 2
        END,
        u.name ASC'
);

$stmt->execute([
    $assignmentId,
    (int) $assignment['class_id'],
    'student'
]);

$students = $stmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Analyze AI feedback for students needing attention
|--------------------------------------------------------------------------
*/

$submittedCount = 0;
$draftCount = 0;
$notStartedCount = 0;
$needsHelpCount = 0;
$feedbackCount = 0;

$needsHelpStudents = [];

foreach ($students as &$student) {

    $student['needs_help'] = false;
    $student['trouble_count'] = 0;
    $student['feedback_summary'] = '';

    /*
    |----------------------------------------------------------------------
    | Submission status counts
    |----------------------------------------------------------------------
    */

    if ($student['submission_status'] === 'submitted') {
        $submittedCount++;
    } elseif ($student['submission_status'] === 'draft') {
        $draftCount++;
    } else {
        $notStartedCount++;
    }

    /*
    |----------------------------------------------------------------------
    | Parse latest AI feedback
    |----------------------------------------------------------------------
    */

    if (!empty($student['ai_feedback'])) {

        $feedbackCount++;

        $decoded = json_decode(
            $student['ai_feedback'],
            true
        );

        if (is_array($decoded)) {

            /*
            |------------------------------------------------------------------
            | Summary
            |------------------------------------------------------------------
            */

            if (!empty($decoded['summary'])) {
                $student['feedback_summary'] = trim(
                    (string) $decoded['summary']
                );
            }

            /*
            |------------------------------------------------------------------
            | Trouble areas
            |------------------------------------------------------------------
            */

            if (
                isset($decoded['trouble_areas']) &&
                is_array($decoded['trouble_areas'])
            ) {

                $student['trouble_count'] = count(
                    $decoded['trouble_areas']
                );

                if ($student['trouble_count'] >= 2) {
                    $student['needs_help'] = true;
                }
            }

            /*
            |------------------------------------------------------------------
            | Improvements
            |------------------------------------------------------------------
            */

            if (
                isset($decoded['improvements']) &&
                is_array($decoded['improvements'])
            ) {

                if (count($decoded['improvements']) >= 3) {
                    $student['needs_help'] = true;
                }
            }

        } else {

            /*
            |------------------------------------------------------------------
            | Older feedback may be plain text / Markdown.
            | Flag serious language conservatively.
            |------------------------------------------------------------------
            */

            $feedbackText = strtolower(
                strip_tags($student['ai_feedback'])
            );

            $attentionTerms = [
                'major improvement',
                'significant improvement',
                'needs improvement',
                'does not meet',
                'doesn\'t meet',
                'struggling',
                'needs attention'
            ];

            foreach ($attentionTerms as $term) {
                if (strpos($feedbackText, $term) !== false) {
                    $student['needs_help'] = true;
                    break;
                }
            }
        }
    }

    /*
    |----------------------------------------------------------------------
    | Empty or extremely short submissions may need attention
    |----------------------------------------------------------------------
    */

    if (
        !empty($student['essay']) &&
        str_word_count(strip_tags($student['essay'])) < 15
    ) {
        $student['needs_help'] = true;
    }

    /*
    |----------------------------------------------------------------------
    | Count students needing help
    |----------------------------------------------------------------------
    */

    if ($student['needs_help']) {

        $needsHelpCount++;

        if (!empty($student['submission_id'])) {
            $needsHelpStudents[] = $student;
        }
    }
}

unset($student);


/*
|--------------------------------------------------------------------------
| Title / Header
|--------------------------------------------------------------------------
*/

$title = 'Assignment Submissions';

require_once dirname(__DIR__) . '/includes/header.php';

?>

<div class="page-header">

<div>

    <p>
        <a href="class.php?id=<?= (int) $assignment['class_id'] ?>">
            ← Back to <?= e($assignment['class_name']) ?>
        </a>
    </p>

    <h1><?= e($assignment['title']) ?></h1>

    <p>
        Assignment Overview
    </p>

</div>

<div class="card">
    <strong>Assignment status:</strong> <?= e(ucfirst($assignment['status'])) ?>
    <form method="post" style="display:inline; margin-left:12px;">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <button class="button secondary" type="submit" name="action" value="<?= $assignment['status'] === 'closed' ? 'publish' : 'close' ?>">
            <?= $assignment['status'] === 'closed' ? 'Reopen assignment' : 'Close submissions' ?>
        </button>
    </form>
</div>

</div>

<div class="grid">

<div class="card">

    <h2><?= count($students) ?></h2>

    <p>Total Students</p>

</div>


<div class="card">

    <h2><?= $submittedCount ?></h2>

    <p>Submitted</p>

</div>


<div class="card">

    <h2><?= $draftCount ?></h2>

    <p>Drafts</p>

</div>


<div class="card">

    <h2><?= $notStartedCount ?></h2>

    <p>Not Started</p>

</div>


<div class="card">

    <h2><?= $needsHelpCount ?></h2>

    <p>May Need Extra Help</p>

</div>

</div>

<?php if ($needsHelpStudents): ?>

<div class="card">

    <h2>Students Who May Need Extra Help</h2>

    <p>
        These students were identified based on their latest AI writing
        feedback, multiple trouble areas, significant revision needs, or
        extremely short writing.
    </p>

    <div class="list">

        <?php foreach ($needsHelpStudents as $student): ?>

            <div class="list-item">

                <div>

                    <strong>
                        <?= e($student['name']) ?>
                    </strong>

                    <?php if (
                        $student['trouble_count'] > 0
                    ): ?>

                        <div>
                            <?= (int) $student['trouble_count'] ?>
                            AI-highlighted trouble area<?= $student['trouble_count'] === 1 ? '' : 's' ?>
                        </div>

                    <?php endif; ?>


                    <?php if (
                        !empty($student['feedback_summary'])
                    ): ?>

                        <div style="margin-top: 6px;">

                            <small>
                                <?= e(
                                    mb_strimwidth(
                                        $student['feedback_summary'],
                                        0,
                                        180,
                                        '...'
                                    )
                                ) ?>
                            </small>

                        </div>

                    <?php endif; ?>

                </div>


                <a
                    class="button"
                    href="teacher-submission.php?id=<?= (int) $student['submission_id'] ?>"
                >
                    Review Student
                </a>

            </div>

        <?php endforeach; ?>

    </div>

</div>

<?php endif; ?>

<div class="card">

<h2>Assignment Details</h2>


<p>
    <strong>Prompt:</strong>
</p>

<p>
    <?= nl2br(e($assignment['prompt'])) ?>
</p>


<?php if (!empty($assignment['instructions'])): ?>

    <p>
        <strong>Instructions:</strong>
    </p>

    <p>
        <?= nl2br(e($assignment['instructions'])) ?>
    </p>

<?php endif; ?>


<?php if (!empty($assignment['rubric_text'])): ?>

    <p>
        <strong>Rubric:</strong>
    </p>

    <p>
        <?= nl2br(e($assignment['rubric_text'])) ?>
    </p>

<?php endif; ?>


<?php if (!empty($assignment['due_at'])): ?>

    <p>

        <strong>Due:</strong>

        <?= e(
            date(
                'M j, Y g:i A',
                strtotime($assignment['due_at'])
            )
        ) ?>

    </p>

<?php endif; ?>

</div>

<div class="card">

<h2>Student Submissions</h2>


<?php if (!$students): ?>

    <p>
        No students are currently enrolled in this class.
    </p>


<?php else: ?>


    <div style="overflow-x: auto;">

        <table
            style="
                width: 100%;
                border-collapse: collapse;
            "
        >

            <thead>

                <tr>

                    <th style="text-align:left; padding:12px;">
                        Student
                    </th>

                    <th style="text-align:left; padding:12px;">
                        Status
                    </th>

                    <th style="text-align:left; padding:12px;">
                        AI Feedback
                    </th>

                    <th style="text-align:left; padding:12px;">
                        Last Activity
                    </th>

                    <th style="text-align:right; padding:12px;">
                        View
                    </th>

                </tr>

            </thead>


            <tbody>

                <?php foreach ($students as $student): ?>

                    <tr>

                        <td style="padding:12px;">

                            <strong>
                                <?= e($student['name']) ?>
                            </strong>

                            <br>

                            <small>
                                <?= e($student['email']) ?>
                            </small>


                            <?php if ($student['needs_help']): ?>

                                <div
                                    style="
                                        margin-top:6px;
                                        font-size:12px;
                                        font-weight:bold;
                                    "
                                >
                                    May need extra help
                                </div>

                            <?php endif; ?>

                        </td>


                        <td style="padding:12px;">

                            <?php if (
                                $student['submission_status'] === 'submitted'
                            ): ?>

                                <strong>
                                    Submitted
                                </strong>

                            <?php elseif (
                                $student['submission_status'] === 'draft'
                            ): ?>

                                Draft Saved

                            <?php else: ?>

                                Not Started

                            <?php endif; ?>

                        </td>


                        <td style="padding:12px;">

                            <?php if (!empty($student['feedback_id'])): ?>

                                <?php if (
                                    $student['trouble_count'] > 0
                                ): ?>

                                    <?= (int) $student['trouble_count'] ?>
                                    trouble area<?= $student['trouble_count'] === 1 ? '' : 's' ?>

                                <?php else: ?>

                                    Feedback Available

                                <?php endif; ?>

                            <?php else: ?>

                                No AI Feedback

                            <?php endif; ?>

                        </td>


                        <td style="padding:12px;">

                            <?php if (
                                !empty(
                                    $student['submission_updated_at']
                                )
                            ): ?>

                                <?= e(
                                    date(
                                        'M j, Y g:i A',
                                        strtotime(
                                            $student['submission_updated_at']
                                        )
                                    )
                                ) ?>

                            <?php else: ?>

                                Not submitted

                            <?php endif; ?>

                        </td>


                        <td
                            style="
                                padding:12px;
                                text-align:right;
                            "
                        >

                            <?php if (
                                !empty(
                                    $student['submission_id']
                                )
                            ): ?>

                                <a
                                    class="button"
                                    href="teacher-submission.php?id=<?= (int) $student['submission_id'] ?>"
                                >
                                    View Writing
                                </a>

                            <?php else: ?>

                                <span>
                                    No submission
                                </span>

                            <?php endif; ?>

                        </td>

                    </tr>

                <?php endforeach; ?>

            </tbody>

        </table>

    </div>


<?php endif; ?>

</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
