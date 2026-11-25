<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/functions.php';
redirect('dashboard.php?lessons_paused=1');
requireStudent();

$lessonAssignmentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST, 'lesson_assignment_id', FILTER_VALIDATE_INT);
if (!$lessonAssignmentId) { http_response_code(400); exit('Invalid lesson.'); }

$stmt = $pdo->prepare(
    'SELECT la.id AS lesson_assignment_id, la.due_at, l.id AS lesson_id, l.title, l.description, l.objective, l.content, l.activity, l.video_url, c.name AS class_name
     FROM lesson_assignments la
     INNER JOIN lessons l ON l.id = la.lesson_id
     INNER JOIN classes c ON c.id = la.class_id
     INNER JOIN class_members cm ON cm.class_id = c.id AND cm.user_id = ?
     WHERE la.id = ? LIMIT 1'
);
$stmt->execute([currentUserId(), $lessonAssignmentId]);
$lesson = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$lesson) { http_response_code(404); exit('Lesson not found.'); }

$qStmt = $pdo->prepare('SELECT * FROM lesson_questions WHERE lesson_id = ? ORDER BY sort_order, id');
$qStmt->execute([(int) $lesson['lesson_id']]);
$questions = $qStmt->fetchAll(PDO::FETCH_ASSOC);

$pStmt = $pdo->prepare('SELECT response, status, score FROM lesson_progress WHERE lesson_assignment_id = ? AND student_id = ? LIMIT 1');
$pStmt->execute([$lessonAssignmentId, currentUserId()]);
$progress = $pStmt->fetch(PDO::FETCH_ASSOC) ?: ['response' => '{}', 'status' => 'not_started', 'score' => null];
$answers = json_decode((string) $progress['response'], true);
$answers = is_array($answers) ? $answers : [];
$feedback = [];
$score = 0;
$mcTotal = 0;
$hasAnswered = false;

foreach ($questions as $question) {
    if ($question['question_type'] !== 'multiple_choice') {
        continue;
    }
    $mcTotal++;
    $questionId = (string) $question['id'];
    if (array_key_exists($questionId, $answers) && $answers[$questionId] !== '') {
        $hasAnswered = true;
        if (hash_equals((string) $question['correct_answer'], (string) $answers[$questionId])) {
            $score++;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $postedAnswers = is_array($_POST['answers'] ?? null) ? $_POST['answers'] : [];
    $score = 0;
    $mcTotal = 0;
    foreach ($questions as $question) {
        $questionId = (string) $question['id'];
        $answer = trim((string) ($postedAnswers[$questionId] ?? ''));
        $answers[$questionId] = $answer;
        if ($question['question_type'] === 'multiple_choice') {
            $mcTotal++;
            if ($answer !== '' && hash_equals((string) $question['correct_answer'], $answer)) { $score++; }
        } elseif ($answer !== '') {
            $feedback[$questionId] = 'Good start. Connect your answer to a specific detail from the lesson.';
        }
    }
    $status = isset($_POST['complete']) ? 'completed' : 'in_progress';
    $completedAt = $status === 'completed' ? date('Y-m-d H:i:s') : null;
    $scorePercent = $mcTotal > 0 ? round(($score / $mcTotal) * 100, 2) : null;
    $stmt = $pdo->prepare(
        'INSERT INTO lesson_progress (lesson_assignment_id, student_id, response, score, status, completed_at) VALUES (?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE response = VALUES(response), score = VALUES(score), status = VALUES(status), completed_at = VALUES(completed_at)'
    );
    $stmt->execute([$lessonAssignmentId, currentUserId(), json_encode($answers), $scorePercent, $status, $completedAt]);
    $progress['status'] = $status;
    $progress['score'] = $scorePercent;
    $hasAnswered = true;
}
$title = $lesson['title'];
$answeredCount = 0;
foreach ($questions as $q) { if (($answers[(string) $q['id']] ?? '') !== '') { $answeredCount++; } }
$totalQuestions = count($questions);
$progressPct = $totalQuestions > 0 ? round(($answeredCount / $totalQuestions) * 100) : 0;
require_once dirname(__DIR__) . '/includes/header.php';
?>
<div class="page-header"><div><p><a href="dashboard.php">Back to Dashboard</a></p><h1><?= e($lesson['title']) ?></h1>
<div class="lesson-meta-bar"><span class="tag"><?= e($lesson['class_name']) ?></span><span class="tag tag-standard">Status: <?= e(ucfirst(str_replace('_', ' ', $progress['status']))) ?></span></div></div></div>
<div class="card"><p><?= e($lesson['description']) ?></p><p><strong>Objective:</strong> <?= e($lesson['objective']) ?></p><?php if (!empty($lesson['video_url'])): ?><div class="video-wrap"><iframe src="https://www.youtube.com/embed/<?= e($lesson['video_url']) ?>" title="Lesson video" frameborder="0" allowfullscreen loading="lazy"></iframe></div><?php endif; ?><div class="preline"><?= e($lesson['content']) ?></div><?php if ($lesson['activity']): ?><h2>Try It</h2><div class="preline"><?= e($lesson['activity']) ?></div><?php endif; ?></div>
<?php if ($hasAnswered): ?><div class="card lesson-score"><h2>Score: <?= (int) $score ?> of <?= (int) $mcTotal ?> correct</h2><p>Your answers are saved. Review the check marks and revise any incorrect answers.</p></div><?php endif; ?>
<div class="card"><p><strong>Progress:</strong> <?= $answeredCount ?> of <?= $totalQuestions ?> questions answered</p><div class="progress-track"><div class="progress-fill" style="width: <?= $progressPct ?>%"></div></div></div>
<form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="lesson_assignment_id" value="<?= (int) $lessonAssignmentId ?>">
<?php foreach ($questions as $index => $question): $questionId = (string) $question['id']; $wasAnswered = ($answers[$questionId] ?? '') !== ''; $isCorrect = $hasAnswered && $wasAnswered && $question['question_type'] === 'multiple_choice' ? (($answers[$questionId] ?? '') === $question['correct_answer']) : null; $cardClass = $isCorrect === true ? 'answered-correct' : ($isCorrect === false ? 'answered-incorrect' : ''); ?><div class="card question-card <?= $cardClass ?>"><h2><span class="question-number"><?= $index + 1 ?></span><?= $question['question_type'] === 'short_response' ? 'Short Answer' : 'Multiple Choice' ?></h2><p><strong><?= e($question['question_text']) ?></strong></p><?php if ($question['question_type'] === 'multiple_choice'): $options = json_decode((string) $question['options_json'], true) ?: []; $seed = (int) $question['id']; usort($options, function ($a, $b) use ($seed) { return crc32($a . $seed) <=> crc32($b . $seed); }); foreach ($options as $option): $optId = 'opt-' . (int) $question['id'] . '-' . md5($option); ?><label class="checkbox-label" for="<?= $optId ?>"><input type="radio" name="answers[<?= (int) $question['id'] ?>]" id="<?= $optId ?>" value="<?= e($option) ?>" <?= (($answers[$questionId] ?? '') === $option) ? 'checked' : '' ?>> <span><?= e($option) ?></span></label><?php endforeach; ?><?php else: ?><textarea id="response-<?= (int) $question['id'] ?>" name="answers[<?= (int) $question['id'] ?>]" rows="4"><?= e($answers[$questionId] ?? '') ?></textarea><button type="button" class="button secondary ai-lesson-feedback" data-question-id="<?= (int) $question['id'] ?>">Get AI Feedback</button><div id="feedback-<?= (int) $question['id'] ?>" class="status-message status-info" hidden></div><?php endif; ?><?php if (isset($feedback[$questionId])): ?><div class="status-message status-info"><?= e($feedback[$questionId]) ?></div><?php endif; ?><?php if ($isCorrect !== null): ?><p class="status-message mark-result <?= $isCorrect ? 'status-success' : 'status-error' ?>"><?= $isCorrect ? '✓ Correct. ' . e((string) $question['explanation']) : '✗ Not quite. Review the lesson and try this question again.' ?></p><?php endif; ?></div><?php endforeach; ?><button class="button" type="submit">Check My Answers</button> <button class="button secondary" type="submit" name="complete" value="1">Mark Lesson Complete</button></form>
<?php if ($score !== null): ?><div class="card"><h2>Progress</h2><p>You answered <?= (int) $score ?> of <?= (int) ($mcTotal ?? 0) ?> multiple-choice questions correctly. Your responses were saved.</p></div><?php endif; ?>
<script>
const lessonResults = <?= json_encode(array_values(array_map(function ($question) use ($answers) {
    if ($question['question_type'] !== 'multiple_choice') {
        return null;
    }
    $questionId = (string) $question['id'];
    if (!isset($answers[$questionId]) || $answers[$questionId] === '') {
        return null;
    }
    return (string) $answers[$questionId] === (string) $question['correct_answer'];
}, $questions))) ?>;
document.querySelectorAll('form > .card').forEach(function (card, index) {
    const result = lessonResults[index];
    if (typeof result !== 'boolean') return;
    const heading = card.querySelector('h2');
    if (!heading) return;
    const marker = document.createElement('span');
    marker.textContent = result ? ' ✓' : ' ✗';
    marker.style.color = result ? '#15803d' : '#b42318';
    marker.setAttribute('aria-label', result ? 'Correct' : 'Incorrect');
    heading.appendChild(marker);
});
document.querySelectorAll('.ai-lesson-feedback').forEach(function (button) {
    button.addEventListener('click', async function () {
        const questionId = button.dataset.questionId;
        const output = document.getElementById('feedback-' + questionId);
        const response = document.getElementById('response-' + questionId).value;
        output.hidden = false;
        output.textContent = 'InsQuire is reviewing your response...';
        const body = new URLSearchParams({csrf_token: <?= json_encode(csrfToken()) ?>, lesson_assignment_id: <?= (int) $lessonAssignmentId ?>, question_id: questionId, response: response});
        try {
            const result = await fetch('lesson-ai-feedback.php', {method: 'POST', body: body});
            const data = await result.json();
            output.textContent = data.feedback || data.error || 'Feedback unavailable.';
        } catch (error) { output.textContent = 'Feedback unavailable right now.'; }
    });
});
</script>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
