<?php

require_once dirname(__DIR__) . '/_bootstrap.php';
apiRequireMethod('GET');
$user = apiRequireUser(['teacher']);
$teacherId = (int) $user['id'];
$classId = isset($_GET['class_id'])
    ? filter_var($_GET['class_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : null;
if ($classId === false) {
    apiRespond(['error' => 'Select a valid class.'], 422);
}

$classesSql = 'SELECT c.id, c.name, c.description, c.grade_level, c.join_code, c.status,
                      (SELECT COUNT(*) FROM class_members cm WHERE cm.class_id = c.id) AS student_count
               FROM classes c
               WHERE c.teacher_id = ? AND c.status = "active"
                 AND (c.institution_id IS NULL OR EXISTS (
                     SELECT 1 FROM institution_members im
                     WHERE im.institution_id = c.institution_id AND im.user_id = ?
                       AND im.role IN ("admin", "teacher")
                 ))';
$classParams = [$teacherId, $teacherId];
if ($classId !== null) {
    $classesSql .= ' AND c.id = ?';
    $classParams[] = $classId;
}
$classesSql .= ' ORDER BY c.name';
$classStmt = $pdo->prepare($classesSql);
$classStmt->execute($classParams);
$classes = $classStmt->fetchAll(PDO::FETCH_ASSOC);
if ($classId !== null && $classes === []) {
    apiRespond(['error' => 'Class not found or unavailable.'], 404);
}
foreach ($classes as &$class) {
    $class['id'] = (int) $class['id'];
    $class['student_count'] = (int) $class['student_count'];
}
unset($class);

if ($classId === null) {
    apiRespond(['classes' => $classes]);
}

$studentStmt = $pdo->prepare(
    'SELECT u.id, u.name, u.email, cm.joined_at
     FROM class_members cm
     INNER JOIN users u ON u.id = cm.user_id AND u.role = "student"
     WHERE cm.class_id = ?
     ORDER BY u.name, u.id'
);
$studentStmt->execute([$classId]);
$students = $studentStmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($students as &$student) {
    $student['id'] = (int) $student['id'];
}
unset($student);

apiRespond(['class' => $classes[0], 'students' => $students]);
