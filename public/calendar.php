<?php

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();

$userId = currentUserId();
$role = currentUserRole();
$isEducation = isEducationHost();
if ($isEducation && (currentAccountScope() !== 'education' || !in_array($role, ['student', 'teacher'], true))) {
    http_response_code(403);
    exit('Access denied.');
}

$requestedMonth = (string) ($_GET['month'] ?? date('Y-m'));
$month = DateTimeImmutable::createFromFormat('!Y-m', $requestedMonth);
$dateErrors = DateTimeImmutable::getLastErrors();
if (
    !$month ||
    ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) ||
    $month->format('Y-m') !== $requestedMonth
) {
    http_response_code(400);
    exit('Choose a valid calendar month.');
}

$monthStart = $month->modify('first day of this month')->setTime(0, 0);
$monthEnd = $monthStart->modify('first day of next month');
$events = [];
$canManageEvents = currentAccountScope() === 'personal'
    || ($isEducation && $role === 'student');
$error = '';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = (string) ($_POST['action'] ?? '');

    if (!$canManageEvents) {
        http_response_code(403);
        exit('Only students can add personal events to this calendar.');
    }

    if ($action === 'create_event') {
        $eventTitle = trim((string) ($_POST['title'] ?? ''));
        $eventDetails = trim((string) ($_POST['details'] ?? ''));
        $startsInput = trim((string) ($_POST['starts_at'] ?? ''));
        $startsAt = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $startsInput);
        $dateErrors = DateTimeImmutable::getLastErrors();

        if ($eventTitle === '' || mb_strlen($eventTitle) > 180) {
            $error = 'Enter an event name of 1 to 180 characters.';
        } elseif (mb_strlen($eventDetails) > 5000) {
            $error = 'Event details must be 5,000 characters or fewer.';
        } elseif (
            !$startsAt ||
            ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) ||
            $startsAt->format('Y-m-d\TH:i') !== $startsInput
        ) {
            $error = 'Enter a valid event date and time.';
        } else {
            $eventStmt = $pdo->prepare(
                'INSERT INTO user_calendar_events (user_id, title, details, starts_at)
                 VALUES (?, ?, ?, ?)'
            );
            $eventStmt->execute([
                $userId,
                $eventTitle,
                $eventDetails !== '' ? $eventDetails : null,
                $startsAt->format('Y-m-d H:i:s')
            ]);
            redirect('calendar.php?month=' . rawurlencode($startsAt->format('Y-m')) . '&event_added=1');
        }
    } elseif ($action === 'delete_event') {
        $eventId = filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT) ?: 0;
        if ($eventId < 1) {
            $error = 'Choose a valid calendar event.';
        } else {
            $deleteStmt = $pdo->prepare('DELETE FROM user_calendar_events WHERE id = ? AND user_id = ?');
            $deleteStmt->execute([$eventId, $userId]);
            if ($deleteStmt->rowCount() !== 1) {
                $error = 'That calendar event could not be found.';
            } else {
                redirect('calendar.php?month=' . rawurlencode($month->format('Y-m')) . '&event_deleted=1');
            }
        }
    } else {
        $error = 'Choose a valid calendar action.';
    }
}

if (isset($_GET['event_added'])) {
    $message = 'Event added to your calendar.';
} elseif (isset($_GET['event_deleted'])) {
    $message = 'Calendar event removed.';
}

if (!$isEducation) {
    if (userHasEducationClasses($pdo, $userId)) {
        redirect(EDUCATION_APP_URL . '/calendar.php');
    }
    $stmt = $pdo->prepare(
        'SELECT id, title, due_at, status
         FROM personal_tasks
         WHERE user_id = ? AND due_at >= ? AND due_at < ?
         ORDER BY due_at, id'
    );
    $stmt->execute([$userId, $monthStart->format('Y-m-d H:i:s'), $monthEnd->format('Y-m-d H:i:s')]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $task) {
        $events[] = [
            'date' => substr((string) $task['due_at'], 0, 10),
            'time' => date('g:i A', strtotime((string) $task['due_at'])),
            'title' => (string) $task['title'],
            'detail' => 'Personal task' . ($task['status'] === 'completed' ? ' · Completed' : ''),
            'url' => 'personal-planner.php'
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
             ORDER BY a.due_at, a.id'
        );
        $stmt->execute([$userId, $monthStart->format('Y-m-d H:i:s'), $monthEnd->format('Y-m-d H:i:s')]);
    } elseif ($role === 'teacher') {
        $stmt = $pdo->prepare(
            'SELECT a.id, a.title, a.due_at, c.name AS class_name
             FROM assignments a
             INNER JOIN classes c ON c.id = a.class_id
             WHERE c.teacher_id = ? AND a.due_at >= ? AND a.due_at < ?
             ORDER BY a.due_at, a.id'
        );
        $stmt->execute([$userId, $monthStart->format('Y-m-d H:i:s'), $monthEnd->format('Y-m-d H:i:s')]);
    }

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $assignment) {
        $events[] = [
            'date' => substr((string) $assignment['due_at'], 0, 10),
            'time' => date('g:i A', strtotime((string) $assignment['due_at'])),
            'title' => (string) $assignment['title'],
            'detail' => (string) $assignment['class_name'],
            'url' => $role === 'student'
                ? 'assignment.php?id=' . (int) $assignment['id']
                : 'teacher-assignment.php?id=' . (int) $assignment['id']
        ];
    }
}

if ($canManageEvents) {
    $eventStmt = $pdo->prepare(
        'SELECT id, title, details, starts_at
         FROM user_calendar_events
         WHERE user_id = ? AND starts_at >= ? AND starts_at < ?
         ORDER BY starts_at, id'
    );
    $eventStmt->execute([
        $userId,
        $monthStart->format('Y-m-d H:i:s'),
        $monthEnd->format('Y-m-d H:i:s')
    ]);
    foreach ($eventStmt->fetchAll(PDO::FETCH_ASSOC) as $calendarEvent) {
        $events[] = [
            'date' => substr((string) $calendarEvent['starts_at'], 0, 10),
            'time' => date('g:i A', strtotime((string) $calendarEvent['starts_at'])),
            'title' => (string) $calendarEvent['title'],
            'detail' => 'My event',
            'details' => (string) ($calendarEvent['details'] ?? ''),
            'url' => '',
            'event_id' => (int) $calendarEvent['id']
        ];
    }
}

$eventsByDate = [];
foreach ($events as $event) {
    $eventsByDate[$event['date']][] = $event;
}

$gridStart = $monthStart->modify('monday this week');
$gridEnd = $monthEnd->modify('sunday this week');
$today = date('Y-m-d');
$previousMonth = $monthStart->modify('-1 month')->format('Y-m');
$nextMonth = $monthStart->modify('+1 month')->format('Y-m');
$title = 'Calendar';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="page-header calendar-heading">
    <div>
        <p class="eyebrow">Your schedule</p>
        <h1><?= e($monthStart->format('F Y')) ?></h1>
        <p><?= $isEducation ? 'Assignment due dates and your personal events.' : 'Due dates from your work plan and personal events.' ?></p>
    </div>
    <nav class="calendar-navigation" aria-label="Calendar navigation">
        <a class="button secondary" href="calendar.php?month=<?= e($previousMonth) ?>" aria-label="Previous month">&larr; Previous</a>
        <a class="button secondary" href="calendar.php?month=<?= e(date('Y-m')) ?>">Today</a>
        <a class="button secondary" href="calendar.php?month=<?= e($nextMonth) ?>" aria-label="Next month">Next &rarr;</a>
        <a class="button" href="calendar-ics.php?month=<?= e($monthStart->format('Y-m')) ?>">Download this month (.ics)</a>
    </nav>
</div>

<?php if ($canManageEvents): ?>
    <?php if ($error !== ''): ?><div class="error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <?php if ($message !== ''): ?><div class="success" role="status"><?= e($message) ?></div><?php endif; ?>
    <section class="card calendar-event-editor">
        <h2>Add something to your calendar</h2>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="create_event">
            <input type="hidden" name="month" value="<?= e($monthStart->format('Y-m')) ?>">
            <label for="calendar-event-title">Event name</label>
            <input id="calendar-event-title" name="title" maxlength="180" required>
            <label for="calendar-event-start">Date and time</label>
            <input id="calendar-event-start" type="datetime-local" name="starts_at" value="<?= e(date('Y-m-d\TH:i')) ?>" required>
            <label for="calendar-event-details">Notes (optional)</label>
            <textarea id="calendar-event-details" name="details" rows="3" maxlength="5000"></textarea>
            <button class="button" type="submit">Add event</button>
        </form>
    </section>
<?php elseif ($error !== ''): ?>
    <div class="error" role="alert"><?= e($error) ?></div>
<?php endif; ?>

<section class="card calendar-card" aria-label="<?= e($monthStart->format('F Y')) ?> calendar">
    <div class="calendar-grid calendar-weekdays" aria-hidden="true">
        <?php foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $weekday): ?>
            <div><?= e($weekday) ?></div>
        <?php endforeach; ?>
    </div>
    <div class="calendar-grid">
        <?php for ($day = $gridStart; $day <= $gridEnd; $day = $day->modify('+1 day')): ?>
            <?php
            $dateKey = $day->format('Y-m-d');
            $isCurrentMonth = $day->format('Y-m') === $monthStart->format('Y-m');
            ?>
            <section class="calendar-day<?= $isCurrentMonth ? '' : ' calendar-day-outside' ?><?= $dateKey === $today ? ' calendar-day-today' : '' ?>" aria-label="<?= e($day->format('l, F j')) ?>">
                <h2><?= e($day->format('j')) ?></h2>
                <?php foreach ($eventsByDate[$dateKey] ?? [] as $event): ?>
                    <?php if (!empty($event['event_id'])): ?>
                        <div class="calendar-event calendar-personal-event">
                            <strong><?= e($event['title']) ?></strong>
                            <span><?= e($event['time']) ?> · <?= e($event['detail']) ?></span>
                            <?php if (!empty($event['details'])): ?><span><?= e($event['details']) ?></span><?php endif; ?>
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                <input type="hidden" name="action" value="delete_event">
                                <input type="hidden" name="event_id" value="<?= (int) $event['event_id'] ?>">
                                <input type="hidden" name="month" value="<?= e($monthStart->format('Y-m')) ?>">
                                <button type="submit" class="calendar-event-delete">Remove</button>
                            </form>
                        </div>
                    <?php else: ?>
                        <a class="calendar-event" href="<?= e($event['url']) ?>">
                            <strong><?= e($event['title']) ?></strong>
                            <span><?= e($event['time']) ?> · <?= e($event['detail']) ?></span>
                        </a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </section>
        <?php endfor; ?>
    </div>
</section>

<?php if (!$events): ?>
    <p class="muted">No events this month yet.</p>
<?php endif; ?>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
