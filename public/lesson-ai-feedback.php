<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/functions.php';
header('Content-Type: application/json; charset=utf-8');
jsonResponse(['success' => false, 'error' => 'Lesson features are temporarily unavailable.'], 410);

requireStudent();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['success' => false, 'error' => 'POST required.'], 405); }
verifyCsrf();
$assignmentId = filter_input(INPUT_POST, 'lesson_assignment_id', FILTER_VALIDATE_INT);
$questionId = filter_input(INPUT_POST, 'question_id', FILTER_VALIDATE_INT);
$response = trim((string) ($_POST['response'] ?? ''));
if (!$assignmentId || !$questionId || $response === '') { jsonResponse(['success' => false, 'error' => 'Add a response first.'], 400); }
$stmt = $pdo->prepare('SELECT q.question_text, l.content FROM lesson_questions q INNER JOIN lessons l ON l.id = q.lesson_id INNER JOIN lesson_assignments la ON la.lesson_id = l.id INNER JOIN class_members cm ON cm.class_id = la.class_id AND cm.user_id = ? WHERE la.id = ? AND q.id = ? AND q.question_type = "short_response" LIMIT 1');
$stmt->execute([currentUserId(), $assignmentId, $questionId]);
$question = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$question) { jsonResponse(['success' => false, 'error' => 'Question not found.'], 404); }
$system = 'You grade a student short-answer response to a lesson question. Do not write the answer for them. First check honestly: does the response actually attempt the question and relate to the lesson content? If it is off-topic, joking, gibberish, or too short to judge (like a greeting or random text), say so directly and ask them to answer the actual question - do not praise it or invent a "strength" that is not there. If it is a real attempt, mention one genuine strength and one specific next step tied to the lesson content. Keep it under 60 words, plain text, no JSON.';
$prompt = "Lesson content:\n" . $question['content'] . "\n\nQuestion:\n" . $question['question_text'] . "\n\nStudent response:\n" . $response;
$result = aiGenerate(DEEPSEEK_FEEDBACK_MODEL, $system, $prompt, false);
if (!$result['success']) {
    jsonResponse(
        ['success' => false, 'error' => $result['error'] ?? 'AI feedback is unavailable.'],
        !empty($result['rate_limited']) ? 429 : 503
    );
}
if (trim((string) ($result['response'] ?? '')) === '') {
    jsonResponse(['success' => false, 'error' => 'AI returned an empty response. Please try again.'], 502);
}
jsonResponse(['success' => true, 'feedback' => trim((string) $result['response'])]);
