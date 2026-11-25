<?php

require_once dirname(__DIR__) . '/includes/billing.php';

function stripeWebhookSignatureIsValid(string $payload, string $header): bool
{
    if (STRIPE_WEBHOOK_SECRET === '') {
        return false;
    }
    $timestamp = null;
    $signatures = [];
    foreach (explode(',', $header) as $part) {
        [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
        if ($key === 't' && ctype_digit($value)) {
            $timestamp = (int) $value;
        } elseif ($key === 'v1' && preg_match('/^[a-f0-9]{64}$/i', $value) === 1) {
            $signatures[] = strtolower($value);
        }
    }
    if ($timestamp === null || !$signatures || abs(time() - $timestamp) > STRIPE_WEBHOOK_TOLERANCE_SECONDS) {
        return false;
    }
    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, STRIPE_WEBHOOK_SECRET);
    foreach ($signatures as $signature) {
        if (hash_equals($expected, $signature)) {
            return true;
        }
    }
    return false;
}

function stripeWebhookSubscription(PDO $pdo, array $subscription, int $eventCreated): void
{
    $stripeSubscriptionId = (string) ($subscription['id'] ?? '');
    if ($stripeSubscriptionId === '') {
        return;
    }
    $checkout = verifiedCheckoutForMetadata($pdo, (array) ($subscription['metadata'] ?? []));
    if (!$checkout) {
        $existing = $pdo->prepare(
            'SELECT bc.* FROM billing_subscriptions bs
             INNER JOIN billing_checkouts bc ON bc.id = bs.checkout_id
             WHERE bs.stripe_subscription_id = ? LIMIT 1'
        );
        $existing->execute([$stripeSubscriptionId]);
        $checkout = $existing->fetch(PDO::FETCH_ASSOC);
    }
    if (!$checkout) {
        error_log('Ignoring Stripe subscription event with no verified local checkout.');
        return;
    }

    $ownerType = (string) $checkout['owner_type'];
    $priceId = (string) ($subscription['items']['data'][0]['price']['id'] ?? $checkout['stripe_price_id']);
    if ($ownerType === 'personal') {
        $knownPrice = STRIPE_PERSONAL_MONTHLY_PRICE_ID !== '' &&
            hash_equals(STRIPE_PERSONAL_MONTHLY_PRICE_ID, $priceId);
        $planKey = $knownPrice ? 'personal_monthly' : 'unrecognized';
        $seatLimit = null;
    } else {
        $tier = institutionTierForPrice($priceId);
        $knownPrice = $tier !== null;
        $planKey = $tier['key'] ?? 'unrecognized';
        $seatLimit = $tier === null ? 0 : $tier['max'];
    }
    $userId = $ownerType === 'personal' ? (int) $checkout['user_id'] : null;
    $institutionId = $ownerType === 'institution' ? (int) $checkout['institution_id'] : null;
    $periodEnd = stripeTimestampToSql(
        $subscription['current_period_end'] ??
        $subscription['items']['data'][0]['current_period_end'] ??
        null
    );
    $status = preg_match('/^[a-z_]{1,32}$/', (string) ($subscription['status'] ?? '')) === 1
        ? (string) $subscription['status']
        : 'unknown';
    if (!$knownPrice && $status === 'active') {
        error_log('Stripe subscription has a price ID not configured for its InsQuire plan.');
    }

    $upsert = $pdo->prepare(
        'INSERT INTO billing_subscriptions
         (owner_type, user_id, institution_id, checkout_id, stripe_customer_id, stripe_subscription_id,
          stripe_price_id, plan_key, seat_limit, status, cancel_at_period_end, current_period_end, last_event_created)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
          stripe_customer_id = IF(VALUES(last_event_created) >= last_event_created, VALUES(stripe_customer_id), stripe_customer_id),
          stripe_price_id = IF(VALUES(last_event_created) >= last_event_created, VALUES(stripe_price_id), stripe_price_id),
          plan_key = IF(VALUES(last_event_created) >= last_event_created, VALUES(plan_key), plan_key),
          seat_limit = IF(VALUES(last_event_created) >= last_event_created, VALUES(seat_limit), seat_limit),
          status = IF(VALUES(last_event_created) >= last_event_created, VALUES(status), status),
          cancel_at_period_end = IF(VALUES(last_event_created) >= last_event_created, VALUES(cancel_at_period_end), cancel_at_period_end),
          current_period_end = IF(VALUES(last_event_created) >= last_event_created, VALUES(current_period_end), current_period_end),
          last_event_created = GREATEST(last_event_created, VALUES(last_event_created))'
    );
    $upsert->execute([
        $ownerType,
        $userId,
        $institutionId,
        (int) $checkout['id'],
        is_string($subscription['customer'] ?? null) ? $subscription['customer'] : null,
        $stripeSubscriptionId,
        $priceId,
        $planKey,
        $seatLimit,
        $status,
        !empty($subscription['cancel_at_period_end']) ? 1 : 0,
        $periodEnd,
        max(0, $eventCreated),
    ]);
}

function stripeWebhookHandleEvent(PDO $pdo, array $event): void
{
    $type = (string) $event['type'];
    $eventCreated = max(0, (int) ($event['created'] ?? 0));
    $object = (array) ($event['data']['object'] ?? []);

    if (in_array($type, ['customer.subscription.created', 'customer.subscription.updated', 'customer.subscription.deleted', 'customer.subscription.paused', 'customer.subscription.resumed'], true)) {
        stripeWebhookSubscription($pdo, $object, $eventCreated);
        return;
    }

    if (in_array($type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded', 'checkout.session.expired', 'checkout.session.async_payment_failed'], true)) {
        $checkout = verifiedCheckoutForMetadata($pdo, (array) ($object['metadata'] ?? []));
        $sessionId = (string) ($object['id'] ?? '');
        if (!$checkout || $sessionId === '' || ($checkout['stripe_session_id'] !== null && $checkout['stripe_session_id'] !== '' &&
            !hash_equals((string) $checkout['stripe_session_id'], $sessionId))) {
            error_log('Ignoring Stripe Checkout event with no verified matching local checkout.');
            return;
        }
        if (($object['mode'] ?? '') !== 'subscription') {
            return;
        }
        if (in_array($type, ['checkout.session.expired', 'checkout.session.async_payment_failed'], true)) {
            $status = 'expired';
        } else {
            $status = 'completed';
        }
        $update = $pdo->prepare(
            'UPDATE billing_checkouts SET status = ?, stripe_session_id = ?, stripe_customer_id = ?
             WHERE id = ? AND status <> "failed"'
        );
        $customerId = is_string($object['customer'] ?? null) ? $object['customer'] : null;
        $update->execute([$status, $sessionId, $customerId, (int) $checkout['id']]);
        return;
    }

    if ($type === 'invoice.payment_failed' || $type === 'invoice.paid') {
        $subscriptionId = $object['subscription'] ?? null;
        if (is_array($subscriptionId)) {
            $subscriptionId = $subscriptionId['id'] ?? null;
        }
        if (!is_string($subscriptionId) || $subscriptionId === '' || $type === 'invoice.paid') {
            return;
        }
        $update = $pdo->prepare(
            'UPDATE billing_subscriptions
             SET status = "past_due", last_event_created = ?
             WHERE stripe_subscription_id = ? AND ? > last_event_created'
        );
        $update->execute([$eventCreated, $subscriptionId, $eventCreated]);
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}
$payload = file_get_contents('php://input');
$signatureHeader = (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');
if (!is_string($payload) || strlen($payload) > 1_000_000 || !stripeWebhookSignatureIsValid($payload, $signatureHeader)) {
    http_response_code(400);
    exit('Invalid Stripe webhook signature.');
}
$event = json_decode($payload, true);
if (!is_array($event) || !is_string($event['id'] ?? null) || !is_string($event['type'] ?? null)) {
    http_response_code(400);
    exit('Invalid Stripe event.');
}

try {
    $pdo->beginTransaction();
    $insert = $pdo->prepare(
        'INSERT INTO stripe_webhook_events (event_id, event_type, event_created, processing_status)
         VALUES (?, ?, ?, "processing")
         ON DUPLICATE KEY UPDATE event_id = VALUES(event_id)'
    );
    $insert->execute([$event['id'], $event['type'], max(0, (int) ($event['created'] ?? 0))]);
    $eventStmt = $pdo->prepare('SELECT processing_status FROM stripe_webhook_events WHERE event_id = ? FOR UPDATE');
    $eventStmt->execute([$event['id']]);
    if ($eventStmt->fetchColumn() === 'processed') {
        $pdo->commit();
        http_response_code(200);
        exit('Already processed.');
    }
    $pdo->prepare(
        'UPDATE stripe_webhook_events SET processing_status = "processing" WHERE event_id = ?'
    )->execute([$event['id']]);
    stripeWebhookHandleEvent($pdo, $event);
    $pdo->prepare(
        'UPDATE stripe_webhook_events
         SET processing_status = "processed", processed_at = UTC_TIMESTAMP()
         WHERE event_id = ?'
    )->execute([$event['id']]);
    $pdo->commit();
    http_response_code(200);
    exit('OK');
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    try {
        $failed = $pdo->prepare(
            'INSERT INTO stripe_webhook_events (event_id, event_type, event_created, processing_status)
             VALUES (?, ?, ?, "failed")
             ON DUPLICATE KEY UPDATE processing_status = "failed"'
        );
        $failed->execute([$event['id'], $event['type'], max(0, (int) ($event['created'] ?? 0))]);
    } catch (Throwable) {
        // Stripe will retry after the 500 response if even the retry marker cannot be persisted.
    }
    error_log('Stripe webhook processing failed for event ' . $event['id'] . ': ' . $exception->getMessage());
    http_response_code(500);
    exit('Webhook processing failed.');
}
