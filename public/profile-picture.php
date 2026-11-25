<?php

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
$targetUserId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
if ($targetUserId < 1) {
    http_response_code(404);
    exit;
}

$stmt = $pdo->prepare('SELECT id, role, profile_image FROM users WHERE id = ? AND status = "active" LIMIT 1');
$stmt->execute([$targetUserId]);
$profile = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$profile || empty($profile['profile_image'])) {
    http_response_code(404);
    exit;
}

$viewerId = currentUserId();
$canView = $viewerId === $targetUserId;
if (!$canView && isEducationHost() && in_array(currentUserRole(), ['student', 'teacher'], true)) {
    $accessStmt = $pdo->prepare(
        'SELECT 1
         FROM classes c
         WHERE c.status = "active"
           AND (
               (c.teacher_id = ? AND EXISTS (
                   SELECT 1 FROM class_members cm
                   WHERE cm.class_id = c.id AND cm.user_id = ?
               ))
               OR (c.teacher_id = ? AND EXISTS (
                   SELECT 1 FROM class_members cm
                   WHERE cm.class_id = c.id AND cm.user_id = ?
               ))
           )
         LIMIT 1'
    );
    $accessStmt->execute([$viewerId, $targetUserId, $targetUserId, $viewerId]);
    $canView = (bool) $accessStmt->fetchColumn();
}

if (!$canView) {
    http_response_code(404);
    exit;
}

$filename = (string) $profile['profile_image'];
if (!preg_match('/\A[a-f0-9]{32}\.(jpg|png|webp)\z/', $filename)) {
    http_response_code(404);
    exit;
}

$mimeTypes = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
$extension = pathinfo($filename, PATHINFO_EXTENSION);
$imagePath = dirname(__DIR__) . '/storage/profile-pictures/' . $filename;
if (!is_file($imagePath) || !is_readable($imagePath)) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $mimeTypes[$extension]);
header('Content-Length: ' . (string) filesize($imagePath));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
readfile($imagePath);
