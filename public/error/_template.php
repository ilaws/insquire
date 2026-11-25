<?php

$statusCode = (int) ($statusCode ?? 500);
$title = (string) ($title ?? 'Something went wrong');
$message = (string) ($message ?? 'InsQuire could not complete that request.');
$requestUri = (string) ($_SERVER['REDIRECT_URL'] ?? $_SERVER['REQUEST_URI'] ?? '');
$isLtiLaunchError = $requestUri === '/lti-launch.php'
    && (string) ($_COOKIE['insquire_lti_frame'] ?? '') === '1';
http_response_code($statusCode);
header('X-Content-Type-Options: nosniff');
if ($isLtiLaunchError) {
    header_remove('X-Frame-Options');
} else {
    header('X-Frame-Options: SAMEORIGIN');
}
header('Referrer-Policy: strict-origin-when-cross-origin');

$accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
if (
    preg_match('#^/api/v1(?:/|$)#', $requestUri) === 1 &&
    str_contains($accept, 'application/json')
) {
    header('Content-Type: application/json; charset=utf-8');
    $apiMessage = match ($statusCode) {
        401 => str_contains($requestUri, '/auth/login.php')
            ? 'Email or password is incorrect.'
            : 'Your session has expired. Please sign in again.',
        403 => 'You do not have permission to complete this request.',
        404 => 'The requested resource could not be found.',
        405 => 'This action is not available for this request.',
        419 => 'Your security token has expired. Refresh the page and try again.',
        429 => 'Too many requests. Please wait before trying again.',
        default => $statusCode >= 500
            ? 'The service is temporarily unavailable. Please try again shortly.'
            : $message
    };
    echo json_encode(
        ['error' => $apiMessage],
        JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="referrer" content="strict-origin-when-cross-origin">
    <title><?= htmlspecialchars($statusCode . ' - ' . $title, ENT_QUOTES, 'UTF-8') ?> - InsQuire</title>
    <meta name="description" content="<?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>">
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" href="/favicon.ico?v=3" sizes="any">
    <link rel="icon" href="/assets/favicon-new.svg?v=3" type="image/svg+xml">
    <link rel="icon" href="/assets/favicon-new-32.png?v=3" type="image/png" sizes="32x32">
    <link rel="apple-touch-icon" href="/assets/apple-touch-icon-new.png?v=3">
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<header class="header">
    <a href="/" class="logo">InsQuire</a>
    <nav>
        <a href="/login.php">Log In</a>
        <a href="/register.php">Register</a>
    </nav>
</header>
<main class="container">
    <section class="card not-found-card" aria-labelledby="error-title">
        <p class="eyebrow">Error <?= $statusCode ?></p>
        <h1 id="error-title"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
        <p><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
        <div class="hero-actions">
            <a class="button" href="/">Return to InsQuire</a>
            <a class="button secondary" href="/privacy.php">Privacy Policy</a>
        </div>
    </section>
</main>
<footer class="footer">
    <p>&copy; <?= date('Y') ?> InsQuire</p>
    <p><a href="/privacy.php">Privacy Policy</a></p>
</footer>
</body>
</html>
