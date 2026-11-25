<?php

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$token = trim((string) ($_GET['token'] ?? ''));

if ($token === '') {
    http_response_code(400);
    echo '<h1>Verification link missing</h1><p>The email verification link is invalid or expired.</p>';
    exit;
}

$stmt = $pdo->prepare(
    'SELECT id, email, name
     FROM users
     WHERE email_verification_token = ?
       AND email_verification_expires_at > NOW()
     LIMIT 1'
);
$stmt->execute([$token]);
$user = $stmt->fetch();

if (!$user) {
    http_response_code(410);
    echo '<h1>Verification failed</h1><p>This link is invalid or has expired. Please request a new verification email.</p>';
    exit;
}

$update = $pdo->prepare(
    'UPDATE users
     SET email_verified_at = NOW(),
         email_verification_token = NULL,
         email_verification_expires_at = NULL,
         status = "active"
     WHERE id = ?'
);
$update->execute([(int) $user['id']]);

header('Location: login.php?verified=1');
exit;
