<?php

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/lti.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');
header('X-Content-Type-Options: nosniff');

if (!ltiSigningKeyReady()) {
    http_response_code(503);
    echo json_encode(['error' => 'Canvas signing key must be configured as RSA with at least 2048 bits.']);
    exit;
}

$privateKey = openssl_pkey_get_private(LTI_PRIVATE_KEY);
$details = $privateKey === false ? false : openssl_pkey_get_details($privateKey);
if (
    !is_array($details)
    || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA
    || ($details['bits'] ?? 0) < 2048
) {
    http_response_code(503);
    echo json_encode(['error' => 'Canvas signing key must be configured as RSA with at least 2048 bits.']);
    exit;
}

echo json_encode([
    'keys' => [[
        'kty' => 'RSA',
        'use' => 'sig',
        'alg' => 'RS256',
        'kid' => LTI_KEY_ID,
        'n' => ltiBase64UrlEncode($details['rsa']['n']),
        'e' => ltiBase64UrlEncode($details['rsa']['e'])
    ]]
], JSON_UNESCAPED_SLASHES);
