<?php

require_once __DIR__ . '/_bootstrap.php';
apiRequireMethod('GET');
$user = apiRequireUser(['personal', 'student', 'teacher', 'admin']);
$userId = $user['id'];
$classes = [];
$tasks = [];
$pendingReviews = 0;

if ($user['account_scope'] === 'personal') {
    $stmt = $pdo->prepare(
        'SELECT id, title, details, due_at
         FROM personal_tasks
         WHERE user_id = ? AND status <> "completed"
         ORDER BY CASE WHEN due_at IS NULL THEN 1 ELSE 0 END, due_at ASC, id DESC
         LIMIT 8'
    );
    $stmt->execute([$userId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $task) {
        $tasks[] = [
            'id' => (int) $task['id'],
            'title' => (string) $task['title'],
            'due_at' => $task['due_at'],
            'detail' => (string) ($task['details'] ?? ''),
            'link' => '/personal-planner.php'
        ];
    }
} elseif ($user['role'] === 'student') {
    $classStmt = $pdo->prepare(
        'SELECT c.id, c.name, u.name AS teacher_name,
                (SELECT COUNT(*) FROM class_members cm_count WHERE cm_count.class_id = c.id) AS student_count
         FROM classes c
         INNER JOIN class_members cm ON cm.class_id = c.id AND cm.user_id = ?
         INNER JOIN users u ON u.id = c.teacher_id
         WHERE c.status = "active"
         ORDER BY c.name
         LIMIT 8'
    );
    $classStmt->execute([$userId]);
    $classes = $classStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($classes as &$class) {
        $class['id'] = (int) $class['id'];
        $class['student_count'] = (int) $class['student_count'];
        $class['link'] = '/class.php?id=' . $class['id'];
    }
    unset($class);

    $taskStmt = $pdo->prepare(
        'SELECT a.id, a.title, a.due_at, c.name AS class_name
         FROM assignments a
         INNER JOIN classes c ON c.id = a.class_id
         INNER JOIN class_members cm ON cm.class_id = c.id AND cm.user_id = ?
         LEFT JOIN submissions s ON s.assignment_id = a.id AND s.student_id = ?
         WHERE a.status = "published"
           AND (s.status IS NULL OR s.status IN ("draft", "returned"))
         ORDER BY CASE WHEN a.due_at IS NULL THEN 1 ELSE 0 END, a.due_at, a.id DESC
         LIMIT 8'
    );
    $taskStmt->execute([$userId, $userId]);
    foreach ($taskStmt->fetchAll(PDO::FETCH_ASSOC) as $task) {
        $tasks[] = [
            'id' => (int) $task['id'],
            'title' => (string) $task['title'],
            'class_name' => (string) $task['class_name'],
            'due_at' => $task['due_at'],
            'link' => '/assignment.php?id=' . (int) $task['id']
        ];
    }
} elseif ($user['role'] === 'teacher') {
    $classStmt = $pdo->prepare(
        'SELECT c.id, c.name, c.join_code,
                (SELECT COUNT(*) FROM class_members cm WHERE cm.class_id = c.id) AS student_count
         FROM classes c
         WHERE c.teacher_id = ? AND c.status = "active"
         ORDER BY c.name
         LIMIT 8'
    );
    $classStmt->execute([$userId]);
    $classes = $classStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($classes as &$class) {
        $class['id'] = (int) $class['id'];
        $class['student_count'] = (int) $class['student_count'];
        $class['link'] = '/class.php?id=' . $class['id'];
    }
    unset($class);

    $countStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM submissions s
         INNER JOIN assignments a ON a.id = s.assignment_id
         INNER JOIN classes c ON c.id = a.class_id
         WHERE c.teacher_id = ? AND s.status = "submitted"'
    );
    $countStmt->execute([$userId]);
    $pendingReviews = (int) $countStmt->fetchColumn();

    $taskStmt = $pdo->prepare(
        'SELECT s.id, u.name AS student_name, a.title, c.name AS class_name, s.submitted_at
         FROM submissions s
         INNER JOIN users u ON u.id = s.student_id
         INNER JOIN assignments a ON a.id = s.assignment_id
         INNER JOIN classes c ON c.id = a.class_id
         WHERE c.teacher_id = ? AND s.status = "submitted"
         ORDER BY s.submitted_at ASC
         LIMIT 8'
    );
    $taskStmt->execute([$userId]);
    foreach ($taskStmt->fetchAll(PDO::FETCH_ASSOC) as $task) {
        $tasks[] = [
            'id' => (int) $task['id'],
            'title' => (string) $task['title'],
            'class_name' => (string) $task['class_name'] . ' · ' . (string) $task['student_name'],
            'due_at' => $task['submitted_at'],
            'link' => '/teacher-submission.php?id=' . (int) $task['id']
        ];
    }
}

apiRespond([
    'user' => $user,
    'summary' => [
        'classes' => count($classes),
        'pending_reviews' => $pendingReviews,
        'upcoming_assignments' => count($tasks)
    ],
    'classes' => $classes,
    'tasks' => $tasks
]);
