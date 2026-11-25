<?php

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireTeacher();

$error = '';
$institutionStmt = $pdo->prepare(
    'SELECT i.id, i.name, im.role
     FROM institution_members im
     INNER JOIN institutions i ON i.id = im.institution_id
     WHERE im.user_id = ? AND im.role IN ("admin","teacher") AND i.status = "active"
     ORDER BY i.name'
);
$institutionStmt->execute([currentUserId()]);
$institutions = $institutionStmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $gradeLevel = trim($_POST['grade_level'] ?? '');
    $institutionId = filter_input(INPUT_POST, 'institution_id', FILTER_VALIDATE_INT) ?: 0;

    if ($name === '') {
        $error = 'Class name is required.';
    } else {
        $membershipCheck = $pdo->prepare(
            'SELECT 1 FROM institution_members
             WHERE user_id = ? AND institution_id = ? AND role IN ("admin","teacher") LIMIT 1'
        );
        $membershipCheck->execute([currentUserId(), $institutionId]);
        if (!$membershipCheck->fetchColumn()) {
            $error = 'Choose an institution where you are an administrator or teacher.';
        }
    }

    if ($error === '') {
        $joinCode = generateJoinCode($pdo);
        $stmt = $pdo->prepare(
            'INSERT INTO classes
             (teacher_id, institution_id, name, description, grade_level, join_code)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            currentUserId(), $institutionId, $name, $description,
            $gradeLevel !== '' ? $gradeLevel : null, $joinCode
        ]);

        redirect('class.php?id=' . $pdo->lastInsertId());
    }
}

$title = 'Create Class';

require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="card small-card">
    <h1>Create Class</h1>

    <?php if (!$institutions): ?>
        <p>Create or join an institution before creating an Education class.</p>
        <a class="button" href="institutions.php">Manage institutions</a>
    <?php else: ?>
    <?php if ($error): ?>
        <div class="error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

        <label for="institution-id">Institution</label>
        <select id="institution-id" name="institution_id" required>
            <option value="">Select an institution</option>
            <?php foreach ($institutions as $institution): ?>
                <option value="<?= (int) $institution['id'] ?>" <?= (int) ($_POST['institution_id'] ?? 0) === (int) $institution['id'] ? 'selected' : '' ?>><?= e($institution['name']) ?></option>
            <?php endforeach; ?>
        </select>

        <label>Class Name</label>
        <input type="text" name="name" required>

        <label>Description</label>
        <textarea name="description" rows="5"></textarea>

        <label>Grade Level</label>
        <select name="grade_level">
            <option value="">Select grade level</option>
            <option value="K-2">K-2</option>
            <option value="3-5">3-5</option>
            <option value="6-8">6-8</option>
            <option value="9-10">9-10</option>
            <option value="11-12">11-12</option>
            <option value="College">College</option>
        </select>

        <button class="button" type="submit">
            Create Class
        </button>
    </form>
    <?php endif; ?>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
