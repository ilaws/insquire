<?php

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';

if (isset($_SESSION['user_id'])) {
    requireLogin();
    redirect('dashboard.php');
}

function verifyGoogleRegistrationToken(string $idToken): ?array
{
    $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken);
    if (!function_exists('curl_init')) {
        return null;
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return null;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return is_string($response) ? json_decode($response, true) : null;
}

$error = '';
$isEducation = isEducationHost();
$inviteToken = trim((string) ($_POST['invite_token'] ?? $_GET['invite'] ?? ''));
$schoolCodeInput = $_POST['school_code'] ?? '';
$schoolCode = is_string($schoolCodeInput) ? strtoupper(trim($schoolCodeInput)) : '';
if ($isEducation && !is_string($schoolCodeInput)) {
    $error = 'Enter a valid class code.';
} elseif ($isEducation && strlen($schoolCode) > 16) {
    $error = 'Class codes must be 16 characters or fewer.';
}
$invite = false;
if ($isEducation && $inviteToken !== '') {
    $invite = findInstitutionInvite($pdo, $inviteToken);
    if (!$invite) {
        $error = 'This teacher invitation is invalid, expired, already used, or unavailable.';
    }
}
$registrationLink = $inviteToken !== '' ? 'login.php?invite=' . rawurlencode($inviteToken) : 'login.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $googlePayload = null;
    $isGoogleRegistration = !empty($_POST['credential']);
    if ($isGoogleRegistration) {
        $googlePayload = verifyGoogleRegistrationToken((string) $_POST['credential']);
        if (
            !is_array($googlePayload) ||
            empty($googlePayload['sub']) ||
            empty($googlePayload['email']) ||
            empty($googlePayload['email_verified']) ||
            GOOGLE_OAUTH_CLIENT_ID === '' || ($googlePayload['aud'] ?? '') !== GOOGLE_OAUTH_CLIENT_ID
        ) {
            $error = 'Google sign-in validation failed.';
        }
    }

    $name = trim((string) ($isGoogleRegistration
        ? ($googlePayload['name'] ?? '')
        : ($_POST['name'] ?? '')));
    $email = strtolower(trim((string) ($isGoogleRegistration
        ? ($googlePayload['email'] ?? '')
        : ($_POST['email'] ?? ''))));
    if ($isGoogleRegistration && $name === '' && $email !== '') {
        $name = explode('@', $email)[0];
    }
    $googleId = $isGoogleRegistration ? (string) ($googlePayload['sub'] ?? '') : '';
    $password = $isGoogleRegistration ? bin2hex(random_bytes(32)) : (string) ($_POST['password'] ?? '');
    $accountScope = $isEducation ? 'education' : 'personal';
    $role = $isEducation && $invite ? 'teacher' : 'student';

    if ($error === '' && $name === '') {
        $error = 'Name is required.';
    } elseif ($error === '' && mb_strlen($name) > 120) {
        $error = 'Name must be 120 characters or fewer.';
    } elseif ($error === '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } elseif ($error === '' && !$isGoogleRegistration && strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif (
        $error === '' &&
        $isEducation &&
        $invite &&
        $invite['email'] !== null &&
        strcasecmp((string) $invite['email'], $email) !== 0
    ) {
        $error = 'Use the email address this teacher invitation was sent to.';
    }

    if ($error === '') {
        $duplicateSql = 'SELECT id, role FROM users WHERE account_scope = ? AND email = ?';
        $duplicateParams = [$accountScope, $email];
        if ($isGoogleRegistration) {
            $duplicateSql .= ' OR account_scope = ? AND google_id = ?';
            $duplicateParams[] = $accountScope;
            $duplicateParams[] = $googleId;
        }
        $duplicateSql .= ' LIMIT 1';
        $duplicate = $pdo->prepare($duplicateSql);
        $duplicate->execute($duplicateParams);
        $existingAccount = $duplicate->fetch(PDO::FETCH_ASSOC);

        if ($existingAccount) {
            $error = $isEducation && $invite && $existingAccount['role'] !== 'teacher'
                ? 'This email already has a non-teacher Education account. Ask your school administrator to resolve the account before accepting a teacher invitation.'
                : 'An account with that email already exists. Log in to use it.';
        }
    }

    if ($error === '' && $invite && $invite['email'] !== null && strcasecmp((string) $invite['email'], $email) !== 0) {
        $error = 'Use the email address this teacher invitation was sent to.';
    }

    if ($error === '') {
        try {
            $pdo->beginTransaction();

            $class = null;
            if ($isEducation && !$invite) {
                $class = getEducationSignupClass($pdo, true, $schoolCode);
                if (!$class) {
                    $pdo->rollBack();
                    $error = $schoolCode !== ''
                        ? 'That class code is invalid or inactive.'
                        : 'Student registration is temporarily unavailable. Please contact your school administrator.';
                }
            }

            $lockedInvite = null;
            if ($error === '' && $invite) {
                $lockedInvite = findInstitutionInvite($pdo, $inviteToken);
                if (!$lockedInvite || (int) $lockedInvite['id'] !== (int) $invite['id']) {
                    $pdo->rollBack();
                    $error = 'This teacher invitation is no longer available.';
                }
            }

            if ($error === '') {
                $insert = $pdo->prepare(
                    'INSERT INTO users
                     (name, email, password_hash, role, status, google_id, account_scope)
                     VALUES (?, ?, ?, ?, "active", ?, ?)'
                );
                $insert->execute([
                    $name,
                    $email,
                    password_hash($password, PASSWORD_DEFAULT),
                    $role,
                    $isGoogleRegistration ? $googleId : null,
                    $accountScope
                ]);
                $userId = (int) $pdo->lastInsertId();

                if ($invite) {
                    if (!acceptInstitutionInviteInTransaction($pdo, (int) $lockedInvite['id'], $userId)) {
                        $pdo->rollBack();
                        $error = 'This teacher invitation is no longer available for this account.';
                    }
                } elseif ($class) {
                    $member = $pdo->prepare(
                        'INSERT INTO class_members (class_id, user_id) VALUES (?, ?)'
                    );
                    $member->execute([(int) $class['id'], $userId]);
                    if ($class['institution_id'] !== null) {
                        $institutionMember = $pdo->prepare(
                            'INSERT INTO institution_members (institution_id, user_id, role)
                             VALUES (?, ?, "student")'
                        );
                        $institutionMember->execute([(int) $class['institution_id'], $userId]);
                    }
                }

                if ($error === '') {
                    $pdo->commit();
                    redirect('thank-you.php');
                }
            }
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (
                $exception->getCode() === '23000' &&
                (int) ($exception->errorInfo[1] ?? 0) === 1062
            ) {
                $error = 'An account with that email already exists.';
            } else {
                throw $exception;
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }
}

$title = 'Register';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<script src="https://accounts.google.com/gsi/client" async defer></script>

<div class="card small-card">
    <h1>Create Account</h1>
    <?php if ($error !== ''): ?>
    <div class="error" id="register-error" role="alert"><?= e($error) ?></div>
        <?php if (str_contains($error, 'already exists')): ?>
            <p><a href="<?= e($registrationLink) ?>">Log in to this account</a></p>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($isEducation && $invite): ?>
        <p>This invitation lets you create a teacher account for <strong><?= e((string) $invite['name']) ?></strong>.</p>
    <?php elseif ($isEducation): ?>
        <p>Student accounts are added to the configured default class. Enter a class code if your school gave you one. Teachers need a school invitation; administrators provision accounts privately.</p>
    <?php else: ?>
        <p>Create a Personal account for InsQuire.</p>
    <?php endif; ?>

    <form method="post"<?= $error !== '' ? ' aria-describedby="register-error"' : '' ?>>
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <?php if ($inviteToken !== ''): ?>
            <input type="hidden" name="invite_token" value="<?= e($inviteToken) ?>">
        <?php endif; ?>
        <label for="register-name">Name</label>
        <input id="register-name" type="text" name="name" maxlength="120" required autocomplete="name">

        <label for="register-email">Email</label>
        <input id="register-email" type="email" name="email" maxlength="190"
               value="<?= e($invite && $invite['email'] !== null ? (string) $invite['email'] : '') ?>"
               required autocomplete="email">

        <?php if ($isEducation && !$invite): ?>
            <label for="register-school-code">Class code (optional)</label>
            <input id="register-school-code" type="text" name="school_code" maxlength="16" autocomplete="off">
        <?php endif; ?>

        <label for="register-password">Password</label>
        <input id="register-password" type="password" name="password" minlength="8" required autocomplete="new-password">

        <button class="button" type="submit">Create Account</button>
    </form>

    <?php if (GOOGLE_OAUTH_CLIENT_ID !== ''): ?>
    <div style="text-align:center;margin:15px 0;color:#666">OR</div>
    <div id="g_id_onload"
         data-client_id="<?= e(GOOGLE_OAUTH_CLIENT_ID) ?>"
         data-callback="handleGoogleRegister"></div>
    <div class="google-signin-wrap">
        <div class="g_id_signin" data-type="standard" data-width="240"></div>
    </div>
    <?php endif; ?>

    <p style="margin-top:20px"><a href="<?= e($registrationLink) ?>">Already have an account? Log in</a></p>
</div>

<script>
function handleGoogleRegister(response) {
    const form = document.createElement('form');
    form.method = 'POST';
    const values = {
        csrf_token: <?= json_encode(csrfToken()) ?>,
        credential: response.credential,
        name: document.getElementById('register-name').value,
        email: document.getElementById('register-email').value,
        invite_token: <?= json_encode($inviteToken) ?>
    };
    for (const [name, value] of Object.entries(values)) {
        if (value === '') continue;
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.appendChild(input);
    }
    document.body.appendChild(form);
    form.submit();
}
</script>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
