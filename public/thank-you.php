<?php

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$title = 'Account created';
$seoDescription = 'Your InsQuire account is ready. Log in to continue.';
$seoSocialTitle = 'Your InsQuire account is ready';
$pageNoIndex = true;
require_once dirname(__DIR__) . '/includes/header.php';
?>

<section class="card thank-you-card" aria-labelledby="thank-you-title">
    <p class="app-kicker">YOU'RE ALL SET</p>
    <h1 id="thank-you-title">Your account has been created.</h1>
    <p>Log in to continue to your InsQuire workspace.</p>
    <a class="button" href="login.php">Log in</a>
</section>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
