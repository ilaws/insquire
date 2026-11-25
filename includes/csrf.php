<?php

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verifyCsrf(): void
{
    $token = $_POST['csrf_token']
        ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

    if (
        empty($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        http_response_code(419);
        exit('Invalid or expired security token. Refresh the assignment page and try again.');
    }
}
