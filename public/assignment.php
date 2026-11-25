<?php

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
requireEducationHost();

$assignmentId = (int) ($_GET['id'] ?? 0);

if ($assignmentId <= 0) {
    http_response_code(400);
    exit('Invalid assignment.');
}

$userId = currentUserId();
$role = currentUserRole();

if (currentAccountScope() !== 'education' || !in_array($role, ['student', 'teacher'], true)) {
    http_response_code(403);
    exit('Access denied.');
} elseif ($role === 'student') {
    $accessCondition = 'a.status = "published" AND EXISTS (
        SELECT 1 FROM class_members cm
        WHERE cm.class_id = a.class_id AND cm.user_id = ?
    )';
    $accessParams = [$assignmentId, $userId];
} elseif ($role === 'teacher') {
    $accessCondition = 'c.teacher_id = ?';
    $accessParams = [$assignmentId, $userId];
} else {
    http_response_code(403);
    exit('Access denied.');
}

$stmt = $pdo->prepare(
    'SELECT
        a.id,
        a.class_id,
        a.title,
        a.prompt,
        a.instructions,
        a.rubric_text,
        a.rubric_filename,
        a.due_at,
        a.status,
        cia.canvas_url,
        c.name AS class_name,
        c.teacher_id
    FROM assignments a
    INNER JOIN classes c
        ON c.id = a.class_id
    LEFT JOIN canvas_imported_assignments cia
        ON cia.assignment_id = a.id
    WHERE a.id = ? AND ' . $accessCondition . '
    LIMIT 1'
);

$stmt->execute($accessParams);

$assignment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$assignment) {
    http_response_code(404);
    exit('Assignment not found.');
}

$submission = null;
$feedbackItems = [];
$latestFeedback = null;
$latestFeedbackData = null;
$teacherFeedback = null;

if ($role === 'student') {

    $stmt = $pdo->prepare(
        'SELECT *
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
    $teacherFeedback = $submission['teacher_feedback'] ?? null;
}
    if ($submission) {

        $stmt = $pdo->prepare(
            'SELECT *
            FROM ai_feedback
            WHERE submission_id = ?
            AND student_id = ?
            ORDER BY id DESC'
        );

        $stmt->execute([
            (int) $submission['id'],
            $userId
        ]);

        $feedbackItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$latestFeedback = $feedbackItems[0] ?? null;

if ($latestFeedback) {

    $decoded = json_decode(
        (string) $latestFeedback['feedback'],
        true
    );

    if (is_array($decoded)) {
        $latestFeedbackData = $decoded;
    }
}

$isSubmitted = (
    $submission &&
    in_array(($submission['status'] ?? ''), ['submitted', 'graded'], true)
    && empty($submission['returned_at'])
);

$isGraded = $submission && ($submission['status'] ?? '') === 'graded';
$isReturned = $submission && (
    ($submission['status'] ?? '') === 'returned'
    || !empty($submission['returned_at'])
);
$canEdit = !$submission || in_array(($submission['status'] ?? ''), ['draft', 'returned'], true) || $isReturned;
$submissionComments = [];
if ($submission && $isReturned) {
    $commentStmt = $pdo->prepare(
        'SELECT sc.id, sc.anchor_text, sc.context_before, sc.context_after,
                sc.body, sc.created_at, u.name AS teacher_name
         FROM submission_comments sc
         INNER JOIN users u ON u.id = sc.teacher_id
         WHERE sc.submission_id = ?
         ORDER BY sc.id'
    );
    $commentStmt->execute([(int) $submission['id']]);
    $submissionComments = $commentStmt->fetchAll(PDO::FETCH_ASSOC);
}

function feedbackText(mixed $value): string
{
    return is_string($value) ? $value : '';
}

require_once dirname(__DIR__) . '/includes/header.php';

?>

<style>
.assignment-details summary {
    cursor: pointer;
    font-weight: 700;
}

.assignment-details[open] summary {
    margin-bottom: 14px;
}

.canvas-popout-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-top: 12px;
    text-decoration: none;
}

.assignment-page {
    max-width: 1050px;
    margin: 0 auto;
    padding: 30px 20px 60px;
}

.assignment-card {
    margin-bottom: 24px;
    padding: 24px;
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 14px;
}

.assignment-card h1,
.assignment-card h2,
.assignment-card h3 {
    margin-top: 0;
}

.assignment-content {
    white-space: pre-wrap;
    line-height: 1.7;
}

.assignment-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    color: #4b5563;
}

.status-badge {
    display: inline-flex;
    align-items: center;
    padding: 5px 10px;
    border-radius: 999px;
    font-size: 13px;
    font-weight: 600;
}

.status-draft {
    background: #fef3c7;
    color: #92400e;
}

.status-submitted {
    background: #dcfce7;
    color: #166534;
}
.status-graded, .status-returned { background: #dbeafe; color: #1e40af; }
.status-missing { background: #fee2e2; color: #991b1b; }
.grade-callout { padding: 16px; margin: 14px 0; background: #e0f2fe; border-left: 4px solid #0284c7; }
.teacher-feedback-box {
    position: relative;
    margin-bottom: 24px;
    padding: 20px 45px 20px 20px;
    background: #dcfce7;
    border: 1px solid #86efac;
    border-radius: 12px;
    color: #166534;
}

.teacher-feedback-box h2 {
    margin-top: 0;
    margin-bottom: 12px;
}

.teacher-feedback-content {
    white-space: pre-wrap;
    line-height: 1.7;
}

.teacher-feedback-close {
    position: absolute;
    top: 10px;
    right: 14px;
    border: none;
    background: transparent;
    font-size: 26px;
    line-height: 1;
    color: #166534;
    cursor: pointer;
}

.teacher-feedback-close:hover {
    opacity: 0.7;
}
.student-comment-highlight {
    background: #fff0a8;
    border-bottom: 2px solid #bd8b00;
    border-radius: 2px;
    cursor: pointer;
}
.student-comment-card {
    padding: 14px 16px;
    margin: 12px 0;
    border: 1px solid #d6dee5;
    border-left: 4px solid #bd8b00;
    border-radius: 8px;
    background: #fffcf0;
}
.student-comment-card.is-active { outline: 3px solid #f0c94d; }
.student-comment-quote { color: #745500; font-weight: 700; }
.editor-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    margin-bottom: 14px;
}

.editor-header-tools {
    display: flex;
    align-items: center;
    gap: 12px;
}

.assignment-popout {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
}

.assignment-popout svg {
    width: 16px;
    height: 16px;
    flex: 0 0 auto;
}

.autosave-status {
    font-size: 14px;
    color: #6b7280;
}

.autosave-status.saving {
    color: #2563eb;
}

.autosave-status.saved {
    color: #15803d;
}

.autosave-status.error {
    color: #dc2626;
}

.editor-wrapper {
    position: relative;
    overflow: hidden;
    background: #f1f3f4;
    border: 1px solid #dadce0;
    border-radius: 8px;
}

.editor-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    min-height: 48px;
    padding: 8px 14px;
    background: #fff;
    border-bottom: 1px solid #dadce0;
    color: #5f6368;
    font-size: 13px;
}

.editor-toolbar-controls {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}

.visually-hidden {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}

.editor-tool-button {
    min-width: 36px;
    min-height: 34px;
    padding: 6px 9px;
    border: 1px solid transparent;
    border-radius: 4px;
    background: transparent;
    color: #3c4043;
    font: inherit;
    cursor: pointer;
}

.editor-tool-button:hover:not(:disabled) {
    background: #f1f3f4;
    border-color: #dadce0;
}

.editor-tool-button:disabled {
    color: #9aa0a6;
    cursor: not-allowed;
}

.editor-tool-divider {
    width: 1px;
    height: 24px;
    background: #dadce0;
}

.editor-toolbar select {
    width: auto;
    min-height: 34px;
    margin: 0;
    padding: 5px 8px;
    border: 1px solid #dadce0;
    border-radius: 4px;
    color: #3c4043;
    background: #fff;
    font: inherit;
}

.editor-toolbar input[type="color"] {
    width: 36px;
    height: 34px;
    margin: 0;
    padding: 3px;
    border: 1px solid #dadce0;
    border-radius: 4px;
    background: #fff;
    cursor: pointer;
}

.editor-document {
    width: min(100% - 40px, 816px);
    min-height: 760px;
    margin: 28px auto;
    padding: 76px 88px;
    border: 1px solid #dadce0;
    background: #fff;
    box-shadow: 0 1px 3px rgba(60, 64, 67, .18);
}

#essay-editor {
    min-height: 608px;
    width: 100%;
    box-sizing: border-box;
    padding: 0;
    border: 0;
    border-radius: 0;
    outline: none;
    color: #202124;
    font-family: Arial, Helvetica, sans-serif;
    font-size: 16px;
    line-height: 1.75;
    white-space: pre-wrap;
    overflow-wrap: break-word;
    background: transparent;
}

#essay-editor:focus {
    outline: none;
}

#essay-editor:empty::before {
    content: attr(data-placeholder);
    color: #9ca3af;
    pointer-events: none;
}

#essay-editor.editor-locked {
    color: #4b5563;
    cursor: default;
}

.trouble-highlight {
    background: #fff3bf;
    border-bottom: 3px solid #e0a400;
    border-radius: 3px;
    cursor: pointer;
    padding: 1px 2px;
}

.trouble-highlight:hover,
.trouble-highlight.active {
    background: #ffe28a;
    outline: 2px solid #e0a400;
}

.trouble-highlight-grammar { background: #ffe0e6; border-bottom-color: #d34b68; }
.trouble-highlight-clarity { background: #e2dcff; border-bottom-color: #7357c7; }
.trouble-highlight-evidence { background: #d9f3ed; border-bottom-color: #218b78; }
.trouble-highlight-organization { background: #dcecff; border-bottom-color: #3d78bd; }

.editor-actions {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-top: 16px;
    flex-wrap: wrap;
}

.editor-actions form {
    display: inline-flex;
    margin: 0;
}

.ai-thinking {
    display: none;
    align-items: center;
    gap: 12px;
    margin-top: 18px;
    padding: 16px;
    border-radius: 10px;
    background: #f3f4f6;
}

.ai-thinking.active {
    display: flex;
}

.thinking-spinner {
    width: 22px;
    height: 22px;
    border: 3px solid #d1d5db;
    border-top-color: #4f46e5;
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
}

@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}

.status-message {
    padding: 14px 16px;
    margin-bottom: 20px;
    border-radius: 10px;
}

.status-success {
    background: #dcfce7;
    color: #166534;
}

.status-error {
    background: #fee2e2;
    color: #991b1b;
}

.status-info {
    background: #eff6ff;
    color: #1d4ed8;
}

.feedback-summary {
    margin-bottom: 25px;
    padding: 18px;
    border-radius: 10px;
    background: #f8fafc;
    line-height: 1.7;
}

.feedback-section {
    margin-top: 28px;
}

.feedback-item {
    margin-bottom: 14px;
    padding: 16px;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
}

.feedback-item h3 {
    margin-bottom: 8px;
}

.feedback-question {
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px solid #e5e7eb;
    font-style: italic;
}

.next-steps {
    padding-left: 22px;
}

.trouble-list {
    display: grid;
    gap: 12px;
}

.trouble-card {
    padding: 16px;
    border: 1px solid #facc15;
    border-radius: 10px;
    background: #fffbeb;
    cursor: pointer;
}

.trouble-card:hover,
.trouble-card.active {
    border-color: #ca8a04;
}

.trouble-card:focus-visible,
.trouble-highlight:focus-visible {
    outline: 3px solid #143d36;
    outline-offset: 2px;
}

.trouble-category {
    display: inline-block;
    margin-bottom: 8px;
    padding: 3px 8px;
    border-radius: 999px;
    font: 700 .72rem Arial, sans-serif;
    letter-spacing: .04em;
    text-transform: uppercase;
    background: #fff3bf;
    color: #765500;
}

.trouble-category-grammar { background: #ffe0e6; color: #9c2945; }
.trouble-category-clarity { background: #e2dcff; color: #5d42a8; }
.trouble-category-evidence { background: #d9f3ed; color: #176b5d; }
.trouble-category-organization { background: #dcecff; color: #285d98; }

.trouble-excerpt {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
}

.trouble-popup {
    display: none;
    margin-top: 20px;
    padding: 18px;
    border-radius: 10px;
    background: #fffdf3;
    border: 1px solid #e0a400;
    line-height: 1.7;
    box-shadow: 0 8px 18px rgba(20, 61, 54, .08);
}

.trouble-popup.active {
    display: block;
}

.previous-feedback {
    margin-top: 15px;
    padding: 16px;
    border-radius: 10px;
    background: #f9fafb;
    white-space: pre-wrap;
}

.submit-warning {
    margin-top: 10px;
    color: #6b7280;
    font-size: 14px;
}

@media (max-width: 700px) {

    .editor-header {
        flex-direction: column;
        align-items: flex-start;
    }

    .editor-toolbar {
        align-items: flex-start;
        flex-direction: column;
    }

    .editor-document {
        width: calc(100% - 20px);
        min-height: 560px;
        margin: 10px auto;
        padding: 32px 24px;
    }

    #essay-editor {
        min-height: 496px;
    }

}

</style>

<div class="assignment-page">

<div class="assignment-card">

    <h1><?= e($assignment['title']) ?></h1>
    <?php if (!empty($assignment['canvas_url'])): ?>
        <a class="canvas-popout-link" href="<?= e($assignment['canvas_url']) ?>" target="_blank" rel="noopener noreferrer">
            <span aria-hidden="true">↗</span>
            <span>Open in Canvas</span>
        </a>
    <?php endif; ?>

    <div class="assignment-meta">

        <?php if (!empty($assignment['class_name'])): ?>

            <span>
                <strong>Class:</strong>
                <?= e($assignment['class_name']) ?>
            </span>

        <?php endif; ?>

        <?php if (!empty($assignment['due_at'])): ?>

            <span>
                <strong>Due:</strong>

                <span
                    class="local-time"
                    data-time="<?= e($assignment['due_at']) ?>"
                >
                    <?= e($assignment['due_at']) ?>
                </span>
            </span>

        <?php endif; ?>

        <?php if ($role === 'student'): ?>

            <?php if (($submission['status'] ?? '') === 'returned'): ?>

                <span class="status-badge status-returned">Returned</span>

            <?php elseif ($isGraded): ?>

                <span class="status-badge status-graded">Graded</span>

            <?php elseif ($isSubmitted): ?>

                <span class="status-badge status-submitted">
                    Submitted
                </span>

            <?php else: ?>

                <span class="status-badge status-draft">
                    Draft
                </span>

            <?php endif; ?>

        <?php endif; ?>

    </div>

</div>

<?php if (!empty($_GET['submitted'])): ?>

    <div class="status-message status-success">
        <strong>Assignment submitted successfully.</strong>
        Your teacher can now review your submission.
    </div>

<?php endif; ?>

<?php if (!empty($_GET['feedback'])): ?>

    <div class="status-message status-success">
        Your AI feedback is ready.
    </div>

<?php endif; ?>

<?php if (!empty($_GET['saved'])): ?>

    <div class="status-message status-success">
        Your writing was saved.
    </div>

<?php endif; ?>

<?php if (!empty($_GET['error'])): ?>

    <div class="status-message status-error">
        <?= e($_GET['error']) ?>
    </div>

<?php endif; ?>

<div class="assignment-card">

    <h2>Assignment Prompt</h2>

    <div class="assignment-content">
        <?= e($assignment['prompt']) ?>
    </div>

</div>

<?php if (!empty($assignment['instructions']) || !empty($assignment['rubric_text'])): ?>
    <details class="assignment-card assignment-details">
        <summary>Show directions/rubric</summary>
        <?php if (!empty($assignment['instructions'])): ?>
            <h2>Directions</h2>
            <div class="assignment-content">
                <?= e($assignment['instructions']) ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($assignment['rubric_text'])): ?>
            <h2>Rubric</h2>
            <div class="assignment-content">
                <?= e($assignment['rubric_text']) ?>
            </div>
        <?php endif; ?>
    </details>
<?php endif; ?>

<?php if ($role === 'student'): ?>

<?php if (!empty($teacherFeedback)): ?>

<div class="teacher-feedback-box" id="teacher-feedback-box">

    <button 
        type="button" 
        class="teacher-feedback-close"
        onclick="document.getElementById('teacher-feedback-box').style.display='none';"
        aria-label="Close feedback"
    >
        ×
    </button>

    <h2>Teacher Feedback</h2>

    <div class="teacher-feedback-content">
        <?= e($teacherFeedback) ?>
    </div>

</div>

<?php endif; ?>

<?php if ($isReturned): ?>
    <div class="status-message status-info">
        <strong>Your teacher returned this assignment for revision.</strong>
        Edit your writing below, save the changes, and use the resubmit button when you are ready.
    </div>
<?php endif; ?>

    <div class="assignment-card">

        <div class="editor-header">

            <h2>Your Writing</h2>

            <div class="editor-header-tools">
                <?php if ($role === 'student' && !empty($_SESSION['lti_frame_origin'])): ?>
                    <a
                        class="button secondary assignment-popout"
                        href="assignment.php?id=<?= (int) $assignment['id'] ?>"
                        target="_blank"
                        rel="noopener noreferrer"
                        aria-label="Open this assignment outside Canvas in a new tab"
                        title="Open this assignment outside Canvas in a new tab"
                    >
                        <svg viewBox="0 0 16 16" fill="none" aria-hidden="true">
                            <path d="M9 2h5v5M14 2 7 9" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M12 9v4a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1h4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
                        </svg>
                        <span>Pop out</span>
                    </a>
                <?php endif; ?>

                <?php if ($isSubmitted): ?>

                    <span class="autosave-status saved">
                        Submitted
                    </span>

                <?php else: ?>

                    <span
                        id="autosave-status"
                        class="autosave-status saved"
                    >
                        All changes saved
                    </span>

                <?php endif; ?>
            </div>

        </div>

        <?php if ($isSubmitted): ?>

            <div class="status-message status-info">
                This assignment has been submitted. Editing is locked.
            </div>

        <?php endif; ?>

        <div class="editor-wrapper">

            <div class="editor-toolbar" role="toolbar" aria-label="Writing document tools">
                <div class="editor-toolbar-controls">
                    <?php if ($canEdit): ?>
                        <button id="essay-undo" class="editor-tool-button" type="button" aria-label="Undo" title="Undo">Undo</button>
                        <button id="essay-redo" class="editor-tool-button" type="button" aria-label="Redo" title="Redo">Redo</button>
                        <span class="editor-tool-divider" aria-hidden="true"></span>
                        <label class="visually-hidden" for="essay-font-family">Font</label>
                        <select id="essay-font-family" aria-label="Font">
                            <option value="Arial">Arial</option>
                            <option value="Georgia">Georgia</option>
                            <option value="Times New Roman">Times New Roman</option>
                            <option value="Courier New">Courier New</option>
                            <option value="Verdana">Verdana</option>
                        </select>
                        <label class="visually-hidden" for="essay-font-size">Text size</label>
                        <select id="essay-font-size" aria-label="Text size">
                            <option value="10px">10</option>
                            <option value="12px">12</option>
                            <option value="14px">14</option>
                            <option value="16px" selected>16</option>
                            <option value="18px">18</option>
                            <option value="24px">24</option>
                            <option value="32px">32</option>
                        </select>
                        <span class="editor-tool-divider" aria-hidden="true"></span>
                        <button class="editor-tool-button" type="button" data-editor-command="bold" aria-label="Bold" title="Bold"><strong>B</strong></button>
                        <button class="editor-tool-button" type="button" data-editor-command="italic" aria-label="Italic" title="Italic"><em>I</em></button>
                        <button class="editor-tool-button" type="button" data-editor-command="underline" aria-label="Underline" title="Underline"><u>U</u></button>
                        <button class="editor-tool-button" type="button" data-editor-command="strikeThrough" aria-label="Strikethrough" title="Strikethrough"><s>S</s></button>
                        <label class="visually-hidden" for="essay-text-color">Text color</label>
                        <input id="essay-text-color" type="color" value="#202124" aria-label="Text color" title="Text color">
                        <label class="visually-hidden" for="essay-highlight-color">Highlight color</label>
                        <input id="essay-highlight-color" type="color" value="#fff2cc" aria-label="Highlight color" title="Highlight color">
                        <span class="editor-tool-divider" aria-hidden="true"></span>
                        <button class="editor-tool-button" type="button" data-editor-command="justifyLeft" aria-label="Align left" title="Align left">☰</button>
                        <button class="editor-tool-button" type="button" data-editor-command="justifyCenter" aria-label="Center align" title="Center align">≡</button>
                        <button class="editor-tool-button" type="button" data-editor-command="justifyRight" aria-label="Align right" title="Align right">☷</button>
                        <button class="editor-tool-button" type="button" data-editor-command="insertUnorderedList" aria-label="Bulleted list" title="Bulleted list">• List</button>
                        <button class="editor-tool-button" type="button" data-editor-command="insertOrderedList" aria-label="Numbered list" title="Numbered list">1. List</button>
                        <button id="essay-clear-formatting" class="editor-tool-button" type="button" aria-label="Clear formatting" title="Clear formatting">Clear</button>
                    <?php endif; ?>
                    <span>Document</span>
                </div>
                <span id="essay-word-count" aria-live="polite">0 words</span>
            </div>

            <div class="editor-document">
              <div
                id="essay-editor"
                contenteditable="<?= $isSubmitted ? 'false' : 'true' ?>"
                class="<?= $isSubmitted ? 'editor-locked' : '' ?>"
                data-placeholder="Start writing here..."
                spellcheck="true"
                role="textbox"
                aria-label="Your assignment writing"
                aria-multiline="true"
            ><?= sanitizeEssayHtml((string) ($submission['essay'] ?? '')) ?></div>
            </div>

        </div>

        <?php if ($submissionComments): ?>
            <section class="assignment-card" aria-labelledby="teacher-comments-title">
                <h2 id="teacher-comments-title">Teacher comments on your writing</h2>
                <p>Select a highlighted passage or comment to move between them.</p>
                <?php foreach ($submissionComments as $comment): ?>
                    <article class="student-comment-card" id="student-comment-<?= (int) $comment['id'] ?>">
                        <button type="button" class="student-comment-quote" data-comment-id="<?= (int) $comment['id'] ?>">
                            “<?= e($comment['anchor_text']) ?>”
                        </button>
                        <p><?= nl2br(e($comment['body'])) ?></p>
                        <small><?= e($comment['teacher_name']) ?> · <?= e(date('M j, Y g:i A', strtotime($comment['created_at']))) ?></small>
                    </article>
                <?php endforeach; ?>
            </section>
            <script type="application/json" id="student-submission-comments"><?= json_encode(
                $submissionComments,
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
            ) ?></script>
        <?php endif; ?>

        <?php if ($isGraded && $submission['grade'] !== null && $submission['grade'] !== ''): ?>
            <div class="grade-callout">
                <strong>Your grade:</strong> <?= e((string) $submission['grade']) ?>
            </div>
        <?php endif; ?>

        <?php if ($canEdit): ?>
            <div class="editor-actions">
                <button
                    id="save-button"
                    type="button"
                    class="button"
                >
                    Save Draft
                </button>

                <form
                    id="submit-assignment-form"
                    method="post"
                    action="submit-submission.php"
                    data-preserve-scroll
                >

                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

                    <input
                        type="hidden"
                        name="assignment_id"
                        value="<?= (int) $assignmentId ?>"
                    >

                    <button
                        id="submit-assignment-button"
                        type="submit"
                        class="button"
                    >
                        <?= $isReturned ? 'Resubmit Assignment' : 'Submit Assignment' ?>
                    </button>

                </form>

            </div>

            <div class="submit-warning">
                Submitting will lock your writing for teacher review.
            </div>

        <?php endif; ?>

    </div>

    <div class="assignment-card">

        <h2>InsQuire</h2>

        <p>
            Get feedback designed to help you improve your own writing.
            Specific areas may be highlighted directly in your writing.
        </p>

        <form
            id="ai-feedback-form"
            method="post"
            action="ai-feedback.php"
            data-preserve-scroll
        >

            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

            <input
                type="hidden"
                name="assignment_id"
                value="<?= (int) $assignmentId ?>"
            >

            <button
                id="ai-feedback-button"
                type="submit"
                class="button"
            >
                Get AI Feedback
            </button>

        </form>

        <div
            id="ai-thinking"
            class="ai-thinking"
        >

            <div class="thinking-spinner"></div>

            <div>
                <strong>InsQuire is reviewing your writing...</strong>
                <br>
                Looking for strengths and areas to improve.
            </div>

        </div>

    </div>

<?php endif; ?>

<?php if ($latestFeedbackData): ?>

    <div class="assignment-card">

        <h2>Latest AI Feedback</h2>

        <p>
            <small>
                Generated

                <span
                    class="local-time"
                    data-time="<?= e($latestFeedback['created_at']) ?>"
                >
                    <?= e($latestFeedback['created_at']) ?>
                </span>
            </small>
        </p>

        <?php if (!empty($latestFeedbackData['summary'])): ?>

            <div class="feedback-summary">
                <?= e(feedbackText($latestFeedbackData['summary'])) ?>
            </div>

        <?php endif; ?>

        <?php if (
            !empty($latestFeedbackData['strengths']) &&
            is_array($latestFeedbackData['strengths'])
        ): ?>

            <div class="feedback-section">

                <h2>What You're Doing Well</h2>

                <?php foreach (
                    $latestFeedbackData['strengths']
                    as $item
                ): ?>

                    <?php if (is_array($item)): ?>

                        <div class="feedback-item">

                            <h3>
                                <?= e(
                                    feedbackText(
                                        $item['title'] ?? 'Strength'
                                    )
                                ) ?>
                            </h3>

                            <div>
                                <?= e(
                                    feedbackText(
                                        $item['text'] ?? ''
                                    )
                                ) ?>
                            </div>

                        </div>

                    <?php endif; ?>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

        <?php if (
            !empty($latestFeedbackData['improvements']) &&
            is_array($latestFeedbackData['improvements'])
        ): ?>

            <div class="feedback-section">

                <h2>Areas to Improve</h2>

                <?php foreach (
                    $latestFeedbackData['improvements']
                    as $item
                ): ?>

                    <?php if (is_array($item)): ?>

                        <div class="feedback-item">

                            <h3>
                                <?= e(
                                    feedbackText(
                                        $item['title']
                                        ?? 'Area to improve'
                                    )
                                ) ?>
                            </h3>

                            <div>
                                <?= e(
                                    feedbackText(
                                        $item['text'] ?? ''
                                    )
                                ) ?>
                            </div>

                            <?php if (!empty($item['question'])): ?>

                                <div class="feedback-question">
                                    <?= e(
                                        feedbackText(
                                            $item['question']
                                        )
                                    ) ?>
                                </div>

                            <?php endif; ?>

                        </div>

                    <?php endif; ?>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

        <?php if (
            !empty($latestFeedbackData['next_steps']) &&
            is_array($latestFeedbackData['next_steps'])
        ): ?>

            <div class="feedback-section">

                <h2>Next Steps</h2>

                <ol class="next-steps">

                    <?php foreach (
                        $latestFeedbackData['next_steps']
                        as $step
                    ): ?>

                        <li>
                            <?= e(feedbackText($step)) ?>
                        </li>

                    <?php endforeach; ?>

                </ol>

            </div>

        <?php endif; ?>

        <?php if (
            !empty($latestFeedbackData['trouble_areas']) &&
            is_array($latestFeedbackData['trouble_areas'])
        ): ?>

            <div class="feedback-section">

                <h2>Areas Highlighted in Your Writing</h2>

                <p>
                    Click an area below or a matching highlight in your
                    writing to see why InsQuire flagged it.
                </p>

                <div class="trouble-list">

                    <?php foreach (
                        $latestFeedbackData['trouble_areas']
                        as $index => $area
                    ): ?>

                        <?php if (is_array($area)): ?>

                            <div
                                class="trouble-card"
                                data-trouble-index="<?= (int) $index ?>"
                                tabindex="0"
                                role="button"
                            >

                                <span class="trouble-category trouble-category-<?= e($area['category'] ?? 'general') ?>"><?= e(ucfirst($area['category'] ?? 'general')) ?></span>

                                <span class="trouble-excerpt">
                                    “<?= e(
                                        feedbackText(
                                            $area['excerpt'] ?? ''
                                        )
                                    ) ?>”
                                </span>

                                <span>
                                    <?= e(
                                        feedbackText(
                                            $area['issue'] ?? ''
                                        )
                                    ) ?>
                                </span>

                            </div>

                        <?php endif; ?>

                    <?php endforeach; ?>

                </div>

                <div
                    id="trouble-popup"
                    class="trouble-popup"
                ></div>

            </div>

        <?php endif; ?>

    </div>

<?php elseif ($latestFeedback): ?>

    <div class="assignment-card">

        <h2>Latest AI Feedback</h2>

        <div class="previous-feedback">
            <?= e($latestFeedback['feedback']) ?>
        </div>

    </div>

<?php endif; ?>


<?php if (count($feedbackItems) > 1): ?>

    <div class="assignment-card">

        <details>

            <summary>Previous AI Feedback</summary>

            <?php foreach (
                array_slice($feedbackItems, 1)
                as $item
            ): ?>

                <div class="previous-feedback">

                    <?php

                    $oldFeedback = json_decode(
                        (string) $item['feedback'],
                        true
                    );

                    ?>

                    <?php if (is_array($oldFeedback)): ?>

                        <?= e(
                            feedbackText(
                                $oldFeedback['summary'] ?? ''
                            )
                        ) ?>

                    <?php else: ?>

                        <?= e($item['feedback']) ?>

                    <?php endif; ?>

                    <br><br>

                    <small>

                        <span
                            class="local-time"
                            data-time="<?= e($item['created_at']) ?>"
                        >
                            <?= e($item['created_at']) ?>
                        </span>

                    </small>

                </div>

            <?php endforeach; ?>

        </details>

    </div>

<?php endif; ?>

</div>

<script>
(function () {
    const writing = document.getElementById('essay-editor');
    const commentsData = document.getElementById('student-submission-comments');
    if (!writing || !commentsData) return;

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

    const comments = JSON.parse(commentsData.textContent);
    const fullText = writing.textContent;
    const located = [];
    comments.forEach(function (comment) {
        const quote = comment.anchor_text;
        let from = 0;
        let start = -1;
        while ((from = fullText.indexOf(quote, from)) !== -1) {
            const before = fullText.slice(Math.max(0, from - comment.context_before.length), from);
            const after = fullText.slice(from + quote.length, from + quote.length + comment.context_after.length);
            if ((!comment.context_before || before.endsWith(comment.context_before))
                && (!comment.context_after || after.startsWith(comment.context_after))) {
                start = from;
                break;
            }
            from += quote.length || 1;
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
        mark.className = 'student-comment-highlight';
        mark.dataset.commentId = String(entry.id);
        try {
            mark.append(range.extractContents());
            range.insertNode(mark);
        } catch (error) {
            return;
        }
    });

    document.querySelectorAll('.student-comment-highlight, .student-comment-quote').forEach(function (target) {
        target.addEventListener('click', function () {
            const comment = document.getElementById('student-comment-' + target.dataset.commentId);
            comment?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            comment?.classList.add('is-active');
            setTimeout(function () { comment?.classList.remove('is-active'); }, 1400);
        });
    });
})();
</script>

<script>

document.addEventListener('DOMContentLoaded', function () {

    const assignmentId = <?= (int) $assignmentId ?>;
    const csrfToken = <?= json_encode(csrfToken()) ?>;
    const isSubmitted = <?= $isSubmitted ? 'true' : 'false' ?>;
    const canEdit = <?= $canEdit ? 'true' : 'false' ?>;

    const editor = document.getElementById('essay-editor');
    const saveButton = document.getElementById('save-button');
    const autosaveStatus = document.getElementById('autosave-status');
    const wordCount = document.getElementById('essay-word-count');
    const undoButton = document.getElementById('essay-undo');
    const redoButton = document.getElementById('essay-redo');
    const fontFamilySelect = document.getElementById('essay-font-family');
    const fontSizeSelect = document.getElementById('essay-font-size');
    const textColorPicker = document.getElementById('essay-text-color');
    const highlightColorPicker = document.getElementById('essay-highlight-color');
    const clearFormattingButton = document.getElementById('essay-clear-formatting');

    const feedbackForm =
        document.getElementById('ai-feedback-form');

    const feedbackButton =
        document.getElementById('ai-feedback-button');

    const thinking =
        document.getElementById('ai-thinking');

    const submitForm =
        document.getElementById('submit-assignment-form');

    const submitButton =
        document.getElementById('submit-assignment-button');

    let saveTimer = null;
    let isSaving = false;
    let lastSavedContent = editor ? editor.innerHTML : '';
    let savedSelection = null;

    const troubleAreas = <?= json_encode(
        $latestFeedbackData['trouble_areas'] ?? [],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ) ?>;

    function escapeHtml(value) {

        const div = document.createElement('div');

        div.textContent = value;

        return div.innerHTML;
    }

    function updateSaveStatus(text, state) {

        if (!autosaveStatus) {
            return;
        }

        autosaveStatus.textContent = text;
        autosaveStatus.className = 'autosave-status';

        if (state) {
            autosaveStatus.classList.add(state);
        }
    }

    function updateWordCount() {
        if (!editor || !wordCount) {
            return;
        }

        const text = editor.innerText.trim();
        const words = text === '' ? 0 : text.split(/\s+/).length;
        wordCount.textContent = words.toLocaleString() + (words === 1 ? ' word' : ' words');
    }

    function rememberSelection() {
        const selection = window.getSelection();
        if (editor && selection && selection.rangeCount > 0 && editor.contains(selection.anchorNode)) {
            savedSelection = selection.getRangeAt(0).cloneRange();
        }
    }

    function restoreSelection() {
        if (!editor || !savedSelection) {
            return;
        }

        editor.focus();
        const selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(savedSelection);
    }

    function runEditorCommand(command) {
        if (!editor || !canEdit || typeof document.execCommand !== 'function') {
            return;
        }

        restoreSelection();
        if (!savedSelection) {
            return;
        }
        document.execCommand(command, false);
        rememberSelection();
        editor.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function runEditorValueCommand(command, value) {
        if (!editor || !canEdit || typeof document.execCommand !== 'function') {
            return;
        }

        restoreSelection();
        if (!savedSelection) {
            return;
        }
        document.execCommand(command, false, value);
        rememberSelection();
        editor.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function setFontSize(size) {
        if (!editor || !canEdit || typeof document.execCommand !== 'function') {
            return;
        }

        runEditorValueCommand('fontSize', '7');
        editor?.querySelectorAll('font[size="7"]').forEach(function (font) {
            const span = document.createElement('span');
            span.style.fontSize = size;
            while (font.firstChild) {
                span.append(font.firstChild);
            }
            font.replaceWith(span);
        });
        editor?.dispatchEvent(new Event('input', { bubbles: true }));
    }

    document.querySelectorAll('[data-editor-command]').forEach(function (button) {
        button.addEventListener('click', function () {
            runEditorCommand(button.dataset.editorCommand);
        });
    });

    document.querySelectorAll('.editor-toolbar select, .editor-toolbar input[type="color"]').forEach(function (control) {
        control.addEventListener('mousedown', rememberSelection);
        control.addEventListener('change', function () {
            if (control === fontFamilySelect) {
                runEditorValueCommand('fontName', control.value);
            } else if (control === fontSizeSelect) {
                setFontSize(control.value);
            } else if (control === textColorPicker) {
                runEditorValueCommand('foreColor', control.value);
            } else if (control === highlightColorPicker) {
                runEditorValueCommand('hiliteColor', control.value);
            }
        });
    });

    clearFormattingButton?.addEventListener('click', function () {
        runEditorCommand('removeFormat');
    });

    editor?.addEventListener('keyup', rememberSelection);
    editor?.addEventListener('mouseup', rememberSelection);
    document.addEventListener('selectionchange', rememberSelection);

    document.querySelectorAll('.editor-toolbar button').forEach(function (button) {
        button.addEventListener('mousedown', function (event) {
            event.preventDefault();
            rememberSelection();
        });
    });

    if (editor) {
        updateWordCount();
        editor.addEventListener('input', updateWordCount);
    }

    undoButton?.addEventListener('click', function () {
        runEditorCommand('undo');
    });

    redoButton?.addEventListener('click', function () {
        runEditorCommand('redo');
    });

    async function saveWriting(forceSave) {

        if (
            !editor ||
            isSaving ||
            isSubmitted
        ) {
            return true;
        }

        const essay = editor.innerHTML;

        if (
            essay === lastSavedContent &&
            !forceSave
        ) {
            return true;
        }

        isSaving = true;

        updateSaveStatus(
            'Saving...',
            'saving'
        );

        const formData = new FormData();

        formData.append(
            'assignment_id',
            assignmentId
        );

        formData.append(
            'essay',
            essay
        );

        formData.append('csrf_token', csrfToken);

        try {

            const response = await fetch(
                'save-submission.php',
                {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-Token': csrfToken
                    },
                    body: formData
                }
            );

            const responseText = await response.text();
            let data;

            try {
                data = JSON.parse(responseText);
            } catch (parseError) {
                throw new Error(
                    'Autosave failed with HTTP ' + response.status + '. Refresh the page and try again.'
                );
            }

            if (!data.success) {
                throw new Error(
                    data.error || 'Could not save.'
                );
            }

            lastSavedContent = essay;

            updateSaveStatus(
                'All changes saved',
                'saved'
            );

            return true;

        } catch (error) {

            updateSaveStatus(
                'Could not save changes',
                'error'
            );

            return false;

        } finally {

            isSaving = false;

        }

    }

    if (editor && <?= $canEdit ? 'true' : 'false' ?>) {

        editor.addEventListener(
            'input',
            function () {

                updateSaveStatus(
                    'Unsaved changes',
                    ''
                );

                clearTimeout(saveTimer);

                saveTimer = setTimeout(
                    function () {
                        saveWriting(false);
                    },
                    1200
                );

            }
        );

        editor.addEventListener(
            'blur',
            function () {
                saveWriting(false);
            }
        );

    }

    if (saveButton) {

        saveButton.addEventListener(
            'click',
            function () {
                saveWriting(true);
            }
        );

    }

    if (feedbackForm) {

        feedbackForm.addEventListener(
            'submit',
            async function (event) {

                if (canEdit) {

                    event.preventDefault();

                    if (feedbackButton) {
                        feedbackButton.disabled = true;
                        feedbackButton.textContent =
                            'Reviewing...';
                    }

                    if (thinking) {
                        thinking.classList.add('active');
                    }

                    const saved =
                        await saveWriting(true);

                    if (!saved) {

                        window.insquireClearRememberedScroll?.();

                        if (feedbackButton) {
                            feedbackButton.disabled = false;
                            feedbackButton.textContent =
                                'Get AI Feedback';
                        }

                        if (thinking) {
                            thinking.classList.remove('active');
                        }

                        return;
                    }

                    feedbackForm.submit();

                    return;
                }

                if (feedbackButton) {
                    feedbackButton.disabled = true;
                    feedbackButton.textContent =
                        'Reviewing...';
                }

                if (thinking) {
                    thinking.classList.add('active');
                }

            }
        );

    }

    if (submitForm) {

        submitForm.addEventListener(
            'submit',
            async function (event) {

                event.preventDefault();

                if (!confirm('Submit this assignment? Your writing will be locked for teacher review.')) {
                    window.insquireClearRememberedScroll?.();
                    return;
                }

                if (submitButton) {
                    submitButton.disabled = true;
                    submitButton.textContent =
                        'Saving and submitting...';
                }

                const saved =
                    await saveWriting(true);

                if (!saved) {

                    window.insquireClearRememberedScroll?.();

                    if (submitButton) {
                        submitButton.disabled = false;
                        submitButton.textContent =
                            'Submit Assignment';
                    }

                    alert(
                        'Your writing could not be saved. Please try again.'
                    );

                    return;
                }

                submitForm.submit();

            }
        );

    }

    function applyHighlights() {

        if (
            !editor ||
            !Array.isArray(troubleAreas) ||
            troubleAreas.length === 0
        ) {
            return;
        }

        const text = editor.innerText;

        if (!text.trim()) {
            return;
        }

        let html = escapeHtml(text);

        troubleAreas.forEach(
            function (area, index) {

                if (
                    !area ||
                    !area.excerpt ||
                    typeof area.excerpt !== 'string'
                ) {
                    return;
                }

                const excerpt =
                    String(area.excerpt).trim();

                if (!excerpt) {
                    return;
                }

                const escapedExcerpt =
                    excerpt.replace(
                        /[.*+?^${}()|[\]\\]/g,
                        '\\$&'
                    );

                const regex = new RegExp(
                    escapedExcerpt,
                    'i'
                );

                html = html.replace(
                    regex,
                    function (match) {

                        const category = ['grammar', 'clarity', 'evidence', 'organization'].includes(area.category)
                            ? area.category
                            : 'general';

                        return (
                            '<span class="trouble-highlight trouble-highlight-' +
                            category +
                            '" tabindex="0" role="button" ' +
                            'data-trouble-index="' +
                            index +
                            '">' +
                            match +
                            '</span>'
                        );

                    }
                );

            }
        );

        if (html !== escapeHtml(text)) {

            editor.innerHTML = html;

            const selection =
                window.getSelection();

            if (selection) {
                selection.removeAllRanges();
            }

        }

    }

    function showTrouble(index) {

        const area =
            troubleAreas[index];

        if (!area) {
            return;
        }

        document.querySelectorAll(
            '.trouble-highlight, .trouble-card'
        ).forEach(
            function (element) {

                element.classList.toggle(
                    'active',
                    Number(
                        element.dataset.troubleIndex
                    ) === Number(index)
                );

            }
        );

        const popup =
            document.getElementById(
                'trouble-popup'
            );

        if (popup) {

            const category = ['grammar', 'clarity', 'evidence', 'organization'].includes(area.category)
                ? area.category
                : 'general';

            popup.innerHTML =
                '<span class="trouble-category trouble-category-' + category + '">' +
                escapeHtml(category.charAt(0).toUpperCase() + category.slice(1)) +
                '</span><strong>“' +
                escapeHtml(
                    area.excerpt || ''
                ) +
                '”</strong><br><br>' +
                escapeHtml(
                    area.issue || ''
                );

            popup.classList.add('active');

            popup.scrollIntoView({
                behavior: 'smooth',
                block: 'nearest'
            });

        }

    }

    applyHighlights();

    document.addEventListener(
        'click',
        function (event) {

            const target =
                event.target.closest(
                    '.trouble-highlight, .trouble-card'
                );

            if (!target) {
                return;
            }

            const index =
                target.dataset.troubleIndex;

            if (index !== undefined) {
                showTrouble(index);
            }

        }
    );

    document.addEventListener('keydown', function (event) {
        if ((event.key !== 'Enter' && event.key !== ' ') || !event.target.closest('.trouble-highlight, .trouble-card')) {
            return;
        }
        event.preventDefault();
        const target = event.target.closest('.trouble-highlight, .trouble-card');
        showTrouble(target.dataset.troubleIndex);
    });

    document.querySelectorAll(
        '.local-time'
    ).forEach(
        function (element) {

            const value =
                element.dataset.time;

            if (!value) {
                return;
            }

            let normalized =
                value.replace(' ', 'T');

            /*
             * Database timestamps are treated as UTC.
             * The browser automatically converts them
             * into the user's local timezone.
             */
            if (
                !normalized.endsWith('Z') &&
                !/[+-]\d\d:\d\d$/.test(normalized)
            ) {
                normalized += 'Z';
            }

            const date =
                new Date(normalized);

            if (isNaN(date.getTime())) {
                return;
            }

            element.textContent =
                date.toLocaleString(
                    undefined,
                    {
                        dateStyle: 'medium',
                        timeStyle: 'short'
                    }
                );

        }
    );

});

</script>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
