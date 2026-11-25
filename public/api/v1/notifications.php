<?php

require_once __DIR__ . '/_bootstrap.php';

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? '');
apiRequireMethod(in_array($method, ['GET', 'POST'], true) ? $method : 'GET');
$user = apiRequireUser(['student', 'teacher']);

if ($method === 'POST') {
    apiVerifyCsrf();
    $input = apiReadJson();
    $notificationId = filter_var(
        $input['notification_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );
    if ($notificationId === false) {
        apiRespond(['error' => 'Select a valid notification.'], 422);
    }

    $update = $pdo->prepare(
        'UPDATE notifications
         SET read_at = UTC_TIMESTAMP()
         WHERE id = ? AND user_id = ? AND read_at IS NULL'
    );
    $update->execute([(int) $notificationId, $user['id']]);
    if ($update->rowCount() !== 1) {
        apiRespond(['error' => 'That notification was already read or is no longer available.'], 404);
    }

    apiRespond(['success' => true]);
}

$stmt = $pdo->prepare(
    'SELECT n.id, n.notification_type, n.created_at, n.read_at,
            c.id AS class_id, c.name AS class_name,
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
$stmt->execute([$user['id']]);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($notifications as &$notification) {
    $notification['id'] = (int) $notification['id'];
    $notification['class_id'] = (int) $notification['class_id'];
    $notification['sender_id'] = (int) $notification['sender_id'];
}
unset($notification);

apiRespond(['notifications' => $notifications]);
