<?php

$privateSettings = [];
$requestHost = strtolower((string) parse_url('https://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
$appDomain = basename(dirname(__DIR__, 2));
$privateHosts = array_unique(['insquire.org', 'ed.insquire.org', $appDomain]);
$privateSettingsPath = in_array($requestHost, $privateHosts, true)
    ? dirname(__DIR__, 3) . '/' . $requestHost . '/private/config.php'
    : '';

// Local development fallback: config/local.php (git-ignored, copy from
// config/local.example.php). Never commit real credentials.
if (($privateSettingsPath === '' || !is_file($privateSettingsPath)) && is_file(__DIR__ . '/local.php')) {
    $privateSettingsPath = __DIR__ . '/local.php';
}

if ($privateSettingsPath !== '' && is_file($privateSettingsPath)) {
    $loadedSettings = require $privateSettingsPath;
    if (is_array($loadedSettings)) {
        $privateSettings = $loadedSettings;
    }
}

function serverSetting(string $name, string $default = ''): string
{
    $value = getenv($name);
    if ($value !== false && $value !== '') {
        return (string) $value;
    }

    global $privateSettings;
    $value = $_ENV[$name] ?? $_SERVER[$name] ?? ($privateSettings[$name] ?? $default);
    if ($value === '' && is_string($privateSettings[$name] ?? null) && $privateSettings[$name] !== '') {
        $value = $privateSettings[$name];
    }
    return is_string($value) && $value !== '' ? $value : $default;
}

define('DB_HOST', serverSetting('ESSAY_DB_HOST', '127.0.0.1'));
define('DB_NAME', serverSetting('ESSAY_DB_NAME'));
define('DB_USER', serverSetting('ESSAY_DB_USER'));
define('DB_PASS', serverSetting('ESSAY_DB_PASS'));

if (DB_NAME === '' || DB_USER === '') {
    throw new RuntimeException('Database configuration is incomplete. Configure ESSAY_DB_NAME and ESSAY_DB_USER outside the web root.');
}

define('API_TOKEN_TTL', (int) serverSetting('ESSAY_API_TOKEN_TTL', '2592000'));

define('DEEPSEEK_API_URL', serverSetting('ESSAY_DEEPSEEK_API_URL', 'https://api.deepseek.com'));
define('DEEPSEEK_API_KEY', serverSetting('ESSAY_DEEPSEEK_API_KEY'));
define('DEEPSEEK_FEEDBACK_MODEL', serverSetting('ESSAY_DEEPSEEK_FEEDBACK_MODEL', 'deepseek-flash'));
define('DEEPSEEK_TEACHER_MODEL', serverSetting('ESSAY_DEEPSEEK_TEACHER_MODEL', 'deepseek-flash'));
define('AI_RATE_LIMIT_PER_MINUTE', max(1, (int) serverSetting('ESSAY_AI_RATE_LIMIT_PER_MINUTE', '12')));
define('AI_RATE_LIMIT_PER_HOUR', max(1, (int) serverSetting('ESSAY_AI_RATE_LIMIT_PER_HOUR', '100')));
define('AI_RATE_LIMIT_PER_DAY', max(1, (int) serverSetting('ESSAY_AI_RATE_LIMIT_PER_DAY', '10')));
define('AI_PERSONAL_FREE_DAILY_LIMIT', max(1, (int) serverSetting('ESSAY_AI_PERSONAL_FREE_DAILY_LIMIT', (string) AI_RATE_LIMIT_PER_DAY)));
define('AI_PERSONAL_PAID_DAILY_LIMIT', max(1, (int) serverSetting('ESSAY_AI_PERSONAL_PAID_DAILY_LIMIT', '100')));
define('AI_EDUCATION_DAILY_LIMIT', max(1, (int) serverSetting('ESSAY_AI_EDUCATION_DAILY_LIMIT', '100')));
define('STRIPE_SECRET_KEY', serverSetting('ESSAY_STRIPE_SECRET_KEY'));
define('STRIPE_WEBHOOK_SECRET', serverSetting('ESSAY_STRIPE_WEBHOOK_SECRET'));
define('STRIPE_PERSONAL_MONTHLY_PRICE_ID', serverSetting('ESSAY_STRIPE_PERSONAL_MONTHLY_PRICE_ID'));
define('STRIPE_EDU_PRICE_1_99', serverSetting('ESSAY_STRIPE_EDU_PRICE_1_99'));
define('STRIPE_EDU_PRICE_100_299', serverSetting('ESSAY_STRIPE_EDU_PRICE_100_299'));
define('STRIPE_EDU_PRICE_300_699', serverSetting('ESSAY_STRIPE_EDU_PRICE_300_699'));
define('STRIPE_EDU_PRICE_700_1299', serverSetting('ESSAY_STRIPE_EDU_PRICE_700_1299'));
define('STRIPE_EDU_PRICE_1300_2199', serverSetting('ESSAY_STRIPE_EDU_PRICE_1300_2199'));
define('STRIPE_EDU_PRICE_2200_3199', serverSetting('ESSAY_STRIPE_EDU_PRICE_2200_3199'));
define('STRIPE_EDU_PRICE_3200_PLUS', serverSetting('ESSAY_STRIPE_EDU_PRICE_3200_PLUS'));
define('STRIPE_WEBHOOK_TOLERANCE_SECONDS', max(60, (int) serverSetting('ESSAY_STRIPE_WEBHOOK_TOLERANCE_SECONDS', '300')));
define('ESSAY_BILLING_METADATA_SECRET', serverSetting('ESSAY_BILLING_METADATA_SECRET'));
define('GA_MEASUREMENT_ID', preg_match('/\AG-[A-Z0-9]+\z/', serverSetting('ESSAY_GA_MEASUREMENT_ID')) ? serverSetting('ESSAY_GA_MEASUREMENT_ID') : '');
define('ADSENSE_ENABLED', serverSetting('ESSAY_ADSENSE_ENABLED', '0') === '1');
define('ADSENSE_PUBLISHER_CLIENT_ID', serverSetting('ESSAY_ADSENSE_PUBLISHER_CLIENT_ID'));
define('ADSENSE_PERSONAL_FREE_SLOT_ID', serverSetting('ESSAY_ADSENSE_PERSONAL_FREE_SLOT_ID'));
$defaultMessageModerationTerms = explode(',', (string) require __DIR__ . '/message-moderation-terms.php');
$customMessageModerationTerms = explode(',', serverSetting('ESSAY_MESSAGE_MODERATION_TERMS'));
define(
    'MESSAGE_MODERATION_TERMS',
    implode(',', array_unique(array_filter(array_map(
        'trim',
        array_merge($defaultMessageModerationTerms, $customMessageModerationTerms)
    ))))
);

define('APP_NAME', 'InsQuire');
define(
    'GOOGLE_OAUTH_CLIENT_ID',
    serverSetting('ESSAY_GOOGLE_OAUTH_CLIENT_ID')
);
define('PERSONAL_APP_URL', rtrim(serverSetting('ESSAY_PERSONAL_APP_URL', 'https://insquire.org'), '/'));
// Show an Education -> Personal link. Off by default (Personal is closed on the
// public site); enabled in .env.example for local/self-hosted copies.
define('SHOW_PERSONAL_LINK', serverSetting('ESSAY_SHOW_PERSONAL_LINK', '0') === '1');
define('EDUCATION_APP_URL', rtrim(serverSetting('ESSAY_EDUCATION_APP_URL', 'https://ed.insquire.org'), '/'));
define('EDUCATION_DEFAULT_CLASS_ID', max(0, (int) serverSetting('ESSAY_EDUCATION_DEFAULT_CLASS_ID', '0')));
define('LTI_ISSUER', rtrim(serverSetting('ESSAY_LTI_ISSUER'), '/'));
define('LTI_CLIENT_ID', serverSetting('ESSAY_LTI_CLIENT_ID'));
define('LTI_DEPLOYMENT_ID', serverSetting('ESSAY_LTI_DEPLOYMENT_ID'));
define('LTI_LOGIN_URL', serverSetting('ESSAY_LTI_LOGIN_URL'));
define('LTI_TOKEN_URL', serverSetting('ESSAY_LTI_TOKEN_URL'));
define('LTI_JWKS_URL', serverSetting('ESSAY_LTI_JWKS_URL'));
define('LTI_KEY_ID', serverSetting('ESSAY_LTI_KEY_ID'));
define('LTI_PRIVATE_KEY', str_replace('\\n', "\n", serverSetting('ESSAY_LTI_PRIVATE_KEY')));
define('CANVAS_API_BASE_URL', rtrim(serverSetting('ESSAY_CANVAS_API_BASE_URL', 'https://lms.insquire.org'), '/'));
define('CANVAS_OAUTH_CLIENT_ID', serverSetting('ESSAY_CANVAS_OAUTH_CLIENT_ID', LTI_CLIENT_ID));
define('CANVAS_OAUTH_CLIENT_SECRET', serverSetting('ESSAY_CANVAS_OAUTH_CLIENT_SECRET'));
define(
    'CANVAS_OAUTH_REDIRECT_URI',
    serverSetting('ESSAY_CANVAS_OAUTH_REDIRECT_URI', EDUCATION_APP_URL . '/canvas-oauth-callback.php')
);
define('RUBRIC_PATH', dirname(__DIR__) . '/storage/rubrics');

if ($requestHost === 'insquire.org') {
    session_name('essay_assistant_personal_session');
} elseif ($requestHost === 'ed.insquire.org') {
    session_name('essay_assistant_education_session');
} else {
    session_name('essay_assistant_session');
}

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || in_array(basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')), ['lti-login.php', 'lti-launch.php'], true)
    ),
    'httponly' => true,
    'samesite' => in_array(basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')), ['lti-login.php', 'lti-launch.php'], true)
        ? 'None'
        : 'Lax'
]);

session_start();
