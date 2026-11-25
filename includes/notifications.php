<?php

function unreadNotificationCount(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM notifications n
         WHERE n.user_id = ? AND n.read_at IS NULL
           AND (
               (n.notification_type = "message" AND EXISTS (
                   SELECT 1
                   FROM class_messages m
                   INNER JOIN classes c ON c.id = m.class_id
                   WHERE m.id = n.source_id AND m.recipient_id = n.user_id
                     AND m.class_id = n.class_id AND m.moderation_status = "approved"
                     AND (c.teacher_id = n.user_id OR EXISTS (
                         SELECT 1 FROM class_members cm
                         WHERE cm.class_id = c.id AND cm.user_id = n.user_id
                     ))
               ))
               OR
               (n.notification_type = "announcement" AND EXISTS (
                   SELECT 1
                   FROM class_announcements a
                   INNER JOIN classes c ON c.id = a.class_id
                   WHERE a.id = n.source_id AND a.moderation_status = "approved"
                     AND a.class_id = n.class_id
                     AND (c.teacher_id = n.user_id OR EXISTS (
                         SELECT 1 FROM class_members cm
                         WHERE cm.class_id = c.id AND cm.user_id = n.user_id
                     ))
               ))
           )'
    );
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

function createMessageNotification(PDO $pdo, int $classId, int $messageId, int $recipientId): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO notifications (user_id, class_id, notification_type, source_id)
         SELECT ?, ?, "message", m.id
         FROM class_messages m
         WHERE m.id = ? AND m.class_id = ? AND m.recipient_id = ?
           AND m.moderation_status = "approved"
         ON DUPLICATE KEY UPDATE id = notifications.id'
    );
    $stmt->execute([$recipientId, $classId, $messageId, $classId, $recipientId]);
}

function createAnnouncementNotifications(PDO $pdo, int $classId, int $announcementId): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO notifications (user_id, class_id, notification_type, source_id)
         SELECT cm.user_id, a.class_id, "announcement", a.id
         FROM class_announcements a
         INNER JOIN class_members cm ON cm.class_id = a.class_id
         INNER JOIN users u ON u.id = cm.user_id AND u.status = "active"
         WHERE a.id = ? AND a.class_id = ? AND a.moderation_status = "approved"
           AND cm.user_id <> a.author_id
         ON DUPLICATE KEY UPDATE id = notifications.id'
    );
    $stmt->execute([$announcementId, $classId]);
}
