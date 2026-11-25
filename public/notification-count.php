<?php

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/notifications.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!isLoggedIn()) {
    http_response_code(401);
    exit;
}
requireEducationHost();
if (!in_array(currentUserRole(), ['student', 'teacher'], true)) {
    http_response_code(403);
    exit;
}

echo json_encode(
    ['unread' => unreadNotificationCount($pdo, currentUserId())],
    JSON_THROW_ON_ERROR
);
