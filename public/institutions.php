<?php

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireTeacher();
$userId = currentUserId();
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create') {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 180) {
            $error = 'Enter an institution name of 1 to 180 characters.';
        } else {
            $pdo->beginTransaction();
            try {
                $insert = $pdo->prepare(
                    'INSERT INTO institutions (name, created_by) VALUES (?, ?)'
                );
                $insert->execute([$name, $userId]);
                $institutionId = (int) $pdo->lastInsertId();
                $member = $pdo->prepare(
                    'INSERT INTO institution_members (institution_id, user_id, role) VALUES (?, ?, "admin")'
                );
                $member->execute([$institutionId, $userId]);
                $pdo->commit();
                redirect('institutions.php?created=1');
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
        }
    } elseif ($action === 'invite') {
        $institutionId = filter_input(INPUT_POST, 'institution_id', FILTER_VALIDATE_INT) ?: 0;
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        if (!isInstitutionAdmin($pdo, $userId, $institutionId)) {
            http_response_code(403);
            exit('You cannot invite teachers to this institution.');
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter a valid email address or leave it blank.';
        } else {
            $token = bin2hex(random_bytes(32));
            $insert = $pdo->prepare(
                'INSERT INTO institution_invites (institution_id, invited_by, email, token_hash, expires_at)
                 VALUES (?, ?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 7 DAY))'
            );
            $insert->execute([
                $institutionId,
                $userId,
                $email !== '' ? $email : null,
                hash('sha256', $token)
            ]);
            $_SESSION['institution_invite_url'] = EDUCATION_APP_URL . '/institution-invite.php?token=' . rawurlencode($token);
            redirect('institutions.php?invite_created=1');
        }
    } elseif ($action === 'link_class') {
        $institutionId = filter_input(INPUT_POST, 'institution_id', FILTER_VALIDATE_INT) ?: 0;
        $classId = filter_input(INPUT_POST, 'class_id', FILTER_VALIDATE_INT) ?: 0;
        if (!isInstitutionAdmin($pdo, $userId, $institutionId)) {
            http_response_code(403);
            exit('You cannot add classes to this institution.');
        }

        $pdo->beginTransaction();
        try {
            $link = $pdo->prepare(
                'UPDATE classes c
                 INNER JOIN institution_members im
                    ON im.institution_id = ? AND im.user_id = c.teacher_id
                   AND im.role IN ("admin", "teacher")
                 SET c.institution_id = ?
                 WHERE c.id = ? AND c.institution_id IS NULL'
            );
            $link->execute([$institutionId, $institutionId, $classId]);
            if ($link->rowCount() !== 1) {
                $pdo->rollBack();
                $error = 'Choose an unlinked class owned by a teacher in this institution.';
            } else {
                $addStudents = $pdo->prepare(
                    'INSERT INTO institution_members (institution_id, user_id, role)
                     SELECT ?, cm.user_id, "student"
                     FROM class_members cm
                     WHERE cm.class_id = ?
                     ON DUPLICATE KEY UPDATE
                        role = IF(role IN ("admin", "teacher"), role, "student")'
                );
                $addStudents->execute([$institutionId, $classId]);
                $pdo->commit();
                redirect('institutions.php?class_linked=1');
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }
}

$stmt = $pdo->prepare(
    'SELECT i.id, i.name, i.created_at, im.role
     FROM institution_members im
     INNER JOIN institutions i ON i.id = im.institution_id
     WHERE im.user_id = ? AND i.status = "active"
     ORDER BY i.name'
);
$stmt->execute([$userId]);
$institutions = $stmt->fetchAll(PDO::FETCH_ASSOC);
$availableClasses = [];
foreach ($institutions as $institution) {
    if ($institution['role'] !== 'admin') {
        continue;
    }
    $classStmt = $pdo->prepare(
        'SELECT c.id, c.name, u.name AS teacher_name
         FROM classes c
         INNER JOIN institution_members teacher_member
           ON teacher_member.institution_id = ? AND teacher_member.user_id = c.teacher_id
          AND teacher_member.role IN ("admin", "teacher")
         INNER JOIN users u ON u.id = c.teacher_id
         WHERE c.institution_id IS NULL AND c.status = "active"
         ORDER BY c.name'
    );
    $classStmt->execute([(int) $institution['id']]);
    $availableClasses[(int) $institution['id']] = $classStmt->fetchAll(PDO::FETCH_ASSOC);
}

if (isset($_GET['created'])) {
    $success = 'Institution created. You are its institution administrator.';
}
if (isset($_GET['joined'])) {
    $success = 'You joined the institution as a teacher.';
}
if (isset($_GET['invite_created'])) {
    $success = 'Teacher invite link created. Share it directly with the invited teacher; it expires in seven days.';
}
if (isset($_GET['class_linked'])) {
    $success = 'Class linked. Its current students were added to the institution.';
}
$inviteUrl = (string) ($_SESSION['institution_invite_url'] ?? '');
unset($_SESSION['institution_invite_url']);

$title = 'Institutions';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="page-header">
    <div><p class="eyebrow">Education administration</p><h1>Institutions</h1>
    <p>Create an institution, invite teachers, link existing classes, and scope classes and moderation to its membership.</p></div>
</div>
<?php if ($error !== ''): ?><div class="error" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if ($success !== ''): ?><div class="success" role="status"><?= e($success) ?></div><?php endif; ?>

<?php if ($inviteUrl !== ''): ?>
    <section class="card">
        <h2>Teacher invite link</h2>
            <p>This link is shown only once. Share it securely with the invited teacher. They can create a teacher account from the link or sign in to an existing teacher account.</p>
        <p><a href="<?= e($inviteUrl) ?>"><?= e($inviteUrl) ?></a></p>
    </section>
<?php endif; ?>

<section class="card">
    <h2>Create an institution</h2>
    <p>Creating an institution makes you its administrator. Existing classes can be linked below if their teacher belongs to the institution.</p>
    <form method="post" class="message-compose">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="action" value="create">
        <label for="institution-name">Institution name</label>
        <input id="institution-name" name="name" maxlength="180" required>
        <button class="button" type="submit">Create institution</button>
    </form>
</section>

<section class="card">
    <h2>Your institutions</h2>
    <?php if (!$institutions): ?><p class="muted">You are not a member of an institution yet.</p>
    <?php else: ?>
        <div class="list">
            <?php foreach ($institutions as $institution): ?>
                <article class="list-item">
                    <div><strong><?= e($institution['name']) ?></strong><p class="muted">Your role: <?= e(ucfirst($institution['role'])) ?></p></div>
                    <?php if ($institution['role'] === 'admin'): ?>
                        <div>
                            <form method="post" class="message-recipient">
                                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                <input type="hidden" name="action" value="invite">
                                <input type="hidden" name="institution_id" value="<?= (int) $institution['id'] ?>">
                                <label for="invite-email-<?= (int) $institution['id'] ?>">Teacher email (optional)</label>
                                <input id="invite-email-<?= (int) $institution['id'] ?>" type="email" name="email">
                                <button class="button secondary" type="submit">Create teacher invite</button>
                            </form>
                            <?php if (!empty($availableClasses[(int) $institution['id']])): ?>
                                <form method="post" class="message-recipient">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                    <input type="hidden" name="action" value="link_class">
                                    <input type="hidden" name="institution_id" value="<?= (int) $institution['id'] ?>">
                                    <label for="link-class-<?= (int) $institution['id'] ?>">Link an existing class</label>
                                    <select id="link-class-<?= (int) $institution['id'] ?>" name="class_id" required>
                                        <option value="">Select a class</option>
                                        <?php foreach ($availableClasses[(int) $institution['id']] as $class): ?>
                                            <option value="<?= (int) $class['id'] ?>"><?= e($class['name'] . ' | ' . $class['teacher_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button class="button secondary" type="submit">Link class</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
