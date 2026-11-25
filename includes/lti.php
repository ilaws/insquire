<?php

const LTI_CLAIM_PREFIX = 'https://purl.imsglobal.org/spec/lti/claim/';
const LTI_AGS_CLAIM_PREFIX = 'https://purl.imsglobal.org/spec/lti-ags/claim/';
const LTI_DEEP_LINK_CLAIM_PREFIX = 'https://purl.imsglobal.org/spec/lti-dl/claim/';
const LTI_NRPS_CLAIM_PREFIX = 'https://purl.imsglobal.org/spec/lti-nrps/claim/';

function ltiSettingsReady(bool $requireSigningKey = false): bool
{
    $settingsReady = LTI_ISSUER !== ''
        && LTI_CLIENT_ID !== ''
        && LTI_DEPLOYMENT_ID !== ''
        && filter_var(LTI_ISSUER, FILTER_VALIDATE_URL)
        && parse_url(LTI_ISSUER, PHP_URL_SCHEME) === 'https'
        && ltiIsHttpsUrl(LTI_LOGIN_URL)
        && ltiIsHttpsUrl(LTI_TOKEN_URL)
        && ltiIsHttpsUrl(LTI_JWKS_URL);

    if (!$settingsReady) {
        return false;
    }

    return !$requireSigningKey || ltiSigningKeyReady();
}

function ltiSigningKeyReady(): bool
{
    if (LTI_KEY_ID === '' || LTI_PRIVATE_KEY === '') {
        return false;
    }

    $privateKey = openssl_pkey_get_private(LTI_PRIVATE_KEY);
    if ($privateKey === false) {
        return false;
    }

    $details = openssl_pkey_get_details($privateKey);
    return is_array($details)
        && ($details['type'] ?? null) === OPENSSL_KEYTYPE_RSA
        && ($details['bits'] ?? 0) >= 2048;
}

function ltiIsHttpsUrl(string $url): bool
{
    return filter_var($url, FILTER_VALIDATE_URL) !== false
        && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https'
        && (string) parse_url($url, PHP_URL_HOST) !== ''
        && parse_url($url, PHP_URL_USER) === null
        && parse_url($url, PHP_URL_PASS) === null
        && parse_url($url, PHP_URL_FRAGMENT) === null;
}

function ltiSameHttpsOrigin(string $firstUrl, string $secondUrl): bool
{
    if (!ltiIsHttpsUrl($firstUrl) || !ltiIsHttpsUrl($secondUrl)) {
        return false;
    }
    $first = parse_url($firstUrl);
    $second = parse_url($secondUrl);
    return strtolower((string) $first['host']) === strtolower((string) $second['host'])
        && (int) ($first['port'] ?? 443) === (int) ($second['port'] ?? 443);
}

function ltiScoresUrl(string $lineitemUrl): string
{
    $parts = parse_url($lineitemUrl);
    if (!is_array($parts) || !ltiIsHttpsUrl($lineitemUrl)) {
        throw new RuntimeException('Canvas provided an invalid gradebook endpoint.');
    }
    $path = rtrim((string) ($parts['path'] ?? ''), '/');
    if (!str_ends_with($path, '/scores')) {
        $path .= '/scores';
    }
    $url = 'https://' . $parts['host']
        . (isset($parts['port']) && (int) $parts['port'] !== 443 ? ':' . (int) $parts['port'] : '')
        . $path;
    return isset($parts['query']) ? $url . '?' . $parts['query'] : $url;
}

function ltiBase64UrlDecode(string $value): string|false
{
    $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
    return $decoded === false ? false : $decoded;
}

function ltiBase64UrlEncode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function ltiHttpJson(string $url, ?array $form = null): array
{
    if (!ltiIsHttpsUrl($url) || !function_exists('curl_init')) {
        throw new RuntimeException('LTI service URL or cURL configuration is invalid.');
    }

    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Unable to initialize the LTI service request.');
    }

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Accept: application/json']
    ];
    if ($form !== null) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = http_build_query($form, '', '&', PHP_QUERY_RFC3986);
        $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded';
    }
    curl_setopt_array($curl, $options);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if (!is_string($body)) {
        throw new RuntimeException('LTI service request failed: ' . ($curlError !== '' ? $curlError : 'no response.'));
    }
    $decoded = json_decode($body, true);
    if ($status < 200 || $status >= 300 || !is_array($decoded)) {
        throw new RuntimeException('LTI service returned an invalid response (HTTP ' . $status . ').');
    }
    return $decoded;
}

function ltiClientAccessToken(string $scope): string
{
    if (!ltiSettingsReady(true)) {
        throw new RuntimeException('LTI signing key is not configured.');
    }
    $now = time();
    $assertion = ltiSignJwt([
        'iss' => LTI_CLIENT_ID,
        'sub' => LTI_CLIENT_ID,
        'aud' => LTI_TOKEN_URL,
        'iat' => $now,
        'exp' => $now + 300,
        'jti' => bin2hex(random_bytes(16))
    ]);
    $token = ltiHttpJson(LTI_TOKEN_URL, [
        'grant_type' => 'client_credentials',
        'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
        'client_assertion' => $assertion,
        'scope' => $scope
    ]);
    $accessToken = $token['access_token'] ?? '';
    if (!is_string($accessToken) || $accessToken === '') {
        throw new RuntimeException('Canvas did not return an LTI service access token.');
    }
    return $accessToken;
}

function ltiHttpJsonWithBearer(string $url, string $accessToken, string $accept): array
{
    if (!ltiSameHttpsOrigin($url, LTI_TOKEN_URL) || !function_exists('curl_init')) {
        throw new RuntimeException('Canvas roster service URL or cURL configuration is invalid.');
    }
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Unable to initialize the Canvas roster request.');
    }
    $headers = [];
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Accept: ' . $accept
        ],
        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
            $length = strlen($line);
            $separator = strpos($line, ':');
            if ($separator !== false) {
                $name = strtolower(trim(substr($line, 0, $separator)));
                $headers[$name][] = trim(substr($line, $separator + 1));
            }
            return $length;
        }
    ]);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);
    if (!is_string($body) || $status < 200 || $status >= 300) {
        throw new RuntimeException(
            'Canvas roster service failed (HTTP ' . $status . ').'
            . ($curlError !== '' ? ' ' . $curlError : '')
        );
    }
    $data = json_decode($body, true);
    if (!is_array($data) || !is_array($data['members'] ?? null)) {
        throw new RuntimeException('Canvas returned an invalid roster response.');
    }

    $nextUrl = null;
    foreach ($headers['link'] ?? [] as $linkHeader) {
        if (!preg_match_all('/<([^>]+)>\\s*;\\s*rel="?([^";]+)"?/i', $linkHeader, $matches, PREG_SET_ORDER)) {
            continue;
        }
        foreach ($matches as $match) {
            if (strtolower(trim($match[2])) !== 'next') {
                continue;
            }
            $candidate = $match[1];
            if (str_starts_with($candidate, '/')) {
                $parts = parse_url(LTI_TOKEN_URL);
                $candidate = 'https://' . $parts['host']
                    . (isset($parts['port']) && (int) $parts['port'] !== 443 ? ':' . (int) $parts['port'] : '')
                    . $candidate;
            }
            if (!ltiSameHttpsOrigin($candidate, LTI_TOKEN_URL)) {
                throw new RuntimeException('Canvas returned an unsafe roster pagination URL.');
            }
            $nextUrl = $candidate;
        }
    }
    return ['members' => $data['members'], 'next' => $nextUrl];
}

function ltiDerLength(int $length): string
{
    if ($length < 128) {
        return chr($length);
    }
    $encoded = '';
    while ($length > 0) {
        $encoded = chr($length & 0xff) . $encoded;
        $length >>= 8;
    }
    return chr(0x80 | strlen($encoded)) . $encoded;
}

function ltiDer(string $tag, string $value): string
{
    return $tag . ltiDerLength(strlen($value)) . $value;
}

function ltiJwkToPem(array $jwk): string
{
    if (($jwk['kty'] ?? '') !== 'RSA' || !isset($jwk['n'], $jwk['e'])) {
        throw new RuntimeException('Canvas returned an unsupported signing key.');
    }
    $modulus = ltiBase64UrlDecode((string) $jwk['n']);
    $exponent = ltiBase64UrlDecode((string) $jwk['e']);
    if ($modulus === false || $exponent === false || $modulus === '' || $exponent === '') {
        throw new RuntimeException('Canvas returned a malformed signing key.');
    }

    $integer = static function (string $value): string {
        $value = ltrim($value, "\0");
        if ($value === '') {
            $value = "\0";
        } elseif ((ord($value[0]) & 0x80) !== 0) {
            $value = "\0" . $value;
        }
        return ltiDer("\x02", $value);
    };

    $rsaKey = ltiDer("\x30", $integer($modulus) . $integer($exponent));
    $algorithm = hex2bin('300d06092a864886f70d0101010500');
    if ($algorithm === false) {
        throw new RuntimeException('Unable to build a public signing key.');
    }
    $publicKey = ltiDer("\x30", $algorithm . ltiDer("\x03", "\0" . $rsaKey));
    return "-----BEGIN PUBLIC KEY-----\n"
        . chunk_split(base64_encode($publicKey), 64, "\n")
        . "-----END PUBLIC KEY-----\n";
}

function ltiVerifyJwt(string $jwt): array
{
    if (strlen($jwt) > 65536) {
        throw new RuntimeException('Canvas launch token is too large.');
    }
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        throw new RuntimeException('Canvas launch token is malformed.');
    }
    $headerJson = ltiBase64UrlDecode($parts[0]);
    $claimsJson = ltiBase64UrlDecode($parts[1]);
    $signature = ltiBase64UrlDecode($parts[2]);
    $header = $headerJson === false ? null : json_decode($headerJson, true);
    $claims = $claimsJson === false ? null : json_decode($claimsJson, true);
    if (
        !is_array($header)
        || !is_array($claims)
        || !is_string($signature)
        || ($header['alg'] ?? '') !== 'RS256'
        || (isset($header['typ']) && $header['typ'] !== 'JWT')
        || !is_string($header['kid'] ?? null)
    ) {
        throw new RuntimeException('Canvas launch token has unsupported signing data.');
    }

    static $keys = null;
    if (!is_array($keys)) {
        $jwks = ltiHttpJson(LTI_JWKS_URL);
        $keys = is_array($jwks['keys'] ?? null) ? $jwks['keys'] : [];
    }
    $selectedKey = null;
    foreach ($keys as $key) {
        if (is_array($key) && ($key['kid'] ?? '') === $header['kid']) {
            $selectedKey = $key;
            break;
        }
    }
    if (!$selectedKey) {
        throw new RuntimeException('Canvas launch signing key was not found.');
    }

    $publicKey = openssl_pkey_get_public(ltiJwkToPem($selectedKey));
    if ($publicKey === false || openssl_verify($parts[0] . '.' . $parts[1], $signature, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
        throw new RuntimeException('Canvas launch signature verification failed.');
    }
    return $claims;
}

function ltiValidateLaunchClaims(array $claims, string $expectedNonce): string
{
    $now = time();
    $issuer = (string) ($claims['iss'] ?? '');
    $audiences = $claims['aud'] ?? [];
    if (is_string($audiences)) {
        $audiences = [$audiences];
    }
    $validationErrors = [];
    if ($issuer !== LTI_ISSUER) {
        $validationErrors[] = 'issuer';
    }
    if (!is_array($audiences) || !in_array(LTI_CLIENT_ID, $audiences, true)) {
        $validationErrors[] = 'audience';
    } elseif (count($audiences) > 1 && ($claims['azp'] ?? '') !== LTI_CLIENT_ID) {
        $validationErrors[] = 'authorized_party';
    }
    if ((int) ($claims['exp'] ?? 0) <= $now) {
        $validationErrors[] = 'expiration';
    }
    if (isset($claims['nbf']) && (int) $claims['nbf'] > $now + 60) {
        $validationErrors[] = 'not_before';
    }
    if ((int) ($claims['iat'] ?? 0) > $now + 60) {
        $validationErrors[] = 'issued_at_future';
    } elseif ((int) ($claims['iat'] ?? 0) < $now - 600) {
        $validationErrors[] = 'issued_at_expired';
    }
    if (!is_string($claims['nonce'] ?? null) || !hash_equals($expectedNonce, $claims['nonce'])) {
        $validationErrors[] = 'nonce';
    }
    if (!is_string($claims['sub'] ?? null) || $claims['sub'] === '') {
        $validationErrors[] = 'subject';
    }
    $deploymentId = $claims[LTI_CLAIM_PREFIX . 'deployment_id'] ?? null;
    if (!is_string($deploymentId) || $deploymentId === '' || strlen($deploymentId) > 255) {
        $validationErrors[] = 'deployment_id';
    }
    if (($claims[LTI_CLAIM_PREFIX . 'version'] ?? '') !== '1.3.0') {
        $validationErrors[] = 'version';
    }
    if ($validationErrors !== []) {
        throw new RuntimeException('Canvas launch validation failed (' . implode(', ', $validationErrors) . ').');
    }

    $messageType = $claims[LTI_CLAIM_PREFIX . 'message_type'] ?? '';
    if (!in_array($messageType, ['LtiResourceLinkRequest', 'LtiDeepLinkingRequest'], true)) {
        throw new RuntimeException('This Canvas launch type is not supported.');
    }
    return (string) $messageType;
}

function ltiUserRole(array $claims): string
{
    $roles = $claims[LTI_CLAIM_PREFIX . 'roles'] ?? [];
    if (!is_array($roles)) {
        throw new RuntimeException('Canvas did not provide valid user roles.');
    }
    foreach ($roles as $role) {
        if (!is_string($role)) {
            continue;
        }
        if (preg_match('~(?:^|[/#])instructor$~i', $role)) {
            return 'teacher';
        }
        if (preg_match('~(?:^|[/#])learner$~i', $role)) {
            return 'student';
        }
    }
    throw new RuntimeException('This Canvas role is not enabled for InsQuire.');
}

function ltiGetOrCreateUser(PDO $pdo, array $claims, string $role): int
{
    $issuer = (string) $claims['iss'];
    $subject = (string) ($claims['sub'] ?? '');
    if ($subject === '' || strlen($subject) > 255) {
        throw new RuntimeException('Canvas did not provide a valid user identifier.');
    }
    $issuerHash = hash('sha256', $issuer);
    $subjectHash = hash('sha256', $subject);
    $identityStmt = $pdo->prepare(
        'SELECT li.user_id, u.role, u.status
         FROM lti_identities li
         INNER JOIN users u ON u.id = li.user_id
         WHERE li.issuer_hash = ? AND li.subject_hash = ?
         LIMIT 1'
    );
    $identityStmt->execute([$issuerHash, $subjectHash]);
    $identity = $identityStmt->fetch(PDO::FETCH_ASSOC);
    if ($identity) {
        if ($identity['status'] !== 'active' || $identity['role'] === 'admin') {
            throw new RuntimeException('This InsQuire account is unavailable for Canvas sign-in.');
        }
        if ($identity['role'] !== $role) {
            $update = $pdo->prepare('UPDATE users SET role = ? WHERE id = ? AND role <> "admin"');
            $update->execute([$role, (int) $identity['user_id']]);
        }
        return (int) $identity['user_id'];
    }

    $name = trim((string) ($claims['name'] ?? ''));
    if ($name === '') {
        $name = trim((string) ($claims['given_name'] ?? '') . ' ' . (string) ($claims['family_name'] ?? ''));
    }
    if ($name === '') {
        $name = 'Canvas learner';
    }
    $name = mb_substr($name, 0, 120);
    $syntheticEmail = 'lti+' . hash('sha256', $issuer . "\0" . $subject) . '@lti.insquire.invalid';

    try {
        $pdo->beginTransaction();
        $insertUser = $pdo->prepare(
            'INSERT INTO users (name, email, password_hash, account_scope, role, status)
             VALUES (?, ?, ?, "education", ?, "active")'
        );
        $insertUser->execute([
            $name,
            $syntheticEmail,
            password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
            $role
        ]);
        $userId = (int) $pdo->lastInsertId();

        $insertIdentity = $pdo->prepare(
            'INSERT INTO lti_identities (user_id, issuer, issuer_hash, subject, subject_hash)
             VALUES (?, ?, ?, ?, ?)'
        );
        $insertIdentity->execute([$userId, $issuer, $issuerHash, $subject, $subjectHash]);
        $pdo->commit();
        return $userId;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $identityStmt->execute([$issuerHash, $subjectHash]);
        $identity = $identityStmt->fetch(PDO::FETCH_ASSOC);
        if ($identity && $identity['status'] === 'active' && $identity['role'] !== 'admin') {
            return (int) $identity['user_id'];
        }
        throw $exception;
    }
}

function ltiCourseKey(string $issuer, string $deploymentId, string $contextId): string
{
    return hash('sha256', $issuer . "\0" . $deploymentId . "\0" . $contextId);
}

function ltiCanvasCourseIdFromClaims(array $claims): ?string
{
    $custom = $claims[LTI_CLAIM_PREFIX . 'custom'] ?? null;
    if (!is_array($custom)) {
        return null;
    }
    $courseId = $custom['custom_insquire_canvas_course_id']
        ?? $custom['insquire_canvas_course_id']
        ?? $custom['custom_canvas_course_id']
        ?? null;
    if ($courseId === null || $courseId === '') {
        return null;
    }
    if (!is_string($courseId) && !is_int($courseId)) {
        throw new RuntimeException('Canvas provided an invalid course API identifier.');
    }
    $courseId = (string) $courseId;
    if (!preg_match('/\A[0-9]+\z/', $courseId) || filter_var($courseId, FILTER_VALIDATE_INT) === false) {
        throw new RuntimeException('Canvas provided an invalid course API identifier.');
    }
    return $courseId;
}

function ltiResourceKey(string $issuer, string $deploymentId, string $contextId, string $resourceLinkId): string
{
    return hash('sha256', $issuer . "\0" . $deploymentId . "\0" . $contextId . "\0" . $resourceLinkId);
}

function ltiResolveCourse(PDO $pdo, array $claims, int $userId, string $role): ?int
{
    $context = $claims[LTI_CLAIM_PREFIX . 'context'] ?? null;
    if (!is_array($context) || !is_string($context['id'] ?? null) || $context['id'] === '') {
        return null;
    }
    $contextId = $context['id'];
    if (strlen($contextId) > 255) {
        throw new RuntimeException('Canvas course identifier is too long.');
    }
    $canvasCourseId = ltiCanvasCourseIdFromClaims($claims);
    $issuer = (string) $claims['iss'];
    $deploymentId = (string) $claims[LTI_CLAIM_PREFIX . 'deployment_id'];
    $key = ltiCourseKey($issuer, $deploymentId, $contextId);
    $courseStmt = $pdo->prepare(
        'SELECT class_id, teacher_user_id, canvas_course_id
         FROM lti_courses
         WHERE course_key = ?
         LIMIT 1'
    );
    $courseStmt->execute([$key]);
    $course = $courseStmt->fetch(PDO::FETCH_ASSOC);

    if (!$course && $role === 'teacher') {
        $className = trim((string) ($context['title'] ?? ''));
        $className = $className !== '' ? mb_substr($className, 0, 150) : 'Canvas course';
        $joinCode = generateJoinCode($pdo);
        $pdo->beginTransaction();
        try {
            $insertClass = $pdo->prepare(
                'INSERT INTO classes (teacher_id, name, description, join_code)
                 VALUES (?, ?, ?, ?)'
            );
            $insertClass->execute([$userId, $className, 'Linked from Canvas LMS.', $joinCode]);
            $classId = (int) $pdo->lastInsertId();
            $insertCourse = $pdo->prepare(
                'INSERT INTO lti_courses
                 (course_key, issuer, deployment_id, context_id, canvas_course_id, class_id, teacher_user_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $insertCourse->execute([
                $key,
                $issuer,
                $deploymentId,
                $contextId,
                $canvasCourseId,
                $classId,
                $userId
            ]);
            $pdo->commit();
            return $classId;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $courseStmt->execute([$key]);
            $course = $courseStmt->fetch(PDO::FETCH_ASSOC);
            if (!$course) {
                throw $exception;
            }
        }
    }

    if (!$course) {
        throw new RuntimeException('Ask the course teacher to open this Canvas course in InsQuire first.');
    }
    if ($role === 'teacher' && (int) $course['teacher_user_id'] !== $userId) {
        throw new RuntimeException('This Canvas course is already connected to another InsQuire teacher.');
    }
    if ($canvasCourseId !== null && $canvasCourseId !== (string) ($course['canvas_course_id'] ?? '')) {
        $updateCourseId = $pdo->prepare('UPDATE lti_courses SET canvas_course_id = ? WHERE course_key = ?');
        $updateCourseId->execute([$canvasCourseId, $key]);
    }
    $classId = (int) $course['class_id'];
    if ($role === 'student') {
        $member = $pdo->prepare('INSERT IGNORE INTO class_members (class_id, user_id) VALUES (?, ?)');
        $member->execute([$classId, $userId]);
    }
    return $classId;
}

function ltiSyncCourseRoster(PDO $pdo, array $claims, int $classId): int
{
    $nrps = $claims[LTI_NRPS_CLAIM_PREFIX . 'namesroleservice'] ?? null;
    $rosterUrl = is_array($nrps) ? ($nrps['context_memberships_url'] ?? null) : null;
    if (!is_string($rosterUrl) || !ltiSameHttpsOrigin($rosterUrl, LTI_TOKEN_URL)) {
        throw new RuntimeException('Canvas Names and Role Provisioning Services is not enabled for this course.');
    }
    $context = $claims[LTI_CLAIM_PREFIX . 'context'] ?? null;
    if (!is_array($context) || !is_string($context['id'] ?? null)) {
        throw new RuntimeException('Canvas roster context is unavailable.');
    }
    $courseKey = ltiCourseKey(
        (string) $claims['iss'],
        (string) $claims[LTI_CLAIM_PREFIX . 'deployment_id'],
        $context['id']
    );
    $courseStmt = $pdo->prepare('SELECT roster_synced_at FROM lti_courses WHERE course_key = ? LIMIT 1');
    $courseStmt->execute([$courseKey]);
    $lastSyncedAt = $courseStmt->fetchColumn();
    if (is_string($lastSyncedAt) && strtotime($lastSyncedAt) > time() - 900) {
        return 0;
    }

    $accessToken = ltiClientAccessToken('https://purl.imsglobal.org/spec/lti-nrps/scope/contextmembership.readonly');
    $nextUrl = $rosterUrl;
    $seenPages = [];
    $pageCount = 0;
    $enrolledCount = 0;
    while ($nextUrl !== null) {
        if (isset($seenPages[$nextUrl]) || ++$pageCount > 20) {
            throw new RuntimeException('Canvas roster pagination did not complete safely.');
        }
        $seenPages[$nextUrl] = true;
        $page = ltiHttpJsonWithBearer(
            $nextUrl,
            $accessToken,
            'application/vnd.ims.lti-nrps.v2.membershipcontainer+json'
        );
        foreach ($page['members'] as $member) {
            if (!is_array($member) || !is_string($member['user_id'] ?? null) || $member['user_id'] === '') {
                continue;
            }
            if (isset($member['status']) && strcasecmp((string) $member['status'], 'Active') !== 0) {
                continue;
            }
            $roles = $member['roles'] ?? null;
            if (!is_array($roles)) {
                continue;
            }
            $identityClaims = [
                'iss' => LTI_ISSUER,
                'sub' => $member['user_id'],
                'name' => is_string($member['name'] ?? null) ? $member['name'] : ''
            ];
            $identityClaims[LTI_CLAIM_PREFIX . 'roles'] = $roles;
            try {
                if (ltiUserRole($identityClaims) !== 'student') {
                    continue;
                }
            } catch (RuntimeException) {
                continue;
            }
            $studentId = ltiGetOrCreateUser($pdo, $identityClaims, 'student');
            $memberStmt = $pdo->prepare('INSERT IGNORE INTO class_members (class_id, user_id) VALUES (?, ?)');
            $memberStmt->execute([$classId, $studentId]);
            $enrolledCount += $memberStmt->rowCount();
            if ($enrolledCount > 10000) {
                throw new RuntimeException('Canvas roster is larger than the supported synchronization limit.');
            }
        }
        $nextUrl = $page['next'];
    }

    $markSynced = $pdo->prepare('UPDATE lti_courses SET roster_synced_at = NOW() WHERE course_key = ?');
    $markSynced->execute([$courseKey]);
    return $enrolledCount;
}

function ltiSaveAssignmentLink(PDO $pdo, array $claims, int $classId): ?int
{
    $resourceLink = $claims[LTI_CLAIM_PREFIX . 'resource_link'] ?? null;
    if (!is_array($resourceLink) || !is_string($resourceLink['id'] ?? null) || $resourceLink['id'] === '') {
        return null;
    }
    $resourceLinkId = $resourceLink['id'];
    if (strlen($resourceLinkId) > 255) {
        throw new RuntimeException('Canvas assignment identifier is too long.');
    }
    $issuer = (string) $claims['iss'];
    $deploymentId = (string) $claims[LTI_CLAIM_PREFIX . 'deployment_id'];
    $resourceKey = ltiResourceKey($issuer, $deploymentId, (string) ($claims[LTI_CLAIM_PREFIX . 'context']['id'] ?? ''), $resourceLinkId);
    $custom = $claims[LTI_CLAIM_PREFIX . 'custom'] ?? [];
    $assignmentId = is_array($custom)
        ? filter_var(
            $custom['insquire_assignment_id'] ?? $custom['custom_insquire_assignment_id'] ?? null,
            FILTER_VALIDATE_INT
        )
        : false;
    if (!$assignmentId) {
        $existing = $pdo->prepare('SELECT assignment_id FROM lti_assignment_links WHERE resource_key = ? LIMIT 1');
        $existing->execute([$resourceKey]);
        $assignmentId = $existing->fetchColumn();
    }
    if (!$assignmentId) {
        return null;
    }

    $assignmentStmt = $pdo->prepare('SELECT id FROM assignments WHERE id = ? AND class_id = ? LIMIT 1');
    $assignmentStmt->execute([(int) $assignmentId, $classId]);
    if (!$assignmentStmt->fetchColumn()) {
        throw new RuntimeException('The Canvas assignment is not linked to this course.');
    }

    $ags = $claims[LTI_AGS_CLAIM_PREFIX . 'endpoint'] ?? [];
    $lineitemUrl = is_array($ags) && is_string($ags['lineitem'] ?? null) ? $ags['lineitem'] : '';
    if ($lineitemUrl !== '' && !ltiSameHttpsOrigin($lineitemUrl, LTI_TOKEN_URL)) {
        throw new RuntimeException('Canvas provided an invalid gradebook endpoint.');
    }
    $scoreMaximum = is_array($ags) && is_numeric($ags['scoreMaximum'] ?? null)
        ? (float) $ags['scoreMaximum']
        : 100.0;
    if ($scoreMaximum <= 0 || $scoreMaximum > 100000) {
        $scoreMaximum = 100.0;
    }

    $save = $pdo->prepare(
        'INSERT INTO lti_assignment_links
         (resource_key, issuer, deployment_id, context_id, resource_link_id, assignment_id, lineitem_url, score_maximum)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            assignment_id = VALUES(assignment_id),
            lineitem_url = VALUES(lineitem_url),
            score_maximum = VALUES(score_maximum)'
    );
    $save->execute([
        $resourceKey,
        $issuer,
        $deploymentId,
        (string) $claims[LTI_CLAIM_PREFIX . 'context']['id'],
        $resourceLinkId,
        (int) $assignmentId,
        $lineitemUrl !== '' ? $lineitemUrl : null,
        $scoreMaximum
    ]);
    return (int) $assignmentId;
}

function ltiSignJwt(array $claims): string
{
    if (!ltiSettingsReady(true)) {
        throw new RuntimeException('LTI signing key is not configured.');
    }
    $header = ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => LTI_KEY_ID];
    $encodedHeader = ltiBase64UrlEncode((string) json_encode($header, JSON_UNESCAPED_SLASHES));
    $encodedClaims = ltiBase64UrlEncode((string) json_encode($claims, JSON_UNESCAPED_SLASHES));
    $signingInput = $encodedHeader . '.' . $encodedClaims;
    if (!openssl_sign($signingInput, $signature, LTI_PRIVATE_KEY, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Unable to sign the LTI response.');
    }
    return $signingInput . '.' . ltiBase64UrlEncode($signature);
}

function ltiPostGradeForSubmission(PDO $pdo, int $submissionId): ?string
{
    $stmt = $pdo->prepare(
        'SELECT s.grade, s.teacher_feedback, s.student_id, a.id AS assignment_id, c.id AS class_id,
                li.subject, link.issuer, link.lineitem_url, link.score_maximum
         FROM submissions s
         INNER JOIN assignments a ON a.id = s.assignment_id
         INNER JOIN classes c ON c.id = a.class_id
         INNER JOIN lti_assignment_links link ON link.assignment_id = a.id
         INNER JOIN lti_courses course ON course.class_id = c.id
              AND course.issuer = link.issuer
              AND course.deployment_id = link.deployment_id
              AND course.context_id = link.context_id
         LEFT JOIN lti_identities li ON li.user_id = s.student_id
              AND li.issuer = link.issuer
         WHERE s.id = ?
         ORDER BY link.id DESC
         LIMIT 1'
    );
    $stmt->execute([$submissionId]);
    $grade = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$grade || $grade['grade'] === null) {
        return null;
    }
    if (!$grade['subject']) {
        throw new RuntimeException('The student must open this assignment from Canvas before grade passback can work.');
    }
    if (!$grade['lineitem_url']) {
        throw new RuntimeException('Canvas did not provide an assignment gradebook endpoint.');
    }
    if (!ltiSettingsReady(true) || !ltiSameHttpsOrigin((string) $grade['lineitem_url'], LTI_TOKEN_URL)) {
        throw new RuntimeException('Canvas grade passback is not configured correctly.');
    }

    $now = time();
    $assertion = ltiSignJwt([
        'iss' => LTI_CLIENT_ID,
        'sub' => LTI_CLIENT_ID,
        'aud' => LTI_TOKEN_URL,
        'iat' => $now,
        'exp' => $now + 300,
        'jti' => bin2hex(random_bytes(16))
    ]);
    $token = ltiHttpJson(LTI_TOKEN_URL, [
        'grant_type' => 'client_credentials',
        'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
        'client_assertion' => $assertion,
        'scope' => 'https://purl.imsglobal.org/spec/lti-ags/scope/score'
    ]);
    $accessToken = $token['access_token'] ?? '';
    if (!is_string($accessToken) || $accessToken === '') {
        throw new RuntimeException('Canvas did not return a grade passback access token.');
    }

    $scoresUrl = ltiScoresUrl((string) $grade['lineitem_url']);
    $feedbackParts = [];
    $teacherFeedback = trim((string) ($grade['teacher_feedback'] ?? ''));
    if ($teacherFeedback !== '') {
        $feedbackParts[] = "Teacher feedback:\n" . $teacherFeedback;
    }
    $comments = $pdo->prepare(
        'SELECT anchor_text, body
         FROM submission_comments
         WHERE submission_id = ?
         ORDER BY id'
    );
    $comments->execute([$submissionId]);
    foreach ($comments->fetchAll(PDO::FETCH_ASSOC) as $comment) {
        $feedbackParts[] = 'Comment on "' . trim((string) $comment['anchor_text']) . '": '
            . trim((string) $comment['body']);
    }
    $score = [
        'userId' => (string) $grade['subject'],
        'scoreGiven' => (float) $grade['grade'] * (float) $grade['score_maximum'] / 100,
        'scoreMaximum' => (float) $grade['score_maximum'],
        'comment' => implode("\n\n", $feedbackParts),
        'activityProgress' => 'Completed',
        'gradingProgress' => 'FullyGraded',
        'timestamp' => gmdate('Y-m-d\TH:i:s\Z')
    ];

    $curl = curl_init($scoresUrl);
    if ($curl === false) {
        throw new RuntimeException('Unable to initialize the Canvas score submission.');
    }
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($score, JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/vnd.ims.lis.v1.score+json',
            'Accept: application/json'
        ]
    ]);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);
    if (!is_string($body) || $status < 200 || $status >= 300) {
        throw new RuntimeException(
            'Canvas grade passback failed (HTTP ' . $status . ').'
            . ($curlError !== '' ? ' ' . $curlError : '')
        );
    }
    return $feedbackParts !== []
        ? 'Grade and teacher feedback sent to Canvas.'
        : 'Grade sent to Canvas.';
}
