<?php

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
requireEducationHost();
$userId = currentUserId();
if (!in_array(currentUserRole(), ['student', 'teacher'], true)) {
    http_response_code(403);
    exit('Notifications are available to students and teachers.');
}

$error = '';
$notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $notificationId = filter_input(INPUT_POST, 'notification_id', FILTER_VALIDATE_INT) ?: 0;
    if ($notificationId > 0) {
        $update = $pdo->prepare(
            'UPDATE notifications SET read_at = UTC_TIMESTAMP()
             WHERE id = ? AND user_id = ? AND read_at IS NULL'
        );
        $update->execute([$notificationId, $userId]);
        if ($update->rowCount() === 1) {
            $notice = 'Notification marked as read.';
        } else {
            $error = 'That notification was already read or is no longer available.';
        }
    } else {
        $error = 'Select a notification to mark as read.';
    }
}

$stmt = $pdo->prepare(
    'SELECT n.id, n.notification_type, n.created_at, n.read_at,
            c.id AS class_id, c.name AS class_name,
            CASE WHEN n.notification_type = "message" THEN m.sender_id ELSE a.author_id END AS actor_id,
            CASE WHEN n.notification_type = "message" THEN sender.name ELSE author.name END AS actor_name,
            CASE WHEN n.notification_type = "message" THEN LEFT(m.body, 180)
                 ELSE LEFT(CONCAT(a.title, ": ", a.body), 180) END AS preview,
            CASE WHEN n.notification_type = "message" THEN m.sender_id ELSE 0 END AS sender_id
     FROM notifications n
     INNER JOIN classes c ON c.id = n.class_id
     LEFT JOIN class_messages m
       ON n.notification_type = "message" AND m.id = n.source_id
      AND m.class_id = n.class_id AND m.recipient_id = n.user_id
      AND m.moderation_status = "approved"
     LEFT JOIN users sender ON sender.id = m.sender_id
     LEFT JOIN class_announcements a
       ON n.notification_type = "announcement" AND a.id = n.source_id
      AND a.class_id = n.class_id AND a.moderation_status = "approved"
     LEFT JOIN users author ON author.id = a.author_id
     WHERE n.user_id = ?
       AND (
           (n.notification_type = "message" AND m.id IS NOT NULL
               AND (c.teacher_id = n.user_id OR EXISTS (
                   SELECT 1 FROM class_members cm
                   WHERE cm.class_id = c.id AND cm.user_id = n.user_id
               )))
           OR (n.notification_type = "announcement" AND a.id IS NOT NULL
               AND (c.teacher_id = n.user_id OR EXISTS (
                   SELECT 1 FROM class_members cm
                   WHERE cm.class_id = c.id AND cm.user_id = n.user_id
               )))
       )
     ORDER BY n.created_at DESC, n.id DESC
     LIMIT 100'
);
$stmt->execute([$userId]);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

$title = 'Notifications';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <p class="eyebrow">Class updates</p>
        <h1>Notifications</h1>
        <p>New private messages and class announcements appear here.</p>
    </div>
</div>
<?php if ($error !== ''): ?><div class="error" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if ($notice !== ''): ?><div class="success" role="status"><?= e($notice) ?></div><?php endif; ?>

<?php if (!$notifications): ?>
    <section class="card"><p class="muted">You’re all caught up.</p></section>
<?php else: ?>
    <div class="list">
        <?php foreach ($notifications as $notification): ?>
            <article class="list-item notification-item<?= $notification['read_at'] === null ? ' notification-unread' : '' ?>">
                <div>
                    <strong><?= $notification['notification_type'] === 'message' ? 'New message' : 'Class announcement' ?></strong>
                    <p><?= e($notification['class_name']) ?> · <?= e($notification['actor_name'] ?? 'Class member') ?></p>
                    <p><?= e($notification['preview']) ?></p>
                    <small><?= e(date('M j, Y g:i A', strtotime($notification['created_at']))) ?></small>
                    <p><a class="text-link" href="messages.php?class_id=<?= (int) $notification['class_id'] ?><?= $notification['notification_type'] === 'message' ? '&with=' . (int) $notification['sender_id'] . '#private-messages' : '#announcements' ?>">
                        <?= $notification['notification_type'] === 'message' ? 'Open conversation' : 'View announcement' ?>
                    </a></p>
                </div>
                <?php if ($notification['read_at'] === null): ?>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                        <input type="hidden" name="notification_id" value="<?= (int) $notification['id'] ?>">
                        <button class="button secondary" type="submit">Mark read</button>
                    </form>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
