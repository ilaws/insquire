<?php

require_once __DIR__ . '/_bootstrap.php';
apiRequireMethod('GET');

$defaultEducationClass = null;
if (isEducationHost() && EDUCATION_DEFAULT_CLASS_ID > 0) {
    $defaultClassStmt = $pdo->prepare(
        'SELECT name FROM classes WHERE id = ? AND status = "active" LIMIT 1'
    );
    $defaultClassStmt->execute([EDUCATION_DEFAULT_CLASS_ID]);
    $defaultClassName = $defaultClassStmt->fetchColumn();
    if (is_string($defaultClassName) && $defaultClassName !== '') {
        $defaultEducationClass = ['name' => $defaultClassName];
    }
}

apiRespond([
    'csrf_token' => csrfToken(),
    'site_scope' => isEducationHost() ? 'education' : 'personal',
    'google_client_id' => GOOGLE_OAUTH_CLIENT_ID,
    'personal_url' => PERSONAL_APP_URL,
    'education_url' => EDUCATION_APP_URL,
    'show_personal_link' => SHOW_PERSONAL_LINK,
    'ga_measurement_id' => GA_MEASUREMENT_ID,
    'default_education_class' => $defaultEducationClass,
    'user' => apiCurrentUser()
]);
