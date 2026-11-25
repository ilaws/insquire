<?php

require_once __DIR__ . '/_bootstrap.php';
apiRequireMethod('GET');
$user = apiRequireUser(['student', 'teacher']);
$userId = $user['id'];

if ($user['role'] === 'teacher') {
    $stmt = $pdo->prepare(
        'SELECT c.id, c.name, c.join_code,
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
    $stmt->execute([$userId, $userId]);
} else {
    $stmt = $pdo->prepare(
        'SELECT c.id, c.name, t.name AS teacher_name,
                (SELECT COUNT(*) FROM class_members cm_count WHERE cm_count.class_id = c.id) AS student_count
         FROM classes c
         INNER JOIN class_members cm ON cm.class_id = c.id AND cm.user_id = ?
         INNER JOIN users t ON t.id = c.teacher_id
         WHERE c.status = "active"
         ORDER BY c.name'
    );
    $stmt->execute([$userId]);
}

$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($classes as &$class) {
    $class['id'] = (int) $class['id'];
    $class['student_count'] = (int) $class['student_count'];
    $class['link'] = '/class.php?id=' . $class['id'];
}
unset($class);

apiRespond(['classes' => $classes]);
