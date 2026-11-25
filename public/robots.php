<?php

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$siteUrl = isEducationHost() ? EDUCATION_APP_URL : PERSONAL_APP_URL;

header('Content-Type: text/plain; charset=utf-8');
echo "User-agent: *\n";
echo "Allow: /\n";
echo "Disallow: /admin/\n";
echo "Disallow: /dashboard.php\n";
echo "Disallow: /profile.php\n";
echo 'Sitemap: ' . $siteUrl . "/sitemap.xml\n";
