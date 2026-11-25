<?php

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';

if (isset($_SESSION['user_id'])) {
    requireLogin();
    redirect('dashboard.php');
}

$error = '';
$inviteToken = trim((string) ($_POST['invite_token'] ?? $_GET['invite'] ?? ''));

function verifyGoogleToken(string $idToken): ?array
{
    $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken);

    if (function_exists('curl_init')) {
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

    $response = @file_get_contents($url);
    return is_string($response) ? json_decode($response, true) : null;
}

function establishLoginSession(
    int $userId,
    string $name,
    string $email,
    string $role,
    string $accountScope
): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    $_SESSION['name'] = $name;
    $_SESSION['email'] = $email;
    $_SESSION['role'] = $role;
    $_SESSION['account_scope'] = $accountScope;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    if (!empty($_POST['credential'])) {
        $payload = verifyGoogleToken((string) $_POST['credential']);

        if (
            is_array($payload) &&
            !empty($payload['sub']) &&
            !empty($payload['email']) &&
            !empty($payload['email_verified']) &&
            GOOGLE_OAUTH_CLIENT_ID !== '' && ($payload['aud'] ?? '') === GOOGLE_OAUTH_CLIENT_ID
        ) {
            $googleId = (string) $payload['sub'];
            $email = strtolower(trim((string) $payload['email']));
            $name = trim((string) ($payload['name'] ?? explode('@', $email)[0]));

            $accountScope = isEducationHost() ? 'education' : 'personal';
            $stmt = $pdo->prepare(
                'SELECT * FROM users WHERE account_scope = ? AND (email = ? OR google_id = ?) LIMIT 1'
            );
            $stmt->execute([$accountScope, $email, $googleId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC            );

            if (!$user) {
                $error = 'No account was found. Sign up to join InsQuire Test, or use a school invitation for a teacher account.';
            } elseif (($user['status'] ?? '') !== 'active') {
                $error = 'This account is unavailable.';
            } else {
                if (empty($user['google_id'])) {
                    $update = $pdo->prepare('UPDATE users SET google_id = ? WHERE id = ?');
                    $update->execute([$googleId, (int) $user['id']]);
                }
                establishLoginSession(
                    (int) $user['id'],
                    (string) $user['name'],
                    (string) $user['email'],
                    (string) $user['role'],
                    (string) $user['account_scope']
                );
                if (
                    isEducationHost() &&
                    (string) $user['role'] === 'teacher' &&
                    preg_match('/\A[a-f0-9]{64}\z/', $inviteToken)
                ) {
                    redirect('institution-invite.php?token=' . rawurlencode($inviteToken));
                }
                redirect('dashboard.php');
            }
        } else {
            $error = 'Google sign-in validation failed.';
        }
    } else {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $stmt = $pdo->prepare(
            'SELECT id, name, email, role, password_hash, account_scope
             FROM users
             WHERE email = ? AND account_scope = ? AND status = "active"
             LIMIT 1'
        );
        $requestedScope = isEducationHost() ? 'education' : 'personal';
        $stmt->execute([$email, $requestedScope]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            $error = 'Invalid email or password.';
        } else {
            establishLoginSession(
                (int) $user['id'],
                (string) $user['name'],
                (string) $user['email'],
                (string) $user['role'],
                (string) $user['account_scope']
            );
            if (
                isEducationHost() &&
                (string) $user['role'] === 'teacher' &&
                preg_match('/\A[a-f0-9]{64}\z/', $inviteToken)
            ) {
                redirect('institution-invite.php?token=' . rawurlencode($inviteToken));
            }
            redirect('dashboard.php');
        }
    }
}

$title = 'Login';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<script src="https://accounts.google.com/gsi/client" async defer></script>

<div class="card small-card">
    <h1>Log In</h1>
    <?php if ($error): ?><div class="error" id="login-error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <?php if (isset($_GET['registered'])): ?><div class="success">Your account has been created. You can log in now.</div><?php endif; ?>
    <form method="post"<?= $error !== '' ? ' aria-describedby="login-error"' : '' ?>>
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <?php if (preg_match('/\A[a-f0-9]{64}\z/', $inviteToken)): ?>
            <input type="hidden" name="invite_token" value="<?= e($inviteToken) ?>">
        <?php endif; ?>
        <label for="login-email">Email</label>
        <input id="login-email" type="email" name="email" autocomplete="username" required>
        <label for="login-password">Password</label>
        <input id="login-password" type="password" name="password" autocomplete="current-password" required>
        <button class="button" type="submit">Log In</button>
    </form>
    <?php if (GOOGLE_OAUTH_CLIENT_ID !== ''): ?>
    <div style="text-align:center;margin:15px 0;color:#666">OR</div>
    <div id="g_id_onload"
         data-client_id="<?= e(GOOGLE_OAUTH_CLIENT_ID) ?>"
         data-callback="handleGoogleLogin"></div>
    <div class="google-signin-wrap">
        <div class="g_id_signin" data-type="standard" data-width="240"></div>
    </div>
    <?php endif; ?>
    <p style="margin-top:20px"><a href="register.php<?= preg_match('/\A[a-f0-9]{64}\z/', $inviteToken) ? '?invite=' . rawurlencode($inviteToken) : '' ?>">Create an account</a></p>
</div>

<script>
function handleGoogleLogin(response) {
    const form = document.createElement('form');
    form.method = 'POST';
    const csrfToken = document.createElement('input');
    csrfToken.type = 'hidden';
    csrfToken.name = 'csrf_token';
    csrfToken.value = <?= json_encode(csrfToken()) ?>;
    form.appendChild(csrfToken);
    const token = document.createElement('input');
    token.type = 'hidden';
    token.name = 'credential';
    token.value = response.credential;
    form.appendChild(token);
    const inviteToken = <?= json_encode(preg_match('/\A[a-f0-9]{64}\z/', $inviteToken) ? $inviteToken : '') ?>;
    if (inviteToken) {
        const invite = document.createElement('input');
        invite.type = 'hidden';
        invite.name = 'invite_token';
        invite.value = inviteToken;
        form.appendChild(invite);
    }
    document.body.appendChild(form);
    form.submit();
}
</script>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
