<?php

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/functions.php';

header('X-Content-Type-Options: nosniff');
if (
    isset($_SESSION['lti_frame_origin'])
    && preg_match('~\Ahttps://[a-z0-9.-]+(?::[0-9]{1,5})?\z~i', (string) $_SESSION['lti_frame_origin'])
) {
    header_remove('X-Frame-Options');
    header("Content-Security-Policy: frame-ancestors 'self' " . $_SESSION['lti_frame_origin']);
} else {
    header('X-Frame-Options: SAMEORIGIN');
}
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

$title = $title ?? APP_NAME;
if ($title === APP_NAME) {
    $pageSlug = pathinfo((string) ($_SERVER['SCRIPT_NAME'] ?? ''), PATHINFO_FILENAME);
    if ($pageSlug !== '' && $pageSlug !== 'index') {
        $title = ucwords(str_replace(['-', '_'], ' ', $pageSlug));
    }
}
$seoDescription = $seoDescription ?? ('Use InsQuire to ' . strtolower($title) . ' with a focused workspace for assignments, writing, and learning.');
$seoCanonicalUrl = $seoCanonicalUrl ?? '';
$seoSocialTitle = $seoSocialTitle ?? ($title . ' - ' . APP_NAME);
$seoImageUrl = (isEducationHost() ? EDUCATION_APP_URL : PERSONAL_APP_URL) . '/assets/social-card.png';
$styleSheetPath = dirname(__DIR__) . '/public/assets/style.css';
$styleSheetHash = hash_file('sha256', $styleSheetPath);
if ($styleSheetHash === false) {
    throw new RuntimeException('Unable to calculate the stylesheet cache version.');
}

$loggedIn = isset($_SESSION['user_id']);
$analyticsPageAllowed = true;
$inAdminArea = str_starts_with((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '/admin/');
$pagePrefix = $inAdminArea ? '../' : '';
$adminHref = $inAdminArea ? 'index.php' : 'admin/index.php';
$unreadNotificationCount = 0;
if ($loggedIn && isEducationHost() && in_array($_SESSION['role'] ?? '', ['student', 'teacher'], true)) {
    require_once dirname(__DIR__) . '/includes/notifications.php';
    $unreadNotificationCount = unreadNotificationCount($pdo, (int) $_SESSION['user_id']);
}
$fontScale = $_SESSION['font_scale'] ?? 'normal';
$accessibilityClasses = 'font-' . e($fontScale);
if (!empty($_SESSION['high_contrast'])) $accessibilityClasses .= ' high-contrast';
if (!empty($_SESSION['reduced_motion'])) $accessibilityClasses .= ' reduced-motion';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="referrer" content="strict-origin-when-cross-origin">
    <title><?= e($title) ?> - <?= e(APP_NAME) ?></title>
    <meta name="description" content="<?= e($seoDescription) ?>">
    <?php if (!empty($pageNoIndex)): ?><meta name="robots" content="noindex, nofollow"><?php endif; ?>
    <meta property="og:title" content="<?= e($seoSocialTitle) ?>">
    <meta property="og:description" content="<?= e($seoDescription) ?>">
    <meta property="og:type" content="website">
    <meta property="og:image" content="<?= e($seoImageUrl) ?>">
    <meta property="og:image:alt" content="InsQuire assignments, due dates, and writing feedback workspace">
    <meta property="og:image:type" content="image/png">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= e($seoSocialTitle) ?>">
    <meta name="twitter:description" content="<?= e($seoDescription) ?>">
    <meta name="twitter:image" content="<?= e($seoImageUrl) ?>">
    <meta name="twitter:image:alt" content="InsQuire assignments, due dates, and writing feedback workspace">
    <?php if ($seoCanonicalUrl !== ''): ?>
    <link rel="canonical" href="<?= e($seoCanonicalUrl) ?>">
    <meta property="og:url" content="<?= e($seoCanonicalUrl) ?>">
    <?php endif; ?>
    <link rel="icon" href="/favicon.ico?v=3" sizes="any">
    <link rel="icon" href="/assets/favicon-new.svg?v=3" type="image/svg+xml">
    <link rel="icon" href="/assets/favicon-new-32.png?v=3" type="image/png" sizes="32x32">
    <link rel="apple-touch-icon" href="/assets/apple-touch-icon-new.png?v=3">
    <link rel="stylesheet" href="<?= e($pagePrefix) ?>assets/style.css?v=<?= e($styleSheetHash) ?>">
</head>
<body class="<?= $accessibilityClasses ?> <?= e($pageBodyClass ?? '') ?>" data-analytics-enabled="<?= $analyticsPageAllowed ? 'true' : 'false' ?>" data-analytics-id="<?= e(GA_MEASUREMENT_ID) ?>">

<header class="header">
    <a href="<?= $loggedIn ? (($_SESSION['role'] ?? '') === 'admin' ? e($adminHref) : '/dashboard') : '/' ?>" class="logo">
        <span class="brand-mark" aria-hidden="true">IQ</span>
        <span>InsQuire</span>
    </a>

    <?php if ($loggedIn): ?>
        <nav class="primary-nav">
            <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
                <a href="/dashboard">Overview</a>
                <a href="<?= e($adminHref) ?>">Platform admin</a>
                <span class="user-chip"><span class="user-avatar"><?= e(mb_substr((string) ($_SESSION['name'] ?? 'A'), 0, 1)) ?></span><?= e((string) ($_SESSION['name'] ?? 'Admin')) ?></span>
                <a href="<?= e($pagePrefix) ?>logout.php">Sign out</a>
            <?php else: ?>
                <a href="/dashboard">Overview</a>
                <?php if (isEducationHost() && in_array($_SESSION['role'] ?? '', ['student', 'teacher'], true)): ?>
                    <a href="/classes">Classes</a>
                    <a class="notification-bell" href="<?= e($pagePrefix) ?>notifications.php" aria-label="Notifications<?= $unreadNotificationCount > 0 ? ', ' . $unreadNotificationCount . ' unread' : '' ?>">
                        <span>Notifications</span>
                        <span id="notification-count" class="notification-count"<?= $unreadNotificationCount > 0 ? '' : ' hidden' ?>><?= $unreadNotificationCount > 99 ? '99+' : $unreadNotificationCount ?></span>
                    </a>
                <script>
                    (function () {
                        var bell = document.querySelector('.notification-bell');
                        var badge = document.getElementById('notification-count');
                        if (!bell || !badge) return;

                        function refreshNotificationCount() {
                            fetch('<?= e($pagePrefix) ?>notification-count.php', {
                                credentials: 'same-origin',
                                cache: 'no-store',
                                headers: { 'Accept': 'application/json' }
                            }).then(function (response) {
                                if (!response.ok) throw new Error('Notification count request failed.');
                                return response.json();
                            }).then(function (data) {
                                if (!Number.isInteger(data.unread) || data.unread < 0) {
                                    throw new Error('Notification count response was invalid.');
                                }
                                badge.textContent = data.unread > 99 ? '99+' : String(data.unread);
                                badge.hidden = data.unread === 0;
                                bell.setAttribute('aria-label', 'Notifications' + (data.unread ? ', ' + data.unread + ' unread' : ''));
                            }).catch(function (error) {
                                console.error('Unable to refresh notification count:', error);
                            });
                        }

                        window.setInterval(refreshNotificationCount, 60000);
                    }());
                </script>
                <?php endif; ?>
                <details class="nav-menu">
                    <summary>More</summary>
                    <div class="nav-menu-panel">
                        <?php if (isEducationHost() && $_SESSION['role'] === 'student'): ?>
                            <a href="<?= e($pagePrefix) ?>join-class.php">Join a class</a>
                            <a href="<?= e($pagePrefix) ?>past-assignments.php">Past assignments</a>
                            <a href="<?= e($pagePrefix) ?>english-diagnostic.php">English diagnostic</a>
                            <a href="<?= e($pagePrefix) ?>grades.php">Grades</a>
                            <a href="<?= e($pagePrefix) ?>messages.php">Messages</a>
                            <a href="<?= e($pagePrefix) ?>calendar.php">Calendar</a>
                        <?php elseif (isEducationHost() && $_SESSION['role'] === 'teacher'): ?>
                            <a href="<?= e($pagePrefix) ?>create-class.php">Create a class</a>
                            <a href="<?= e($pagePrefix) ?>create-assignment.php">Create an assignment</a>
                            <a href="<?= e($pagePrefix) ?>lti-settings.php">LTI settings</a>
                            <a href="<?= e($pagePrefix) ?>gradebook.php">Gradebook</a>
                            <a href="<?= e($pagePrefix) ?>institutions.php">Institutions</a>
                            <a href="<?= e($pagePrefix) ?>lesson-library.php">Lesson library</a>
                            <a href="<?= e($pagePrefix) ?>english-diagnostics.php">English diagnostics</a>
                            <a href="<?= e($pagePrefix) ?>messages.php">Messages</a>
                            <a href="<?= e($pagePrefix) ?>calendar.php">Calendar</a>
                            <?php if (isset($pdo) && isInstitutionAdmin($pdo, currentUserId())): ?><a href="<?= e($pagePrefix) ?>moderation.php">Review queue</a><?php endif; ?>
                        <?php elseif (!isEducationHost()): ?>
                            <a href="<?= e($pagePrefix) ?>personal-planner.php">Assignments and writing coach</a>
                            <a href="<?= e($pagePrefix) ?>calendar.php">Calendar</a>
                            <a href="<?= e($pagePrefix) ?>billing.php">Plans and billing</a>
                            <a href="<?= e($pagePrefix) ?>education.php">InsQuire for Education</a>
                        <?php else: ?>
                            <a href="<?= e($pagePrefix) ?>calendar.php">Calendar</a>
                        <?php endif; ?>
                        <?php if (isEducationHost()): ?><a href="<?= e($pagePrefix) ?>billing.php">Plans and billing</a><?php endif; ?>
                        <a href="<?= e($pagePrefix) ?>profile.php">Profile</a>
                    </div>
                </details>
                <span class="user-chip"><span class="user-avatar"><?= e(mb_substr((string) ($_SESSION['name'] ?? 'U'), 0, 1)) ?></span><?= e((string) ($_SESSION['name'] ?? 'Account')) ?></span>
                <a href="<?= e($pagePrefix) ?>logout.php">Sign out</a>
            <?php endif; ?>
        </nav>
    <?php else: ?>
        <nav>
            <a href="/login">Sign in</a>
            <a href="/register"><?= isEducationHost() ? 'Sign up' : 'Get started' ?></a>
            <?php if (!isEducationHost()): ?><a href="<?= e($pagePrefix) ?>education.php">InsQuire for Education</a><?php endif; ?>
            </nav>
        <?php endif; ?>
</header>

<script>
    (function () {
        var scrollStorageKey = 'insquire:restore-scroll';

        window.insquireRememberScroll = function () {
            try {
                sessionStorage.setItem(scrollStorageKey, JSON.stringify({
                    path: window.location.pathname,
                    y: window.scrollY,
                    expires: Date.now() + 30000
                }));
            } catch (error) {
                console.error('Unable to remember the page position:', error);
            }
        };

        window.insquireClearRememberedScroll = function () {
            try {
                sessionStorage.removeItem(scrollStorageKey);
            } catch (error) {
                console.error('Unable to clear the remembered page position:', error);
            }
        };

        document.addEventListener('submit', function (event) {
            if (event.target.matches('form[data-preserve-scroll]')) {
                window.insquireRememberScroll();
            }
        });

        window.addEventListener('pageshow', function () {
            var saved;
            try {
                saved = sessionStorage.getItem(scrollStorageKey);
                if (saved) sessionStorage.removeItem(scrollStorageKey);
            } catch (error) {
                console.error('Unable to read the remembered page position:', error);
                return;
            }
            if (!saved) return;

            try {
                var position = JSON.parse(saved);
                if (
                    position.path === window.location.pathname &&
                    Number.isFinite(position.y) &&
                    position.expires >= Date.now()
                ) {
                    window.requestAnimationFrame(function () {
                        window.requestAnimationFrame(function () {
                            window.scrollTo(0, position.y);
                        });
                    });
                }
            } catch (error) {
                console.error('Unable to restore the previous page position:', error);
            }
        });
    }());
</script>

<?php if (!empty($_SESSION['lti_roster_warning'])): ?>
    <?php unset($_SESSION['lti_roster_warning']); ?>
    <div class="container">
        <div class="status-message status-info" role="status">The Canvas roster could not be synchronized. Students can still join this course when they launch InsQuire from Canvas.</div>
    </div>
<?php endif; ?>

<?php if (!isEducationHost()): ?>
    <aside class="edition-disclaimer" aria-label="About InsQuire Personal">
        <p>You're using <strong>InsQuire Personal</strong> for individual use. InsQuire Education includes teacher and administrator accounts, classes, student submissions, and gradebooks.</p>
        <a href="<?= e(EDUCATION_APP_URL) ?>/">Visit InsQuire Education <span aria-hidden="true">→</span></a>
    </aside>
<?php elseif (SHOW_PERSONAL_LINK): ?>
    <aside class="edition-disclaimer" aria-label="About InsQuire Education">
        <p>You're using <strong>InsQuire Education</strong> for schools. Looking for the individual writing coach and planner?</p>
        <a href="<?= e(PERSONAL_APP_URL) ?>/">Visit InsQuire Personal <span aria-hidden="true">→</span></a>
    </aside>
<?php endif; ?>

<main class="container">
