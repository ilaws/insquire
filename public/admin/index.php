<?php

require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/csrf.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';

if (!isLoggedIn()) {
    header('Location: ../login.php');
    exit;
}
requireAdmin();
$actorId = currentUserId();
$notice = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $targetId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
    $reason = is_string($_POST['reason'] ?? null) ? trim($_POST['reason']) : '';
    if (!$targetId || $targetId < 1 || mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
        $error = 'Choose an account and provide a reason of 5 to 500 characters.';
    } else {
        $targetStmt = $pdo->prepare('SELECT id, role FROM users WHERE id = ? LIMIT 1');
        $targetStmt->execute([(int) $targetId]);
        $target = $targetStmt->fetch(PDO::FETCH_ASSOC);
        if (!$target || $target['role'] === 'admin') {
            $error = 'Platform administrator accounts cannot receive or have this entitlement revoked here.';
        } elseif ($action === 'grant') {
            $expiresAt = null;
            $expiryInput = is_string($_POST['expires_at'] ?? null) ? trim($_POST['expires_at']) : '';
            if ($expiryInput !== '') {
                $expiry = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $expiryInput, new DateTimeZone('UTC'));
                $expiryErrors = DateTimeImmutable::getLastErrors();
                if (
                    !$expiry ||
                    ($expiryErrors !== false && ($expiryErrors['warning_count'] > 0 || $expiryErrors['error_count'] > 0)) ||
                    $expiry <= new DateTimeImmutable('now', new DateTimeZone('UTC'))
                ) {
                    $error = 'Expiry must be a valid future UTC date and time.';
                } else {
                    $expiresAt = $expiry->format('Y-m-d H:i:s');
                }
            }
            if ($error === '') {
                $pdo->beginTransaction();
                try {
                    $grant = $pdo->prepare(
                        'INSERT INTO platform_entitlement_overrides (user_id, granted_by, reason, expires_at)
                         VALUES (?, ?, ?, ?)'
                    );
                    $grant->execute([(int) $targetId, $actorId, $reason, $expiresAt]);
                    $audit = $pdo->prepare(
                        'INSERT INTO platform_entitlement_audit (user_id, actor_id, action, reason, expires_at)
                         VALUES (?, ?, "granted", ?, ?)'
                    );
                    $audit->execute([(int) $targetId, $actorId, $reason, $expiresAt]);
                    $pdo->commit();
                    $notice = 'Unrestricted daily AI entitlement granted and audited.';
                } catch (Throwable $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $exception;
                }
            }
        } elseif ($action === 'revoke') {
            $pdo->beginTransaction();
            try {
                $revoke = $pdo->prepare(
                    'UPDATE platform_entitlement_overrides
                     SET revoked_by = ?, revoked_at = UTC_TIMESTAMP()
                     WHERE user_id = ? AND revoked_at IS NULL'
                );
                $revoke->execute([$actorId, (int) $targetId]);
                $audit = $pdo->prepare(
                    'INSERT INTO platform_entitlement_audit (user_id, actor_id, action, reason)
                     VALUES (?, ?, "revoked", ?)'
                );
                $audit->execute([(int) $targetId, $actorId, $reason]);
                $pdo->commit();
                $notice = $revoke->rowCount() > 0
                    ? 'Unrestricted entitlement revoked and audited.'
                    : 'No active unrestricted entitlement was found. The revocation attempt was audited.';
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
        } else {
            $error = 'Invalid administrative action.';
        }
    }
}

$search = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 190) : '';
$userStmt = $pdo->prepare(
    'SELECT u.id, u.name, u.email, u.role, u.status,
       (SELECT COUNT(*) FROM platform_entitlement_overrides eo
        WHERE eo.user_id = u.id AND eo.revoked_at IS NULL
          AND (eo.expires_at IS NULL OR eo.expires_at > UTC_TIMESTAMP())) AS active_overrides,
       (SELECT MAX(eo.expires_at) FROM platform_entitlement_overrides eo
        WHERE eo.user_id = u.id AND eo.revoked_at IS NULL
          AND (eo.expires_at IS NULL OR eo.expires_at > UTC_TIMESTAMP())) AS override_expires_at
     FROM users u
     WHERE (? = "" OR u.name LIKE ? OR u.email LIKE ?)
     ORDER BY u.created_at DESC, u.id DESC LIMIT 50'
);
$like = '%' . $search . '%';
$userStmt->execute([$search, $like, $like]);
$users = $userStmt->fetchAll(PDO::FETCH_ASSOC);

$auditStmt = $pdo->query(
    'SELECT a.action, a.reason, a.expires_at, a.created_at,
            target.name AS target_name, target.email AS target_email, actor.name AS actor_name
     FROM platform_entitlement_audit a
     INNER JOIN users target ON target.id = a.user_id
     INNER JOIN users actor ON actor.id = a.actor_id
     ORDER BY a.id DESC LIMIT 30'
);
$audits = $auditStmt->fetchAll(PDO::FETCH_ASSOC);
$title = 'Platform administration';
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="page-header">
    <div><p class="eyebrow">Platform administrator</p><h1>Account entitlements</h1>
    <p>Grant or revoke an unlimited daily AI allowance. Existing per-minute and per-hour burst caps always remain in effect. Every change requires a reason and is recorded in the audit trail.</p></div>
</div>
<?php if ($error !== ''): ?><div class="error" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if ($notice !== ''): ?><div class="success" role="status"><?= e($notice) ?></div><?php endif; ?>

<section class="card">
    <h2>Find an account</h2>
    <form method="get" action="index.php" class="message-compose">
        <label for="admin-search">Name or email</label>
        <input id="admin-search" name="q" value="<?= e($search) ?>" maxlength="190">
        <button class="button" type="submit">Search</button>
    </form>
    <div class="list">
        <?php foreach ($users as $user): ?>
            <article class="list-item">
                <div>
                    <strong><?= e($user['name']) ?></strong>
                    <p><?= e($user['email']) ?> · <?= e($user['role']) ?> · <?= e($user['status']) ?></p>
                    <p class="muted">
                        <?= (int) $user['active_overrides'] > 0 ? 'Unrestricted daily AI allowance active' : 'No active override' ?>
                        <?php if (!empty($user['override_expires_at'])): ?> · expires <?= e((string) $user['override_expires_at']) ?> UTC<?php endif; ?>
                    </p>
                </div>
                <?php if ($user['role'] !== 'admin'): ?>
                    <form method="post" action="index.php<?= $search !== '' ? '?q=' . rawurlencode($search) : '' ?>" class="message-compose">
                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                        <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                        <label for="reason-<?= (int) $user['id'] ?>">Reason (required)</label>
                        <input id="reason-<?= (int) $user['id'] ?>" name="reason" maxlength="500" minlength="5" required>
                        <?php if ((int) $user['active_overrides'] > 0): ?>
                            <input type="hidden" name="action" value="revoke">
                            <button class="button secondary" type="submit">Revoke access</button>
                        <?php else: ?>
                            <input type="hidden" name="action" value="grant">
                            <label for="expiry-<?= (int) $user['id'] ?>">Optional expiry (UTC)</label>
                            <input id="expiry-<?= (int) $user['id'] ?>" name="expires_at" type="datetime-local">
                            <button class="button" type="submit">Grant unrestricted access</button>
                        <?php endif; ?>
                    </form>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
        <?php if (!$users): ?><p class="muted">No accounts matched that search.</p><?php endif; ?>
    </div>
</section>

<section class="card">
    <h2>Recent entitlement audit</h2>
    <?php if (!$audits): ?><p class="muted">No entitlement changes have been recorded.</p>
    <?php else: ?>
        <div class="list">
            <?php foreach ($audits as $audit): ?>
                <article class="list-item">
                    <div>
                        <strong><?= e(ucfirst($audit['action'])) ?> · <?= e($audit['target_name']) ?> (<?= e($audit['target_email']) ?>)</strong>
                        <p><?= e($audit['reason']) ?></p>
                        <p class="muted">By <?= e($audit['actor_name']) ?> · <?= e((string) $audit['created_at']) ?> UTC
                            <?php if (!empty($audit['expires_at'])): ?> · expiry <?= e((string) $audit['expires_at']) ?> UTC<?php endif; ?>
                        </p>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
