<?php
// Optional local-development settings for running without Docker.
// Copy to config/local.php (git-ignored). Environment variables still take
// precedence. See .env.example for every available setting.
return [
    'ESSAY_DB_HOST' => '127.0.0.1',
    'ESSAY_DB_NAME' => 'insquire',
    'ESSAY_DB_USER' => 'insquire',
    'ESSAY_DB_PASS' => '',
    'ESSAY_PERSONAL_APP_URL' => 'http://localhost:8080',
    'ESSAY_EDUCATION_APP_URL' => 'http://127.0.0.1:8080',
    'ESSAY_DEEPSEEK_API_KEY' => '',
];
