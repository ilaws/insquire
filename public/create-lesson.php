<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/functions.php';
redirect('dashboard.php?lessons_paused=1');
requireTeacher();

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $titleValue = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $objective = trim($_POST['objective'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $activity = trim($_POST['activity'] ?? '');
    $skill = trim($_POST['skill'] ?? '');
    $gradeLevel = trim($_POST['grade_level'] ?? '') ?: 'All grades';
    $isSkillOnly = ($_POST['lesson_kind'] ?? 'skill') === 'skill';
    $videoUrlRaw = trim($_POST['video_url'] ?? '');
    $videoUrl = null;
    if ($videoUrlRaw !== '') {
        if (preg_match('~(?:youtube\.com/watch\?v=|youtu\.be/|youtube\.com/embed/)([A-Za-z0-9_-]{6,})~', $videoUrlRaw, $m)) {
            $videoUrl = $m[1];
        } else {
            $error = 'That does not look like a valid YouTube link.';
        }
    }

    if (($titleValue === '' || $description === '' || $objective === '' || $content === '' || $skill === '') && $error === '') {
        $error = 'Title, skill, description, objective, and lesson content are required.';
    } elseif ($error === '') {
        $standardCode = 'CUSTOM.' . currentUserId() . '.' . strtoupper(bin2hex(random_bytes(4)));
        $stateCode = $isSkillOnly ? 'SKILL' : 'CUSTOM';
        $stateName = $isSkillOnly ? 'Skills Library' : 'Teacher-created';
        $stmt = $pdo->prepare(
            'INSERT INTO lesson_standards
             (state_code, state_name, grade_level, skill, subject, standard_code, standard_title)
             VALUES (?, ?, ?, ?, "English Language Arts", ?, ?)'
        );
        $stmt->execute([$stateCode, $stateName, $gradeLevel, $skill, $standardCode, $skill]);
        $standardId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare(
            'INSERT INTO lessons
             (standard_id, title, description, objective, content, activity, video_url, created_by, is_public)
             VALUES (?,?,?,?,?,?,?,?,0)'
        );
        $stmt->execute([$standardId, $titleValue, $description, $objective, $content, $activity, $videoUrl, currentUserId()]);
        $lessonId = (int) $pdo->lastInsertId();

        $questionTexts = $_POST['question_text'] ?? [];
        $questionTypes = $_POST['question_type'] ?? [];
        $options = $_POST['options'] ?? [];
        $correctAnswers = $_POST['correct_answer'] ?? [];
        $explanations = $_POST['explanation'] ?? [];
        $questionStmt = $pdo->prepare(
            'INSERT INTO lesson_questions
             (lesson_id, question_type, question_text, options_json, correct_answer, explanation, sort_order)
             VALUES (?,?,?,?,?,?,?)'
        );
        foreach ($questionTexts as $index => $questionText) {
            $questionText = trim((string) $questionText);
            if ($questionText === '') continue;
            $type = ($questionTypes[$index] ?? 'multiple_choice') === 'short_response' ? 'short_response' : 'multiple_choice';
            $optionList = array_values(array_filter(array_map('trim', explode("\n", (string) ($options[$index] ?? '')))));
            $questionStmt->execute([
                $lessonId,
                $type,
                $questionText,
                $type === 'multiple_choice' ? json_encode($optionList) : null,
                $type === 'multiple_choice' ? trim((string) ($correctAnswers[$index] ?? '')) : null,
                trim((string) ($explanations[$index] ?? '')),
                $index + 1
            ]);
        }
        redirect('lesson-library.php');
    }
}
$title = 'Create Lesson';
require_once dirname(__DIR__) . '/includes/header.php';
?>
<h1>Create Lesson</h1>
<p>Build a reusable lesson with explanation, practice, and interactive checks.</p>
<?php if ($error): ?><div class="error"><?= e($error) ?></div><?php endif; ?>
<form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
<div class="card"><label>Lesson title<input name="title" required></label><label>Type<select name="lesson_kind"><option value="skill">General skill (not tied to a state standard)</option><option value="standard">Tied to a specific standard/grade band</option></select></label><label>Skill<input name="skill" placeholder="Example: Thesis writing" required></label><label>Grade level<input name="grade_level" placeholder="Example: 9-10 or All grades"></label><label>YouTube video (optional)<input name="video_url" placeholder="Paste a YouTube link, e.g. https://youtu.be/..."></label><label>Short description<textarea name="description" rows="3" required></textarea></label><label>Learning objective<textarea name="objective" rows="3" required></textarea></label><label>Lesson content<textarea name="content" rows="10" required></textarea></label><label>Practice activity<textarea name="activity" rows="5"></textarea></label></div>
<div id="questions"><div class="card question-editor"><h2>Question 1</h2><label>Type<select name="question_type[]"><option value="multiple_choice">Multiple choice</option><option value="short_response">Short response</option></select></label><label>Question<textarea name="question_text[]" rows="3"></textarea></label><label>Choices, one per line<textarea name="options[]" rows="4"></textarea></label><label>Correct choice<input name="correct_answer[]"></label><label>Explanation<textarea name="explanation[]" rows="2"></textarea></label></div></div>
<p><button type="button" class="button secondary" id="add-question">Add another question</button> <button class="button" type="submit">Publish Lesson</button></p></form>
<script>
document.getElementById('add-question').addEventListener('click', function () { const source = document.querySelector('.question-editor'); const copy = source.cloneNode(true); const count = document.querySelectorAll('.question-editor').length + 1; copy.querySelector('h2').textContent = 'Question ' + count; copy.querySelectorAll('textarea, input').forEach(function (field) { field.value = ''; }); document.getElementById('questions').appendChild(copy); });
</script>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
