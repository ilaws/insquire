<?php

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/notifications.php';

requireLogin();
requireEducationHost();
$userId = currentUserId();
if (!isInstitutionAdmin($pdo, $userId)) {
    http_response_code(403);
    exit('Institution administrator access required.');
}

$institutionsStmt = $pdo->prepare(
    'SELECT i.id, i.name
     FROM institution_members im
     INNER JOIN institutions i ON i.id = im.institution_id
     WHERE im.user_id = ? AND im.role = "admin" AND i.status = "active"
     ORDER BY i.name'
);
$institutionsStmt->execute([$userId]);
$institutions = $institutionsStmt->fetchAll(PDO::FETCH_ASSOC);
$institutionId = filter_input(INPUT_POST, 'institution_id', FILTER_VALIDATE_INT)
    ?: (filter_input(INPUT_GET, 'institution_id', FILTER_VALIDATE_INT) ?: (int) ($institutions[0]['id'] ?? 0));
$institution = null;
foreach ($institutions as $availableInstitution) {
    if ((int) $availableInstitution['id'] === $institutionId) {
        $institution = $availableInstitution;
        break;
    }
}
if (!$institution) {
    http_response_code(403);
    exit('You cannot review messages for this institution.');
}

$type = (string) ($_POST['item_type'] ?? $_GET['type'] ?? '');
$itemId = filter_input(INPUT_POST, 'item_id', FILTER_VALIDATE_INT)
    ?: (filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0);
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $decision = (string) ($_POST['decision'] ?? '');
    if (!in_array($type, ['message', 'announcement'], true) || !in_array($decision, ['approved', 'rejected'], true) || $itemId < 1) {
        $error = 'Choose a valid moderation action.';
    } else {
        $table = $type === 'message' ? 'class_messages' : 'class_announcements';
        $sql = 'UPDATE ' . $table . ' item
                INNER JOIN classes c ON c.id = item.class_id
                INNER JOIN institution_members im
                    ON im.institution_id = c.institution_id
                   AND im.user_id = ? AND im.role = "admin"
                SET item.moderation_status = ?, item.moderated_by = ?, item.moderated_at = UTC_TIMESTAMP()
                WHERE item.id = ? AND c.institution_id = ? AND item.moderation_status = "pending"';
        $update = $pdo->prepare($sql);
        $pdo->beginTransaction();
        $update->execute([$userId, $decision, $userId, $itemId, $institutionId]);
        if ($update->rowCount() === 1) {
            if ($decision === 'approved' && $type === 'message') {
                $itemStmt = $pdo->prepare('SELECT class_id, recipient_id FROM class_messages WHERE id = ?');
                $itemStmt->execute([$itemId]);
                $message = $itemStmt->fetch(PDO::FETCH_ASSOC);
                if ($message) {
                    createMessageNotification($pdo, (int) $message['class_id'], $itemId, (int) $message['recipient_id']);
                }
            } elseif ($decision === 'approved') {
                $itemStmt = $pdo->prepare('SELECT class_id FROM class_announcements WHERE id = ?');
                $itemStmt->execute([$itemId]);
                $announcementClassId = (int) $itemStmt->fetchColumn();
                if ($announcementClassId > 0) {
                    createAnnouncementNotifications($pdo, $announcementClassId, $itemId);
                }
            }
            $pdo->commit();
            redirect('moderation.php?institution_id=' . $institutionId . '&reviewed=1');
        }
        $pdo->rollBack();
        $error = 'That item is no longer pending or is outside your institution.';
    }
}

$detail = null;
if ($itemId > 0 && in_array($type, ['message', 'announcement'], true)) {
    if ($type === 'message') {
        $detailStmt = $pdo->prepare(
            'SELECT m.id, m.body, m.created_at, m.flagged_at, c.name AS class_name,
                    u.name AS sender_name, "Private message" AS item_label
             FROM class_messages m
             INNER JOIN classes c ON c.id = m.class_id
             INNER JOIN users u ON u.id = m.sender_id
             INNER JOIN institution_members im
                ON im.institution_id = c.institution_id AND im.user_id = ? AND im.role = "admin"
             WHERE m.id = ? AND c.institution_id = ? AND m.moderation_status = "pending"
             LIMIT 1'
        );
    } else {
        $detailStmt = $pdo->prepare(
            'SELECT a.id, CONCAT(a.title, "\n\n", a.body) AS body, a.created_at, a.flagged_at,
                    c.name AS class_name, u.name AS sender_name, "Class announcement" AS item_label
             FROM class_announcements a
             INNER JOIN classes c ON c.id = a.class_id
             INNER JOIN users u ON u.id = a.author_id
             INNER JOIN institution_members im
                ON im.institution_id = c.institution_id AND im.user_id = ? AND im.role = "admin"
             WHERE a.id = ? AND c.institution_id = ? AND a.moderation_status = "pending"
             LIMIT 1'
        );
    }
    $detailStmt->execute([$userId, $itemId, $institutionId]);
    $detail = $detailStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$messagesStmt = $pdo->prepare(
    'SELECT m.id, m.created_at, c.name AS class_name, u.name AS sender_name
     FROM class_messages m
     INNER JOIN classes c ON c.id = m.class_id AND c.institution_id = ?
     INNER JOIN users u ON u.id = m.sender_id
     WHERE m.moderation_status = "pending"
       AND EXISTS (SELECT 1 FROM institution_members im
                   WHERE im.institution_id = c.institution_id AND im.user_id = ? AND im.role = "admin")
     ORDER BY m.created_at, m.id LIMIT 100'
);
$messagesStmt->execute([$institutionId, $userId]);
$pendingMessages = $messagesStmt->fetchAll(PDO::FETCH_ASSOC);

$announcementsStmt = $pdo->prepare(
    'SELECT a.id, a.created_at, c.name AS class_name, u.name AS sender_name
     FROM class_announcements a
     INNER JOIN classes c ON c.id = a.class_id AND c.institution_id = ?
     INNER JOIN users u ON u.id = a.author_id
     WHERE a.moderation_status = "pending"
       AND EXISTS (SELECT 1 FROM institution_members im
                   WHERE im.institution_id = c.institution_id AND im.user_id = ? AND im.role = "admin")
     ORDER BY a.created_at, a.id LIMIT 100'
);
$announcementsStmt->execute([$institutionId, $userId]);
$pendingAnnouncements = $announcementsStmt->fetchAll(PDO::FETCH_ASSOC);

if (isset($_GET['reviewed'])) {
    $success = 'Moderation decision saved.';
}
$title = 'Institution Review Queue';
require_once dirname(__DIR__) . '/includes/header.php';
?>
<div class="page-header">
    <div><p class="eyebrow">Institution-only moderation</p><h1>Review queue</h1>
    <p>Only pending content from <?= e($institution['name']) ?> is shown. Message bodies load only when you open an item.</p></div>
</div>
<?php if ($error !== ''): ?><div class="error" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if ($success !== ''): ?><div class="success" role="status"><?= e($success) ?></div><?php endif; ?>

<?php if (count($institutions) > 1): ?>
    <form method="get" class="card message-recipient">
        <label for="institution-id">Institution</label>
        <select id="institution-id" name="institution_id">
            <?php foreach ($institutions as $item): ?>
                <option value="<?= (int) $item['id'] ?>" <?= (int) $item['id'] === $institutionId ? 'selected' : '' ?>><?= e($item['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="button secondary" type="submit">Open queue</button>
    </form>
<?php endif; ?>

<?php if ($detail): ?>
    <section class="card">
        <p class="eyebrow"><?= e($detail['item_label']) ?> · <?= e($detail['class_name']) ?></p>
        <h2>Flagged content for review</h2>
        <p class="muted">Sent by <?= e($detail['sender_name']) ?> on <?= e(date('M j, Y g:i A', strtotime($detail['created_at']))) ?>. The server-side word filter held this item.</p>
        <div class="card preline"><?= e($detail['body']) ?></div>
        <form method="post" class="message-recipient">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="institution_id" value="<?= $institutionId ?>">
            <input type="hidden" name="item_type" value="<?= e($type) ?>">
            <input type="hidden" name="item_id" value="<?= $itemId ?>">
            <button class="button" name="decision" value="approved" type="submit">Approve and deliver</button>
            <button class="button secondary" name="decision" value="rejected" type="submit">Reject and withhold</button>
        </form>
    </section>
<?php endif; ?>

<?php foreach ([
    ['Private messages', 'message', $pendingMessages],
    ['Class announcements', 'announcement', $pendingAnnouncements]
] as [$heading, $itemType, $items]): ?>
    <section class="card">
        <h2><?= e($heading) ?></h2>
        <?php if (!$items): ?><p class="muted">No pending items.</p>
        <?php else: ?><div class="list">
            <?php foreach ($items as $item): ?>
                <article class="list-item">
                    <div><strong><?= e($item['class_name']) ?></strong><p class="muted">From <?= e($item['sender_name']) ?> · <?= e(date('M j, Y g:i A', strtotime($item['created_at']))) ?></p></div>
                    <a class="button secondary" href="moderation.php?institution_id=<?= $institutionId ?>&amp;type=<?= e($itemType) ?>&amp;id=<?= (int) $item['id'] ?>">Review</a>
                </article>
            <?php endforeach; ?>
        </div><?php endif; ?>
    </section>
<?php endforeach; ?>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
