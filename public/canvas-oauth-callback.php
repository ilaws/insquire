<?php

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/canvas-publishing.php';

$state = $_GET['state'] ?? '';
$nativeState = null;
$teacherId = 0;
if (is_string($state) && preg_match('/\A[a-f0-9]{64}\z/', $state)) {
    $nativeStateStmt = $pdo->prepare(
        'SELECT teacher_id, assignment_read_enabled
         FROM canvas_native_oauth_states
         WHERE state_hash = ? AND status = "pending" AND expires_at > UTC_TIMESTAMP()
         LIMIT 1'
    );
    $nativeStateStmt->execute([hash('sha256', $state)]);
    $nativeState = $nativeStateStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$isNativeAuthorization = $nativeState !== null;
if ($isNativeAuthorization) {
    $teacherId = (int) $nativeState['teacher_id'];
    $teacherStmt = $pdo->prepare(
        'SELECT id FROM users
         WHERE id = ? AND role = "teacher" AND account_scope = "education" AND status = "active"
         LIMIT 1'
    );
    $teacherStmt->execute([$teacherId]);
    if (!$teacherStmt->fetchColumn()) {
        http_response_code(400);
        exit('The InsQuire teacher account for this Canvas authorization is no longer active.');
    }
    $claimState = $pdo->prepare(
        'UPDATE canvas_native_oauth_states SET status = "processing"
         WHERE state_hash = ? AND status = "pending" AND expires_at > UTC_TIMESTAMP()'
    );
    $claimState->execute([hash('sha256', $state)]);
    if ($claimState->rowCount() !== 1) {
        http_response_code(400);
        exit('This Canvas authorization was already used or has expired. Start again in the InsQuire app.');
    }
} else {
    requireTeacher();
    $teacherId = currentUserId();
}

$showNativeResult = static function (bool $success, string $message): never {
    http_response_code($success ? 200 : 400);
    $heading = $success ? 'Canvas connected' : 'Canvas connection failed';
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width">'
        . '<title>' . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . ' - InsQuire</title>'
        . '<body style="font:16px Arial,sans-serif;max-width:620px;margin:12vh auto;padding:24px;color:#2d3b45">'
        . '<h1>' . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . '</h1><p>'
        . htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
        . '</p><p>You can close this window and return to the InsQuire app.</p></body></html>';
    exit;
};

$failNativeAuthorization = static function (string $message) use ($pdo, $state, $isNativeAuthorization, $showNativeResult): void {
    if ($isNativeAuthorization) {
        $pdo->prepare(
            'UPDATE canvas_native_oauth_states
             SET status = "failed", error_message = ?, completed_at = UTC_TIMESTAMP()
             WHERE state_hash = ? AND status = "processing"'
        )->execute([mb_substr($message, 0, 500), hash('sha256', $state)]);
        $showNativeResult(false, $message);
    }
};

$returnToSettings = false;
if (!$isNativeAuthorization) {
    $expectedState = $_SESSION['canvas_oauth_state'] ?? null;
    unset($_SESSION['canvas_oauth_state']);
    if (
        !is_array($expectedState)
        || !is_string($expectedState['value'] ?? null)
        || !is_string($state)
        || !hash_equals($expectedState['value'], $state)
        || (int) ($expectedState['expires_at'] ?? 0) < time()
    ) {
        http_response_code(400);
        exit('Canvas authorization could not be verified. Return to Publish to Canvas and try again.');
    }
    $returnToSettings = !empty($expectedState['return_to_settings']);
} else {
    $expectedState = [
        'include_assignment_read' => !empty($nativeState['assignment_read_enabled'])
    ];
}
$returnPage = $returnToSettings ? 'lti-settings.php' : 'canvas-publish.php';

if (isset($_GET['error'])) {
    $canvasError = is_string($_GET['error']) ? substr($_GET['error'], 0, 120) : 'unknown_error';
    $description = $_GET['error_description'] ?? '';
    $description = is_string($description) ? trim(substr($description, 0, 500)) : '';
    $message = 'Canvas authorization was not completed (' . $canvasError . ').'
        . ($description !== '' ? ' ' . $description : '');
    $failNativeAuthorization($message);
    $_SESSION[$returnToSettings ? 'lti_settings_error' : 'canvas_publish_error'] = $message;
    header('Location: ' . $returnPage);
    exit;
}

$code = $_GET['code'] ?? '';
if (!is_string($code) || $code === '' || strlen($code) > 4096) {
    $failNativeAuthorization('Canvas did not return an authorization code.');
    http_response_code(400);
    exit('Canvas did not return an authorization code.');
}

try {
    if (!canvasOAuthReady()) {
        throw new RuntimeException('Canvas OAuth is not configured on this server.');
    }
    $token = canvasOAuthTokenRequest([
        'grant_type' => 'authorization_code',
        'client_id' => CANVAS_OAUTH_CLIENT_ID,
        'client_secret' => CANVAS_OAUTH_CLIENT_SECRET,
        'redirect_uri' => CANVAS_OAUTH_REDIRECT_URI,
        'code' => $code
    ]);
    if (!is_string($token['access_token'] ?? null) || $token['access_token'] === '') {
        throw new RuntimeException('Canvas did not return an access token.');
    }
    $identity = canvasApiRequest('GET', '/api/v1/users/self', $token['access_token']);
    $canvasUserId = isset($identity['id']) ? (string) $identity['id'] : '';
    if ($canvasUserId === '' || !canvasTeacherHasLinkedCourses($pdo, $teacherId)) {
        throw new RuntimeException(
            'No Canvas-linked course was found for this InsQuire teacher account. Open InsQuire from the Canvas course first, then reconnect.'
        );
    }

    $refreshToken = is_string($token['refresh_token'] ?? null) ? $token['refresh_token'] : '';
    $expiresAt = gmdate('Y-m-d H:i:s', time() + max(60, (int) ($token['expires_in'] ?? 3600)));
    $assignmentReadEnabled = !empty($expectedState['include_assignment_read']);
    $saveConnection = $pdo->prepare(
        'INSERT INTO canvas_oauth_connections
            (teacher_id, access_token, refresh_token, expires_at, canvas_user_id, assignment_read_enabled)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            access_token = VALUES(access_token),
            refresh_token = VALUES(refresh_token),
            expires_at = VALUES(expires_at),
            canvas_user_id = VALUES(canvas_user_id),
            assignment_read_enabled = VALUES(assignment_read_enabled)'
    );
    $saveConnection->execute([
        $teacherId,
        $token['access_token'],
        $refreshToken !== '' ? $refreshToken : null,
        $expiresAt,
        $canvasUserId,
        $assignmentReadEnabled ? 1 : 0
    ]);

    if ($isNativeAuthorization) {
        $pdo->prepare(
            'UPDATE canvas_native_oauth_states
             SET status = "connected", error_message = NULL, completed_at = UTC_TIMESTAMP()
             WHERE state_hash = ?'
        )->execute([hash('sha256', $state)]);
        $showNativeResult(true, 'Canvas is connected to your InsQuire teacher account.');
    }

    session_regenerate_id(true);
    $_SESSION['canvas_oauth'] = [
        'access_token' => $token['access_token'],
        'refresh_token' => $refreshToken,
        'expires_at' => time() + max(60, (int) ($token['expires_in'] ?? 3600)),
        'canvas_user_id' => $canvasUserId,
        'assignment_read_enabled' => $assignmentReadEnabled
    ];
    $_SESSION[$returnToSettings ? 'lti_settings_notice' : 'canvas_publish_notice'] = $returnToSettings
        ? 'Canvas account connected with assignment-read access.'
        : 'Canvas is connected. Choose an assignment and module to publish.';
    header('Location: ' . $returnPage);
    exit;
} catch (Throwable $exception) {
    unset($_SESSION['canvas_oauth']);
    $failNativeAuthorization($exception->getMessage());
    $_SESSION[$returnToSettings ? 'lti_settings_error' : 'canvas_publish_error'] = $exception->getMessage();
    header('Location: ' . $returnPage);
    exit;
}
