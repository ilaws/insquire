<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requirePersonalAccount();
$userId = currentUserId();

$stmt = $pdo->prepare(
    'SELECT id, title, details, due_at, status
     FROM personal_tasks
     WHERE user_id = ? AND due_at IS NOT NULL
     ORDER BY due_at, id'
);
$stmt->execute([$userId]);
$tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
$eventStmt = $pdo->prepare(
    'SELECT id, title, details, starts_at
     FROM user_calendar_events
     WHERE user_id = ?
     ORDER BY starts_at, id'
);
$eventStmt->execute([$userId]);
$calendarEvents = $eventStmt->fetchAll(PDO::FETCH_ASSOC);

$escapeIcs = static function (string $value): string {
    $value = str_replace(["\\", "\r\n", "\r", "\n"], ["\\\\", "\\n", "\\n", "\\n"], $value);
    return str_replace([',', ';'], ['\\,', '\\;'], $value);
};
$lines = [
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    'PRODID:-//InsQuire//Personal Work Plan//EN',
    'CALSCALE:GREGORIAN',
    'METHOD:PUBLISH'
];
$now = gmdate('Ymd\THis\Z');
foreach ($tasks as $task) {
    $due = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $task['due_at']);
    if (!$due) {
        continue;
    }
    $start = $due->format('Ymd\THis');
    $end = $due->modify('+30 minutes')->format('Ymd\THis');
    $lines[] = 'BEGIN:VEVENT';
    $lines[] = 'UID:personal-task-' . (int) $task['id'] . '@insquire.org';
    $lines[] = 'DTSTAMP:' . $now;
    $lines[] = 'DTSTART:' . $start;
    $lines[] = 'DTEND:' . $end;
    $lines[] = 'SUMMARY:' . $escapeIcs('Due: ' . (string) $task['title']);
    if (!empty($task['details'])) {
        $lines[] = 'DESCRIPTION:' . $escapeIcs((string) $task['details']);
    }
    if ($task['status'] === 'completed') {
        $lines[] = 'STATUS:COMPLETED';
        $lines[] = 'PERCENT-COMPLETE:100';
    } else {
        $lines[] = 'STATUS:CONFIRMED';
    }
    $lines[] = 'END:VEVENT';
}
foreach ($calendarEvents as $event) {
    $startsAt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $event['starts_at']);
    if (!$startsAt) {
        continue;
    }
    $lines[] = 'BEGIN:VEVENT';
    $lines[] = 'UID:user-event-' . (int) $event['id'] . '@insquire.org';
    $lines[] = 'DTSTAMP:' . $now;
    $lines[] = 'DTSTART:' . $startsAt->format('Ymd\THis');
    $lines[] = 'DTEND:' . $startsAt->modify('+30 minutes')->format('Ymd\THis');
    $lines[] = 'SUMMARY:' . $escapeIcs((string) $event['title']);
    if (!empty($event['details'])) {
        $lines[] = 'DESCRIPTION:' . $escapeIcs((string) $event['details']);
    }
    $lines[] = 'STATUS:CONFIRMED';
    $lines[] = 'END:VEVENT';
}
$lines[] = 'END:VCALENDAR';

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="insquire-work-plan.ics"');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
echo implode("\r\n", $lines) . "\r\n";
