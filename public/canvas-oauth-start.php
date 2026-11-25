<?php

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/canvas-publishing.php';

requireTeacher();
verifyCsrf();

try {
    $state = bin2hex(random_bytes(32));
    $_SESSION['canvas_oauth_state'] = [
        'value' => $state,
        'expires_at' => time() + 600,
        'include_assignment_read' => ($_POST['include_assignment_read'] ?? '') === '1',
        'return_to_settings' => ($_POST['return_to_settings'] ?? '') === '1'
    ];
    header('Location: ' . canvasOauthStartUrl($state, $_SESSION['canvas_oauth_state']['include_assignment_read']));
    exit;
} catch (Throwable $exception) {
    unset($_SESSION['canvas_oauth_state']);
    http_response_code(500);
    exit('Unable to start Canvas authorization: ' . htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8'));
}
