<?php

require_once dirname(__DIR__) . '/config/config.php';

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

setcookie('insquire_lti_frame', '', [
    'expires' => time() - 42000,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'None'
]);

session_destroy();

header('Location: login.php');
exit;