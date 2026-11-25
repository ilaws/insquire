<?php

require_once dirname(__DIR__) . '/_bootstrap.php';
apiRequireMethod('POST');
apiVerifyCsrf();
$input = apiReadJson();

$credential = trim((string) ($input['credential'] ?? ''));
$googleId = '';
$email = strtolower(trim((string) ($input['email'] ?? '')));
$password = (string) ($input['password'] ?? '');
$scope = isEducationHost() ? 'education' : 'personal';

if ($credential !== '') {
    $payload = apiGoogleCredential($credential);
    if (!$payload) {
        apiRespond(['error' => 'Google sign-in validation failed.'], 401);
    }
    $email = strtolower(trim((string) $payload['email']));
    $googleId = (string) $payload['sub'];
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
    apiRespond(['error' => 'Enter a valid email address and password.'], 422);
}

if ($googleId !== '') {
    $stmt = $pdo->prepare(
        'SELECT id, name, email, role, password_hash, account_scope, google_id
         FROM users
         WHERE account_scope = ? AND google_id = ? AND status = "active"
         LIMIT 1'
    );
    $stmt->execute([$scope, $googleId]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$account) {
        $stmt = $pdo->prepare(
            'SELECT id, name, email, role, password_hash, account_scope, google_id
             FROM users
             WHERE account_scope = ? AND email = ? AND status = "active"
             LIMIT 1'
        );
        $stmt->execute([$scope, $email]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} else {
    $stmt = $pdo->prepare(
        'SELECT id, name, email, role, password_hash, account_scope, google_id
         FROM users
         WHERE email = ? AND account_scope = ? AND status = "active"
         LIMIT 1'
    );
    $stmt->execute([$email, $scope]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (
    !$account ||
    ($googleId === '' && !password_verify($password, (string) $account['password_hash'])) ||
    ($googleId !== '' && !empty($account['google_id']) && !hash_equals((string) $account['google_id'], $googleId))
) {
    apiRespond(['error' => 'Invalid email or password.'], 401);
}

if ($googleId !== '' && empty($account['google_id'])) {
    $accountLinked = false;
    try {
        $link = $pdo->prepare(
            'UPDATE users SET google_id = ?
             WHERE id = ? AND (google_id IS NULL OR google_id = "")'
        );
        $link->execute([$googleId, (int) $account['id']]);
        $accountLinked = $link->rowCount() === 1;
    } catch (PDOException $exception) {
        if ($exception->getCode() !== '23000' || (int) ($exception->errorInfo[1] ?? 0) !== 1062) {
            throw $exception;
        }
    }
    if (!$accountLinked) {
        $linkedAccount = $pdo->prepare(
            'SELECT id, name, email, role, password_hash, account_scope, google_id
             FROM users
             WHERE account_scope = ? AND google_id = ? AND status = "active"
             LIMIT 1'
        );
        $linkedAccount->execute([$scope, $googleId]);
        $account = $linkedAccount->fetch(PDO::FETCH_ASSOC);
        if (!$account) {
            apiRespond(['error' => 'This Google account is already linked to another InsQuire account.'], 409);
        }
    }
}

session_regenerate_id(true);
$_SESSION['user_id'] = (int) $account['id'];
$_SESSION['name'] = (string) $account['name'];
$_SESSION['email'] = (string) $account['email'];
$_SESSION['role'] = (string) $account['role'];
$_SESSION['account_scope'] = (string) $account['account_scope'];
unset($_SESSION['csrf_token']);
$csrfToken = csrfToken();

apiRespond([
    'csrf_token' => $csrfToken,
    'user' => [
        'id' => (int) $account['id'],
        'name' => (string) $account['name'],
        'email' => (string) $account['email'],
        'role' => (string) $account['role'],
        'account_scope' => (string) $account['account_scope']
    ]
]);
