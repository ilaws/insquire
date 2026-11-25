<?php

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/billing.php';

requireLogin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('POST required.');
}
verifyCsrf();

$userId = currentUserId();
$scope = is_string($_POST['scope'] ?? null) ? $_POST['scope'] : '';
$ownerId = $userId;
$userStmt = $pdo->prepare('SELECT email FROM users WHERE id = ? AND status = "active" LIMIT 1');
$userStmt->execute([$userId]);
$email = (string) $userStmt->fetchColumn();
if ($email === '') {
    http_response_code(403);
    exit('An active account is required to start billing.');
}

if ($scope === 'personal') {
    if (currentAccountScope() !== 'personal') {
        http_response_code(403);
        exit('Personal billing is available only to Personal accounts.');
    }
    $planKey = 'personal_monthly';
    $priceId = STRIPE_PERSONAL_MONTHLY_PRICE_ID;
    $seatLimit = null;
    $userOwnerId = $userId;
    $institutionOwnerId = null;
} elseif ($scope === 'institution') {
    if (currentAccountScope() !== 'education' || currentUserRole() !== 'teacher') {
        http_response_code(403);
        exit('Institution billing is available only to Education teachers.');
    }
    $institutionId = filter_var($_POST['institution_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$institutionId || !isInstitutionAdmin($pdo, $userId, (int) $institutionId)) {
        http_response_code(403);
        exit('Only an administrator of this institution may start its subscription.');
    }
    $instStmt = $pdo->prepare('SELECT id FROM institutions WHERE id = ? AND status = "active" LIMIT 1');
    $instStmt->execute([(int) $institutionId]);
    if (!$instStmt->fetchColumn()) {
        http_response_code(404);
        exit('Institution not found.');
    }
    $seats = institutionActiveSeatCount($pdo, (int) $institutionId);
    $tier = institutionTierForSeats($seats);
    if (!$tier) {
        http_response_code(400);
        exit('At least one active institution member is required before subscribing.');
    }
    $planKey = $tier['key'];
    $priceId = $tier['price_id'];
    $seatLimit = $tier['max'];
    $ownerId = (int) $institutionId;
    $userOwnerId = null;
    $institutionOwnerId = (int) $institutionId;
} else {
    http_response_code(400);
    exit('Invalid billing scope.');
}

if (
    STRIPE_SECRET_KEY === '' || STRIPE_WEBHOOK_SECRET === '' ||
    ESSAY_BILLING_METADATA_SECRET === '' || $priceId === ''
) {
    http_response_code(503);
    exit('Billing is not configured for this plan.');
}

try {
    $pdo->beginTransaction();
    $ownerLock = $pdo->prepare(
        $scope === 'personal'
            ? 'SELECT id FROM users WHERE id = ? AND status = "active" FOR UPDATE'
            : 'SELECT id FROM institutions WHERE id = ? AND status = "active" FOR UPDATE'
    );
    $ownerLock->execute([$ownerId]);
    if (!$ownerLock->fetchColumn()) {
        $pdo->rollBack();
        http_response_code(404);
        exit('Billing owner is no longer active.');
    }
    if ($scope === 'institution') {
        $adminCheck = $pdo->prepare(
            'SELECT 1 FROM institution_members
             WHERE institution_id = ? AND user_id = ? AND role = "admin"
             LIMIT 1 FOR UPDATE'
        );
        $adminCheck->execute([$ownerId, $userId]);
        if (!$adminCheck->fetchColumn()) {
            $pdo->rollBack();
            http_response_code(403);
            exit('Only an administrator of this institution may start its subscription.');
        }
        $seats = institutionActiveSeatCount($pdo, $ownerId);
        $tier = institutionTierForSeats($seats);
        if ($tier === null) {
            $pdo->rollBack();
            http_response_code(400);
            exit('At least one active institution member is required before subscribing.');
        }
        $planKey = $tier['key'];
        $priceId = $tier['price_id'];
        $seatLimit = $tier['max'];
        if ($priceId === '') {
            $pdo->rollBack();
            http_response_code(503);
            exit('Billing is not configured for this institution tier.');
        }
    }

$activeSql = 'SELECT id FROM billing_subscriptions
              WHERE owner_type = ? AND status IN ("active", "trialing", "incomplete", "past_due", "unpaid", "paused", "canceled")
                AND (current_period_end IS NULL OR current_period_end > UTC_TIMESTAMP())';
$activeParams = [$scope];
if ($scope === 'personal') {
    $activeSql .= ' AND user_id = ?';
    $activeParams[] = $userId;
} else {
    $activeSql .= ' AND institution_id = ?';
    $activeParams[] = $ownerId;
}
$activeSql .= ' LIMIT 1';
$activeStmt = $pdo->prepare($activeSql);
$activeStmt->execute($activeParams);
if ($activeStmt->fetchColumn()) {
    $pdo->rollBack();
    http_response_code(409);
    exit('An active subscription already exists. Manage or cancel it before starting another checkout.');
}

$pendingSql = 'SELECT bc.id FROM billing_checkouts bc
               LEFT JOIN billing_subscriptions bs ON bs.checkout_id = bc.id
               WHERE bc.owner_type = ?
                 AND ((bc.status = "open" AND bc.expires_at > UTC_TIMESTAMP())
                   OR (bc.status = "completed" AND bs.id IS NULL))';
$pendingParams = [$scope];
if ($scope === 'personal') {
    $pendingSql .= ' AND bc.user_id = ?';
    $pendingParams[] = $userId;
} else {
    $pendingSql .= ' AND bc.institution_id = ?';
    $pendingParams[] = $ownerId;
}
$pendingSql .= ' ORDER BY bc.id DESC LIMIT 1';
$pendingStmt = $pdo->prepare($pendingSql);
$pendingStmt->execute($pendingParams);
if ($pendingStmt->fetchColumn()) {
    $pdo->rollBack();
    http_response_code(409);
    exit('A checkout session is already open for this account. Finish or wait for it to expire before trying again.');
}

    $placeholderSignature = str_repeat('0', 64);
    $create = $pdo->prepare(
        'INSERT INTO billing_checkouts
         (owner_type, user_id, institution_id, created_by, plan_key, stripe_price_id, metadata_signature, expires_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR))'
    );
    $create->execute([
        $scope,
        $userOwnerId,
        $institutionOwnerId,
        $userId,
        $planKey,
        $priceId,
        $placeholderSignature
    ]);
    $checkoutId = (int) $pdo->lastInsertId();
    $signature = checkoutMetadataSignature($checkoutId, $scope, $ownerId, $userId, $planKey);
    $updateSignature = $pdo->prepare('UPDATE billing_checkouts SET metadata_signature = ? WHERE id = ?');
    $updateSignature->execute([$signature, $checkoutId]);
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Stripe checkout reservation failed for account ' . $userId . ': ' . $exception->getMessage());
    http_response_code(503);
    exit('Billing could not be started. Please try again later or contact support.');
}

try {
    $metadata = [
        'checkout_id' => (string) $checkoutId,
        'owner_type' => $scope,
        'owner_id' => (string) $ownerId,
        'created_by' => (string) $userId,
        'plan_key' => $planKey,
        'signature' => $signature,
    ];
    $baseUrl = billingOwnerBaseUrl($scope);
    $payload = [
        'mode' => 'subscription',
        'line_items' => [['price' => $priceId, 'quantity' => 1]],
        'success_url' => $baseUrl . '/billing.php?checkout=return&session_id={CHECKOUT_SESSION_ID}',
        'cancel_url' => $baseUrl . '/billing.php?checkout=cancel',
        'metadata' => $metadata,
        'subscription_data' => ['metadata' => $metadata],
    ];
    $customerStmt = $pdo->prepare(
        'SELECT stripe_customer_id FROM billing_subscriptions
         WHERE owner_type = ? AND stripe_customer_id IS NOT NULL AND stripe_customer_id <> ""' .
         ($scope === 'personal' ? ' AND user_id = ?' : ' AND institution_id = ?') .
         ' ORDER BY updated_at DESC, id DESC LIMIT 1'
    );
    $customerStmt->execute([$scope, $ownerId]);
    $customerId = (string) ($customerStmt->fetchColumn() ?: '');
    if ($customerId !== '') {
        $payload['customer'] = $customerId;
    } else {
        $payload['customer_email'] = $email;
    }

    $session = stripeApiRequest('POST', 'checkout/sessions', $payload);
    $sessionUrl = (string) ($session['url'] ?? '');
    $sessionId = (string) ($session['id'] ?? '');
    $sessionHost = strtolower((string) parse_url($sessionUrl, PHP_URL_HOST));
    if ($sessionId === '' || $sessionHost !== 'checkout.stripe.com' || !str_starts_with($sessionUrl, 'https://')) {
        throw new RuntimeException('Stripe returned an invalid checkout URL.');
    }
    $save = $pdo->prepare(
        'UPDATE billing_checkouts SET stripe_session_id = ?, stripe_customer_id = ? WHERE id = ?'
    );
    $save->execute([$sessionId, $customerId !== '' ? $customerId : null, $checkoutId]);
    header('Location: ' . $sessionUrl, true, 303);
    exit;
} catch (Throwable $exception) {
    if (isset($checkoutId)) {
        $failed = $pdo->prepare('UPDATE billing_checkouts SET status = "failed" WHERE id = ?');
        $failed->execute([$checkoutId]);
    }
    error_log('Stripe checkout creation failed for account ' . $userId . ': ' . $exception->getMessage());
    http_response_code(503);
    exit('Billing could not be started. Please try again later or contact support.');
}
