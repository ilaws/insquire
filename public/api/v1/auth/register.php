<?php

require_once dirname(__DIR__) . '/_bootstrap.php';
apiRequireMethod('POST');
apiVerifyCsrf();
$input = apiReadJson();

$scope = isEducationHost() ? 'education' : 'personal';
$credential = trim((string) ($input['credential'] ?? ''));
$googleId = '';
$googlePayload = $credential !== '' ? apiGoogleCredential($credential) : null;
if ($credential !== '' && !$googlePayload) {
    apiRespond(['error' => 'Google sign-in validation failed.'], 401);
}
$name = trim((string) ($googlePayload['name'] ?? $input['name'] ?? ''));
$email = strtolower(trim((string) ($googlePayload['email'] ?? $input['email'] ?? '')));
$googleId = (string) ($googlePayload['sub'] ?? '');
if ($credential !== '' && $name === '' && $email !== '') {
    $name = explode('@', $email)[0];
}
$password = $credential !== '' ? bin2hex(random_bytes(32)) : (string) ($input['password'] ?? '');
$inviteToken = trim((string) ($input['invite_token'] ?? ''));
$schoolCodeInput = $input['school_code'] ?? '';
if ($scope === 'education' && !is_string($schoolCodeInput)) {
    apiRespond(['error' => 'Enter a valid class code.'], 422);
}
$schoolCode = $scope === 'education' ? strtoupper(trim($schoolCodeInput)) : '';
$invite = false;
$role = 'student';

if ($name === '' || mb_strlen($name) > 120) {
    apiRespond(['error' => 'Enter a name of 1 to 120 characters.'], 422);
}
if ($scope === 'education' && strlen($schoolCode) > 16) {
    apiRespond(['error' => 'Class codes must be 16 characters or fewer.'], 422);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
    apiRespond(['error' => 'Enter a valid email address.'], 422);
}
if ($credential === '' && (strlen($password) < 8 || strlen($password) > 4096)) {
    apiRespond(['error' => 'Password must be at least 8 characters.'], 422);
}

if ($scope === 'education') {
    if ($inviteToken !== '') {
        $invite = findInstitutionInvite($pdo, $inviteToken);
        if (!$invite) {
            apiRespond(['error' => 'This teacher invitation is invalid, expired, or already used.'], 422);
        }
        if ($invite['email'] !== null && strcasecmp((string) $invite['email'], $email) !== 0) {
            apiRespond(['error' => 'Use the email address this teacher invitation was sent to.'], 422);
        }
        $role = 'teacher';
    }
}

$duplicate = $pdo->prepare(
    'SELECT id FROM users
     WHERE account_scope = ? AND (email = ? OR (? <> "" AND google_id = ?))
     LIMIT 1'
);
$duplicate->execute([$scope, $email, $googleId, $googleId]);
if ($duplicate->fetchColumn()) {
    apiRespond(['error' => 'An account with that email already exists. Sign in instead.'], 409);
}

try {
    $pdo->beginTransaction();
    $class = null;
    if ($scope === 'education' && !$invite) {
        $class = getEducationSignupClass($pdo, true, $schoolCode);
        if (!$class) {
            $pdo->rollBack();
            if ($schoolCode !== '') {
                apiRespond(['error' => 'That class code is invalid or inactive.'], 422);
            }
            apiRespond(['error' => 'Student registration is temporarily unavailable. Please contact your school administrator.'], 503);
        }
    }

    $insert = $pdo->prepare(
        'INSERT INTO users (name, email, password_hash, role, status, google_id, account_scope)
         VALUES (?, ?, ?, ?, "active", ?, ?)'
    );
    $insert->execute([
        $name,
        $email,
        password_hash($password, PASSWORD_DEFAULT),
        $role,
        $googleId !== '' ? $googleId : null,
        $scope
    ]);
    $userId = (int) $pdo->lastInsertId();

    if ($invite && !acceptInstitutionInviteInTransaction($pdo, (int) $invite['id'], $userId)) {
        $pdo->rollBack();
        apiRespond(['error' => 'This teacher invitation is no longer available.'], 409);
    }

    if ($class) {
        $member = $pdo->prepare('INSERT INTO class_members (class_id, user_id) VALUES (?, ?)');
        $member->execute([(int) $class['id'], $userId]);
        if ($class['institution_id'] !== null) {
            $institutionMember = $pdo->prepare(
                'INSERT INTO institution_members (institution_id, user_id, role)
                 VALUES (?, ?, "student")'
            );
            $institutionMember->execute([(int) $class['institution_id'], $userId]);
        }
    }

    $pdo->commit();
    apiRespond(['message' => 'Account created. Sign in to continue.'], 201);
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($exception->getCode() === '23000' && (int) ($exception->errorInfo[1] ?? 0) === 1062) {
        apiRespond(['error' => 'An account with that email already exists. Sign in instead.'], 409);
    }
    throw $exception;
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $exception;
}
