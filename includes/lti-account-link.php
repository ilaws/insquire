<?php

function ltiAccountLinkHasIdentity(PDO $pdo, int $userId): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM lti_identities WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    return (bool) $stmt->fetchColumn();
}

function ltiAccountLinkColumnExists(PDO $pdo, string $table, string $column): bool
{
    if (!preg_match('/\A[a-z_]+\z/', $table) || !preg_match('/\A[a-z_]+\z/', $column)) {
        throw new InvalidArgumentException('Invalid account-link migration identifier.');
    }
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    return (bool) $stmt->fetchColumn();
}

function ltiAccountLinkMoveReference(
    PDO $pdo,
    string $table,
    string $column,
    int $sourceUserId,
    int $targetUserId
): void {
    if (!ltiAccountLinkColumnExists($pdo, $table, $column)) {
        return;
    }
    $stmt = $pdo->prepare(
        'UPDATE `' . $table . '` SET `' . $column . '` = ? WHERE `' . $column . '` = ?'
    );
    $stmt->execute([$targetUserId, $sourceUserId]);
}

function ltiCreateTeacherAccountLinkCode(PDO $pdo, int $targetUserId): string
{
    $account = $pdo->prepare(
        'SELECT email, role, account_scope, status
         FROM users WHERE id = ? LIMIT 1'
    );
    $account->execute([$targetUserId]);
    $target = $account->fetch(PDO::FETCH_ASSOC);
    if (
        !$target
        || $target['role'] !== 'teacher'
        || $target['account_scope'] !== 'education'
        || $target['status'] !== 'active'
        || str_ends_with((string) $target['email'], '@lti.insquire.invalid')
    ) {
        throw new RuntimeException('Only an active Education teacher email account can generate a sync code.');
    }

    $code = bin2hex(random_bytes(8));
    $pdo->beginTransaction();
    try {
        $expirePrevious = $pdo->prepare(
            'UPDATE lti_account_link_codes
             SET redeemed_at = UTC_TIMESTAMP()
             WHERE target_user_id = ? AND redeemed_at IS NULL'
        );
        $expirePrevious->execute([$targetUserId]);
        $insert = $pdo->prepare(
            'INSERT INTO lti_account_link_codes (code_hash, target_user_id, expires_at)
             VALUES (?, ?, UTC_TIMESTAMP() + INTERVAL 15 MINUTE)'
        );
        $insert->execute([hash('sha256', $code), $targetUserId]);
        $pdo->commit();
        return $code;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function ltiRedeemTeacherAccountLinkCode(PDO $pdo, int $ltiUserId, string $code): array
{
    if (!preg_match('/\A[a-f0-9]{16}\z/i', $code)) {
        throw new RuntimeException('Enter the 16-character sync code exactly as shown.');
    }

    $pdo->beginTransaction();
    try {
        $findCode = $pdo->prepare(
            'SELECT id, target_user_id, expires_at, redeemed_at
             FROM lti_account_link_codes
             WHERE code_hash = ?
               AND redeemed_at IS NULL
               AND expires_at > UTC_TIMESTAMP()
             LIMIT 1
             FOR UPDATE'
        );
        $findCode->execute([hash('sha256', strtolower($code))]);
        $linkCode = $findCode->fetch(PDO::FETCH_ASSOC);
        if (!$linkCode) {
            throw new RuntimeException('That sync code is invalid, expired, or already used. Generate a new code from the teacher email account.');
        }

        $users = $pdo->prepare(
            'SELECT id, name, email, role, account_scope, status
             FROM users
             WHERE id IN (?, ?)
             ORDER BY id
             FOR UPDATE'
        );
        $targetUserId = (int) $linkCode['target_user_id'];
        $users->execute([$ltiUserId, $targetUserId]);
        $accounts = [];
        foreach ($users->fetchAll(PDO::FETCH_ASSOC) as $account) {
            $accounts[(int) $account['id']] = $account;
        }
        $source = $accounts[$ltiUserId] ?? null;
        $target = $accounts[$targetUserId] ?? null;
        if (
            !$source
            || !$target
            || $ltiUserId === $targetUserId
            || $source['role'] !== 'teacher'
            || $target['role'] !== 'teacher'
            || $source['account_scope'] !== 'education'
            || $target['account_scope'] !== 'education'
            || $source['status'] !== 'active'
            || $target['status'] !== 'active'
            || str_ends_with((string) $target['email'], '@lti.insquire.invalid')
        ) {
            throw new RuntimeException('This code can only link an active Canvas-created teacher account to an active Education teacher email account.');
        }

        $identityCount = $pdo->prepare('SELECT COUNT(*) FROM lti_identities WHERE user_id = ?');
        $identityCount->execute([$ltiUserId]);
        if ((int) $identityCount->fetchColumn() < 1) {
            throw new RuntimeException('Sign in to the Canvas-created teacher account before redeeming this code.');
        }

        if (
            ltiAccountLinkColumnExists($pdo, 'institution_members', 'user_id')
            && ltiAccountLinkColumnExists($pdo, 'institution_members', 'institution_id')
        ) {
            $updateInstitutionMembers = $pdo->prepare(
                'UPDATE institution_members AS target
                 INNER JOIN institution_members AS source
                    ON source.institution_id = target.institution_id
                 SET target.role = IF(target.role = "admin" OR source.role = "admin", "admin", "teacher")
                 WHERE target.user_id = ? AND source.user_id = ?'
            );
            $updateInstitutionMembers->execute([$targetUserId, $ltiUserId]);
            $insertInstitutionMembers = $pdo->prepare(
                'INSERT IGNORE INTO institution_members (institution_id, user_id, role)
                 SELECT source.institution_id, ?, source.role
                 FROM institution_members AS source
                 WHERE source.user_id = ?'
            );
            $insertInstitutionMembers->execute([$targetUserId, $ltiUserId]);
            $deleteInstitutionMembers = $pdo->prepare('DELETE FROM institution_members WHERE user_id = ?');
            $deleteInstitutionMembers->execute([$ltiUserId]);
        }

        if (
            ltiAccountLinkColumnExists($pdo, 'class_members', 'user_id')
            && ltiAccountLinkColumnExists($pdo, 'class_members', 'class_id')
        ) {
            $mergeClassMembers = $pdo->prepare(
                'INSERT IGNORE INTO class_members (class_id, user_id)
                 SELECT class_id, ? FROM class_members WHERE user_id = ?'
            );
            $mergeClassMembers->execute([$targetUserId, $ltiUserId]);
            $deleteClassMembers = $pdo->prepare('DELETE FROM class_members WHERE user_id = ?');
            $deleteClassMembers->execute([$ltiUserId]);
        }

        $notificationsExist = ltiAccountLinkColumnExists($pdo, 'notifications', 'user_id');
        if ($notificationsExist) {
            $mergeNotifications = $pdo->prepare(
                'INSERT IGNORE INTO notifications
                 (user_id, class_id, notification_type, source_id, created_at, read_at)
                 SELECT ?, class_id, notification_type, source_id, created_at, read_at
                 FROM notifications WHERE user_id = ?'
            );
            $mergeNotifications->execute([$targetUserId, $ltiUserId]);
            $deleteNotifications = $pdo->prepare('DELETE FROM notifications WHERE user_id = ?');
            $deleteNotifications->execute([$ltiUserId]);
        }

        $references = [
            ['institutions', 'created_by'],
            ['institution_invites', 'invited_by'],
            ['institution_invites', 'accepted_by'],
            ['classes', 'teacher_id'],
            ['lti_courses', 'teacher_user_id'],
            ['class_messages', 'sender_id'],
            ['class_messages', 'recipient_id'],
            ['class_announcements', 'author_id'],
            ['submission_comments', 'teacher_id'],
            ['english_diagnostic_assignments', 'teacher_id'],
            ['user_calendar_events', 'user_id'],
            ['api_tokens', 'user_id'],
            ['ai_request_log', 'user_id'],
            ['lessons', 'created_by'],
            ['lesson_assignments', 'assigned_by']
        ];
        foreach ($references as [$table, $column]) {
            ltiAccountLinkMoveReference($pdo, $table, $column, $ltiUserId, $targetUserId);
        }

        $moveIdentities = $pdo->prepare('UPDATE lti_identities SET user_id = ? WHERE user_id = ?');
        $moveIdentities->execute([$targetUserId, $ltiUserId]);
        $markCodeUsed = $pdo->prepare(
            'UPDATE lti_account_link_codes SET redeemed_at = UTC_TIMESTAMP() WHERE id = ?'
        );
        $markCodeUsed->execute([(int) $linkCode['id']]);
        $disableSource = $pdo->prepare('UPDATE users SET status = "disabled" WHERE id = ?');
        $disableSource->execute([$ltiUserId]);
        $pdo->commit();
        return $target;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}
