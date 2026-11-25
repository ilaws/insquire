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
if (
    ($scope === 'personal' && currentAccountScope() !== 'personal') ||
    ($scope === 'institution' && (currentAccountScope() !== 'education' || currentUserRole() !== 'teacher'))
) {
    http_response_code(403);
    exit('This billing portal is not available to this account type.');
}
$baseUrl = billingOwnerBaseUrl($scope);
if ($scope === 'personal') {
    $stmt = $pdo->prepare(
        'SELECT stripe_customer_id FROM billing_subscriptions
         WHERE owner_type = "personal" AND user_id = ? AND stripe_customer_id IS NOT NULL
         ORDER BY updated_at DESC, id DESC LIMIT 1'
    );
    $stmt->execute([$userId]);
} elseif ($scope === 'institution') {
    $institutionId = filter_var($_POST['institution_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$institutionId || !isInstitutionAdmin($pdo, $userId, (int) $institutionId)) {
        http_response_code(403);
        exit('Only an administrator of this institution may manage its billing.');
    }
    $stmt = $pdo->prepare(
        'SELECT stripe_customer_id FROM billing_subscriptions
         WHERE owner_type = "institution" AND institution_id = ? AND stripe_customer_id IS NOT NULL
         ORDER BY updated_at DESC, id DESC LIMIT 1'
    );
    $stmt->execute([(int) $institutionId]);
} else {
    http_response_code(400);
    exit('Invalid billing scope.');
}
$customerId = (string) ($stmt->fetchColumn() ?: '');
if ($customerId === '' || STRIPE_SECRET_KEY === '') {
    http_response_code(404);
    exit('No Stripe customer is available for this account.');
}

try {
    $portal = stripeApiRequest('POST', 'billing_portal/sessions', [
        'customer' => $customerId,
        'return_url' => $baseUrl . '/billing.php',
    ]);
    $url = (string) ($portal['url'] ?? '');
    if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https' ||
        strtolower((string) parse_url($url, PHP_URL_HOST)) !== 'billing.stripe.com') {
        throw new RuntimeException('Stripe returned an invalid billing portal URL.');
    }
    header('Location: ' . $url, true, 303);
    exit;
} catch (Throwable $exception) {
    error_log('Stripe billing portal creation failed for user ' . $userId . ': ' . $exception->getMessage());
    header('Location: billing.php?billing_error=1', true, 303);
    exit;
}
