<?php

require_once dirname(__DIR__) . '/config/database.php';

function isLoggedIn(): bool
{
    return isset($_SESSION['user_id']);
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        $loginPath = str_starts_with((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '/admin/')
            ? '../login.php'
            : 'login.php';
        header('Location: ' . $loginPath);
        exit;
    }

    global $pdo;
    $stmt = $pdo->prepare('SELECT role, account_scope, status FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([currentUserId()]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$account || $account['status'] !== 'active') {
        $_SESSION = [];
        session_destroy();
        header('Location: login.php');
        exit;
    }

    $_SESSION['role'] = (string) $account['role'];
    $_SESSION['account_scope'] = (string) $account['account_scope'];

    $educationScope = $account['account_scope'] === 'education';
    $requestHost = strtolower((string) parse_url('https://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
    $educationHost = strtolower((string) parse_url(EDUCATION_APP_URL, PHP_URL_HOST));
    if ($educationScope !== ($requestHost === $educationHost)) {
        $homeUrl = $educationScope ? EDUCATION_APP_URL : PERSONAL_APP_URL;
        header('Location: ' . $homeUrl . '/dashboard.php');
        exit;
    }
}

function requireEducationHost(): void
{
    $requestHost = strtolower((string) parse_url('https://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
    $educationHost = strtolower((string) parse_url(EDUCATION_APP_URL, PHP_URL_HOST));
    if ($requestHost !== $educationHost) {
        header('Location: ' . EDUCATION_APP_URL);
        exit;
    }
}

function requireTeacher(): void
{
    requireLogin();
    requireEducationHost();

    if (currentAccountScope() !== 'education' || currentUserRole() !== 'teacher') {
        http_response_code(403);
        exit('Access denied.');
    }
}

function requireAdmin(): void
{
    requireLogin();

    if (currentAccountScope() !== 'education' || currentUserRole() !== 'admin') {
        http_response_code(403);
        exit('Access denied.');
    }
}

function requireStudent(): void
{
    requireLogin();
    requireEducationHost();

    if (currentAccountScope() !== 'education' || currentUserRole() !== 'student') {
        http_response_code(403);
        exit('Access denied.');
    }
}

function requirePersonalAccount(): void
{
    requireLogin();
    if (currentAccountScope() !== 'personal') {
        http_response_code(403);
        exit('This feature is available only to Personal accounts.');
    }
}

function currentAccountScope(): string
{
    return (string) ($_SESSION['account_scope'] ?? '');
}

function currentUserId(): int
{
    return (int)$_SESSION['user_id'];
}

function currentUserRole(): string
{
    return (string) ($_SESSION['role'] ?? '');
}
