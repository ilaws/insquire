<?php

/**
 * The AI call can outlast MySQL's wait_timeout. Reconnect before any later
 * database write and use the returned connection.
 */
function ensureConnection(PDO $pdo): PDO
{
    try {
        $pdo->query('SELECT 1');
        return $pdo;
    } catch (Throwable $e) {
        return new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => false
            ]
        );
    }
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function sanitizeEssayHtml(string $html): string
{
    if (!class_exists(DOMDocument::class)) {
        throw new RuntimeException('The DOM extension is required to safely process formatted writing.');
    }

    $document = new DOMDocument('1.0', 'UTF-8');
    $previousErrorSetting = libxml_use_internal_errors(true);
    try {
        $loaded = $document->loadHTML(
            '<!doctype html><html><head><meta charset="utf-8"></head><body><div id="essay-root">' . $html . '</div></body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrorSetting);
    }

    $root = null;
    if ($loaded) {
        foreach ($document->getElementsByTagName('div') as $candidate) {
            if ($candidate->getAttribute('id') === 'essay-root') {
                $root = $candidate;
                break;
            }
        }
    }
    if (!$root) {
        throw new RuntimeException('Formatted writing could not be parsed safely.');
    }

    $allowedTags = [
        'p', 'br', 'div', 'span', 'b', 'strong', 'i', 'em', 'u', 's',
        'strike', 'font', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'blockquote'
    ];
    $fontFamilies = [
        'arial' => 'Arial',
        'georgia' => 'Georgia',
        'times new roman' => '"Times New Roman"',
        'courier new' => '"Courier New"',
        'verdana' => 'Verdana'
    ];

    $serializeNode = static function (DOMNode $node) use (&$serializeNode, $allowedTags, $fontFamilies): string {
        if ($node instanceof DOMText) {
            return e($node->nodeValue);
        }

        if (!$node instanceof DOMElement) {
            $output = '';
            foreach ($node->childNodes as $child) {
                $output .= $serializeNode($child);
            }
            return $output;
        }

        $tag = strtolower($node->tagName);
        if (!in_array($tag, $allowedTags, true)) {
            $output = '';
            foreach ($node->childNodes as $child) {
                $output .= $serializeNode($child);
            }
            return $output;
        }

        $styles = [];
        $styleAttribute = $node->getAttribute('style');
        if (in_array($tag, ['p', 'div', 'h1', 'h2', 'h3'], true)
            && in_array(strtolower($node->getAttribute('align')), ['left', 'center', 'right', 'justify'], true)) {
            $styleAttribute .= ';text-align:' . strtolower($node->getAttribute('align'));
        }
        if ($tag === 'font') {
            $legacyFontSize = [
                '1' => '10px',
                '2' => '13px',
                '3' => '16px',
                '4' => '18px',
                '5' => '24px',
                '6' => '32px',
                '7' => '48px'
            ];
            $styleAttribute .= ';font-family:' . $node->getAttribute('face');
            $styleAttribute .= ';font-size:' . ($legacyFontSize[$node->getAttribute('size')] ?? '');
            $styleAttribute .= ';color:' . $node->getAttribute('color');
        }
        foreach (explode(';', $styleAttribute) as $declaration) {
            $parts = explode(':', $declaration, 2);
            if (count($parts) !== 2) {
                continue;
            }
            [$property, $value] = array_map('trim', $parts);
            $property = strtolower($property);
            $value = strtolower($value);

            if (in_array($property, ['color', 'background-color'], true)
                && preg_match('/^(?:#[0-9a-f]{3}|#[0-9a-f]{6}|#[0-9a-f]{8}|(?:rgb|rgba)\\([0-9.,%\\s]+\\))$/i', $value)) {
                $styles[$property] = $value;
            } elseif ($property === 'font-size'
                && preg_match('/^(?:8|9|1[0-9]|2[0-9]|3[0-6])px$/', $value)) {
                $styles[$property] = $value;
            } elseif ($property === 'font-family') {
                $family = trim($value, " \t\n\r\0\x0B\"'");
                if (isset($fontFamilies[$family])) {
                    $styles[$property] = $fontFamilies[$family];
                }
            } elseif ($property === 'text-align'
                && in_array($value, ['left', 'center', 'right', 'justify'], true)) {
                $styles[$property] = $value;
            }
        }

        $attributes = $styles
            ? ' style="' . e(implode(';', array_map(
                static fn(string $property, string $value): string => $property . ':' . $value,
                array_keys($styles),
                array_values($styles)
            ))) . '"'
            : '';
        if ($tag === 'br') {
            return '<br>';
        }

        $outputTag = $tag === 'font' ? 'span' : $tag;
        $output = '<' . $outputTag . $attributes . '>';
        foreach ($node->childNodes as $child) {
            $output .= $serializeNode($child);
        }
        return $output . '</' . $outputTag . '>';
    };

    $result = '';
    foreach ($root->childNodes as $child) {
        $result .= $serializeNode($child);
    }
    return $result;
}

function essayPlainText(string $essay): string
{
    $safeHtml = sanitizeEssayHtml($essay);
    $withLineBreaks = preg_replace(
        '/<\/(?:p|div|li|h[1-3]|blockquote)>|<br\s*\/?>/i',
        "\n",
        $safeHtml
    );
    return trim(html_entity_decode(
        strip_tags($withLineBreaks ?? $safeHtml),
        ENT_QUOTES | ENT_HTML5,
        'UTF-8'
    ));
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function userHasEducationClasses(PDO $pdo, int $userId): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM classes WHERE teacher_id = ? AND status = "active"
         UNION
         SELECT 1 FROM class_members cm
         INNER JOIN classes c ON c.id = cm.class_id
         WHERE cm.user_id = ? AND c.status = "active"
         LIMIT 1'
    );
    $stmt->execute([$userId, $userId]);
    return (bool) $stmt->fetchColumn();
}

function redirectLinkedEducationUser(PDO $pdo, int $userId): void
{
    $requestHost = strtolower((string) parse_url('https://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
    $educationHost = strtolower((string) parse_url(EDUCATION_APP_URL, PHP_URL_HOST));
    if ($requestHost !== $educationHost && userHasEducationClasses($pdo, $userId)) {
        redirect(EDUCATION_APP_URL . '/dashboard.php');
    }
}

function isEducationHost(): bool
{
    $requestHost = strtolower((string) parse_url('https://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
    $educationHost = strtolower((string) parse_url(EDUCATION_APP_URL, PHP_URL_HOST));
    return $requestHost === $educationHost;
}

function jsonResponse(array $data, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function generateJoinCode(PDO $pdo): string
{
    do {
        $code = strtoupper(bin2hex(random_bytes(4)));

        $stmt = $pdo->prepare(
            'SELECT id FROM classes WHERE join_code = ? LIMIT 1'
        );

        $stmt->execute([$code]);
    } while ($stmt->fetch());

    return $code;
}

function getEducationSignupClass(PDO $pdo, bool $lock = false, string $joinCode = ''): array|false
{
    if ($joinCode !== '') {
        $sql = 'SELECT id, institution_id
                FROM classes
                WHERE join_code = ? AND status = "active"
                LIMIT 1';
        $parameters = [strtoupper(trim($joinCode))];
    } elseif (EDUCATION_DEFAULT_CLASS_ID > 0) {
        $sql = 'SELECT id, institution_id
                FROM classes
                WHERE id = ? AND status = "active"
                LIMIT 1';
        $parameters = [EDUCATION_DEFAULT_CLASS_ID];
    } else {
        $sql = 'SELECT id, institution_id
                FROM classes
                WHERE name = ? AND status = "active"
                ORDER BY id
                LIMIT 1';
        $parameters = ['InsQuire Test'];
    }

    if ($lock) {
        $sql .= ' FOR UPDATE';
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($parameters);
    $class = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$class) {
        if ($joinCode === '') {
            if (EDUCATION_DEFAULT_CLASS_ID > 0) {
                error_log('Configured Education signup class ' . EDUCATION_DEFAULT_CLASS_ID . ' is missing or inactive.');
            } else {
                error_log('Education signup class "InsQuire Test" is missing or inactive.');
            }
        }
        return false;
    }
    return $class;
}

function getClassForTeacher(PDO $pdo, int $classId, int $teacherId): array|false
{
    $stmt = $pdo->prepare(
        'SELECT c.*
         FROM classes c
         WHERE c.id = ? AND c.teacher_id = ?
           AND (c.institution_id IS NULL OR EXISTS (
               SELECT 1 FROM institution_members im
               WHERE im.institution_id = c.institution_id AND im.user_id = ?
                 AND im.role IN ("admin","teacher")
           ))
         LIMIT 1'
    );

    $stmt->execute([
        $classId,
        $teacherId,
        $teacherId
    ]);

    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function studentInClass(PDO $pdo, int $classId, int $studentId): bool
{
    $stmt = $pdo->prepare(
        'SELECT id
        FROM class_members
        WHERE class_id = ?
        AND user_id = ?
        LIMIT 1'
    );

    $stmt->execute([
        $classId,
        $studentId
    ]);

    return (bool) $stmt->fetch();
}

function isInstitutionAdmin(PDO $pdo, int $userId, ?int $institutionId = null): bool
{
    $sql = 'SELECT 1 FROM institution_members
            WHERE user_id = ? AND role = "admin"';
    $params = [$userId];
    if ($institutionId !== null) {
        $sql .= ' AND institution_id = ?';
        $params[] = $institutionId;
    }
    $sql .= ' LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (bool) $stmt->fetchColumn();
}

function isInstitutionMember(PDO $pdo, int $userId, int $institutionId): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM institution_members WHERE user_id = ? AND institution_id = ? LIMIT 1'
    );
    $stmt->execute([$userId, $institutionId]);
    return (bool) $stmt->fetchColumn();
}

function messageFlagTerms(): array
{
    $terms = array_map('trim', explode(',', MESSAGE_MODERATION_TERMS));
    return array_values(array_filter(
        $terms,
        static fn(string $term): bool => $term !== '' && mb_strlen($term) <= 80
    ));
}

function messageFlagMatches(string $text): bool
{
    foreach (messageFlagTerms() as $term) {
        $pattern = '/(?<![\p{L}\p{N}_])' . preg_quote($term, '/') . '(?![\p{L}\p{N}_])/iu';
        if (preg_match($pattern, $text) === 1) {
            return true;
        }
    }
    return false;
}

function teacherFeedbackContainsProfanity(string $text): bool
{
    $terms = [
        'ass', 'asshole', 'bastard', 'bitch', 'bullshit', 'cock', 'cocksucker',
        'crap', 'cunt', 'damn', 'dick', 'dildo', 'fuck', 'fucker', 'fucking',
        'motherfucker', 'piss', 'prick', 'pussy', 'shit', 'shitty', 'slut',
        'whore'
    ];

    foreach ($terms as $term) {
        $pattern = '/(?<![\p{L}\p{N}_])' . preg_quote($term, '/') . '(?![\p{L}\p{N}_])/iu';
        if (preg_match($pattern, $text) === 1) {
            return true;
        }
    }

    return false;
}

function findInstitutionInvite(PDO $pdo, string $token): array|false
{
    if (!preg_match('/\A[a-f0-9]{64}\z/', $token)) {
        return false;
    }

    $stmt = $pdo->prepare(
        'SELECT ii.id, ii.institution_id, ii.email, ii.expires_at, i.name
         FROM institution_invites ii
         INNER JOIN institutions i ON i.id = ii.institution_id
         WHERE ii.token_hash = ? AND ii.accepted_at IS NULL
           AND ii.expires_at > UTC_TIMESTAMP() AND i.status = "active"
         LIMIT 1'
    );
    $stmt->execute([hash('sha256', $token)]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: false;
}

function acceptInstitutionInviteInTransaction(PDO $pdo, int $inviteId, int $userId): bool
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('Institution invitations must be accepted within a transaction.');
    }

    $stmt = $pdo->prepare(
        'SELECT ii.id, ii.institution_id, ii.email
         FROM institution_invites ii
         INNER JOIN institutions i ON i.id = ii.institution_id
         WHERE ii.id = ? AND ii.accepted_at IS NULL
           AND ii.expires_at > UTC_TIMESTAMP() AND i.status = "active"
         FOR UPDATE'
    );
    $stmt->execute([$inviteId]);
    $invite = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$invite) {
        return false;
    }

    $userStmt = $pdo->prepare(
        'SELECT email FROM users
         WHERE id = ? AND account_scope = "education"
           AND role = "teacher" AND status = "active"
         LIMIT 1'
    );
    $userStmt->execute([$userId]);
    $email = $userStmt->fetchColumn();
    if (
        !is_string($email) ||
        ($invite['email'] !== null && strcasecmp((string) $invite['email'], $email) !== 0)
    ) {
        return false;
    }

    $member = $pdo->prepare(
        'INSERT INTO institution_members (institution_id, user_id, role)
         VALUES (?, ?, "teacher")
         ON DUPLICATE KEY UPDATE role = IF(role = "admin", role, "teacher")'
    );
    $member->execute([(int) $invite['institution_id'], $userId]);
    $accept = $pdo->prepare(
        'UPDATE institution_invites
         SET accepted_by = ?, accepted_at = UTC_TIMESTAMP()
         WHERE id = ? AND accepted_at IS NULL'
    );
    $accept->execute([$userId, $inviteId]);

    return $accept->rowCount() === 1;
}

function hasTableColumn(PDO $pdo, string $table, string $column): bool
{
    $allowedTables = ['assignments', 'submissions', 'users', 'classes'];

    if (!in_array($table, $allowedTables, true)) {
        return false;
    }

    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);

    return (bool) $stmt->fetchColumn();
}

/**
 * Return the effective account plan. An unrestricted platform override removes
 * only the daily cap; existing minute/hour burst limits continue to apply.
 */
function getAccountEntitlement(PDO $pdo, int $userId): array
{
    $accountStmt = $pdo->prepare(
        'SELECT account_scope, role FROM users WHERE id = ? AND status = "active" LIMIT 1'
    );
    $accountStmt->execute([$userId]);
    $account = $accountStmt->fetch(PDO::FETCH_ASSOC);
    if (!$account) {
        return ['plan' => 'unavailable', 'daily_limit' => 0, 'unrestricted' => false];
    }

    $override = $pdo->prepare(
        'SELECT id FROM platform_entitlement_overrides
         WHERE user_id = ? AND revoked_at IS NULL
           AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())
         ORDER BY created_at DESC, id DESC LIMIT 1'
    );
    $override->execute([$userId]);
    if ($override->fetchColumn()) {
        return ['plan' => 'platform_unrestricted', 'daily_limit' => null, 'unrestricted' => true];
    }

    if ($account['account_scope'] === 'personal') {
        $personal = $pdo->prepare(
            'SELECT id FROM billing_subscriptions
             WHERE owner_type = "personal" AND user_id = ?
               AND status IN ("active", "trialing")
               AND stripe_price_id = ?
               AND current_period_end > UTC_TIMESTAMP()
             ORDER BY updated_at DESC, id DESC LIMIT 1'
        );
        $personal->execute([$userId, STRIPE_PERSONAL_MONTHLY_PRICE_ID]);
        if ($personal->fetchColumn()) {
            return ['plan' => 'personal_paid', 'daily_limit' => AI_PERSONAL_PAID_DAILY_LIMIT, 'unrestricted' => false];
        }
    }

    if ($account['account_scope'] === 'education' && in_array($account['role'], ['student', 'teacher'], true)) {
        $education = $pdo->prepare(
            'SELECT bs.id, bs.seat_limit, bs.stripe_price_id, (
                 SELECT COUNT(DISTINCT active_member.user_id)
                 FROM institution_members active_member
                 INNER JOIN users active_user ON active_user.id = active_member.user_id
                 WHERE active_member.institution_id = bs.institution_id
                   AND active_user.status = "active"
             ) AS active_seats
             FROM institution_members im
             INNER JOIN institutions i ON i.id = im.institution_id AND i.status = "active"
             INNER JOIN billing_subscriptions bs ON bs.institution_id = i.id
               AND bs.owner_type = "institution"
               AND bs.status IN ("active", "trialing")
               AND bs.current_period_end > UTC_TIMESTAMP()
             WHERE im.user_id = ?
             ORDER BY bs.updated_at DESC, bs.id DESC'
        );
        $education->execute([$userId]);
        $knownEducationPrices = array_filter([
            STRIPE_EDU_PRICE_1_99,
            STRIPE_EDU_PRICE_100_299,
            STRIPE_EDU_PRICE_300_699,
            STRIPE_EDU_PRICE_700_1299,
            STRIPE_EDU_PRICE_1300_2199,
            STRIPE_EDU_PRICE_2200_3199,
            STRIPE_EDU_PRICE_3200_PLUS,
        ], static fn(string $priceId): bool => $priceId !== '');
        foreach ($education->fetchAll(PDO::FETCH_ASSOC) as $institutionSubscription) {
            if (!in_array($institutionSubscription['stripe_price_id'], $knownEducationPrices, true)) {
                continue;
            }
            $seatLimit = $institutionSubscription['seat_limit'] === null
                ? null
                : (int) $institutionSubscription['seat_limit'];
            if ($seatLimit === null || (int) $institutionSubscription['active_seats'] <= $seatLimit) {
                return ['plan' => 'education_paid', 'daily_limit' => AI_EDUCATION_DAILY_LIMIT, 'unrestricted' => false];
            }
        }
    }

    $fallbackLimit = $account['account_scope'] === 'personal'
        ? AI_PERSONAL_FREE_DAILY_LIMIT
        : AI_RATE_LIMIT_PER_DAY;
    return [
        'plan' => $account['account_scope'] === 'personal' ? 'personal_free' : 'education_free',
        'daily_limit' => $fallbackLimit,
        'unrestricted' => false
    ];
}

function getAiDailyRequestLimit(PDO $pdo, int $userId): ?int
{
    $entitlement = getAccountEntitlement($pdo, $userId);
    return $entitlement['daily_limit'];
}

/**
 * Ad eligibility only; this helper does not load an ad, tracker, or provider.
 */
function getAdEntitlement(PDO $pdo, int $userId): string
{
    if (isEducationHost() || userHasEducationClasses($pdo, $userId)) {
        return 'none';
    }
    $account = $pdo->prepare('SELECT role, account_scope FROM users WHERE id = ? AND status = "active" LIMIT 1');
    $account->execute([$userId]);
    $accountRow = $account->fetch(PDO::FETCH_ASSOC);
    if (!$accountRow || $accountRow['account_scope'] !== 'personal' || $accountRow['role'] !== 'student') {
        return 'none';
    }
    $institutionMembership = $pdo->prepare(
        'SELECT 1
         FROM institution_members im
         INNER JOIN institutions i ON i.id = im.institution_id AND i.status = "active"
         WHERE im.user_id = ? LIMIT 1'
    );
    $institutionMembership->execute([$userId]);
    if ($institutionMembership->fetchColumn()) {
        return 'none';
    }

    $entitlement = getAccountEntitlement($pdo, $userId);
    return in_array($entitlement['plan'], ['personal_paid', 'education_paid', 'platform_unrestricted'], true)
        ? 'none'
        : 'contextual_non_personalized_only';
}

function reserveAiRequest(PDO $pdo, int $userId): array
{
    try {
        $pdo->exec(
            'DELETE FROM ai_request_log
             WHERE created_at < UTC_TIMESTAMP() - INTERVAL 1 DAY'
        );
        $pdo->beginTransaction();

        $userStmt = $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
        $userStmt->execute([$userId]);
        if (!$userStmt->fetchColumn()) {
            $pdo->rollBack();
            return ['allowed' => false, 'error' => 'Sign in again before requesting AI help.'];
        }
        $dailyLimit = getAiDailyRequestLimit($pdo, $userId);

        $minuteStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM ai_request_log
             WHERE user_id = ? AND created_at >= UTC_TIMESTAMP() - INTERVAL 1 MINUTE'
        );
        $minuteStmt->execute([$userId]);
        $minuteCount = (int) $minuteStmt->fetchColumn();

        $hourStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM ai_request_log
             WHERE user_id = ? AND created_at >= UTC_TIMESTAMP() - INTERVAL 1 HOUR'
        );
        $hourStmt->execute([$userId]);
        $hourCount = (int) $hourStmt->fetchColumn();

        $dayStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM ai_request_log
             WHERE user_id = ? AND created_at >= UTC_TIMESTAMP() - INTERVAL 1 DAY'
        );
        $dayStmt->execute([$userId]);
        $dayCount = (int) $dayStmt->fetchColumn();

        if (
            $minuteCount >= AI_RATE_LIMIT_PER_MINUTE ||
            $hourCount >= AI_RATE_LIMIT_PER_HOUR ||
            ($dailyLimit !== null && $dayCount >= $dailyLimit)
        ) {
            $pdo->commit();
            $retryAfter = 0;
            if ($minuteCount >= AI_RATE_LIMIT_PER_MINUTE) {
                $retryStmt = $pdo->prepare(
                    'SELECT GREATEST(
                        1,
                        TIMESTAMPDIFF(
                            SECOND,
                            UTC_TIMESTAMP(),
                            MIN(created_at) + INTERVAL 1 MINUTE
                        )
                     )
                     FROM ai_request_log
                     WHERE user_id = ?
                       AND created_at >= UTC_TIMESTAMP() - INTERVAL 1 MINUTE'
                );
                $retryStmt->execute([$userId]);
                $retryAfter = max(1, (int) $retryStmt->fetchColumn());
            }
            if ($hourCount >= AI_RATE_LIMIT_PER_HOUR) {
                $retryStmt = $pdo->prepare(
                    'SELECT GREATEST(
                        1,
                        TIMESTAMPDIFF(
                            SECOND,
                            UTC_TIMESTAMP(),
                            MIN(created_at) + INTERVAL 1 HOUR
                        )
                     )
                     FROM ai_request_log
                     WHERE user_id = ?
                       AND created_at >= UTC_TIMESTAMP() - INTERVAL 1 HOUR'
                );
                $retryStmt->execute([$userId]);
                $retryAfter = max($retryAfter, (int) $retryStmt->fetchColumn());
            }
            if ($dailyLimit !== null && $dayCount >= $dailyLimit) {
                $retryStmt = $pdo->prepare(
                    'SELECT GREATEST(
                        1,
                        TIMESTAMPDIFF(
                            SECOND,
                            UTC_TIMESTAMP(),
                            MIN(created_at) + INTERVAL 1 DAY
                        )
                     )
                     FROM ai_request_log
                     WHERE user_id = ?
                       AND created_at >= UTC_TIMESTAMP() - INTERVAL 1 DAY'
                );
                $retryStmt->execute([$userId]);
                $retryAfter = max($retryAfter, (int) $retryStmt->fetchColumn());
            }
            return [
                'allowed' => false,
                'rate_limited' => true,
                'retry_after' => $retryAfter,
                'error' => 'You have reached the AI request limit. Please wait a little before trying again.'
            ];
        }

        $insertStmt = $pdo->prepare(
            'INSERT INTO ai_request_log (user_id, created_at)
             VALUES (?, UTC_TIMESTAMP())'
        );
        $insertStmt->execute([$userId]);

        $pdo->commit();

        return ['allowed' => true];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('AI request limit check failed: ' . $e->getMessage());
        return [
            'allowed' => false,
            'error' => 'AI feedback is temporarily unavailable. Please try again later.'
        ];
    }
}

function aiGenerate(
    string $model,
    string $system,
    string $prompt,
    bool $jsonFormat = true,
    int $maxTokens = 1200
): array {
    if (DEEPSEEK_API_KEY === '') {
        return [
            'success' => false,
            'error' => 'DeepSeek is not configured. Please contact your school administrator.'
        ];
    }

    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        return [
            'success' => false,
            'error' => 'Sign in before requesting AI help.'
        ];
    }

    global $pdo;
    if (!$pdo instanceof PDO) {
        return [
            'success' => false,
            'error' => 'AI feedback is temporarily unavailable. Please try again later.'
        ];
    }

    $limit = reserveAiRequest($pdo, $userId);
    if (!$limit['allowed']) {
        if (!empty($limit['rate_limited'])) {
            http_response_code(429);
            header('Retry-After: ' . (int) $limit['retry_after']);
        }
        return [
            'success' => false,
            'rate_limited' => !empty($limit['rate_limited']),
            'retry_after' => $limit['retry_after'] ?? null,
            'error' => $limit['error']
        ];
    }

    $payload = json_encode(array_filter([
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $prompt]
        ],
        'stream' => false,
        'thinking' => ['type' => 'disabled'],
        'response_format' => $jsonFormat ? ['type' => 'json_object'] : null,
        'temperature' => 0.1,
        'max_tokens' => max(1, $maxTokens)
    ], fn($v) => $v !== null));

    if ($payload === false) {
        return [
            'success' => false,
            'error' => 'Could not encode the AI request.'
        ];
    }

    $url = rtrim(DEEPSEEK_API_URL, '/') . '/chat/completions';

    $ch = curl_init($url);

    if ($ch === false) {
        return [
            'success' => false,
            'error' => 'Could not connect to DeepSeek.'
        ];
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . DEEPSEEK_API_KEY
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 90
    ]);

    $response = curl_exec($ch);

    $httpCode = (int) curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );

    curl_close($ch);

    if ($response === false) {
        return [
            'success' => false,
            'error' => 'DeepSeek connection failed.'
        ];
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        if ($httpCode === 429) {
            http_response_code(429);
            header('Retry-After: 60');
            return [
                'success' => false,
                'rate_limited' => true,
                'retry_after' => 60,
                'error' => 'DeepSeek is temporarily rate-limiting requests. Please wait briefly and try again.'
            ];
        }
        return [
            'success' => false,
            'error' => 'DeepSeek API returned HTTP ' . $httpCode . '.'
        ];
    }

    $data = json_decode($response, true);

    if (!is_array($data)) {
        return [
            'success' => false,
            'error' => 'InsQuire returned an invalid response.'
        ];
    }

    if (!empty($data['error'])) {
        return [
            'success' => false,
            'error' => (string) $data['error']
        ];
    }

    $content = $data['choices'][0]['message']['content'] ?? null;
    if (!is_string($content)) {
        return [
            'success' => false,
            'error' => 'DeepSeek returned an invalid response.'
        ];
    }

    if (trim($content) === '') {
        $finishReason = $data['choices'][0]['finish_reason'] ?? 'unknown';
        error_log(
            'DeepSeek returned empty content; finish_reason=' .
            preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $finishReason)
        );
        return [
            'success' => false,
            'error' => 'DeepSeek returned no feedback. Please try again.'
        ];
    }

    return [
        'success' => true,
        'response' => trim($content),
        'finish_reason' => (string) ($data['choices'][0]['finish_reason'] ?? '')
    ];
}
