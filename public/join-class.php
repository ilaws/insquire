<?php

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireStudent();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $code = strtoupper(trim($_POST['join_code'] ?? ''));

    $stmt = $pdo->prepare(
        'SELECT id, institution_id
         FROM classes
         WHERE join_code = ?
         AND status = "active"
         LIMIT 1'
    );

    $stmt->execute([$code]);

    $class = $stmt->fetch();

    if (!$class) {
        $error = 'Class code not found.';
    } else {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT IGNORE INTO class_members (class_id,user_id) VALUES (?,?)'
            );
            $stmt->execute([(int) $class['id'], currentUserId()]);
            if (!empty($class['institution_id'])) {
                $institutionMember = $pdo->prepare(
                    'INSERT INTO institution_members (institution_id, user_id, role)
                     VALUES (?, ?, "student")
                     ON DUPLICATE KEY UPDATE role = IF(role = "admin" OR role = "teacher", role, "student")'
                );
                $institutionMember->execute([(int) $class['institution_id'], currentUserId()]);
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        redirect('class.php?id=' . $class['id']);
    }
}

$title = 'Join Class';

require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="card small-card">
    <h1>Join Class</h1>

    <?php if ($error): ?>
        <div class="error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

        <label>Class Code</label>
        <input
            type="text"
            name="join_code"
            maxlength="16"
            required
            autocomplete="off"
        >

        <button class="button" type="submit">
            Join Class
        </button>
    </form>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
