<?php

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$educationSite = isEducationHost();
$siteUrl = $educationSite ? EDUCATION_APP_URL : PERSONAL_APP_URL;
$pages = $educationSite
    ? ['/', '/privacy.php']
    : ['/', '/education.php', '/privacy.php'];

header('Content-Type: application/xml; charset=utf-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($pages as $page) {
    echo '  <url><loc>' . htmlspecialchars($siteUrl . $page, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc></url>' . "\n";
}
echo '</urlset>' . "\n";
