<?php

require_once __DIR__ . '/lti.php';

function canvasOAuthReady(): bool
{
    return CANVAS_OAUTH_CLIENT_ID !== ''
        && CANVAS_OAUTH_CLIENT_SECRET !== ''
        && ltiIsHttpsUrl(CANVAS_API_BASE_URL)
        && ltiIsHttpsUrl(CANVAS_OAUTH_REDIRECT_URI)
        && CANVAS_OAUTH_REDIRECT_URI === EDUCATION_APP_URL . '/canvas-oauth-callback.php';
}

function canvasOauthStartUrl(string $state, bool $includeAssignmentRead = false): string
{
    if (!canvasOAuthReady()) {
        throw new RuntimeException('Canvas OAuth is not configured on this server.');
    }

    $scopes = [
        'url:GET|/api/v1/users/:id',
        'url:GET|/api/v1/courses/:course_id/modules',
        'url:POST|/api/v1/courses/:course_id/assignments',
        'url:POST|/api/v1/courses/:course_id/modules/:module_id/items'
    ];
    if ($includeAssignmentRead) {
        $scopes[] = 'url:GET|/api/v1/courses/:course_id/assignments';
    }
    return CANVAS_API_BASE_URL . '/login/oauth2/auth?' . http_build_query([
        'client_id' => CANVAS_OAUTH_CLIENT_ID,
        'response_type' => 'code',
        'redirect_uri' => CANVAS_OAUTH_REDIRECT_URI,
        'state' => $state,
        'scope' => implode(' ', $scopes)
    ], '', '&', PHP_QUERY_RFC3986);
}

function canvasOAuthTokenRequest(array $form): array
{
    if (!canvasOAuthReady() || !function_exists('curl_init')) {
        throw new RuntimeException('Canvas OAuth is not configured on this server.');
    }
    $url = CANVAS_API_BASE_URL . '/login/oauth2/token';
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Unable to initialize the Canvas authorization request.');
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
        CURLOPT_POSTFIELDS => http_build_query($form, '', '&', PHP_QUERY_RFC3986),
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded'
        ]
    ]);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);
    if (!is_string($body)) {
        throw new RuntimeException('Canvas OAuth request failed: ' . ($curlError !== '' ? $curlError : 'no response.'));
    }
    $data = json_decode($body, true);
    if ($status < 200 || $status >= 300 || !is_array($data)) {
        throw new RuntimeException('Canvas OAuth returned an invalid response (HTTP ' . $status . ').');
    }
    return $data;
}

function canvasFormFields(array $values, string $prefix = ''): array
{
    $fields = [];
    foreach ($values as $key => $value) {
        $name = $prefix === '' ? (string) $key : $prefix . '[' . $key . ']';
        if (is_array($value)) {
            if (array_is_list($value)) {
                foreach ($value as $index => $item) {
                    $fields[$name . '[' . $index . ']'] = (string) $item;
                }
            } else {
                $fields = array_merge($fields, canvasFormFields($value, $name));
            }
        } elseif (is_bool($value)) {
            $fields[$name] = $value ? 'true' : 'false';
        } elseif ($value !== null) {
            $fields[$name] = (string) $value;
        }
    }
    return $fields;
}

function canvasApiErrorDetails(string $body): string
{
    $response = json_decode($body, true);
    if (!is_array($response)) {
        return '';
    }
    $messages = [];
    $collect = static function (mixed $value) use (&$collect, &$messages): void {
        if (is_string($value) && trim($value) !== '') {
            $messages[] = trim($value);
        } elseif (is_array($value)) {
            foreach ($value as $item) {
                $collect($item);
            }
        }
    };
    $collect($response['errors'] ?? $response['error'] ?? $response['message'] ?? null);
    $messages = array_values(array_unique($messages));
    if ($messages === []) {
        return '';
    }
    return ' ' . mb_substr(implode(' ', $messages), 0, 400);
}

function canvasApiRequest(string $method, string $path, string $accessToken, array $payload = []): array
{
    if (
        !ltiIsHttpsUrl(CANVAS_API_BASE_URL)
        || !str_starts_with($path, '/api/v1/')
        || str_contains($path, '..')
        || !function_exists('curl_init')
    ) {
        throw new RuntimeException('Canvas API request configuration is invalid.');
    }
    $url = CANVAS_API_BASE_URL . $path;
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Unable to initialize the Canvas API request.');
    }
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json'
        ]
    ];
    if (strtoupper($method) === 'GET') {
        if ($payload !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?')
                . http_build_query($payload, '', '&', PHP_QUERY_RFC3986);
            curl_setopt($curl, CURLOPT_URL, $url);
        }
    } else {
        $body = http_build_query(canvasFormFields($payload), '', '&', PHP_QUERY_RFC3986);
        $options[CURLOPT_POSTFIELDS] = $body;
        $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded';
    }
    curl_setopt_array($curl, $options);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);
    if (!is_string($body)) {
        throw new RuntimeException('Canvas API request failed: ' . ($curlError !== '' ? $curlError : 'no response.'));
    }
    $data = json_decode($body, true);
    if ($status < 200 || $status >= 300 || !is_array($data)) {
        throw new RuntimeException(
            'Canvas API request failed (HTTP ' . $status . ').'
            . canvasApiErrorDetails($body)
        );
    }
    return $data;
}

function canvasOAuthAccessToken(): string
{
    $teacherId = (int) ($_SESSION['user_id'] ?? 0);
    $persistentConnection = $teacherId > 0;
    $oauth = null;
    if ($teacherId > 0) {
        global $pdo;
        if (isset($pdo) && $pdo instanceof PDO) {
            $connectionStmt = $pdo->prepare(
                'SELECT access_token, refresh_token, expires_at, canvas_user_id, assignment_read_enabled
                 FROM canvas_oauth_connections
                 WHERE teacher_id = ?
                 LIMIT 1'
            );
            $connectionStmt->execute([$teacherId]);
            $connection = $connectionStmt->fetch(PDO::FETCH_ASSOC);
            if ($connection) {
                $oauth = [
                    'access_token' => (string) $connection['access_token'],
                    'refresh_token' => (string) ($connection['refresh_token'] ?? ''),
                    'expires_at' => strtotime((string) $connection['expires_at']) ?: 0,
                    'canvas_user_id' => (string) $connection['canvas_user_id'],
                    'assignment_read_enabled' => (bool) $connection['assignment_read_enabled']
                ];
                $persistentConnection = true;
            }
        }
    }
    if (!$oauth) {
        $oauth = $_SESSION['canvas_oauth'] ?? null;
        $persistentConnection = false;
    }
    if (!is_array($oauth) || !is_string($oauth['access_token'] ?? null) || $oauth['access_token'] === '') {
        throw new RuntimeException('Connect your Canvas account to publish assignments.');
    }
    if ((int) ($oauth['expires_at'] ?? 0) > time() + 60) {
        return $oauth['access_token'];
    }
    $refreshToken = $oauth['refresh_token'] ?? '';
    if (!is_string($refreshToken) || $refreshToken === '') {
        unset($_SESSION['canvas_oauth']);
        if ($persistentConnection) {
            global $pdo;
            $pdo->prepare('DELETE FROM canvas_oauth_connections WHERE teacher_id = ?')->execute([$teacherId]);
        }
        throw new RuntimeException('Your Canvas connection expired. Connect your account again.');
    }
    try {
        $token = canvasOAuthTokenRequest([
            'grant_type' => 'refresh_token',
            'client_id' => CANVAS_OAUTH_CLIENT_ID,
            'client_secret' => CANVAS_OAUTH_CLIENT_SECRET,
            'refresh_token' => $refreshToken
        ]);
    } catch (Throwable $exception) {
        unset($_SESSION['canvas_oauth']);
        if ($persistentConnection) {
            global $pdo;
            $pdo->prepare('DELETE FROM canvas_oauth_connections WHERE teacher_id = ?')->execute([$teacherId]);
        }
        throw new RuntimeException('Your Canvas connection expired. Connect your account again.', 0, $exception);
    }
    if (!is_string($token['access_token'] ?? null) || $token['access_token'] === '') {
        unset($_SESSION['canvas_oauth']);
        if ($persistentConnection) {
            global $pdo;
            $pdo->prepare('DELETE FROM canvas_oauth_connections WHERE teacher_id = ?')->execute([$teacherId]);
        }
        throw new RuntimeException('Canvas did not refresh the authorization. Connect your account again.');
    }
    $_SESSION['canvas_oauth'] = [
        'access_token' => $token['access_token'],
        'refresh_token' => is_string($token['refresh_token'] ?? null) ? $token['refresh_token'] : $refreshToken,
        'expires_at' => time() + max(60, (int) ($token['expires_in'] ?? 3600)),
        'canvas_user_id' => (string) ($oauth['canvas_user_id'] ?? ''),
        'assignment_read_enabled' => !empty($oauth['assignment_read_enabled'])
    ];
    if ($persistentConnection) {
        global $pdo;
        $updatedToken = $token['access_token'];
        $updatedRefreshToken = is_string($token['refresh_token'] ?? null) ? $token['refresh_token'] : $refreshToken;
        $expiresAt = gmdate('Y-m-d H:i:s', time() + max(60, (int) ($token['expires_in'] ?? 3600)));
        $saveStmt = $pdo->prepare(
            'UPDATE canvas_oauth_connections
             SET access_token = ?, refresh_token = ?, expires_at = ?
             WHERE teacher_id = ?'
        );
        $saveStmt->execute([$updatedToken, $updatedRefreshToken, $expiresAt, $teacherId]);
    }
    return $token['access_token'];
}

function canvasTeacherHasLinkedCourses(PDO $pdo, int $teacherId): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1
         FROM lti_courses
         WHERE teacher_user_id = ?
           AND issuer = ?
         LIMIT 1'
    );
    $stmt->execute([$teacherId, LTI_ISSUER]);
    return (bool) $stmt->fetchColumn();
}

function canvasLinkedCourses(PDO $pdo, int $teacherId): array
{
    $stmt = $pdo->prepare(
        'SELECT c.id AS class_id, c.name AS class_name, c.institution_id,
                lc.course_key, lc.context_id, lc.canvas_course_id, lc.issuer, lc.deployment_id
         FROM lti_courses lc
         INNER JOIN classes c ON c.id = lc.class_id
         WHERE lc.teacher_user_id = ?
           AND c.teacher_id = ?
           AND lc.issuer = ?
         ORDER BY c.name, c.id'
    );
    $stmt->execute([$teacherId, $teacherId, LTI_ISSUER]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function canvasSyncAndMergeClasses(PDO $pdo, int $teacherId, int $sourceClassId, int $destinationClassId): array
{
    if ($sourceClassId < 1 || $destinationClassId < 1 || $sourceClassId === $destinationClassId) {
        throw new RuntimeException('Choose a Canvas-linked class and a different InsQuire class to keep.');
    }

    $connectionStmt = $pdo->prepare(
        'SELECT assignment_read_enabled
         FROM canvas_oauth_connections
         WHERE teacher_id = ?
         LIMIT 1'
    );
    $connectionStmt->execute([$teacherId]);
    if (!(bool) $connectionStmt->fetchColumn()) {
        throw new RuntimeException('Connect Canvas with assignment import enabled before syncing and linking classes.');
    }

    $preflightStmt = $pdo->prepare(
        'SELECT id, teacher_id, institution_id
         FROM classes
         WHERE id IN (?, ?)'
    );
    $preflightStmt->execute([$sourceClassId, $destinationClassId]);
    $preflightClasses = $preflightStmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($preflightClasses) !== 2) {
        throw new RuntimeException('One or both selected classes could not be found.');
    }
    $preflightById = [];
    foreach ($preflightClasses as $class) {
        if ((int) $class['teacher_id'] !== $teacherId) {
            throw new RuntimeException('You can link only classes that you own.');
        }
        $preflightById[(int) $class['id']] = $class;
    }
    if (
        (string) ($preflightById[$sourceClassId]['institution_id'] ?? '')
        !== (string) ($preflightById[$destinationClassId]['institution_id'] ?? '')
    ) {
        throw new RuntimeException('Classes must belong to the same institution to be linked.');
    }
    $institutionId = $preflightById[$sourceClassId]['institution_id'];
    if ($institutionId !== null) {
        $membershipStmt = $pdo->prepare(
            'SELECT 1
             FROM institution_members
             WHERE institution_id = ? AND user_id = ? AND role IN ("admin", "teacher")
             LIMIT 1'
        );
        $membershipStmt->execute([(int) $institutionId, $teacherId]);
        if (!$membershipStmt->fetchColumn()) {
            throw new RuntimeException('You no longer have permission to manage classes in this institution.');
        }
    }

    $courseStmt = $pdo->prepare(
        'SELECT lc.course_key, lc.issuer, lc.deployment_id, lc.context_id,
                lc.canvas_course_id, lc.class_id, lc.teacher_user_id
         FROM lti_courses lc
         INNER JOIN classes c ON c.id = lc.class_id
         WHERE lc.class_id = ? AND lc.teacher_user_id = ? AND c.teacher_id = ?
         ORDER BY lc.id'
    );
    $courseStmt->execute([$sourceClassId, $teacherId, $teacherId]);
    $canvasCourses = $courseStmt->fetchAll(PDO::FETCH_ASSOC);
    if ($canvasCourses === []) {
        throw new RuntimeException('Choose a class linked to a Canvas course as the source.');
    }
    foreach ($canvasCourses as $canvasCourse) {
        if (empty($canvasCourse['canvas_course_id'])) {
            throw new RuntimeException(
                'A linked Canvas course has no numeric course ID. Add the custom field '
                . 'insquire_canvas_course_id=$Canvas.course.id to the Canvas Developer Key and relaunch.'
            );
        }
    }

    $accessToken = canvasOAuthAccessToken();
    $assignmentsByCourse = [];
    foreach ($canvasCourses as $canvasCourse) {
        $canvasAssignments = canvasListAssignments($accessToken, (string) $canvasCourse['canvas_course_id']);
        foreach ($canvasAssignments as $canvasAssignment) {
            if (
                !is_array($canvasAssignment)
                || !filter_var($canvasAssignment['id'] ?? null, FILTER_VALIDATE_INT)
                || trim((string) ($canvasAssignment['name'] ?? '')) === ''
            ) {
                throw new RuntimeException('Canvas returned an assignment without a valid ID or title.');
            }
        }
        $assignmentsByCourse[] = [$canvasCourse, $canvasAssignments];
    }

    $syncedCreated = 0;
    $syncedUpdated = 0;
    try {
        foreach ($assignmentsByCourse as [$canvasCourse, $canvasAssignments]) {
            foreach ($canvasAssignments as $canvasAssignment) {
                if (canvasImportAssignment($pdo, $canvasCourse, $canvasAssignment)) {
                    $syncedCreated++;
                } else {
                    $syncedUpdated++;
                }
            }
        }
    } catch (Throwable $exception) {
        if ($syncedCreated + $syncedUpdated > 0) {
            throw new RuntimeException(
                $exception->getMessage()
                . ' ' . $syncedCreated . ' new and ' . $syncedUpdated
                . ' updated assignments were synced into the Canvas class; the classes were not linked. Retry to finish.',
                0,
                $exception
            );
        }
        throw $exception;
    }

    $pdo->beginTransaction();
    try {
        $classIds = [$sourceClassId, $destinationClassId];
        sort($classIds, SORT_NUMERIC);
        $classStmt = $pdo->prepare(
            'SELECT id, teacher_id, institution_id
             FROM classes
             WHERE id IN (?, ?)
             ORDER BY id
             FOR UPDATE'
        );
        $classStmt->execute($classIds);
        $lockedClasses = $classStmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($lockedClasses) !== 2) {
            throw new RuntimeException('One or both selected classes could not be found.');
        }
        foreach ($lockedClasses as $class) {
            if ((int) $class['teacher_id'] !== $teacherId) {
                throw new RuntimeException('You can link only classes that you own.');
            }
        }
        $institutionIds = array_map(
            static fn(array $class): string => (string) ($class['institution_id'] ?? ''),
            $lockedClasses
        );
        if ($institutionIds[0] !== $institutionIds[1]) {
            throw new RuntimeException('Classes must belong to the same institution to be linked.');
        }
        if ($institutionIds[0] !== '') {
            $membershipStmt->execute([(int) $institutionIds[0], $teacherId]);
            if (!$membershipStmt->fetchColumn()) {
                throw new RuntimeException('You no longer have permission to manage classes in this institution.');
            }
        }

        $canvasLinkCheck = $pdo->prepare(
            'SELECT 1 FROM lti_courses WHERE class_id = ? AND teacher_user_id = ? LIMIT 1'
        );
        $canvasLinkCheck->execute([$sourceClassId, $teacherId]);
        if (!$canvasLinkCheck->fetchColumn()) {
            throw new RuntimeException('Choose a class linked to a Canvas course as the source.');
        }

        $pdo->prepare(
            'INSERT IGNORE INTO class_members (class_id, user_id, joined_at)
             SELECT ?, user_id, joined_at FROM class_members WHERE class_id = ?'
        )->execute([$destinationClassId, $sourceClassId]);
        $pdo->prepare('DELETE FROM class_members WHERE class_id = ?')->execute([$sourceClassId]);

        $mergeLessonProgress = $pdo->prepare(
            'INSERT INTO lesson_progress
                (lesson_assignment_id, student_id, response, score, status, completed_at, updated_at)
             SELECT ?, source_progress.student_id, source_progress.response, source_progress.score,
                    source_progress.status, source_progress.completed_at, source_progress.updated_at
             FROM lesson_progress source_progress
             WHERE source_progress.lesson_assignment_id = ?
             ON DUPLICATE KEY UPDATE
                response = IF(
                    (VALUES(status) = "completed" AND status <> "completed")
                    OR (VALUES(updated_at) > updated_at
                        AND (VALUES(status) = status OR status <> "completed")),
                    VALUES(response), response
                ),
                score = IF(
                    (VALUES(status) = "completed" AND status <> "completed")
                    OR (VALUES(updated_at) > updated_at
                        AND (VALUES(status) = status OR status <> "completed")),
                    VALUES(score), score
                ),
                completed_at = IF(
                    (VALUES(status) = "completed" AND status <> "completed")
                    OR (VALUES(updated_at) > updated_at
                        AND (VALUES(status) = status OR status <> "completed")),
                    VALUES(completed_at), completed_at
                ),
                status = IF(
                    (VALUES(status) = "completed" AND status <> "completed")
                    OR (VALUES(updated_at) > updated_at
                        AND (VALUES(status) = status OR status <> "completed")),
                    VALUES(status), status
                ),
                updated_at = GREATEST(updated_at, VALUES(updated_at))'
        );
        $duplicateLessons = $pdo->prepare(
            'SELECT source_assignment.id AS source_id, destination_assignment.id AS destination_id
             FROM lesson_assignments source_assignment
             INNER JOIN lesson_assignments destination_assignment
                ON destination_assignment.lesson_id = source_assignment.lesson_id
               AND destination_assignment.class_id = ?
             WHERE source_assignment.class_id = ?'
        );
        $duplicateLessons->execute([$destinationClassId, $sourceClassId]);
        foreach ($duplicateLessons->fetchAll(PDO::FETCH_ASSOC) as $lesson) {
            $mergeLessonProgress->execute([(int) $lesson['destination_id'], (int) $lesson['source_id']]);
        }
        $pdo->prepare(
            'UPDATE lesson_assignments source_assignment
             INNER JOIN lesson_assignments destination_assignment
                ON destination_assignment.lesson_id = source_assignment.lesson_id
               AND destination_assignment.class_id = ?
             SET destination_assignment.due_at = COALESCE(destination_assignment.due_at, source_assignment.due_at)
             WHERE source_assignment.class_id = ?'
        )->execute([$destinationClassId, $sourceClassId]);
        $pdo->prepare(
            'DELETE source_assignment
             FROM lesson_assignments source_assignment
             INNER JOIN lesson_assignments destination_assignment
                ON destination_assignment.lesson_id = source_assignment.lesson_id
               AND destination_assignment.class_id = ?
             WHERE source_assignment.class_id = ?'
        )->execute([$destinationClassId, $sourceClassId]);

        foreach ([
            'class_messages',
            'class_announcements',
            'assignments',
            'english_diagnostic_assignments',
            'lesson_assignments',
            'lti_courses',
            'canvas_published_assignments',
            'canvas_imported_assignments',
            'notifications'
        ] as $table) {
            $pdo->prepare("UPDATE {$table} SET class_id = ? WHERE class_id = ?")
                ->execute([$destinationClassId, $sourceClassId]);
        }
        $deleteClass = $pdo->prepare('DELETE FROM classes WHERE id = ? AND teacher_id = ?');
        $deleteClass->execute([$sourceClassId, $teacherId]);
        if ($deleteClass->rowCount() !== 1) {
            throw new RuntimeException('The temporary Canvas class could not be removed. No changes were saved.');
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    return ['created' => $syncedCreated, 'updated' => $syncedUpdated];
}

function canvasCourseApiId(string $courseId): string
{
    if (
        !preg_match('/\A(?:course_)?([0-9]+)\z/', $courseId, $matches)
        || filter_var($matches[1], FILTER_VALIDATE_INT) === false
    ) {
        throw new RuntimeException('Canvas course API ID is unavailable. Launch InsQuire from the Canvas course, then try again.');
    }
    return $matches[1];
}

function canvasListModules(string $accessToken, string $canvasCourseId): array
{
    $canvasCourseId = canvasCourseApiId($canvasCourseId);
    $modules = canvasApiRequest(
        'GET',
        '/api/v1/courses/' . rawurlencode($canvasCourseId) . '/modules',
        $accessToken,
        ['per_page' => 100]
    );
    if (!array_is_list($modules)) {
        throw new RuntimeException('Canvas returned an invalid modules response.');
    }
    return $modules;
}

function canvasListAssignments(string $accessToken, string $canvasCourseId): array
{
    $canvasCourseId = canvasCourseApiId($canvasCourseId);
    $assignments = [];
    $page = 1;
    do {
        $pageAssignments = canvasApiRequest(
            'GET',
            '/api/v1/courses/' . rawurlencode($canvasCourseId) . '/assignments',
            $accessToken,
            ['per_page' => 100, 'page' => $page]
        );
        if (!array_is_list($pageAssignments)) {
            throw new RuntimeException('Canvas returned an invalid assignments response.');
        }
        $assignments = array_merge($assignments, $pageAssignments);
        if (count($pageAssignments) < 100) {
            break;
        }
        if ($page >= 1000) {
            throw new RuntimeException('Canvas assignment pagination exceeded the supported limit.');
        }
        $page++;
    } while (true);

    if (!array_is_list($assignments)) {
        throw new RuntimeException('Canvas returned an invalid assignments response.');
    }
    return $assignments;
}

function canvasAssignmentImportKey(array $course, int $canvasAssignmentId): string
{
    return hash(
        'sha256',
        (string) $course['issuer'] . "\0"
            . (string) $course['deployment_id'] . "\0"
            . (string) $course['context_id'] . "\0"
            . (string) $course['canvas_course_id'] . "\0"
            . $canvasAssignmentId
    );
}

function canvasImportedAssignments(PDO $pdo, array $course): array
{
    $stmt = $pdo->prepare(
        'SELECT canvas_assignment_id, last_synced_at
         FROM canvas_imported_assignments
         WHERE class_id = ?
           AND issuer = ?
           AND deployment_id = ?
           AND context_id = ?
           AND canvas_course_id = ?'
    );
    $stmt->execute([
        (int) $course['class_id'],
        (string) $course['issuer'],
        (string) $course['deployment_id'],
        (string) $course['context_id'],
        (string) $course['canvas_course_id']
    ]);
    $imports = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $imports[(string) $row['canvas_assignment_id']] = (string) $row['last_synced_at'];
    }
    return $imports;
}

function canvasHtmlToText(string $html): string
{
    $html = preg_replace('~<(script|style)\b[^>]*>.*?</\1\s*>~is', '', $html) ?? $html;
    $html = preg_replace('~<\s*br\s*/?\s*>|</(?:p|div|li|h[1-6]|blockquote)\s*>~i', "\n", $html) ?? $html;
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace(["\r\n", "\r", "\xC2\xA0"], ["\n", "\n", ' '], $text);
    $text = preg_replace('/[ \t]+\n/', "\n", $text) ?? $text;
    $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
    return trim($text);
}

function canvasRubricToText(mixed $rubric): string
{
    if (!is_array($rubric)) {
        return '';
    }
    $lines = [];
    foreach ($rubric as $criterion) {
        if (!is_array($criterion)) {
            continue;
        }
        $title = canvasHtmlToText((string) ($criterion['description'] ?? ''));
        if ($title === '') {
            $title = 'Criterion';
        }
        $points = $criterion['points'] ?? null;
        $lines[] = $title . (is_numeric($points) ? ' (' . $points . ' points)' : '');
        $description = canvasHtmlToText((string) ($criterion['long_description'] ?? ''));
        if ($description !== '') {
            $lines[] = '  ' . $description;
        }
        $ratings = $criterion['ratings'] ?? [];
        if (!is_array($ratings)) {
            $ratings = [];
        }
        foreach ($ratings as $rating) {
            if (!is_array($rating)) {
                continue;
            }
            $ratingDescription = canvasHtmlToText((string) ($rating['description'] ?? ''));
            if ($ratingDescription === '') {
                continue;
            }
            $ratingPoints = $rating['points'] ?? null;
            $lines[] = '  - ' . $ratingDescription
                . (is_numeric($ratingPoints) ? ' (' . $ratingPoints . ' points)' : '');
        }
        $lines[] = '';
    }
    return trim(implode("\n", $lines));
}

function canvasImportAssignment(PDO $pdo, array $course, array $canvasAssignment): bool
{
    $canvasAssignmentId = filter_var($canvasAssignment['id'] ?? null, FILTER_VALIDATE_INT);
    $title = trim((string) ($canvasAssignment['name'] ?? ''));
    if (!$canvasAssignmentId || $title === '') {
        throw new RuntimeException('Canvas returned an assignment without a valid ID or title.');
    }
    $title = mb_substr($title, 0, 200);
    $instructions = canvasHtmlToText((string) ($canvasAssignment['description'] ?? ''));
    $instructions = mb_substr($instructions, 0, 60000);
    $rubricText = mb_substr(canvasRubricToText($canvasAssignment['rubric'] ?? null), 0, 200000);
    $dueAt = null;
    if (is_string($canvasAssignment['due_at'] ?? null) && $canvasAssignment['due_at'] !== '') {
        try {
            $dueAt = (new DateTimeImmutable($canvasAssignment['due_at']))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        } catch (Throwable $exception) {
            throw new RuntimeException('Canvas returned an invalid assignment due date.', 0, $exception);
        }
    }
    $canvasUrl = $canvasAssignment['html_url'] ?? null;
    if (
        !is_string($canvasUrl)
        || !ltiSameHttpsOrigin($canvasUrl, CANVAS_API_BASE_URL)
        || strlen($canvasUrl) > 2048
    ) {
        $canvasUrl = null;
    }
    $status = filter_var($canvasAssignment['published'] ?? false, FILTER_VALIDATE_BOOLEAN)
        ? 'published'
        : 'draft';
    $sourceKey = canvasAssignmentImportKey($course, (int) $canvasAssignmentId);
    $prompt = $instructions !== '' || $rubricText !== ''
        ? 'Open “Show directions/rubric” to view the assignment details from Canvas.'
        : 'Complete this assignment.';

    $pdo->beginTransaction();
    try {
        $find = $pdo->prepare(
            'SELECT assignment_id
             FROM canvas_imported_assignments
             WHERE source_key = ?
             LIMIT 1
             FOR UPDATE'
        );
        $find->execute([$sourceKey]);
        $assignmentId = $find->fetchColumn();
        if ($assignmentId) {
            $update = $pdo->prepare(
                'UPDATE assignments
                 SET title = ?, prompt = ?, instructions = ?, rubric_text = ?, due_at = ?, status = ?
                 WHERE id = ? AND class_id = ?'
            );
            $update->execute([
                $title,
                $prompt,
                $instructions !== '' ? $instructions : null,
                $rubricText !== '' ? $rubricText : null,
                $dueAt,
                $status,
                (int) $assignmentId,
                (int) $course['class_id']
            ]);
            if ($update->rowCount() === 0) {
                $exists = $pdo->prepare('SELECT id FROM assignments WHERE id = ? AND class_id = ? LIMIT 1');
                $exists->execute([(int) $assignmentId, (int) $course['class_id']]);
                if (!$exists->fetchColumn()) {
                    throw new RuntimeException('The imported assignment is no longer linked to this class.');
                }
            }
            $save = $pdo->prepare(
                'UPDATE canvas_imported_assignments
                 SET canvas_url = ?, canvas_updated_at = ?, last_synced_at = NOW()
                 WHERE source_key = ?'
            );
            $save->execute([
                $canvasUrl,
                !empty($canvasAssignment['updated_at'])
                    ? (new DateTimeImmutable((string) $canvasAssignment['updated_at']))
                        ->setTimezone(new DateTimeZone('UTC'))
                        ->format('Y-m-d H:i:s')
                    : null,
                $sourceKey
            ]);
            $created = false;
        } else {
            $insert = $pdo->prepare(
                'INSERT INTO assignments
                 (class_id, title, prompt, instructions, rubric_text, due_at, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                (int) $course['class_id'],
                $title,
                $prompt,
                $instructions !== '' ? $instructions : null,
                $rubricText !== '' ? $rubricText : null,
                $dueAt,
                $status
            ]);
            $assignmentId = (int) $pdo->lastInsertId();
            $save = $pdo->prepare(
                'INSERT INTO canvas_imported_assignments
                 (source_key, class_id, assignment_id, issuer, deployment_id, context_id,
                  canvas_course_id, canvas_assignment_id, canvas_url, canvas_updated_at, last_synced_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            $save->execute([
                $sourceKey,
                (int) $course['class_id'],
                $assignmentId,
                (string) $course['issuer'],
                (string) $course['deployment_id'],
                (string) $course['context_id'],
                (string) $course['canvas_course_id'],
                (int) $canvasAssignmentId,
                $canvasUrl,
                !empty($canvasAssignment['updated_at'])
                    ? (new DateTimeImmutable((string) $canvasAssignment['updated_at']))
                        ->setTimezone(new DateTimeZone('UTC'))
                        ->format('Y-m-d H:i:s')
                    : null
            ]);
            $created = true;
        }
        $pdo->commit();
        return $created;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function canvasPublishKey(array $course, int $assignmentId, int $moduleId): string
{
    return hash(
        'sha256',
        (string) $course['issuer'] . "\0"
            . (string) $course['deployment_id'] . "\0"
            . (string) $course['context_id'] . "\0"
            . $assignmentId . "\0" . $moduleId
    );
}

function canvasPublishAssignment(
    PDO $pdo,
    array $course,
    int $assignmentId,
    int $moduleId,
    array $assignment,
    string $accessToken
): bool {
    $canvasCourseId = canvasCourseApiId((string) ($course['canvas_course_id'] ?? ''));
    $publishKey = canvasPublishKey($course, $assignmentId, $moduleId);
    $stmt = $pdo->prepare('SELECT * FROM canvas_published_assignments WHERE publish_key = ? LIMIT 1');
    $stmt->execute([$publishKey]);
    $published = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($published && $published['publish_state'] === 'published') {
        return false;
    }
    if (!$published) {
        $insert = $pdo->prepare(
            'INSERT INTO canvas_published_assignments
             (publish_key, class_id, assignment_id, issuer, deployment_id, context_id, canvas_module_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([
            $publishKey,
            (int) $course['class_id'],
            $assignmentId,
            (string) $course['issuer'],
            (string) $course['deployment_id'],
            (string) $course['context_id'],
            $moduleId
        ]);
        $published = [
            'canvas_assignment_id' => null,
            'canvas_module_item_id' => null,
            'resource_link_id' => null
        ];
    }

    if (empty($published['canvas_assignment_id'])) {
        $description = '<p>' . nl2br(htmlspecialchars((string) $assignment['prompt'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>';
        if (trim((string) ($assignment['instructions'] ?? '')) !== '') {
            $description .= '<h3>Teacher instructions</h3><p>'
                . nl2br(htmlspecialchars((string) $assignment['instructions'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))
                . '</p>';
        }
        $canvasAssignment = canvasApiRequest(
            'POST',
            '/api/v1/courses/' . rawurlencode($canvasCourseId) . '/assignments',
            $accessToken,
            [
                'assignment' => [
                    'name' => (string) $assignment['title'],
                    'description' => $description,
                    'points_possible' => 100,
                    'due_at' => !empty($assignment['due_at']) ? gmdate('Y-m-d\TH:i:s\Z', strtotime((string) $assignment['due_at'])) : null,
                    'submission_types' => 'external_tool',
                    'published' => true,
                    'external_tool_tag_attributes' => [
                        'url' => EDUCATION_APP_URL . '/lti-launch.php',
                        'new_tab' => true,
                        'custom_params' => [
                            'insquire_assignment_id' => (string) $assignmentId
                        ]
                    ]
                ]
            ]
        );
        $canvasAssignmentId = filter_var($canvasAssignment['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$canvasAssignmentId) {
            throw new RuntimeException('Canvas created no assignment record. Check its API permissions.');
        }
        $resourceLinkId = $canvasAssignment['external_tool_tag_attributes']['resource_link_id'] ?? null;
        if (!is_string($resourceLinkId) || $resourceLinkId === '' || strlen($resourceLinkId) > 255) {
            $resourceLinkId = null;
        }
        $update = $pdo->prepare(
            'UPDATE canvas_published_assignments
             SET canvas_assignment_id = ?, resource_link_id = ?, publish_state = "assignment_created"
             WHERE publish_key = ?'
        );
        $update->execute([$canvasAssignmentId, $resourceLinkId, $publishKey]);
        $published['canvas_assignment_id'] = $canvasAssignmentId;
        $published['resource_link_id'] = $resourceLinkId;
    }

    if (is_string($published['resource_link_id'] ?? null) && $published['resource_link_id'] !== '') {
        $resourceKey = ltiResourceKey(
            (string) $course['issuer'],
            (string) $course['deployment_id'],
            (string) $course['context_id'],
            $published['resource_link_id']
        );
        $saveLink = $pdo->prepare(
            'INSERT INTO lti_assignment_links
             (resource_key, issuer, deployment_id, context_id, resource_link_id, assignment_id, lineitem_url, score_maximum)
             VALUES (?, ?, ?, ?, ?, ?, NULL, 100)
             ON DUPLICATE KEY UPDATE assignment_id = VALUES(assignment_id)'
        );
        $saveLink->execute([
            $resourceKey,
            (string) $course['issuer'],
            (string) $course['deployment_id'],
            (string) $course['context_id'],
            $published['resource_link_id'],
            $assignmentId
        ]);
    }

    if (empty($published['canvas_module_item_id'])) {
        $moduleItem = canvasApiRequest(
            'POST',
            '/api/v1/courses/' . rawurlencode($canvasCourseId)
                . '/modules/' . $moduleId . '/items',
            $accessToken,
            [
                'module_item' => [
                    'type' => 'Assignment',
                    'content_id' => (int) $published['canvas_assignment_id']
                ]
            ]
        );
        $moduleItemId = filter_var($moduleItem['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$moduleItemId) {
            throw new RuntimeException('Canvas created no module item. The assignment exists; retry publishing to finish adding it to the module.');
        }
        $update = $pdo->prepare(
            'UPDATE canvas_published_assignments
             SET canvas_module_item_id = ?, publish_state = "published"
             WHERE publish_key = ?'
        );
        $update->execute([$moduleItemId, $publishKey]);
    }
    return true;
}
