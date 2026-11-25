<?php

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
requireEducationHost();
if (currentAccountScope() !== 'education' || !in_array(currentUserRole(), ['student', 'teacher'], true)) {
    http_response_code(403);
    exit('Access denied.');
}

$classId = filter_input(INPUT_GET, 'class_id', FILTER_VALIDATE_INT) ?: 0;
if (!$classId) {
    http_response_code(404);
    exit('Class not found.');
}
redirect('class.php?id=' . $classId);
