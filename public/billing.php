<?php

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/billing.php';

requireLogin();
if (currentUserRole() === 'admin') {
    redirect('admin/index.php');
}
$userId = currentUserId();
$accountScope = currentAccountScope();
$userStmt = $pdo->prepare('SELECT email FROM users WHERE id = ? AND status = "active" LIMIT 1');
$userStmt->execute([$userId]);
$email = (string) $userStmt->fetchColumn();

$personalSubscription = null;
if ($accountScope === 'personal') {
    $personalStmt = $pdo->prepare(
        'SELECT plan_key, status, cancel_at_period_end, current_period_end, stripe_customer_id, stripe_price_id
         FROM billing_subscriptions WHERE owner_type = "personal" AND user_id = ?
         ORDER BY updated_at DESC, id DESC LIMIT 1'
    );
    $personalStmt->execute([$userId]);
    $personalSubscription = $personalStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
$personalEntitled = $personalSubscription &&
    in_array($personalSubscription['status'], ['active', 'trialing'], true) &&
    STRIPE_PERSONAL_MONTHLY_PRICE_ID !== '' &&
    hash_equals(STRIPE_PERSONAL_MONTHLY_PRICE_ID, (string) $personalSubscription['stripe_price_id']);
$personalBlocksCheckout = billingSubscriptionBlocksCheckout($personalSubscription);

$institutions = [];
if ($accountScope === 'education' && currentUserRole() === 'teacher') {
    $institutionStmt = $pdo->prepare(
        'SELECT i.id, i.name, im.role
         FROM institution_members im
         INNER JOIN institutions i ON i.id = im.institution_id AND i.status = "active"
         WHERE im.user_id = ? AND im.role = "admin"
         ORDER BY i.name'
    );
    $institutionStmt->execute([$userId]);
    $institutions = $institutionStmt->fetchAll(PDO::FETCH_ASSOC);
}
foreach ($institutions as &$institution) {
    $institution['seats'] = institutionActiveSeatCount($pdo, (int) $institution['id']);
    $institution['tier'] = institutionTierForSeats($institution['seats']);
    $subStmt = $pdo->prepare(
        'SELECT plan_key, status, cancel_at_period_end, current_period_end, stripe_customer_id, seat_limit
         FROM billing_subscriptions WHERE owner_type = "institution" AND institution_id = ?
         ORDER BY updated_at DESC, id DESC LIMIT 1'
    );
    $subStmt->execute([(int) $institution['id']]);
    $institution['subscription'] = $subStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $institution['blocks_checkout'] = billingSubscriptionBlocksCheckout($institution['subscription']);
}
unset($institution);

$metadataReady = ESSAY_BILLING_METADATA_SECRET !== '';
$stripeReady = STRIPE_SECRET_KEY !== '' && STRIPE_WEBHOOK_SECRET !== '' && $metadataReady;
$title = 'Plans and billing';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <p class="eyebrow">Account plans</p>
        <h1>Plans and billing</h1>
        <p>Subscription status shown here is synchronized only from signed Stripe webhooks. Checkout redirects and browser-supplied status never activate, renew, or cancel a plan.</p>
    </div>
</div>

<?php if (isset($_GET['checkout']) && $_GET['checkout'] === 'return'): ?>
    <div class="muted" role="status">Checkout returned. This page does not infer payment success; subscription status changes only after Stripe's signed webhook is verified and processed.</div>
<?php elseif (isset($_GET['checkout']) && $_GET['checkout'] === 'cancel'): ?>
    <div class="muted" role="status">Checkout was canceled. No plan change has been recorded.</div>
<?php endif; ?>
<?php if (isset($_GET['billing_error'])): ?>
    <div class="error" role="alert">Billing could not be started. Please check your plan configuration or contact support.</div>
<?php endif; ?>

<?php if ($accountScope === 'personal'): ?>
<section class="card">
    <h2>Personal</h2>
    <p><strong>Free:</strong> <?= AI_PERSONAL_FREE_DAILY_LIMIT ?> AI requests per rolling 24 hours. One contextual, non-personalized AdSense slot may appear on the Personal work planner only after publisher, consent, and server configuration are complete.</p>
    <p><strong>Paid:</strong> <?= AI_PERSONAL_PAID_DAILY_LIMIT ?> AI requests per rolling 24 hours and no ads. Suggested starter price is $4.99/month; the actual amount and currency come from the Stripe Price configured by InsQuire.</p>
    <?php if ($personalSubscription): ?>
        <p>Subscription status: <strong><?= e(ucfirst(str_replace('_', ' ', $personalSubscription['status']))) ?></strong>
            <?php if (!empty($personalSubscription['cancel_at_period_end'])): ?> · scheduled to cancel at period end<?php endif; ?>
            <?php if (!empty($personalSubscription['current_period_end'])): ?> · current period ends <?= e((string) $personalSubscription['current_period_end']) ?> UTC<?php endif; ?>
        </p>
        <?php if (!empty($personalSubscription['stripe_customer_id'])): ?>
            <form method="post" action="create-portal.php">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="scope" value="personal">
                <button class="button secondary" type="submit">Manage or cancel subscription</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
    <?php if ($personalEntitled): ?>
        <p class="muted">Your configured Personal paid plan is active. Manage its payment method or cancellation above.</p>
    <?php elseif ($personalSubscription && in_array($personalSubscription['status'], ['active', 'trialing'], true)): ?>
        <p class="error">Stripe reports an active subscription, but its Price ID is not currently configured for Personal Paid access. Contact support; the subscription remains manageable above.</p>
    <?php endif; ?>
    <?php if ($personalBlocksCheckout): ?>
        <p class="muted">Manage the existing Personal subscription above. A new subscription cannot be started while this one is unresolved.</p>
    <?php elseif (STRIPE_PERSONAL_MONTHLY_PRICE_ID !== '' && $stripeReady): ?>
        <form method="post" action="create-checkout.php">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="scope" value="personal">
            <button class="button" type="submit">Subscribe to Personal Paid</button>
        </form>
    <?php else: ?>
        <button class="button" type="button" disabled>Personal Paid checkout is not configured</button>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($accountScope === 'education' && currentUserRole() === 'teacher'): ?>
<section class="card">
    <h2>Education institution plans</h2>
    <p>Monthly flat pricing is selected from distinct, active institution members. Education has no ads and active paid institutional members receive <?= AI_EDUCATION_DAILY_LIMIT ?> AI requests per rolling 24 hours. Tier upgrades are never silently charged: changes must be explicitly made by an institution administrator.</p>
    <?php foreach (institutionBillingTiers() as $tier): ?>
        <p><strong><?= e($tier['min'] . '–' . ($tier['max'] === null ? 'unlimited' : $tier['max'])) ?> active users:</strong> <?= e($tier['amount']) ?></p>
    <?php endforeach; ?>
    <p class="muted">The 3,200+ tier is a fixed $1,199/month with no per-seat overage, not a metered price. The configured Stripe Price must be a recurring monthly flat-rate price.</p>
    <?php if (!$institutions): ?>
        <p class="muted">Only an administrator of an active institution can start or manage institution billing.</p>
    <?php else: ?>
        <?php foreach ($institutions as $institution): ?>
            <article class="list-item">
                <div>
                    <h3><?= e($institution['name']) ?></h3>
                    <p><?= (int) $institution['seats'] ?> distinct active institution member(s).
                        <?php if ($institution['tier']): ?>
                            Suggested current tier: <?= e($institution['tier']['amount']) ?> (<?= e($institution['tier']['min'] . '–' . ($institution['tier']['max'] ?? 'unlimited')) ?>).
                        <?php else: ?>
                            Add at least one active institution member before subscribing.
                        <?php endif; ?>
                    </p>
                    <?php $subscription = $institution['subscription']; ?>
                    <?php if ($subscription): ?>
                        <p>Subscription status: <strong><?= e(ucfirst(str_replace('_', ' ', $subscription['status']))) ?></strong>
                            <?php if (!empty($subscription['cancel_at_period_end'])): ?> · scheduled to cancel at period end<?php endif; ?>
                            <?php if (!empty($subscription['current_period_end'])): ?> · period ends <?= e((string) $subscription['current_period_end']) ?> UTC<?php endif; ?>
                        </p>
                        <?php if (!empty($subscription['stripe_customer_id'])): ?>
                            <form method="post" action="create-portal.php">
                                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                <input type="hidden" name="scope" value="institution">
                                <input type="hidden" name="institution_id" value="<?= (int) $institution['id'] ?>">
                                <button class="button secondary" type="submit">Manage or cancel institution subscription</button>
                            </form>
                        <?php endif; ?>
                        <?php if (in_array($subscription['status'], ['active', 'trialing'], true) && (int) $institution['seats'] > (int) ($subscription['seat_limit'] ?? PHP_INT_MAX)): ?>
                            <p class="error">The active roster exceeds the paid tier's seat ceiling. The institution must arrange a higher configured tier; paid Education AI entitlement is paused for this institution until its roster fits a verified tier.</p>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if (!$institution['blocks_checkout']): ?>
                        <?php if ($institution['tier'] && $institution['tier']['price_id'] !== '' && $stripeReady): ?>
                        <form method="post" action="create-checkout.php">
                            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                            <input type="hidden" name="scope" value="institution">
                            <input type="hidden" name="institution_id" value="<?= (int) $institution['id'] ?>">
                            <button class="button" type="submit">Subscribe institution · <?= e($institution['tier']['amount']) ?></button>
                        </form>
                        <?php else: ?>
                            <button class="button" type="button" disabled>Institution checkout is not configured</button>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>
</section>
<?php elseif ($accountScope === 'education'): ?>
<section class="card"><p class="muted">Institution billing is managed by a teacher who is an administrator of that institution.</p></section>
<?php endif; ?>

<p class="muted">AI limits are rolling 24-hour account caps; existing per-minute and per-hour burst limits remain in effect. Google AdSense is disabled by default and requires manual publisher/consent verification; no tracking or personalized-ad code is included.</p>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
