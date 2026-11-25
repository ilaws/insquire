<?php

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/lti.php';

header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function ltiInput(string $name): string
{
    $value = $_POST[$name] ?? $_GET[$name] ?? '';
    return is_string($value) ? trim($value) : '';
}

try {
    if (!ltiSettingsReady()) {
        http_response_code(503);
        exit('Canvas sign-in is not configured yet.');
    }

    $issuer = rtrim(ltiInput('iss'), '/');
    $clientId = ltiInput('client_id');
    $loginHint = ltiInput('login_hint');
    $targetLinkUri = ltiInput('target_link_uri');
    $messageHint = ltiInput('lti_message_hint');
    $deploymentId = ltiInput('lti_deployment_id');
    $allowedTargets = [
        EDUCATION_APP_URL . '/lti-launch.php',
        EDUCATION_APP_URL . '/lti-launch.php/'
    ];
    if (
        $issuer !== LTI_ISSUER
        || $clientId !== LTI_CLIENT_ID
        || $loginHint === ''
        || !in_array($targetLinkUri, $allowedTargets, true)
        || strlen($deploymentId) > 255
    ) {
        http_response_code(400);
        exit('Invalid Canvas sign-in request.');
    }

    $state = bin2hex(random_bytes(32));
    $nonce = bin2hex(random_bytes(32));
    $pdo->exec('DELETE FROM lti_auth_requests WHERE created_at < (NOW() - INTERVAL 10 MINUTE)');
    $saveRequest = $pdo->prepare(
        'INSERT INTO lti_auth_requests (state_hash, nonce, session_hash, issuer, target_link_uri)
         VALUES (?, ?, ?, ?, ?)'
    );
    $saveRequest->execute([
        hash('sha256', $state),
        $nonce,
        hash('sha256', session_id()),
        $issuer,
        $targetLinkUri
    ]);
    setcookie('insquire_lti_frame', '1', [
        'expires' => 0,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'None'
    ]);

    $authorizationParameters = [
        'scope' => 'openid',
        'response_type' => 'id_token',
        'response_mode' => 'form_post',
        'client_id' => LTI_CLIENT_ID,
        'redirect_uri' => $targetLinkUri,
        'login_hint' => $loginHint,
        'state' => $state,
        'nonce' => $nonce,
        'prompt' => 'none'
    ];
    if ($messageHint !== '') {
        $authorizationParameters['lti_message_hint'] = $messageHint;
    }
    if ($deploymentId !== '') {
        $authorizationParameters['lti_deployment_id'] = $deploymentId;
    }

    header('Location: ' . LTI_LOGIN_URL . '?' . http_build_query($authorizationParameters, '', '&', PHP_QUERY_RFC3986), true, 302);
    exit;
} catch (Throwable $exception) {
    error_log('LTI OIDC initiation failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('Canvas sign-in could not be started. Please try again later.');
}
