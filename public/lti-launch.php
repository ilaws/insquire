<?php

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/lti.php';

header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
$tokenUrlParts = parse_url(LTI_TOKEN_URL);
if (
    is_array($tokenUrlParts)
    && strtolower((string) ($tokenUrlParts['scheme'] ?? '')) === 'https'
    && preg_match('/\A[a-z0-9.-]+\z/i', (string) ($tokenUrlParts['host'] ?? ''))
) {
    $platformOrigin = 'https://' . strtolower((string) $tokenUrlParts['host'])
        . (isset($tokenUrlParts['port']) && (int) $tokenUrlParts['port'] !== 443
            ? ':' . (int) $tokenUrlParts['port']
            : '');
    header_remove('X-Frame-Options');
    header_remove('Content-Security-Policy');
    header("Content-Security-Policy: frame-ancestors 'self' " . $platformOrigin);
}

function ltiLaunchFailure(Throwable $exception): never
{
    error_log('LTI launch rejected: ' . $exception->getMessage());
    http_response_code(400);
    exit('Canvas could not sign you in. Return to Canvas and try launching InsQuire again.');
}

try {
    if (!ltiSettingsReady()) {
        http_response_code(503);
        exit('Canvas sign-in is not configured yet.');
    }
    $state = $_POST['state'] ?? '';
    $idToken = $_POST['id_token'] ?? '';
    if (!is_string($state) || !preg_match('/\A[a-f0-9]{64}\z/', $state) || !is_string($idToken) || $idToken === '') {
        throw new RuntimeException('Missing OIDC state or launch token.');
    }

    $pdo->beginTransaction();
    $stateQuery = $pdo->prepare(
        'SELECT nonce, session_hash, issuer, target_link_uri
         FROM lti_auth_requests
         WHERE state_hash = ? AND created_at >= (NOW() - INTERVAL 10 MINUTE)
         LIMIT 1 FOR UPDATE'
    );
    $stateQuery->execute([hash('sha256', $state)]);
    $authRequest = $stateQuery->fetch(PDO::FETCH_ASSOC);
    if (!$authRequest || !hash_equals((string) ($authRequest['session_hash'] ?? ''), hash('sha256', session_id()))) {
        $pdo->rollBack();
        throw new RuntimeException('OIDC state was missing, expired, reused, or not bound to this browser.');
    }
    $deleteState = $pdo->prepare('DELETE FROM lti_auth_requests WHERE state_hash = ?');
    $deleteState->execute([hash('sha256', $state)]);
    $pdo->commit();

    $claims = ltiVerifyJwt($idToken);
    $messageType = ltiValidateLaunchClaims($claims, (string) $authRequest['nonce']);
    if (
        (string) $claims['iss'] !== (string) $authRequest['issuer']
        || (string) ($claims[LTI_CLAIM_PREFIX . 'target_link_uri'] ?? '') !== (string) $authRequest['target_link_uri']
        || !isset($claims['sub'])
    ) {
        throw new RuntimeException('Launch issuer, subject, or target did not match the OIDC request.');
    }

    $role = ltiUserRole($claims);
    $userId = ltiGetOrCreateUser($pdo, $claims, $role);
    $classId = ltiResolveCourse($pdo, $claims, $userId, $role);
    $rosterWarning = false;
    if ($role === 'teacher' && $classId) {
        try {
            ltiSyncCourseRoster($pdo, $claims, $classId);
        } catch (Throwable $exception) {
            error_log('Canvas roster synchronization failed: ' . $exception->getMessage());
            $rosterWarning = true;
        }
    }
    $userStmt = $pdo->prepare('SELECT name, email FROM users WHERE id = ? AND status = "active" LIMIT 1');
    $userStmt->execute([$userId]);
    $userAccount = $userStmt->fetch(PDO::FETCH_ASSOC);
    if (!$userAccount) {
        throw new RuntimeException('The Canvas-linked account is unavailable.');
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    $_SESSION['name'] = (string) $userAccount['name'];
    $_SESSION['email'] = (string) $userAccount['email'];
    $_SESSION['role'] = $role;
    $_SESSION['account_scope'] = 'education';
    $_SESSION['lti_issuer'] = LTI_ISSUER;
    $_SESSION['lti_deployment_id'] = (string) $claims[LTI_CLAIM_PREFIX . 'deployment_id'];
    $_SESSION['lti_context_id'] = (string) ($claims[LTI_CLAIM_PREFIX . 'context']['id'] ?? '');
    $_SESSION['lti_class_id'] = $classId;
    $_SESSION['lti_message_type'] = $messageType;
    $_SESSION['lti_deep_link_settings'] = $claims[LTI_DEEP_LINK_CLAIM_PREFIX . 'deep_linking_settings'] ?? null;
    if ($rosterWarning) {
        $_SESSION['lti_roster_warning'] = true;
    }
    $_SESSION['lti_frame_origin'] = 'https://' . strtolower((string) ($tokenUrlParts['host'] ?? ''))
        . (isset($tokenUrlParts['port']) && $tokenUrlParts['port'] !== 443 ? ':' . (int) $tokenUrlParts['port'] : '');
    setcookie('insquire_lti_frame', '1', [
        'expires' => 0,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'None'
    ]);

    if ($messageType === 'LtiDeepLinkingRequest') {
        if ($role !== 'teacher' || !$classId || !is_array($_SESSION['lti_deep_link_settings'])) {
            throw new RuntimeException('Deep linking is available only to course instructors.');
        }
        header('Location: lti-deep-link.php', true, 303);
        exit;
    }

    $assignmentId = $classId ? ltiSaveAssignmentLink($pdo, $claims, $classId) : null;
    if ($assignmentId) {
        header('Location: assignment.php?id=' . $assignmentId, true, 303);
        exit;
    }
    if ($classId) {
        header('Location: class.php?id=' . $classId, true, 303);
        exit;
    }
    header('Location: dashboard.php', true, 303);
    exit;
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    ltiLaunchFailure($exception);
}
