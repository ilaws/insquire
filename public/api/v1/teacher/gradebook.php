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

$latePenaltyColumn = hasTableColumn($pdo, 'assignments', 'late_penalty_percent')
    ? 'a.late_penalty_percent'
    : '0 AS late_penalty_percent';
$gradeColumn = hasTableColumn($pdo, 'submissions', 'grade')
    ? 's.grade'
    : 'NULL AS grade';
$sql = 'SELECT c.id AS class_id, c.name AS class_name,
               a.id AS assignment_id, a.title AS assignment_title, a.due_at,
               ' . $latePenaltyColumn . ',
               u.id AS student_id, u.name AS student_name,
               s.id AS submission_id, s.status, ' . $gradeColumn . ', s.submitted_at
        FROM assignments a
        INNER JOIN classes c ON c.id = a.class_id
        INNER JOIN class_members cm ON cm.class_id = c.id
        INNER JOIN users u ON u.id = cm.user_id AND u.role = "student"
        LEFT JOIN submissions s ON s.assignment_id = a.id AND s.student_id = u.id
        WHERE c.teacher_id = ?
          AND (c.institution_id IS NULL OR EXISTS (
              SELECT 1 FROM institution_members im
              WHERE im.institution_id = c.institution_id AND im.user_id = ?
                AND im.role IN ("admin", "teacher")
          ))';
$params = [$teacherId, $teacherId];
if ($classId !== null) {
    $sql .= ' AND c.id = ?';
    $params[] = $classId;
}
$sql .= ' ORDER BY c.name, a.due_at IS NULL, a.due_at, u.name, u.id';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$now = time();
foreach ($rows as &$row) {
    foreach (['class_id', 'assignment_id', 'student_id'] as $field) {
        $row[$field] = (int) $row[$field];
    }
    $row['submission_id'] = $row['submission_id'] !== null ? (int) $row['submission_id'] : null;
    $late = !empty($row['submitted_at']) && !empty($row['due_at'])
        && strtotime((string) $row['submitted_at']) > strtotime((string) $row['due_at']);
    $row['late'] = $late;
    $row['display_status'] = (string) ($row['status'] ?? '');
    if ($row['display_status'] === '') {
        $row['display_status'] = !empty($row['due_at']) && strtotime((string) $row['due_at']) < $now
            ? 'missing'
            : 'not_started';
    }
    if ($late && is_numeric($row['grade']) && (float) $row['late_penalty_percent'] > 0) {
        $row['grade'] = number_format(
            (float) $row['grade'] * (1 - ((float) $row['late_penalty_percent'] / 100)),
            2,
            '.',
            ''
        );
        $row['penalty_applied'] = true;
    } else {
        $row['penalty_applied'] = false;
    }
}
unset($row);

apiRespond(['rows' => $rows]);
