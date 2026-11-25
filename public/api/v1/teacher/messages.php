<?php

require_once dirname(__DIR__) . '/_bootstrap.php';
require_once dirname(__DIR__, 4) . '/includes/notifications.php';

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? '');
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    apiRespond(['error' => 'Method not allowed.'], 405);
}
apiRequireMethod($method);
$user = apiRequireUser(['teacher']);
$teacherId = (int) $user['id'];

$classesStmt = $pdo->prepare(
    'SELECT c.id, c.name, c.description, c.grade_level, c.status, c.join_code, c.institution_id,
            (SELECT COUNT(*) FROM class_members cm WHERE cm.class_id = c.id) AS student_count
     FROM classes c
     WHERE c.teacher_id = ? AND c.status = "active"
       AND (c.institution_id IS NULL OR EXISTS (
           SELECT 1 FROM institution_members im
           WHERE im.institution_id = c.institution_id AND im.user_id = ?
             AND im.role IN ("admin", "teacher")
       ))
     ORDER BY c.name'
);
$classesStmt->execute([$teacherId, $teacherId]);
$classes = $classesStmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($classes as &$class) {
    $class['id'] = (int) $class['id'];
    $class['student_count'] = (int) $class['student_count'];
}
unset($class);

if ($method === 'GET') {
    $classId = isset($_GET['class_id'])
        ? filter_var($_GET['class_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
        : null;
    $recipientId = isset($_GET['with'])
        ? filter_var($_GET['with'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
        : null;
    if ($classId === false || $recipientId === false) {
        apiRespond(['error' => 'Choose a valid class and student.'], 422);
    }
    $response = ['classes' => $classes, 'students' => [], 'announcements' => [], 'messages' => []];
    if ($classId !== null) {
        $availableClass = null;
        foreach ($classes as $class) {
            if ($class['id'] === $classId) {
                $availableClass = $class;
                break;
            }
        }
        if ($availableClass === null) {
            apiRespond(['error' => 'Class not found or unavailable.'], 404);
        }

        $studentStmt = $pdo->prepare(
            'SELECT u.id, u.name, u.email, cm.joined_at
             FROM class_members cm
             INNER JOIN users u ON u.id = cm.user_id AND u.role = "student" AND u.status = "active"
             WHERE cm.class_id = ?
             ORDER BY u.name, u.id'
        );
        $studentStmt->execute([$classId]);
        $students = $studentStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($students as &$student) {
            $student['id'] = (int) $student['id'];
        }
        unset($student);
        $response['students'] = $students;

        $announcementStmt = $pdo->prepare(
            'SELECT a.id, a.title, a.body, a.created_at, a.moderation_status,
                    u.name AS author_name
             FROM class_announcements a
             INNER JOIN users u ON u.id = a.author_id
             WHERE a.class_id = ?
               AND (a.moderation_status = "approved" OR a.author_id = ?)
             ORDER BY a.created_at DESC, a.id DESC
             LIMIT 50'
        );
        $announcementStmt->execute([$classId, $teacherId]);
        $announcements = $announcementStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($announcements as &$announcement) {
            $announcement['id'] = (int) $announcement['id'];
        }
        unset($announcement);
        $response['announcements'] = $announcements;

        if ($recipientId !== null) {
            $recipientCheck = $pdo->prepare(
                'SELECT 1
                 FROM class_members cm
                 INNER JOIN users u ON u.id = cm.user_id
                 WHERE cm.class_id = ? AND cm.user_id = ?
                   AND u.role = "student" AND u.status = "active"
                 LIMIT 1'
            );
            $recipientCheck->execute([$classId, $recipientId]);
            if (!$recipientCheck->fetchColumn()) {
                apiRespond(['error' => 'Choose a student in this class.'], 404);
            }
            $messagesStmt = $pdo->prepare(
                'SELECT m.id, m.sender_id, m.recipient_id, m.body, m.created_at,
                        m.moderation_status, u.name AS sender_name
                 FROM class_messages m
                 INNER JOIN users u ON u.id = m.sender_id
                 WHERE m.class_id = ?
                   AND ((m.sender_id = ? AND m.recipient_id = ?)
                     OR (m.sender_id = ? AND m.recipient_id = ?))
                   AND (m.moderation_status = "approved" OR m.sender_id = ?)
                 ORDER BY m.created_at, m.id
                 LIMIT 200'
            );
            $messagesStmt->execute([$classId, $teacherId, $recipientId, $recipientId, $teacherId, $teacherId]);
            $messages = $messagesStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($messages as &$message) {
                $message['id'] = (int) $message['id'];
                $message['sender_id'] = (int) $message['sender_id'];
                $message['recipient_id'] = (int) $message['recipient_id'];
            }
            unset($message);
            $response['messages'] = $messages;
        }
    }
    apiRespond($response);
}

apiVerifyCsrf();
$input = apiReadJson();
$action = (string) ($input['action'] ?? '');
$classId = filter_var($input['class_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($classId === false) {
    apiRespond(['error' => 'Choose a valid class.'], 422);
}
$selectedClass = null;
foreach ($classes as $class) {
    if ($class['id'] === $classId) {
        $selectedClass = $class;
        break;
    }
}
if ($selectedClass === null) {
    apiRespond(['error' => 'Class not found or unavailable.'], 404);
}

$body = trim((string) ($input['body'] ?? ''));
if ($action === 'message') {
    $recipientId = filter_var($input['recipient_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($recipientId === false) {
        apiRespond(['error' => 'Choose a student in this class.'], 422);
    }
    $recipientCheck = $pdo->prepare(
        'SELECT 1 FROM class_members cm
         INNER JOIN users u ON u.id = cm.user_id
         WHERE cm.class_id = ? AND cm.user_id = ?
           AND u.role = "student" AND u.status = "active"
         LIMIT 1'
    );
    $recipientCheck->execute([$classId, $recipientId]);
    if (!$recipientCheck->fetchColumn()) {
        apiRespond(['error' => 'Choose a student in this class.'], 422);
    }
    if ($body === '' || mb_strlen($body) > 5000) {
        apiRespond(['error' => 'Enter a message of 1 to 5000 characters.'], 422);
    }

    $flagged = messageFlagMatches($body);
    if ($flagged && empty($selectedClass['institution_id'])) {
        apiRespond(['error' => 'This message contains wording blocked by the class filter. Rephrase it and try again.'], 422);
    }
    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare(
            'INSERT INTO class_messages
             (class_id, sender_id, recipient_id, body, moderation_status, flagged_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([
            $classId,
            $teacherId,
            $recipientId,
            $body,
            $flagged ? 'pending' : 'approved',
            $flagged ? gmdate('Y-m-d H:i:s') : null
        ]);
        $messageId = (int) $pdo->lastInsertId();
        if (!$flagged) {
            createMessageNotification($pdo, $classId, $messageId, $recipientId);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
    apiRespond(['success' => true, 'held_for_review' => $flagged]);
}

if ($action === 'announcement') {
    $title = trim((string) ($input['title'] ?? ''));
    if ($title === '' || mb_strlen($title) > 180) {
        apiRespond(['error' => 'Enter an announcement title of 1 to 180 characters.'], 422);
    }
    if ($body === '' || mb_strlen($body) > 5000) {
        apiRespond(['error' => 'Enter an announcement of 1 to 5000 characters.'], 422);
    }
    $flagged = messageFlagMatches($title . "\n" . $body);
    if ($flagged && empty($selectedClass['institution_id'])) {
        apiRespond(['error' => 'This announcement contains wording blocked by the class filter. Rephrase it and try again.'], 422);
    }
    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare(
            'INSERT INTO class_announcements
             (class_id, author_id, title, body, moderation_status, flagged_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([
            $classId,
            $teacherId,
            $title,
            $body,
            $flagged ? 'pending' : 'approved',
            $flagged ? gmdate('Y-m-d H:i:s') : null
        ]);
        if (!$flagged) {
            createAnnouncementNotifications($pdo, $classId, (int) $pdo->lastInsertId());
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
    apiRespond(['success' => true, 'held_for_review' => $flagged]);
}

apiRespond(['error' => 'Choose a valid messaging action.'], 422);
