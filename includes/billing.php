<?php

require_once __DIR__ . '/functions.php';

function institutionBillingTiers(): array
{
    return [
        ['key' => 'edu_1_99', 'min' => 1, 'max' => 99, 'amount' => '$49/mo', 'price_id' => STRIPE_EDU_PRICE_1_99],
        ['key' => 'edu_100_299', 'min' => 100, 'max' => 299, 'amount' => '$99/mo', 'price_id' => STRIPE_EDU_PRICE_100_299],
        ['key' => 'edu_300_699', 'min' => 300, 'max' => 699, 'amount' => '$199/mo', 'price_id' => STRIPE_EDU_PRICE_300_699],
        ['key' => 'edu_700_1299', 'min' => 700, 'max' => 1299, 'amount' => '$349/mo', 'price_id' => STRIPE_EDU_PRICE_700_1299],
        ['key' => 'edu_1300_2199', 'min' => 1300, 'max' => 2199, 'amount' => '$549/mo', 'price_id' => STRIPE_EDU_PRICE_1300_2199],
        ['key' => 'edu_2200_3199', 'min' => 2200, 'max' => 3199, 'amount' => '$799/mo', 'price_id' => STRIPE_EDU_PRICE_2200_3199],
        ['key' => 'edu_3200_plus', 'min' => 3200, 'max' => null, 'amount' => '$1199/mo', 'price_id' => STRIPE_EDU_PRICE_3200_PLUS],
    ];
}

function institutionActiveSeatCount(PDO $pdo, int $institutionId): int
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(DISTINCT im.user_id)
         FROM institution_members im
         INNER JOIN users u ON u.id = im.user_id AND u.status = "active"
         INNER JOIN institutions i ON i.id = im.institution_id AND i.status = "active"
         WHERE im.institution_id = ?'
    );
    $stmt->execute([$institutionId]);
    return (int) $stmt->fetchColumn();
}

function institutionTierForSeats(int $seats): ?array
{
    if ($seats < 1) {
        return null;
    }
    foreach (institutionBillingTiers() as $tier) {
        if ($seats >= $tier['min'] && ($tier['max'] === null || $seats <= $tier['max'])) {
            return $tier;
        }
    }
    return null;
}

function institutionTierForPrice(string $priceId): ?array
{
    if ($priceId === '') {
        return null;
    }
    foreach (institutionBillingTiers() as $tier) {
        if ($tier['price_id'] !== '' && hash_equals($tier['price_id'], $priceId)) {
            return $tier;
        }
    }
    return null;
}

function checkoutMetadataSignature(int $checkoutId, string $ownerType, int $ownerId, int $createdBy, string $planKey): string
{
    if (ESSAY_BILLING_METADATA_SECRET === '') {
        throw new RuntimeException('Billing metadata signing is not configured.');
    }
    $message = implode('|', [$checkoutId, $ownerType, $ownerId, $createdBy, $planKey]);
    return hash_hmac('sha256', $message, ESSAY_BILLING_METADATA_SECRET);
}

function verifiedCheckoutForMetadata(PDO $pdo, array $metadata): array|false
{
    $checkoutId = filter_var($metadata['checkout_id'] ?? null, FILTER_VALIDATE_INT);
    $ownerId = filter_var($metadata['owner_id'] ?? null, FILTER_VALIDATE_INT);
    $createdBy = filter_var($metadata['created_by'] ?? null, FILTER_VALIDATE_INT);
    $ownerType = (string) ($metadata['owner_type'] ?? '');
    $planKey = (string) ($metadata['plan_key'] ?? '');
    $signature = (string) ($metadata['signature'] ?? '');
    if (!$checkoutId || !$ownerId || !$createdBy || !in_array($ownerType, ['personal', 'institution'], true) || $signature === '') {
        return false;
    }
    $stmt = $pdo->prepare('SELECT * FROM billing_checkouts WHERE id = ? LIMIT 1');
    $stmt->execute([$checkoutId]);
    $checkout = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$checkout || $checkout['owner_type'] !== $ownerType || $checkout['plan_key'] !== $planKey) {
        return false;
    }
    $storedOwnerId = $ownerType === 'personal' ? (int) $checkout['user_id'] : (int) $checkout['institution_id'];
    if (
        $storedOwnerId !== $ownerId ||
        (int) $checkout['created_by'] !== $createdBy ||
        !hash_equals((string) $checkout['metadata_signature'], $signature)
    ) {
        return false;
    }
    try {
        return hash_equals(
            (string) $checkout['metadata_signature'],
            checkoutMetadataSignature((int) $checkout['id'], $ownerType, $ownerId, $createdBy, $planKey)
        ) ? $checkout : false;
    } catch (Throwable) {
        return false;
    }
}

function stripeApiRequest(string $method, string $path, array $parameters = []): array
{
    if (STRIPE_SECRET_KEY === '') {
        throw new RuntimeException('Stripe is not configured.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP cURL extension is required for Stripe billing.');
    }
    $url = 'https://api.stripe.com/v1/' . ltrim($path, '/');
    $curl = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . STRIPE_SECRET_KEY],
    ];
    if (strtoupper($method) === 'POST') {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    } else {
        $options[CURLOPT_CUSTOMREQUEST] = strtoupper($method);
    }
    curl_setopt_array($curl, $options);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);
    if (!is_string($body)) {
        throw new RuntimeException('Stripe request failed: ' . ($curlError !== '' ? $curlError : 'network error'));
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded) || $status < 200 || $status >= 300) {
        $stripeError = is_array($decoded) ? (string) ($decoded['error']['message'] ?? '') : '';
        throw new RuntimeException('Stripe request failed' . ($stripeError !== '' ? ': ' . $stripeError : '.'));
    }
    return $decoded;
}

function stripeTimestampToSql(mixed $timestamp): ?string
{
    if (!is_numeric($timestamp) || (int) $timestamp <= 0) {
        return null;
    }
    return gmdate('Y-m-d H:i:s', (int) $timestamp);
}

function billingOwnerBaseUrl(string $ownerType): string
{
    return $ownerType === 'institution' ? EDUCATION_APP_URL : PERSONAL_APP_URL;
}

function billingSubscriptionBlocksCheckout(?array $subscription): bool
{
    if (!$subscription) {
        return false;
    }
    if (!in_array(
        (string) ($subscription['status'] ?? ''),
        ['active', 'trialing', 'incomplete', 'past_due', 'unpaid', 'paused', 'canceled'],
        true
    )) {
        return false;
    }
    $periodEnd = $subscription['current_period_end'] ?? null;
    if (!is_string($periodEnd) || $periodEnd === '') {
        return true;
    }
    $expiry = new DateTimeImmutable($periodEnd, new DateTimeZone('UTC'));
    return $expiry > new DateTimeImmutable('now', new DateTimeZone('UTC'));
}
