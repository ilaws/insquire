<?php

require_once dirname(__DIR__, 3) . '/config/database.php';
require_once dirname(__DIR__, 3) . '/includes/functions.php';
require_once dirname(__DIR__, 3) . '/includes/csrf.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function apiRespond(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function apiRequireMethod(string $method): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
        header('Allow: ' . $method);
        apiRespond(['error' => 'Method not allowed.'], 405);
    }
}

function apiReadJson(): array
{
    $contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
    if ($contentType !== 'application/json') {
        apiRespond(['error' => 'Send request data as JSON.'], 415);
    }

    $raw = file_get_contents('php://input');
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data)) {
        apiRespond(['error' => 'The request body must be a valid JSON object.'], 400);
    }

    return $data;
}

function apiVerifyCsrf(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (
        !is_string($token) ||
        empty($_SESSION['csrf_token']) ||
        !hash_equals((string) $_SESSION['csrf_token'], $token)
    ) {
        apiRespond(['error' => 'Your security token expired. Refresh the page and try again.'], 419);
    }
}

function apiGoogleCredential(string $credential): ?array
{
    if (!function_exists('curl_init')) {
        apiRespond(['error' => 'Google sign-in is temporarily unavailable.'], 503);
    }

    $ch = curl_init('https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($credential));
    if ($ch === false) {
        apiRespond(['error' => 'Google sign-in is temporarily unavailable.'], 503);
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10
    ]);
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        error_log('Google token verification failed: ' . $curlError);
        apiRespond(['error' => 'Google sign-in could not be verified right now. Please try again.'], 503);
    }
    if ($status !== 200) {
        return null;
    }

    $payload = json_decode($response, true);
    if (
        !is_array($payload) ||
        empty($payload['sub']) ||
        empty($payload['email']) ||
        empty($payload['email_verified']) ||
        ($payload['aud'] ?? '') !== GOOGLE_OAUTH_CLIENT_ID
    ) {
        return null;
    }

    return $payload;
}

function apiCurrentUser(): ?array
{
    if (!isset($_SESSION['user_id'])) {
        return null;
    }

    global $pdo;
    $stmt = $pdo->prepare(
        'SELECT id, name, email, role, account_scope
         FROM users
         WHERE id = ? AND status = "active"
         LIMIT 1'
    );
    $stmt->execute([(int) $_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $expectedScope = isEducationHost() ? 'education' : 'personal';

    if (!$user || $user['account_scope'] !== $expectedScope) {
        $_SESSION = [];
        session_destroy();
        return null;
    }

    $_SESSION['role'] = (string) $user['role'];
    $_SESSION['account_scope'] = (string) $user['account_scope'];
    return [
        'id' => (int) $user['id'],
        'name' => (string) $user['name'],
        'email' => (string) $user['email'],
        'role' => (string) $user['role'],
        'account_scope' => (string) $user['account_scope']
    ];
}

function apiRequireUser(array $allowedRoles = []): array
{
    $user = apiCurrentUser();
    if (!$user) {
        apiRespond(['error' => 'Sign in to continue.'], 401);
    }
    if ($allowedRoles && !in_array($user['role'], $allowedRoles, true)) {
        apiRespond(['error' => 'You do not have permission to do that.'], 403);
    }

    return $user;
}

set_exception_handler(static function (Throwable $exception): void {
    error_log(sprintf(
        'InsQuire API exception: %s in %s:%d',
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine()
    ));
    apiRespond(['error' => 'The request could not be completed. Please try again.'], 500);
});
