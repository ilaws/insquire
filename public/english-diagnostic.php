<?php

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/english-diagnostic-bank.php';

requireStudent();

$studentId = currentUserId();
$assignmentId = 0;
$assignment = null;
$attempt = null;
$error = '';
$resultData = null;
$submittedAnswers = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $assignmentId = filter_input(INPUT_POST, 'assignment_id', FILTER_VALIDATE_INT) ?: 0;
    $answers = $_POST['answers'] ?? null;
    if (is_array($answers)) {
        foreach (ENGLISH_DIAGNOSTIC_QUESTIONS as $question) {
            $answer = $answers[$question['id']] ?? null;
            if (is_string($answer) && array_key_exists($answer, $question['choices'])) {
                $submittedAnswers[$question['id']] = $answer;
            }
        }
    }

    if (!is_array($answers) || count($answers) !== count(ENGLISH_DIAGNOSTIC_QUESTIONS)) {
        $error = 'Answer every question before submitting the diagnostic.';
    } else {
        $questionById = [];
        foreach (ENGLISH_DIAGNOSTIC_QUESTIONS as $question) {
            $questionById[$question['id']] = $question;
        }

        $score = 0;
        $skillScores = [];
        $safeAnswers = [];
        foreach ($questionById as $questionId => $question) {
            $answer = $answers[$questionId] ?? null;
            if (!is_string($answer) || !array_key_exists($answer, $question['choices'])) {
                $error = 'Answer every question using one of the listed choices.';
                break;
            }
            $isCorrect = $answer === $question['answer'];
            $score += $isCorrect ? 1 : 0;
            $skillScores[$question['skill']] ??= ['correct' => 0, 'total' => 0];
            $skillScores[$question['skill']]['correct'] += $isCorrect ? 1 : 0;
            $skillScores[$question['skill']]['total']++;
            $safeAnswers[$questionId] = $answer;
        }
    }

    if ($error === '' && $assignmentId > 0) {
        $assignmentStmt = $pdo->prepare(
            'SELECT da.id, da.title, da.due_at, da.status, da.class_id, c.name AS class_name
             FROM english_diagnostic_assignments da
             INNER JOIN classes c ON c.id = da.class_id
             INNER JOIN class_members cm ON cm.class_id = c.id AND cm.user_id = ?
             WHERE da.id = ?
             LIMIT 1'
        );
        $assignmentStmt->execute([$studentId, $assignmentId]);
        $assignment = $assignmentStmt->fetch(PDO::FETCH_ASSOC);
        if (
            !$assignment ||
            $assignment['status'] !== 'open' ||
            (!empty($assignment['due_at']) && strtotime((string) $assignment['due_at']) < time())
        ) {
            $error = 'This class diagnostic is no longer available.';
        }
    }

    if ($error === '') {
        $existingStmt = $pdo->prepare(
            'SELECT id FROM english_diagnostic_attempts
             WHERE assignment_id <=> ? AND student_id = ?
             ORDER BY id DESC LIMIT 1'
        );
        $existingStmt->execute([$assignmentId > 0 ? $assignmentId : null, $studentId]);
        $existingAttemptId = $existingStmt->fetchColumn();
        if ($existingAttemptId) {
            redirect('english-diagnostic.php?attempt=' . (int) $existingAttemptId);
        }

        $level = englishDiagnosticLevel($score);
        try {
            $saveStmt = $pdo->prepare(
                'INSERT INTO english_diagnostic_attempts
                    (assignment_id, student_id, score, max_score, cefr_level, skill_scores, answers)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $saveStmt->execute([
                $assignmentId > 0 ? $assignmentId : null,
                $studentId,
                $score,
                count(ENGLISH_DIAGNOSTIC_QUESTIONS),
                $level['level'],
                json_encode($skillScores, JSON_THROW_ON_ERROR),
                json_encode($safeAnswers, JSON_THROW_ON_ERROR)
            ]);
            redirect('english-diagnostic.php?attempt=' . (int) $pdo->lastInsertId());
        } catch (PDOException $e) {
            if ($assignmentId > 0 && $e->getCode() === '23000') {
                $existingStmt->execute([$assignmentId, $studentId]);
                $existingAttemptId = $existingStmt->fetchColumn();
                if ($existingAttemptId) {
                    redirect('english-diagnostic.php?attempt=' . (int) $existingAttemptId);
                }
            }
            error_log('English diagnostic save failed: ' . $e->getMessage());
            $error = 'Your result could not be saved. Please try again.';
        }
    }
} else {
    $attemptId = filter_input(INPUT_GET, 'attempt', FILTER_VALIDATE_INT) ?: 0;
    if ($attemptId > 0) {
        $attemptStmt = $pdo->prepare(
            'SELECT ea.*, da.title AS assignment_title, c.name AS class_name
             FROM english_diagnostic_attempts ea
             LEFT JOIN english_diagnostic_assignments da ON da.id = ea.assignment_id
             LEFT JOIN classes c ON c.id = da.class_id
             WHERE ea.id = ? AND ea.student_id = ?
             LIMIT 1'
        );
        $attemptStmt->execute([$attemptId, $studentId]);
        $attempt = $attemptStmt->fetch(PDO::FETCH_ASSOC);
        if (!$attempt) {
            http_response_code(404);
            exit('Diagnostic result not found.');
        }
        $resultData = json_decode((string) $attempt['skill_scores'], true);
        $resultData = is_array($resultData) ? $resultData : [];
    } else {
        $assignmentId = filter_input(INPUT_GET, 'assignment_id', FILTER_VALIDATE_INT) ?: 0;
        if ($assignmentId > 0) {
            $assignmentStmt = $pdo->prepare(
                'SELECT da.id, da.title, da.due_at, da.status, da.class_id, c.name AS class_name
                 FROM english_diagnostic_assignments da
                 INNER JOIN classes c ON c.id = da.class_id
                 INNER JOIN class_members cm ON cm.class_id = c.id AND cm.user_id = ?
                 WHERE da.id = ?
                 LIMIT 1'
            );
            $assignmentStmt->execute([$studentId, $assignmentId]);
            $assignment = $assignmentStmt->fetch(PDO::FETCH_ASSOC);
            if (!$assignment) {
                http_response_code(404);
                exit('Class diagnostic not found.');
            }
            $existingStmt = $pdo->prepare(
                'SELECT id FROM english_diagnostic_attempts
                 WHERE assignment_id = ? AND student_id = ? LIMIT 1'
            );
            $existingStmt->execute([$assignmentId, $studentId]);
            $existingAttemptId = $existingStmt->fetchColumn();
            if ($existingAttemptId) {
                redirect('english-diagnostic.php?attempt=' . (int) $existingAttemptId);
            }
            if (
                $assignment['status'] !== 'open' ||
                (!empty($assignment['due_at']) && strtotime((string) $assignment['due_at']) < time())
            ) {
                $error = 'This class diagnostic is closed. Ask your teacher if you need access.';
            }
        }
    }
}

$title = $attempt ? 'Diagnostic results' : 'English diagnostic';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<main class="english-diagnostic">
    <?php if ($attempt): ?>
        <?php $level = englishDiagnosticLevel((int) $attempt['score']); ?>
        <p class="eyebrow">ENGLISH DIAGNOSTIC RESULTS</p>
        <h1>Your English snapshot</h1>
        <section class="card" aria-labelledby="diagnostic-level">
            <p class="muted"><?= $attempt['assignment_id'] ? 'Class diagnostic · ' . e($attempt['class_name'] ?? '') : 'Personal practice' ?></p>
            <?php if (!empty($attempt['assignment_title'])): ?><p><strong><?= e($attempt['assignment_title']) ?></strong></p><?php endif; ?>
            <h2 id="diagnostic-level">Estimated CEFR range: <?= e((string) $attempt['cefr_level']) ?></h2>
            <p><strong><?= (int) $attempt['score'] ?> / <?= (int) $attempt['max_score'] ?></strong> correct</p>
            <p><?= e($level['description']) ?></p>
            <p>This short, multiple-choice diagnostic is an informal estimate, not an official CEFR certificate or a complete measure of English ability. It does not assess speaking, listening, or extended writing.</p>
        </section>
        <section class="card">
            <h2>Skills snapshot</h2>
            <div class="table-wrap"><table>
                <thead><tr><th>Area</th><th>Correct</th><th>What it covers</th></tr></thead>
                <tbody>
                    <?php foreach ($resultData as $skill => $values): ?>
                        <tr>
                            <th scope="row"><?= e((string) $skill) ?></th>
                            <td><?= (int) ($values['correct'] ?? 0) ?> / <?= (int) ($values['total'] ?? 0) ?></td>
                            <td><?= $skill === 'Reading' ? 'Finding details and interpreting a short text.' : ($skill === 'Vocabulary' ? 'Understanding common and academic words in context.' : 'Using English grammar and sentence patterns.') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
            <p class="muted">Your teacher can use this result to guide a conversation about what to practice next. It should not be used as the only basis for a grade or placement decision.</p>
        </section>
        <p><a class="button secondary" href="dashboard.php">Back to dashboard</a></p>
    <?php else: ?>
        <p class="eyebrow">EDUCATION · ENGLISH</p>
        <h1><?= $assignment ? e($assignment['title']) : 'English skills diagnostic' ?></h1>
        <?php if ($assignment): ?><p><?= e($assignment['class_name']) ?><?= !empty($assignment['due_at']) ? ' · Due ' . e(date('M j, Y', strtotime($assignment['due_at']))) : '' ?></p><?php endif; ?>
        <section class="card">
            <h2>What this diagnostic measures</h2>
            <p>Answer 25 original questions about grammar, vocabulary, and reading. Your result gives an approximate CEFR level from A1 to C1 and a snapshot by skill area.</p>
            <p>Allow about 15 minutes. Choose the best answer without translation or outside help. You may review your choices before submitting.</p>
            <p>This is an informal, low-stakes check, not a certified placement exam. It does not test speaking, listening, or extended writing.</p>
        </section>
        <?php if ($error !== ''): ?><p class="alert alert-error" role="alert"><?= e($error) ?></p><?php endif; ?>
        <form method="post" class="english-diagnostic-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="assignment_id" value="<?= (int) ($assignment['id'] ?? 0) ?>">
            <?php $shownPassages = []; ?>
            <?php foreach (ENGLISH_DIAGNOSTIC_QUESTIONS as $index => $question): ?>
                <?php if (!empty($question['passage'])): ?>
                    <?php $passageId = $question['passage']; ?>
                    <?php if (!isset($shownPassages[$passageId])): $shownPassages[$passageId] = true; ?>
                        <aside class="card diagnostic-passage"><strong>Read this text for the related questions</strong><p><?= e(ENGLISH_DIAGNOSTIC_PASSAGES[$passageId]) ?></p></aside>
                    <?php endif; ?>
                <?php endif; ?>
                <fieldset class="card diagnostic-question">
                    <legend><?= (int) ($index + 1) ?>. <?= e($question['prompt']) ?></legend>
                    <?php foreach ($question['choices'] as $choiceId => $choice): ?>
                        <label class="diagnostic-choice">
                            <input type="radio" name="answers[<?= e($question['id']) ?>]" value="<?= e($choiceId) ?>" required<?= ($submittedAnswers[$question['id']] ?? null) === $choiceId ? ' checked' : '' ?>>
                            <span><?= e($choice) ?></span>
                        </label>
                    <?php endforeach; ?>
                </fieldset>
            <?php endforeach; ?>
            <button class="button" type="submit">Submit diagnostic</button>
        </form>
    <?php endif; ?>
</main>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
