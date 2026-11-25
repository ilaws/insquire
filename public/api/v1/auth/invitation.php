<?php

require_once dirname(__DIR__) . '/_bootstrap.php';
apiRequireMethod('POST');
apiVerifyCsrf();
$user = apiRequireUser(['teacher']);
$input = apiReadJson();
$inviteToken = trim((string) ($input['invite_token'] ?? ''));
$invite = findInstitutionInvite($pdo, $inviteToken);

if (!$invite) {
    apiRespond(['error' => 'This teacher invitation is invalid, expired, or already used.'], 422);
}

$pdo->beginTransaction();
try {
    if (!acceptInstitutionInviteInTransaction($pdo, (int) $invite['id'], $user['id'])) {
        $pdo->rollBack();
        apiRespond(['error' => 'This invitation is no longer available for this account.'], 409);
    }
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $exception;
}

apiRespond(['success' => true]);
