<?php

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/lti.php';

requireTeacher();

$submissionId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT
);

if (!$submissionId) {
    http_response_code(404);
    exit('Submission not found.');
}

$teacherId = (int) currentUserId();


/*
|--------------------------------------------------------------------------
| Load submission and verify teacher ownership
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare(
    'SELECT
        s.*,

        u.id AS student_id,
        u.name AS student_name,
        u.email AS student_email,

        a.id AS assignment_id,
        a.title AS assignment_title,
        a.prompt AS assignment_prompt,
        a.instructions AS assignment_instructions,
        a.rubric_text,

        c.id AS class_id,
        c.name AS class_name,
        c.teacher_id

    FROM submissions s

    INNER JOIN users u
        ON u.id = s.student_id

    INNER JOIN assignments a
        ON a.id = s.assignment_id

    INNER JOIN classes c
        ON c.id = a.class_id

    WHERE s.id = ?
        AND c.teacher_id = ?

    LIMIT 1'
);

$stmt->execute([
    $submissionId,
    $teacherId
]);

$submission = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$submission) {
    http_response_code(403);
    exit('You do not have permission to view this submission.');
}


/*
|--------------------------------------------------------------------------
| Detect optional submission columns safely
|--------------------------------------------------------------------------
*/

$submissionColumns = [];

try {
    $columnStmt = $pdo->query('SHOW COLUMNS FROM submissions');

    foreach ($columnStmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $submissionColumns[] = $column['Field'];
    }
} catch (Throwable $e) {
    $submissionColumns = [];
}

$hasTeacherFeedback = in_array(
    'teacher_feedback',
    $submissionColumns,
    true
);

$hasReturnedAt = in_array(
    'returned_at',
    $submissionColumns,
    true
);

$hasGradedAt = in_array(
    'graded_at',
    $submissionColumns,
    true
);

$hasGrade = in_array(
    'grade',
    $submissionColumns,
    true
);


/*
|--------------------------------------------------------------------------
| Handle teacher actions
|--------------------------------------------------------------------------
*/

$message = '';
$error = '';
$warning = '';
$canvasSyncNotice = (string) ($_SESSION['teacher_submission_canvas_sync_notice'] ?? '');
$canvasSyncWarning = (string) ($_SESSION['teacher_submission_canvas_sync_warning'] ?? '');
unset($_SESSION['teacher_submission_canvas_sync_notice'], $_SESSION['teacher_submission_canvas_sync_warning']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verifyCsrf();

    $action = $_POST['action'] ?? '';

    /*
    |--------------------------------------------------------------------------
    | Save teacher feedback
    |--------------------------------------------------------------------------
    */

    if ($action === 'save_feedback') {

        $teacherFeedback = trim(
            (string) ($_POST['teacher_feedback'] ?? '')
        );

        if (teacherFeedbackContainsProfanity($teacherFeedback)) {
            $error = 'Please remove profanity from teacher feedback before saving.';

        } elseif (!$hasTeacherFeedback) {

            $error = 'The teacher_feedback column is missing from the submissions table.';

        } else {

            try {

                $stmt = $pdo->prepare(
                    'UPDATE submissions
                    SET teacher_feedback = ?
                    WHERE id = ?'
                );

                $stmt->execute([
                    $teacherFeedback,
                    $submissionId
                ]);

                $message = 'Teacher feedback saved.';

                $submission['teacher_feedback'] = $teacherFeedback;
                if ($hasGrade && is_numeric($submission['grade'] ?? null)) {
                    try {
                        $gradeSyncMessage = ltiPostGradeForSubmission($pdo, $submissionId);
                        if ($gradeSyncMessage !== null) {
                            $message .= ' ' . $gradeSyncMessage;
                        }
                    } catch (Throwable $exception) {
                        error_log('Canvas feedback passback failed after saving teacher feedback: ' . $exception->getMessage());
                        $warning = 'Teacher feedback was saved in InsQuire, but it could not be sent to Canvas.';
                    }
                }

            } catch (Throwable $e) {

                $error = 'Unable to save teacher feedback.';
            }
        }
    }

    if ($action === 'add_submission_comment') {
        $anchorText = (string) ($_POST['anchor_text'] ?? '');
        $contextBefore = (string) ($_POST['context_before'] ?? '');
        $contextAfter = (string) ($_POST['context_after'] ?? '');
        $commentBody = trim((string) ($_POST['comment_body'] ?? ''));
        $plainEssay = essayPlainText((string) ($submission['essay'] ?? ''));
        $normalizedEssay = preg_replace('/\s+/u', ' ', $plainEssay);
        $normalizedAnchor = preg_replace('/\s+/u', ' ', trim($anchorText));

        if (trim($anchorText) === '' || mb_strlen($anchorText) > 3000
            || $normalizedEssay === null || $normalizedAnchor === null
            || strpos($normalizedEssay, $normalizedAnchor) === false) {
            $error = 'Select a portion of the student writing before adding a comment.';
        } elseif ($commentBody === '' || mb_strlen($commentBody) > 5000) {
            $error = 'Enter a comment of 1 to 5,000 characters.';
        } elseif (teacherFeedbackContainsProfanity($commentBody)) {
            $error = 'Please remove profanity from the comment before saving.';
        } else {
            try {
                $commentStmt = $pdo->prepare(
                    'INSERT INTO submission_comments
                     (submission_id, teacher_id, anchor_text, context_before, context_after, body)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                $commentStmt->execute([
                    $submissionId,
                    $teacherId,
                    $anchorText,
                    mb_substr($contextBefore, -160),
                    mb_substr($contextAfter, 0, 160),
                    $commentBody
                ]);
                if ($hasGrade && is_numeric($submission['grade'] ?? null)) {
                    try {
                        $gradeSyncMessage = ltiPostGradeForSubmission($pdo, $submissionId);
                        if ($gradeSyncMessage !== null) {
                            $_SESSION['teacher_submission_canvas_sync_notice'] = $gradeSyncMessage;
                        }
                    } catch (Throwable $exception) {
                        error_log('Canvas comment passback failed after saving a writing comment: ' . $exception->getMessage());
                        $_SESSION['teacher_submission_canvas_sync_warning'] =
                            'The comment was saved, but the grade and feedback could not be sent to Canvas.';
                    }
                }
                redirect('teacher-submission.php?id=' . $submissionId . '&comment_added=1');
            } catch (Throwable $exception) {
                error_log('Could not save submission comment: ' . $exception->getMessage());
                $error = 'Unable to save this comment. Please try again.';
            }
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Return submission to student
    |--------------------------------------------------------------------------
    */

    if ($action === 'return_submission') {

        $teacherFeedback = trim(
            (string) ($_POST['teacher_feedback'] ?? '')
        );

        if (teacherFeedbackContainsProfanity($teacherFeedback)) {
            $error = 'Please remove profanity from teacher feedback before returning this submission.';
        } else {
            try {
                $updates = [];
                $params = [];

                if ($hasTeacherFeedback) {
                    $updates[] = 'teacher_feedback = ?';
                    $params[] = $teacherFeedback;
                }

                $updates[] = 'status = ?';
                $params[] = 'returned';

                if ($hasReturnedAt) {
                    $updates[] = 'returned_at = CURRENT_TIMESTAMP';
                }

                if ($hasGradedAt) {
                    $updates[] = 'graded_at = CURRENT_TIMESTAMP';
                }

                if (!empty($updates)) {
                    $params[] = $submissionId;

                    $sql =
                        'UPDATE submissions
                        SET ' . implode(', ', $updates) . '
                        WHERE id = ?';

                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);

                    $message = 'Assignment returned to the student.';
                    $submission['status'] = 'returned';

                    if ($hasTeacherFeedback) {
                        $submission['teacher_feedback'] = $teacherFeedback;
                    }
                    if ($hasGrade && is_numeric($submission['grade'] ?? null)) {
                        try {
                            $gradeSyncMessage = ltiPostGradeForSubmission($pdo, $submissionId);
                            if ($gradeSyncMessage !== null) {
                                $message .= ' ' . $gradeSyncMessage;
                            }
                        } catch (Throwable $exception) {
                            error_log('Canvas grade passback failed after returning submission: ' . $exception->getMessage());
                            $warning = 'The submission was returned, but its grade could not be sent to Canvas.';
                        }
                    }
                }
            } catch (Throwable $e) {

                $error = 'Unable to return this submission. Run the latest database/migrations.sql migration so submissions support the returned status.';
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Save teacher grade
    |--------------------------------------------------------------------------
    */

    if ($action === 'save_grade') {

        $grade = trim(
            (string) ($_POST['grade'] ?? '')
        );

        if (!$hasGrade) {

            $error = 'The grade column is missing from the submissions table.';

        } elseif (
            $grade === '' ||
            !is_numeric($grade) ||
            (float) $grade < 0 ||
            (float) $grade > 100
        ) {
            $error = 'Enter a grade from 0 to 100.';

        } else {

            try {

                $updates = [
                    'grade = ?',
                    'status = ?'
                ];

                $params = [
                    $grade,
                    'graded'
                ];

                if ($hasGradedAt) {
                    $updates[] = 'graded_at = CURRENT_TIMESTAMP';
                }

                $params[] = $submissionId;

                $stmt = $pdo->prepare(
                    'UPDATE submissions
                    SET ' . implode(', ', $updates) . '
                    WHERE id = ?'
                );

                $stmt->execute($params);

                $submission['grade'] = $grade;
                $submission['status'] = 'graded';

                $message = 'Grade saved and assignment marked graded.';
                try {
                    $gradeSyncMessage = ltiPostGradeForSubmission($pdo, $submissionId);
                    if ($gradeSyncMessage !== null) {
                        $message .= ' ' . $gradeSyncMessage;
                    }
                } catch (Throwable $exception) {
                    error_log('Canvas grade passback failed after saving grade: ' . $exception->getMessage());
                    $warning = 'The grade was saved in InsQuire, but it could not be sent to Canvas.';
                }

            } catch (Throwable $e) {

                $error = 'Unable to save grade.';
            }
        }
    }
}

$commentStmt = $pdo->prepare(
    'SELECT sc.id, sc.anchor_text, sc.context_before, sc.context_after,
            sc.body, sc.created_at, u.name AS teacher_name
     FROM submission_comments sc
     INNER JOIN users u ON u.id = sc.teacher_id
     WHERE sc.submission_id = ?
     ORDER BY sc.id'
);
$commentStmt->execute([$submissionId]);
$submissionComments = $commentStmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Get latest student AI feedback
|--------------------------------------------------------------------------
*/

$studentAiFeedback = null;
$studentAiData = null;

try {

    $stmt = $pdo->prepare(
        'SELECT
            id,
            feedback,
            model_name,
            created_at
        FROM ai_feedback
        WHERE submission_id = ?
        ORDER BY created_at DESC, id DESC
        LIMIT 1'
    );

    $stmt->execute([
        $submissionId
    ]);

    $studentAiFeedback = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($studentAiFeedback) {

        $decoded = json_decode(
            $studentAiFeedback['feedback'],
            true
        );

        if (is_array($decoded)) {
            $studentAiData = $decoded;
        }
    }

} catch (Throwable $e) {
    $studentAiFeedback = null;
}


/*
|--------------------------------------------------------------------------
| Get latest teacher AI evaluation
|--------------------------------------------------------------------------
*/

$teacherAiEvaluation = null;
$teacherAiData = null;

try {

    $stmt = $pdo->prepare(
        'SELECT *
        FROM teacher_ai_evaluations
        WHERE submission_id = ?
        ORDER BY created_at DESC, id DESC
        LIMIT 1'
    );

    $stmt->execute([
        $submissionId
    ]);

    $teacherAiEvaluation = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($teacherAiEvaluation) {

        /*
        | Support different possible evaluation column names
        */

        $evaluationRaw =
            $teacherAiEvaluation['evaluation']
            ?? $teacherAiEvaluation['feedback']
            ?? $teacherAiEvaluation['result']
            ?? null;

        if ($evaluationRaw) {

            $decoded = json_decode(
                $evaluationRaw,
                true
            );

            if (is_array($decoded)) {
                $teacherAiData = $decoded;
            }
        }
    }

} catch (Throwable $e) {
    $teacherAiEvaluation = null;
}


/*
|--------------------------------------------------------------------------
| Determine recommended grade
|--------------------------------------------------------------------------
*/

$recommendedGrade = null;

if ($teacherAiData) {

    $possibleGradeKeys = [
        'recommended_grade',
        'grade',
        'score',
        'recommended_score'
    ];

    foreach ($possibleGradeKeys as $key) {

        if (
            isset($teacherAiData[$key]) &&
            $teacherAiData[$key] !== ''
        ) {

            $recommendedGrade =
                $teacherAiData[$key];

            break;
        }
    }
}

$legacyFourPointScale = false;
$criterionScores = $teacherAiData['criterion_scores'] ?? null;
if (is_array($criterionScores) && $criterionScores !== []) {
    $legacyFourPointScale = true;
    foreach ($criterionScores as $criterionScore) {
        $score = is_array($criterionScore) ? ($criterionScore['score'] ?? null) : null;
        if (!is_numeric($score) || (float) $score < 1 || (float) $score > 4) {
            $legacyFourPointScale = false;
            break;
        }
    }
}
if (
    $legacyFourPointScale &&
    is_numeric($recommendedGrade) &&
    (float) $recommendedGrade > 0 &&
    (float) $recommendedGrade <= 4
) {
    $recommendedGrade = round((float) $recommendedGrade * 25, 2);
}


/*
|--------------------------------------------------------------------------
| Page title
|--------------------------------------------------------------------------
*/

$title = 'Review Student Submission';

require_once dirname(__DIR__) . '/includes/header.php';

?>

<style>
.submission-writing {
    white-space: pre-wrap;
    line-height: 1.7;
    font-size: 16px;
}
.submission-comment-highlight,
.student-comment-highlight {
    background: #fff0a8;
    border-bottom: 2px solid #bd8b00;
    border-radius: 2px;
    cursor: pointer;
}
.submission-comment-form {
    margin-top: 18px;
    padding: 16px;
    border: 1px solid #c6d8f0;
    border-radius: 8px;
    background: #f4f8ff;
}
.submission-comment-form textarea { width: 100%; box-sizing: border-box; }
.submission-comment-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
.submission-comments { margin-top: 24px; }
.submission-comment {
    padding: 14px;
    margin-top: 10px;
    border: 1px solid #d6dee5;
    border-radius: 8px;
    background: #f8fafc;
}
.submission-comment.is-active { outline: 3px solid #f0c94d; }
.submission-comment-anchor {
    padding: 0;
    border: 0;
    background: transparent;
    color: #745500;
    text-align: left;
    font: inherit;
    font-weight: 700;
    cursor: pointer;
}
</style>

<div class="page-header">

<div>

    <p>

        <a href="teacher-assignment.php?id=<?= (int) $submission['assignment_id'] ?>">

            ← Back to Assignment

        </a>

    </p>

    <h1>
        <?= e($submission['student_name']) ?>
    </h1>

    <p>

        <?= e($submission['assignment_title']) ?>

        ·

        <?= e($submission['class_name']) ?>

    </p>

</div>

</div>

<?php if ($message): ?>

<div class="card">

    <strong>
        <?= e($message) ?>
    </strong>

</div>

<?php endif; ?>

<?php if ($error): ?>

<div class="card">

    <strong>
        <?= e($error) ?>
    </strong>

</div>

<?php endif; ?>

<?php if ($warning !== ''): ?>

<div class="card" role="alert">

    <strong><?= e($warning) ?></strong>

</div>

<?php endif; ?>

<?php if (isset($_GET['comment_added'])): ?>
    <div class="card" role="status"><strong>Comment added to the student writing.</strong></div>
<?php endif; ?>
<?php if ($canvasSyncNotice !== ''): ?>
    <div class="card" role="status"><strong><?= e($canvasSyncNotice) ?></strong></div>
<?php endif; ?>
<?php if ($canvasSyncWarning !== ''): ?>
    <div class="card" role="alert"><strong><?= e($canvasSyncWarning) ?></strong></div>
<?php endif; ?>

<div class="grid">

<div class="card">

    <h3>Status</h3>

    <strong>

        <?= e(
            ucfirst(
                $submission['status']
            )
        ) ?>

    </strong>

</div>


<div class="card">

    <h3>Submitted</h3>

    <?php if (!empty($submission['submitted_at'])): ?>

        <?= e(
            date(
                'M j, Y g:i A',
                strtotime($submission['submitted_at'])
            )
        ) ?>

    <?php else: ?>

        Not submitted yet

    <?php endif; ?>

</div>


<div class="card">

    <h3>Current Grade</h3>

    <strong>

        <?= !empty($submission['grade'])
            ? e((string) $submission['grade']) . '%'
            : 'Not graded' ?>

    </strong>

</div>


<div class="card">

    <h3>AI Recommendation</h3>

    <strong>

        <?= $recommendedGrade !== null
            ? e((string) $recommendedGrade) . '%'
            : 'Not evaluated' ?>

    </strong>

</div>

</div>

<div class="card">

<h2>Assignment</h2>

<h3>Prompt</h3>

<p>
    <?= nl2br(e($submission['assignment_prompt'])) ?>
</p>


<?php if (!empty($submission['assignment_instructions'])): ?>

    <h3>Instructions</h3>

    <p>
        <?= nl2br(
            e(
                $submission['assignment_instructions']
            )
        ) ?>
    </p>

<?php endif; ?>


<?php if (!empty($submission['rubric_text'])): ?>

    <h3>Rubric</h3>

    <p>
        <?= nl2br(e($submission['rubric_text'])) ?>
    </p>

<?php endif; ?>

</div>

<div class="card">

<h2>Student Writing</h2>

<?php if (essayPlainText((string) $submission['essay']) !== ''): ?>

    <p class="muted">Select a passage in the writing to add a specific comment.</p>
    <div id="submission-writing" class="submission-writing">
        <?= sanitizeEssayHtml((string) $submission['essay']) ?>
    </div>

    <form id="submission-comment-form" method="post" class="submission-comment-form" data-preserve-scroll hidden>
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="action" value="add_submission_comment">
        <input type="hidden" name="anchor_text" id="comment-anchor-text">
        <input type="hidden" name="context_before" id="comment-context-before">
        <input type="hidden" name="context_after" id="comment-context-after">
        <label for="comment-body">Comment on selected text</label>
        <textarea id="comment-body" name="comment_body" rows="3" maxlength="5000" required></textarea>
        <div class="submission-comment-actions">
            <button class="button" type="submit">Add comment</button>
            <button class="button secondary" type="button" id="cancel-submission-comment">Cancel</button>
            <span id="submission-comment-selection" class="muted"></span>
        </div>
    </form>

    <?php if ($submissionComments): ?>
        <section class="submission-comments" aria-labelledby="submission-comments-title">
            <h3 id="submission-comments-title">Comments on the writing</h3>
            <?php foreach ($submissionComments as $comment): ?>
                <article class="submission-comment" id="submission-comment-<?= (int) $comment['id'] ?>">
                    <button type="button" class="submission-comment-anchor" data-comment-id="<?= (int) $comment['id'] ?>">
                        “<?= e($comment['anchor_text']) ?>”
                    </button>
                    <p><?= nl2br(e($comment['body'])) ?></p>
                    <small><?= e($comment['teacher_name']) ?> · <?= e(date('M j, Y g:i A', strtotime($comment['created_at']))) ?></small>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

<?php else: ?>

    <p>
        The student has not written anything yet.
    </p>

<?php endif; ?>

</div>

<script type="application/json" id="submission-comments-data"><?= json_encode(
    $submissionComments,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
) ?></script>

<script>
(function () {
    const writing = document.getElementById('submission-writing');
    const form = document.getElementById('submission-comment-form');
    const anchorInput = document.getElementById('comment-anchor-text');
    const beforeInput = document.getElementById('comment-context-before');
    const afterInput = document.getElementById('comment-context-after');
    const selectionLabel = document.getElementById('submission-comment-selection');
    const commentsData = document.getElementById('submission-comments-data');
    if (!writing) return;

    function getRangePoint(root, offset) {
        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
        let node;
        let cursor = 0;
        while ((node = walker.nextNode())) {
            const next = cursor + node.nodeValue.length;
            if (offset <= next) return { node: node, offset: offset - cursor };
            cursor = next;
        }
        return null;
    }

    function applyCommentHighlights() {
        if (!commentsData) return;
        const comments = JSON.parse(commentsData.textContent);
        const fullText = writing.textContent;
        const located = [];

        comments.forEach(function (comment) {
            const quote = comment.anchor_text;
            let start = -1;
            let searchFrom = 0;
            while ((searchFrom = fullText.indexOf(quote, searchFrom)) !== -1) {
                const before = fullText.slice(Math.max(0, searchFrom - comment.context_before.length), searchFrom);
                const after = fullText.slice(searchFrom + quote.length, searchFrom + quote.length + comment.context_after.length);
                if ((!comment.context_before || before.endsWith(comment.context_before))
                    && (!comment.context_after || after.startsWith(comment.context_after))) {
                    start = searchFrom;
                    break;
                }
                searchFrom += quote.length || 1;
            }
            if (start >= 0) located.push({ start: start, end: start + quote.length, id: comment.id });
        });

        located.sort(function (a, b) { return b.start - a.start; });
        located.forEach(function (entry) {
            const start = getRangePoint(writing, entry.start);
            const end = getRangePoint(writing, entry.end);
            if (!start || !end) return;
            const range = document.createRange();
            range.setStart(start.node, start.offset);
            range.setEnd(end.node, end.offset);
            const mark = document.createElement('mark');
            mark.className = 'submission-comment-highlight';
            mark.dataset.commentId = String(entry.id);
            try {
                mark.append(range.extractContents());
                range.insertNode(mark);
            } catch (error) {
                return;
            }
        });
    }

    applyCommentHighlights();

    function prepareCommentForm(shouldFocus) {
        const selection = window.getSelection();
        if (!selection || selection.isCollapsed || !form) return;
        const range = selection.getRangeAt(0);
        if (!writing.contains(range.commonAncestorContainer)) return;

        const selected = selection.toString();
        if (!selected.trim()) return;
        const prefixRange = document.createRange();
        prefixRange.selectNodeContents(writing);
        prefixRange.setEnd(range.startContainer, range.startOffset);
        const suffixRange = document.createRange();
        suffixRange.selectNodeContents(writing);
        suffixRange.setStart(range.endContainer, range.endOffset);

        anchorInput.value = selected;
        beforeInput.value = prefixRange.toString().slice(-160);
        afterInput.value = suffixRange.toString().slice(0, 160);
        selectionLabel.textContent = 'Selected: “' + selected.slice(0, 100) + (selected.length > 100 ? '…' : '') + '”';
        form.hidden = false;
        if (shouldFocus) {
            form.scrollIntoView({ behavior: 'smooth', block: 'center' });
            document.getElementById('comment-body').focus();
        }
    }

    writing.addEventListener('mouseup', function () { prepareCommentForm(true); });
    writing.addEventListener('keyup', function () { prepareCommentForm(false); });

    document.getElementById('cancel-submission-comment')?.addEventListener('click', function () {
        form.hidden = true;
        form.reset();
        window.getSelection()?.removeAllRanges();
    });

    document.querySelectorAll('.submission-comment-anchor, .submission-comment-highlight').forEach(function (target) {
        target.addEventListener('click', function () {
            const comment = document.getElementById('submission-comment-' + target.dataset.commentId);
            comment?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            comment?.classList.add('is-active');
            setTimeout(function () { comment?.classList.remove('is-active'); }, 1400);
        });
    });
})();
</script>

<div class="card">

<h2>Teacher AI Evaluation</h2>

<p>
    Use AI to analyze this submission against the assignment prompt
    and rubric. This recommendation is for the teacher only.
</p>


<div
    id="ai-evaluation-status"
    style="margin-bottom: 15px;"
></div>


<button
    type="button"
    class="button"
    id="evaluate-ai-button"
>
    Generate AI Grade Recommendation
</button>


<div
    id="ai-evaluation-result"
    style="margin-top: 20px;"
>

    <?php if ($teacherAiData): ?>

        <?php if ($recommendedGrade !== null): ?>

            <h3>
                Recommended Grade:
                <?= e((string) $recommendedGrade) ?>%
            </h3>

        <?php endif; ?>

        <?php if (!empty($teacherAiData['criterion_scores']) && is_array($teacherAiData['criterion_scores'])): ?>
            <h3>Rubric Scores</h3>
            <ul>
                <?php foreach ($teacherAiData['criterion_scores'] as $criterion): ?>
                    <li>
                        <strong><?= e((string) ($criterion['criterion'] ?? 'Criterion')) ?>:</strong>
                        <?php
                        $criterionScore = (int) ($criterion['score'] ?? 0);
                        if ($legacyFourPointScale) {
                            $criterionScore *= 25;
                        }
                        ?>
                        <?= $criterionScore ?>%
                        <?php if (!empty($criterion['evidence'])): ?>
                            <br><?= e((string) $criterion['evidence']) ?>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>


        <?php
        $summary =
            $teacherAiData['summary']
            ?? $teacherAiData['evaluation_summary']
            ?? null;
        ?>

        <?php if ($summary): ?>

            <p>
                <?= nl2br(e((string) $summary)) ?>
            </p>

        <?php endif; ?>


        <?php
        $strengths =
            $teacherAiData['strengths']
            ?? [];
        ?>

        <?php if (is_array($strengths) && $strengths): ?>

            <h3>Strengths</h3>

            <ul>

                <?php foreach ($strengths as $item): ?>

                    <li>

                        <?= e(
                            is_array($item)
                                ? (
                                    $item['text']
                                    ?? $item['title']
                                    ?? json_encode($item)
                                )
                                : (string) $item
                        ) ?>

                    </li>

                <?php endforeach; ?>

            </ul>

        <?php endif; ?>


        <?php
        $concerns =
            $teacherAiData['concerns']
            ?? $teacherAiData['areas_for_improvement']
            ?? $teacherAiData['improvements']
            ?? [];
        ?>

        <?php if (is_array($concerns) && $concerns): ?>

            <h3>Areas of Concern</h3>

            <ul>

                <?php foreach ($concerns as $item): ?>

                    <li>

                        <?= e(
                            is_array($item)
                                ? (
                                    $item['text']
                                    ?? $item['title']
                                    ?? json_encode($item)
                                )
                                : (string) $item
                        ) ?>

                    </li>

                <?php endforeach; ?>

            </ul>

        <?php endif; ?>


        <?php
        $reasoning =
            $teacherAiData['reasoning']
            ?? $teacherAiData['grade_reasoning']
            ?? $teacherAiData['rationale']
            ?? null;
        ?>

        <?php if ($reasoning): ?>

            <h3>Reasoning</h3>

            <p>
                <?= nl2br(e((string) $reasoning)) ?>
            </p>

        <?php endif; ?>

    <?php elseif ($teacherAiEvaluation): ?>

        <p>
            An AI evaluation exists, but its format could not be displayed.
        </p>

    <?php else: ?>

        <p>
            No AI grade recommendation has been generated yet.
        </p>

    <?php endif; ?>

</div>

</div>

<div class="card">

<h2>Teacher Feedback</h2>

<p>
    This feedback and comments on selected passages will be visible to the student when you return the assignment. For Canvas-linked assignments, they are also included in the Canvas gradebook comments when a grade is sent.
</p>


<form method="post" data-preserve-scroll>

    <input
        type="hidden"
        name="csrf_token"
        value="<?= e(csrfToken()) ?>"
    >

    <textarea
        name="teacher_feedback"
        rows="8"
        style="
            width: 100%;
            box-sizing: border-box;
        "
        placeholder="Write feedback for the student..."
    ><?= e($submission['teacher_feedback'] ?? '') ?></textarea>


    <div
        style="
            margin-top: 15px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        "
    >

        <button
            type="submit"
            name="action"
            value="save_feedback"
            class="button secondary"
        >
            Save Feedback
        </button>


        <button
            type="submit"
            name="action"
            value="return_submission"
            class="button"
        >
            Return to Student
        </button>

    </div>

</form>

</div>

<div class="card">

<h2>Final Grade</h2>

<p>
    AI recommendations are advisory. The teacher decides the final grade.
</p>


<form method="post" data-preserve-scroll>

    <input
        type="hidden"
        name="csrf_token"
        value="<?= e(csrfToken()) ?>"
    >

    <input
        type="text"
        name="grade"
        value="<?= e((string) ($submission['grade'] ?? '')) ?>"
        placeholder="Example: 85 or B+"
        style="
            width: 200px;
            padding: 10px;
        "
    >


    <button
        type="submit"
        name="action"
        value="save_grade"
        class="button"
    >
        Save Grade
    </button>

</form>

</div>

<?php if ($studentAiFeedback): ?>

<div class="card">

    <h2>Student AI Writing Feedback</h2>

    <p>
        This is the writing feedback the student received from InsQuire.
    </p>


    <?php if (
        !empty($studentAiFeedback['created_at'])
    ): ?>

        <p>

            <small>

                Generated

                <?= e(
                    date(
                        'M j, Y g:i A',
                        strtotime(
                            $studentAiFeedback['created_at']
                        )
                    )
                ) ?>

            </small>

        </p>

    <?php endif; ?>


    <?php if ($studentAiData): ?>


        <?php if (!empty($studentAiData['summary'])): ?>

            <h3>Summary</h3>

            <p>
                <?= e(
                    (string) $studentAiData['summary']
                ) ?>
            </p>

        <?php endif; ?>


        <?php if (
            !empty($studentAiData['strengths']) &&
            is_array($studentAiData['strengths'])
        ): ?>

            <h3>What the Student Is Doing Well</h3>

            <ul>

                <?php foreach (
                    $studentAiData['strengths']
                    as $strength
                ): ?>

                    <li>

                        <?= e(
                            is_array($strength)
                                ? (
                                    $strength['text']
                                    ?? $strength['title']
                                    ?? ''
                                )
                                : (string) $strength
                        ) ?>

                    </li>

                <?php endforeach; ?>

            </ul>

        <?php endif; ?>


        <?php if (
            !empty($studentAiData['improvements']) &&
            is_array($studentAiData['improvements'])
        ): ?>

            <h3>Areas to Improve</h3>

            <?php foreach (
                $studentAiData['improvements']
                as $improvement
            ): ?>

                <div
                    style="
                        margin-bottom: 15px;
                    "
                >

                    <?php if (
                        is_array($improvement) &&
                        !empty($improvement['title'])
                    ): ?>

                        <strong>
                            <?= e(
                                $improvement['title']
                            ) ?>
                        </strong>

                    <?php endif; ?>


                    <?php if (is_array($improvement)): ?>

                        <p>

                            <?= e(
                                $improvement['text']
                                ?? ''
                            ) ?>

                        </p>

                    <?php else: ?>

                        <p>
                            <?= e(
                                (string) $improvement
                            ) ?>
                        </p>

                    <?php endif; ?>

                </div>

            <?php endforeach; ?>

        <?php endif; ?>


        <?php if (
            !empty($studentAiData['trouble_areas']) &&
            is_array($studentAiData['trouble_areas'])
        ): ?>

            <h3>AI-Highlighted Writing Areas</h3>

            <ul>

                <?php foreach (
                    $studentAiData['trouble_areas']
                    as $area
                ): ?>

                    <li>

                        <?php if (
                            is_array($area)
                        ): ?>

                            <?php if (
                                !empty($area['excerpt'])
                            ): ?>

                                <strong>
                                    “<?= e($area['excerpt']) ?>”
                                </strong>

                                :

                            <?php endif; ?>

                            <?= e(
                                $area['issue']
                                ?? ''
                            ) ?>

                        <?php else: ?>

                            <?= e((string) $area) ?>

                        <?php endif; ?>

                    </li>

                <?php endforeach; ?>

            </ul>

        <?php endif; ?>


    <?php else: ?>

        <div
            style="
                white-space: pre-wrap;
            "
        >

            <?= e(
                strip_tags(
                    $studentAiFeedback['feedback']
                )
            ) ?>

        </div>

    <?php endif; ?>

</div>

<?php endif; ?>

<script>

(function () {

    const button = document.getElementById(
        'evaluate-ai-button'
    );

    const status = document.getElementById(
        'ai-evaluation-status'
    );

    const result = document.getElementById(
        'ai-evaluation-result'
    );

    if (!button) {
        return;
    }

    button.addEventListener(
        'click',
        async function () {

            button.disabled = true;

            window.insquireRememberScroll?.();

            const originalText =
                button.textContent;

            button.textContent =
                'AI is evaluating...';

            status.textContent =
                'InsQuire is reviewing the writing and rubric.';

            try {

                const response = await fetch(
                    'teacher-ai-evaluate.php',
                    {
                        method: 'POST',

                        headers: {
                            'Content-Type':
                                'application/x-www-form-urlencoded'
                        },

                        body:
                            'submission_id=' +
                            encodeURIComponent(
                                <?= (int) $submissionId ?>
                            )
                    }
                );

                const text =
                    await response.text();

                let data;

                try {

                    data = JSON.parse(text);

                } catch (jsonError) {

                    throw new Error(
                        'The server returned HTTP ' + response.status +
                        ' instead of JSON. The session may have expired, or the AI service may be unavailable.'
                    );
                }

                if (!response.ok || !data.success) {

                    throw new Error(
                        data.error ||
                        'AI evaluation failed.'
                    );
                }

                status.textContent =
                    'AI evaluation complete.';

                /*
                |--------------------------------------------------------------------------
                | Reload the page
                |--------------------------------------------------------------------------
                |
                | This is intentional.
                | It guarantees the newest saved evaluation is displayed,
                | preventing stale AI grades after re-evaluation.
                |
                */

                window.location.reload();

            } catch (error) {

                window.insquireClearRememberedScroll?.();
                status.textContent =
                    error.message ||
                    'Unable to generate AI evaluation.';

                button.disabled = false;

                button.textContent =
                    originalText;
            }
        }
    );

})();

</script>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
