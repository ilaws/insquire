<?php

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
requireEducationHost();
if (currentUserRole() !== 'teacher') {
    http_response_code(403);
    exit('Only a signed-in teacher can accept an institution invitation.');
}

$token = trim((string) ($_POST['token'] ?? $_GET['token'] ?? ''));
$error = '';
$invite = null;
if (!$invite = findInstitutionInvite($pdo, $token)) {
    $error = 'This invitation is invalid, expired, already used, or unavailable.';
} elseif (
    $invite['email'] !== null &&
    strcasecmp((string) $invite['email'], (string) ($_SESSION['email'] ?? '')) !== 0
) {
    $error = 'Sign in with the email address this invitation was sent to.';
    $invite = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $invite) {
    verifyCsrf();
    $pdo->beginTransaction();
    try {
        if (!acceptInstitutionInviteInTransaction($pdo, (int) $invite['id'], currentUserId())) {
            $pdo->rollBack();
            $error = 'This invitation is no longer available for this account.';
        } else {
            $pdo->commit();
            redirect('institutions.php?joined=1');
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

$title = 'Accept Institution Invitation';
require_once dirname(__DIR__) . '/includes/header.php';
?>
<section class="card small-card">
    <h1>Institution invitation</h1>
    <?php if ($error !== ''): ?><div class="error" role="alert"><?= e($error) ?></div>
    <?php else: ?>
        <p>Join <strong><?= e($invite['name']) ?></strong> as a teacher?</p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <button class="button" type="submit">Accept invitation</button>
        </form>
    <?php endif; ?>
</section>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
