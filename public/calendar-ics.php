<?php

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();

$monthValue = (string) ($_GET['month'] ?? date('Y-m'));
$month = DateTimeImmutable::createFromFormat('!Y-m', $monthValue);
$dateErrors = DateTimeImmutable::getLastErrors();
if (
    !$month ||
    ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) ||
    $month->format('Y-m') !== $monthValue
) {
    http_response_code(400);
    exit('Choose a valid calendar month.');
}

$monthStart = $month->modify('first day of this month')->setTime(0, 0);
$monthEnd = $monthStart->modify('first day of next month');
$userId = currentUserId();
$role = currentUserRole();
$isEducation = isEducationHost();
if ($isEducation && (currentAccountScope() !== 'education' || !in_array($role, ['student', 'teacher'], true))) {
    http_response_code(403);
    exit('Access denied.');
}
$events = [];
$canManageEvents = currentAccountScope() === 'personal'
    || ($isEducation && $role === 'student');

if (!$isEducation) {
    if (userHasEducationClasses($pdo, $userId)) {
        redirect(EDUCATION_APP_URL . '/calendar-ics.php?month=' . rawurlencode($monthValue));
    }
    $stmt = $pdo->prepare(
        'SELECT id, title, details, due_at, status
         FROM personal_tasks
         WHERE user_id = ? AND due_at >= ? AND due_at < ?
         ORDER BY due_at'
    );
    $stmt->execute([$userId, $monthStart->format('Y-m-d H:i:s'), $monthEnd->format('Y-m-d H:i:s')]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $task) {
        $events[] = [
            'id' => 'personal-' . (int) $task['id'],
            'title' => 'Due: ' . (string) $task['title'],
            'description' => (string) ($task['details'] ?? ''),
            'due_at' => (string) $task['due_at'],
            'status' => (string) $task['status']
        ];
    }
} else {
    if ($role === 'student') {
        $stmt = $pdo->prepare(
            'SELECT a.id, a.title, a.due_at, c.name AS class_name
             FROM assignments a
             INNER JOIN class_members cm ON cm.class_id = a.class_id AND cm.user_id = ?
             INNER JOIN classes c ON c.id = a.class_id
             WHERE a.status = "published" AND a.due_at >= ? AND a.due_at < ?
             ORDER BY a.due_at'
        );
        $stmt->execute([$userId, $monthStart->format('Y-m-d H:i:s'), $monthEnd->format('Y-m-d H:i:s')]);
    } elseif ($role === 'teacher') {
        $stmt = $pdo->prepare(
            'SELECT a.id, a.title, a.due_at, c.name AS class_name
             FROM assignments a
             INNER JOIN classes c ON c.id = a.class_id
             WHERE c.teacher_id = ? AND a.due_at >= ? AND a.due_at < ?
             ORDER BY a.due_at'
        );
        $stmt->execute([$userId, $monthStart->format('Y-m-d H:i:s'), $monthEnd->format('Y-m-d H:i:s')]);
    }

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $assignment) {
        $events[] = [
            'id' => 'assignment-' . (int) $assignment['id'],
            'title' => (string) $assignment['title'],
            'description' => (string) $assignment['class_name'],
            'due_at' => (string) $assignment['due_at'],
            'status' => 'planned'
        ];
    }
}

if ($canManageEvents) {
    $stmt = $pdo->prepare(
        'SELECT id, title, details, starts_at
         FROM user_calendar_events
         WHERE user_id = ? AND starts_at >= ? AND starts_at < ?
         ORDER BY starts_at, id'
    );
    $stmt->execute([
        $userId,
        $monthStart->format('Y-m-d H:i:s'),
        $monthEnd->format('Y-m-d H:i:s')
    ]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $event) {
        $events[] = [
            'id' => 'user-event-' . (int) $event['id'],
            'title' => (string) $event['title'],
            'description' => (string) ($event['details'] ?? ''),
            'due_at' => (string) $event['starts_at'],
            'status' => 'planned'
        ];
    }
}

$escapeIcs = static function (string $value): string {
    $value = str_replace(["\\", "\r\n", "\r", "\n"], ["\\\\", "\\n", "\\n", "\\n"], $value);
    return str_replace([',', ';'], ['\\,', '\\;'], $value);
};
$now = gmdate('Ymd\THis\Z');
$lines = [
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    'PRODID:-//InsQuire//Calendar//EN',
    'CALSCALE:GREGORIAN',
    'METHOD:PUBLISH'
];

foreach ($events as $event) {
    $due = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $event['due_at']);
    if (!$due) {
        continue;
    }
    $lines[] = 'BEGIN:VEVENT';
    $lines[] = 'UID:' . $event['id'] . '@insquire.org';
    $lines[] = 'DTSTAMP:' . $now;
    $lines[] = 'DTSTART:' . $due->format('Ymd\THis');
    $lines[] = 'DTEND:' . $due->modify('+30 minutes')->format('Ymd\THis');
    $lines[] = 'SUMMARY:' . $escapeIcs($event['title']);
    if ($event['description'] !== '') {
        $lines[] = 'DESCRIPTION:' . $escapeIcs($event['description']);
    }
    if ($event['status'] === 'completed') {
        $lines[] = 'STATUS:COMPLETED';
        $lines[] = 'PERCENT-COMPLETE:100';
    } else {
        $lines[] = 'STATUS:CONFIRMED';
    }
    $lines[] = 'END:VEVENT';
}

$lines[] = 'END:VCALENDAR';
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="insquire-calendar-' . $monthValue . '.ics"');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
echo implode("\r\n", $lines) . "\r\n";
