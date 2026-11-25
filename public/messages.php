<?php

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/notifications.php';

requireLogin();
requireEducationHost();

$userId = currentUserId();
$role = currentUserRole();
if (!in_array($role, ['student', 'teacher'], true)) {
    http_response_code(403);
    exit('Messaging is available to students and teachers.');
}

$classQuery = $role === 'teacher'
    ? 'SELECT c.id, c.name, c.institution_id
       FROM classes c
       LEFT JOIN institution_members im ON im.institution_id = c.institution_id AND im.user_id = ?
       WHERE c.teacher_id = ? AND c.status = "active"
         AND (c.institution_id IS NULL OR im.role IN ("admin","teacher"))
       ORDER BY c.name'
    : 'SELECT c.id, c.name, c.institution_id
       FROM classes c
       INNER JOIN class_members cm ON cm.class_id = c.id
       LEFT JOIN institution_members im ON im.institution_id = c.institution_id AND im.user_id = ?
       WHERE cm.user_id = ? AND c.status = "active"
         AND (c.institution_id IS NULL OR im.user_id IS NOT NULL)
       ORDER BY c.name';
$classStmt = $pdo->prepare($classQuery);
$classStmt->execute([$userId, $userId]);
$classes = $classStmt->fetchAll(PDO::FETCH_ASSOC);

$classId = filter_input(
    INPUT_POST,
    'class_id',
    FILTER_VALIDATE_INT,
    FILTER_REQUIRE_SCALAR
);
if (!$classId) {
    $classId = filter_input(INPUT_GET, 'class_id', FILTER_VALIDATE_INT) ?: 0;
}

$class = null;
foreach ($classes as $availableClass) {
    if ((int) $availableClass['id'] === (int) $classId) {
        $class = $availableClass;
        break;
    }
}

$error = '';
$success = '';
$students = [];
$recipientId = filter_input(INPUT_GET, 'with', FILTER_VALIDATE_INT) ?: 0;
$teacherId = 0;
$teacherProfile = [];

if ($class) {
    $ownerStmt = $pdo->prepare(
        'SELECT c.teacher_id, c.institution_id, u.name, u.bio, u.profile_image
         FROM classes c INNER JOIN users u ON u.id = c.teacher_id
         WHERE c.id = ?'
    );
    $ownerStmt->execute([(int) $class['id']]);
    $teacherProfile = $ownerStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $teacherId = (int) ($teacherProfile['teacher_id'] ?? 0);
    $class['institution_id'] = $teacherProfile['institution_id'] ?? null;

    if ($role === 'teacher') {
        $studentStmt = $pdo->prepare(
            'SELECT u.id, u.name, u.bio, u.profile_image
             FROM class_members cm
             INNER JOIN users u ON u.id = cm.user_id
             WHERE cm.class_id = ? AND u.role = "student" AND u.status = "active"
             ORDER BY u.name'
        );
        $studentStmt->execute([(int) $class['id']]);
        $students = $studentStmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $recipientId = $teacherId;
    }
} elseif ($classId > 0) {
    http_response_code(403);
    exit('You do not have access to this class.');
}

if ($class && $role === 'teacher' && $recipientId > 0) {
    $recipientCheck = $pdo->prepare(
        'SELECT 1 FROM class_members cm
         INNER JOIN users u ON u.id = cm.user_id
         WHERE cm.class_id = ? AND cm.user_id = ? AND u.role = "student" AND u.status = "active"
         LIMIT 1'
    );
    $recipientCheck->execute([(int) $class['id'], $recipientId]);
    if (!$recipientCheck->fetchColumn()) {
        $recipientId = 0;
        $error = 'Choose a student in this class.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $class) {
    verifyCsrf();
    $action = (string) ($_POST['action'] ?? '');
    $body = trim((string) ($_POST['body'] ?? ''));

    if ($action === 'announcement' && $role === 'teacher') {
        $announcementTitle = trim((string) ($_POST['title'] ?? ''));
        if ($announcementTitle === '' || mb_strlen($announcementTitle) > 180) {
            $error = 'Enter an announcement title of 1 to 180 characters.';
        } elseif ($body === '' || mb_strlen($body) > 5000) {
            $error = 'Enter an announcement of 1 to 5000 characters.';
        } else {
            $containsBlockedTerm = messageFlagMatches($announcementTitle . "\n" . $body);
            if ($containsBlockedTerm && empty($class['institution_id'])) {
                $error = 'This announcement contains wording blocked by the class filter. Rephrase it and try again.';
            } else {
                $flagged = $containsBlockedTerm;
                $pdo->beginTransaction();
                $insert = $pdo->prepare(
                    'INSERT INTO class_announcements
                     (class_id, author_id, title, body, moderation_status, flagged_at)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                $insert->execute([
                    (int) $class['id'], $userId, $announcementTitle, $body,
                    $flagged ? 'pending' : 'approved',
                    $flagged ? gmdate('Y-m-d H:i:s') : null
                ]);
                if (!$flagged) {
                    createAnnouncementNotifications($pdo, (int) $class['id'], (int) $pdo->lastInsertId());
                }
                $pdo->commit();
                redirect('messages.php?class_id=' . (int) $class['id'] . '&tab=announcements&posted=1' . ($flagged ? '&held=1' : ''));
            }
        }
    } elseif ($action === 'message') {
        $postRecipientId = $role === 'student'
            ? $teacherId
            : (filter_input(INPUT_POST, 'recipient_id', FILTER_VALIDATE_INT) ?: 0);

        if ($role === 'teacher') {
            $recipientCheck = $pdo->prepare(
                'SELECT 1 FROM class_members cm
                 INNER JOIN users u ON u.id = cm.user_id
                 WHERE cm.class_id = ? AND cm.user_id = ? AND u.role = "student" AND u.status = "active"
                 LIMIT 1'
            );
            $recipientCheck->execute([(int) $class['id'], $postRecipientId]);
            if (!$recipientCheck->fetchColumn()) {
                $error = 'Choose a student in this class.';
            }
        }

        if ($error === '' && ($body === '' || mb_strlen($body) > 5000)) {
            $error = 'Enter a message of 1 to 5000 characters.';
        }

        if ($error === '') {
            $containsBlockedTerm = messageFlagMatches($body);
            if ($containsBlockedTerm && empty($class['institution_id'])) {
                $error = 'This message contains wording blocked by the class filter. Rephrase it and try again.';
            } else {
                $flagged = $containsBlockedTerm;
                $pdo->beginTransaction();
                $insert = $pdo->prepare(
                    'INSERT INTO class_messages
                     (class_id, sender_id, recipient_id, body, moderation_status, flagged_at)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                $insert->execute([
                    (int) $class['id'], $userId, $postRecipientId, $body,
                    $flagged ? 'pending' : 'approved',
                    $flagged ? gmdate('Y-m-d H:i:s') : null
                ]);
                if (!$flagged) {
                    createMessageNotification($pdo, (int) $class['id'], (int) $pdo->lastInsertId(), $postRecipientId);
                }
                $pdo->commit();
                redirect('messages.php?class_id=' . (int) $class['id'] . '&with=' . $postRecipientId . '&sent=1' . ($flagged ? '&held=1' : ''));
            }
        }
    } else {
        $error = 'Choose a valid messaging action.';
    }
}

if (isset($_GET['posted'])) {
    $success = isset($_GET['held'])
        ? 'Announcement held for institution administrator review. It is not visible to students unless approved.'
        : 'Announcement posted to your class.';
} elseif (isset($_GET['sent'])) {
    $success = isset($_GET['held'])
        ? 'Message held for institution administrator review. It is not visible to the recipient unless approved.'
        : 'Message sent.';
}

$announcements = [];
$conversation = [];
$recipientName = '';
if ($class) {
    $announcementStmt = $pdo->prepare(
        'SELECT ca.title, ca.body, ca.created_at, ca.author_id, ca.moderation_status,
                u.name AS author_name, u.profile_image AS author_image, u.bio AS author_bio
         FROM class_announcements ca
         INNER JOIN users u ON u.id = ca.author_id
         WHERE ca.class_id = ?
           AND (ca.moderation_status = "approved" OR ca.author_id = ?)
         ORDER BY ca.created_at DESC
         LIMIT 30'
    );
    $announcementStmt->execute([(int) $class['id'], $userId]);
    $announcements = $announcementStmt->fetchAll(PDO::FETCH_ASSOC);

    if ($recipientId > 0) {
        $nameStmt = $pdo->prepare('SELECT name, bio, profile_image FROM users WHERE id = ?');
        $nameStmt->execute([$recipientId]);
        $recipientProfile = $nameStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $recipientName = (string) ($recipientProfile['name'] ?? '');
        $conversationStmt = $pdo->prepare(
            'SELECT m.sender_id, m.body, m.created_at, m.moderation_status, u.name AS sender_name, u.profile_image AS sender_image
             FROM class_messages m
             INNER JOIN users u ON u.id = m.sender_id
             WHERE m.class_id = ?
               AND ((m.sender_id = ? AND m.recipient_id = ?)
                 OR (m.sender_id = ? AND m.recipient_id = ?))
               AND (m.moderation_status = "approved" OR m.sender_id = ?)
             ORDER BY m.created_at DESC, m.id DESC
             LIMIT 100'
        );
        $conversationStmt->execute([(int) $class['id'], $userId, $recipientId, $recipientId, $userId, $userId]);
        $conversation = array_reverse($conversationStmt->fetchAll(PDO::FETCH_ASSOC));
    }
}

$title = 'Messages';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <p class="eyebrow">Class communication</p>
        <h1>Messages &amp; announcements</h1>
        <p>Private teacher-student conversations and updates shared with your class.</p>
    </div>
</div>

<?php if ($error !== ''): ?><div class="error" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if ($success !== ''): ?><div class="success" role="status"><?= e($success) ?></div><?php endif; ?>

<?php if (!$classes): ?>
    <section class="card"><p>No active classes are available for messaging.</p></section>
<?php else: ?>
    <?php if (!$class): ?>
        <section class="card">
            <h2>Choose a class</h2>
            <div class="list">
                <?php foreach ($classes as $availableClass): ?>
                    <div class="list-item">
                        <strong><?= e($availableClass['name']) ?></strong>
                        <a class="button secondary" href="messages.php?class_id=<?= (int) $availableClass['id'] ?>">Open</a>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php else: ?>
        <section class="card">
            <div class="dashboard-section-heading">
                <div><p class="eyebrow">Selected class</p><h2><?= e($class['name']) ?></h2></div>
                <a class="text-link" href="messages.php">Change class</a>
            </div>
            <nav class="message-tabs" aria-label="Class communication">
                <a class="button secondary" href="#announcements">Announcements</a>
                <a class="button secondary" href="#private-messages">Private messages</a>
            </nav>
        </section>

        <section class="card" id="announcements">
            <h2>Class announcements</h2>
            <?php if ($role === 'teacher'): ?>
                <form method="post" class="message-compose">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="class_id" value="<?= (int) $class['id'] ?>">
                    <input type="hidden" name="action" value="announcement">
                    <label for="announcement-title">Title</label>
                    <input id="announcement-title" name="title" maxlength="180" required>
                    <label for="announcement-body">Announcement</label>
                    <textarea id="announcement-body" name="body" rows="4" maxlength="5000" required></textarea>
                    <button class="button" type="submit">Post to class</button>
                </form>
            <?php endif; ?>
            <?php if (!$announcements): ?>
                <p class="muted">No announcements yet.</p>
            <?php else: ?>
                <div class="list">
                    <?php foreach ($announcements as $announcement): ?>
                        <article class="card message-item">
                            <h3><?= e($announcement['title']) ?></h3>
                            <?php if ((int) $announcement['author_id'] === $userId && $announcement['moderation_status'] !== 'approved'): ?>
                                <p class="muted"><?= $announcement['moderation_status'] === 'pending' ? 'Awaiting institution administrator review; not delivered to students.' : 'This announcement was rejected and was not delivered.' ?></p>
                            <?php endif; ?>
                            <p class="preline"><?= e($announcement['body']) ?></p>
                            <p class="message-author">
                                <?php if (!empty($announcement['author_image'])): ?><img class="profile-avatar" src="profile-picture.php?id=<?= (int) $announcement['author_id'] ?>" alt="<?= e((string) $announcement['author_name']) ?> profile picture"><?php endif; ?>
                                <span>By <?= e($announcement['author_name']) ?> · <?= e(date('M j, Y g:i A', strtotime($announcement['created_at']))) ?>
                                <?php if (!empty($announcement['author_bio'])): ?><small><?= e($announcement['author_bio']) ?></small><?php endif; ?></span>
                            </p>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="card" id="private-messages">
            <h2>Private messages</h2>
            <?php if ($role === 'teacher'): ?>
                <?php if (!$students): ?>
                    <p class="muted">Students who join this class will appear here.</p>
                <?php else: ?>
                    <form method="get" class="message-recipient">
                        <input type="hidden" name="class_id" value="<?= (int) $class['id'] ?>">
                        <label for="message-recipient">Choose a student</label>
                        <select id="message-recipient" name="with" required>
                            <option value="">Select a student</option>
                            <?php foreach ($students as $student): ?>
                                <option value="<?= (int) $student['id'] ?>" <?= (int) $student['id'] === $recipientId ? 'selected' : '' ?>><?= e($student['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="button secondary" type="submit">Open conversation</button>
                    </form>
                <?php endif; ?>
                <?php if (!empty($recipientProfile['profile_image'])): ?><img class="profile-avatar" src="profile-picture.php?id=<?= $recipientId ?>" alt="<?= e((string) $recipientProfile['name']) ?> profile picture"><?php endif; ?>
                <?php if (!empty($recipientProfile['bio'])): ?><p class="muted"><?= e((string) $recipientProfile['bio']) ?></p><?php endif; ?>
            <?php else: ?>
                <p>Private conversation with your teacher<?= !empty($teacherProfile['name']) ? ': <strong>' . e((string) $teacherProfile['name']) . '</strong>' : '' ?>.</p>
                <?php if (!empty($teacherProfile['profile_image'])): ?><img class="profile-avatar" src="profile-picture.php?id=<?= $teacherId ?>" alt="<?= e((string) $teacherProfile['name']) ?> profile picture"><?php endif; ?>
                <?php if (!empty($teacherProfile['bio'])): ?><p class="muted"><?= e((string) $teacherProfile['bio']) ?></p><?php endif; ?>
            <?php endif; ?>

            <?php if ($recipientId > 0 && $recipientName !== ''): ?>
                <div class="message-thread" aria-live="polite">
                    <?php if (!$conversation): ?><p class="muted">Start the conversation with a message.</p><?php endif; ?>
                    <?php foreach ($conversation as $message): ?>
                        <article class="message-bubble<?= (int) $message['sender_id'] === $userId ? ' message-own' : '' ?>">
                            <?php if (!empty($message['sender_image'])): ?><img class="profile-avatar" src="profile-picture.php?id=<?= (int) $message['sender_id'] ?>" alt="<?= e((string) $message['sender_name']) ?> profile picture"><?php endif; ?>
                            <strong><?= e($message['sender_name']) ?></strong>
                            <?php if ((int) $message['sender_id'] === $userId && $message['moderation_status'] !== 'approved'): ?>
                                <small><?= $message['moderation_status'] === 'pending' ? 'Awaiting institution administrator review; not delivered to the recipient.' : 'Rejected; not delivered to the recipient.' ?></small>
                            <?php endif; ?>
                            <p class="preline"><?= e($message['body']) ?></p>
                            <small><?= e(date('M j, Y g:i A', strtotime($message['created_at']))) ?></small>
                        </article>
                    <?php endforeach; ?>
                </div>
                <form method="post" class="message-compose">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="class_id" value="<?= (int) $class['id'] ?>">
                    <input type="hidden" name="action" value="message">
                    <?php if ($role === 'teacher'): ?><input type="hidden" name="recipient_id" value="<?= $recipientId ?>"><?php endif; ?>
                    <label for="private-message">Message <?= $role === 'teacher' ? 'to ' . e($recipientName) : '' ?></label>
                    <textarea id="private-message" name="body" rows="3" maxlength="5000" required></textarea>
                    <button class="button" type="submit">Send message</button>
                </form>
            <?php endif; ?>
        </section>
    <?php endif; ?>
<?php endif; ?>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
