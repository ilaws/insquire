<?php

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireTeacher();

$classId = filter_input(INPUT_GET, 'class_id', FILTER_VALIDATE_INT);

if (!$classId) {
    http_response_code(404);
    exit('Class not found.');
}

$class = getClassForTeacher(
    $pdo,
    $classId,
    currentUserId()
);

if (!$class) {
    http_response_code(403);
    exit('Access denied.');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $titleValue = trim($_POST['title'] ?? '');
    $prompt = trim($_POST['prompt'] ?? '');
    $instructions = trim($_POST['instructions'] ?? '');
    $dueAt = trim($_POST['due_at'] ?? '');
    $allowLate = isset($_POST['allow_late']) ? 1 : 0;
    $latePenalty = max(0, min(100, (float) ($_POST['late_penalty_percent'] ?? 0)));
    $rubricText = trim($_POST['rubric_text'] ?? '');
    $rubricFilename = null;

    if ($titleValue === '' || $prompt === '') {
        $error = 'Title and prompt are required.';
    }

    if (
        !$error &&
        isset($_FILES['rubric']) &&
        $_FILES['rubric']['error'] !== UPLOAD_ERR_NO_FILE
    ) {
        if ($_FILES['rubric']['error'] !== UPLOAD_ERR_OK) {
            $error = 'Unable to upload rubric.';
        } elseif ($_FILES['rubric']['size'] > 2000000) {
            $error = 'Rubric must be smaller than 2 MB.';
        } else {
            $extension = strtolower(
                pathinfo(
                    $_FILES['rubric']['name'],
                    PATHINFO_EXTENSION
                )
            );

            $allowed = [
                'txt',
                'md',
                'csv',
                'json'
            ];

            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['rubric']['tmp_name']);
            $allowedMimeTypes = [
                'txt' => ['text/plain', 'application/octet-stream'],
                'md' => ['text/plain', 'text/markdown', 'application/octet-stream'],
                'csv' => ['text/plain', 'text/csv', 'application/csv', 'application/octet-stream'],
                'json' => ['application/json', 'text/plain', 'application/octet-stream']
            ];

            if (!isset($allowedMimeTypes[$extension]) || !in_array($mime, $allowedMimeTypes[$extension], true)) {
                $error = 'Rubric must be TXT, MD, CSV, or JSON.';
            } else {
                if (!is_dir(RUBRIC_PATH)) {
                    mkdir(RUBRIC_PATH, 0750, true);
                }

                $rubricFilename =
                    bin2hex(random_bytes(20)) .
                    '.' .
                    $extension;

                $destination =
                    RUBRIC_PATH .
                    '/' .
                    $rubricFilename;

                if (!move_uploaded_file(
                    $_FILES['rubric']['tmp_name'],
                    $destination
                )) {
                    $error = 'Unable to save rubric.';
                } else {
                    chmod($destination, 0640);
                    $uploadedText = file_get_contents($destination);

                    if ($uploadedText !== false) {
                        $rubricText = $uploadedText;
                    }
                }
            }
        }
    }

    if (!$error) {
        $hasLateColumns = hasTableColumn($pdo, 'assignments', 'allow_late')
            && hasTableColumn($pdo, 'assignments', 'late_penalty_percent');

        if ($hasLateColumns) {
            $stmt = $pdo->prepare(
                'INSERT INTO assignments
            (
                class_id,
                title,
                prompt,
                instructions,
                rubric_text,
                rubric_filename,
                due_at,
                allow_late,
                late_penalty_percent,
                status
            )
                VALUES (?,?,?,?,?,?,?,?,?,"published")'
            );

            $stmt->execute([
                $classId, $titleValue, $prompt, $instructions,
                $rubricText, $rubricFilename,
                $dueAt !== '' ? $dueAt : null,
                $allowLate, $latePenalty
            ]);
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO assignments
                (class_id, title, prompt, instructions, rubric_text, rubric_filename, due_at, status)
                VALUES (?,?,?,?,?,?,?,"published")'
            );
            $stmt->execute([
                $classId, $titleValue, $prompt, $instructions,
                $rubricText, $rubricFilename, $dueAt !== '' ? $dueAt : null
            ]);
        }

        redirect('class.php?id=' . $classId);
    }
}

$title = 'Create Assignment';

require_once dirname(__DIR__) . '/includes/header.php';
?>

<h1>Create Assignment</h1>

<div class="card">

    <p>
        Class:
        <strong><?= e($class['name']) ?></strong>
    </p>

    <?php if ($error): ?>
        <div class="error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
        <input
            type="hidden"
            name="csrf_token"
            value="<?= e(csrfToken()) ?>"
        >

        <label>Assignment Title</label>
        <input type="text" name="title" required>

        <label>Assignment Prompt</label>
        <textarea name="prompt" rows="7" required></textarea>

        <label>Teacher Instructions</label>
        <textarea name="instructions" rows="6"></textarea>

        <label>Due Date</label>
        <input type="datetime-local" name="due_at">

        <label class="checkbox-label">
            <input type="checkbox" name="allow_late" value="1" checked>
            Accept late submissions
        </label>

        <label>Late Penalty (%)</label>
        <input type="number" name="late_penalty_percent" min="0" max="100" step="0.01" value="0">

        <label>Rubric Text</label>
        <textarea name="rubric_text" rows="10"></textarea>

        <label>Upload Rubric</label>
        <input
            type="file"
            name="rubric"
            accept=".txt,.md,.csv,.json"
        >

        <button class="button" type="submit">
            Create Assignment
        </button>
    </form>

</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
