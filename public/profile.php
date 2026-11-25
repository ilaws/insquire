<?php

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
if (currentUserRole() === 'admin') {
    redirect('admin/index.php');
}

$error = '';
$success = '';
$userId = currentUserId();
$isEducationProfile = isEducationHost() && in_array(currentUserRole(), ['student', 'teacher'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'delete_account') {
        if (($_POST['delete_confirmation'] ?? '') !== 'DELETE') {
            $error = 'Type DELETE to confirm account removal.';
        } else {
            $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            session_destroy();
            redirect('index.php');
        }
    } else {
        $name = trim((string) ($_POST['name'] ?? ''));
        $fontScale = in_array($_POST['font_scale'] ?? 'normal', ['normal', 'large', 'x-large'], true)
            ? $_POST['font_scale']
            : 'normal';
        $highContrast = isset($_POST['high_contrast']) ? 1 : 0;
        $reducedMotion = isset($_POST['reduced_motion']) ? 1 : 0;
        $bio = trim((string) ($_POST['bio'] ?? ''));
        $newImageName = null;

        if ($name === '') {
            $error = 'Name is required.';
        } elseif ($isEducationProfile && mb_strlen($bio) > 500) {
            $error = 'Bio must be 500 characters or fewer.';
        } else {
            $image = $_FILES['profile_picture'] ?? null;
            if ($isEducationProfile && is_array($image) && ($image['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                if (($image['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    $error = 'The profile picture upload failed. Please try again.';
                } elseif (($image['size'] ?? 0) < 1 || ($image['size'] ?? 0) > 3 * 1024 * 1024) {
                    $error = 'Choose an image smaller than 3 MB.';
                } else {
                    $imageInfo = @getimagesize((string) $image['tmp_name']);
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mime = $finfo->file((string) $image['tmp_name']);
                    $imageTypes = [
                        'image/jpeg' => 'jpg',
                        'image/png' => 'png',
                        'image/webp' => 'webp'
                    ];
                    if (
                        !is_array($imageInfo) ||
                        !isset($imageTypes[$mime]) ||
                        ($imageInfo['mime'] ?? '') !== $mime ||
                        $imageInfo[0] > 2048 ||
                        $imageInfo[1] > 2048
                    ) {
                        $error = 'Choose a valid JPEG, PNG, or WebP image no larger than 2048 by 2048 pixels.';
                    } else {
                        $pictureDirectory = dirname(__DIR__) . '/storage/profile-pictures';
                        if (!is_dir($pictureDirectory) && !mkdir($pictureDirectory, 0750, true) && !is_dir($pictureDirectory)) {
                            $error = 'Profile picture storage is unavailable. Please contact support.';
                        } else {
                            $newImageName = bin2hex(random_bytes(16)) . '.' . $imageTypes[$mime];
                            if (!move_uploaded_file((string) $image['tmp_name'], $pictureDirectory . '/' . $newImageName)) {
                                $newImageName = null;
                                $error = 'The profile picture could not be saved. Please try again.';
                            }
                        }
                    }
                }
            }

            if ($error === '') {
                if ($isEducationProfile) {
                    $sql = 'UPDATE users SET name = ?, font_scale = ?, high_contrast = ?, reduced_motion = ?, bio = ?';
                    $params = [$name, $fontScale, $highContrast, $reducedMotion, $bio !== '' ? $bio : null];
                    if ($newImageName !== null) {
                        $sql .= ', profile_image = ?';
                        $params[] = $newImageName;
                    }
                    $sql .= ' WHERE id = ?';
                    $params[] = $userId;
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                } else {
                    $stmt = $pdo->prepare(
                        'UPDATE users SET name = ?, font_scale = ?, high_contrast = ?, reduced_motion = ? WHERE id = ?'
                    );
                    $stmt->execute([$name, $fontScale, $highContrast, $reducedMotion, $userId]);
                }

                $_SESSION['name'] = $name;
                $_SESSION['font_scale'] = $fontScale;
                $_SESSION['high_contrast'] = $highContrast;
                $_SESSION['reduced_motion'] = $reducedMotion;
                $success = 'Profile updated.';
            }
        }
    }
}

$stmt = $pdo->prepare(
    'SELECT name, email, role, created_at, font_scale, high_contrast, reduced_motion, bio, profile_image
     FROM users
     WHERE id = ?
     LIMIT 1'
);
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user) {
    $_SESSION['font_scale'] = $user['font_scale'] ?? 'normal';
    $_SESSION['high_contrast'] = (int) ($user['high_contrast'] ?? 0);
    $_SESSION['reduced_motion'] = (int) ($user['reduced_motion'] ?? 0);
}

$title = 'Profile';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <p class="eyebrow">Account</p>
        <h1>Your profile</h1>
        <p>Manage your personal details and accessibility preferences.</p>
    </div>
</div>

<div class="card small-card">
    <?php if ($error): ?><div class="error"><?= e($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="success"><?= e($success) ?></div><?php endif; ?>

    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <?php if ($isEducationProfile): ?>
            <?php if (!empty($user['profile_image'])): ?>
                <img class="profile-avatar profile-avatar-large" src="profile-picture.php?id=<?= $userId ?>" alt="Your profile picture">
            <?php endif; ?>
            <label for="profile-picture">Profile picture (JPEG, PNG, or WebP; max 3 MB)</label>
            <input id="profile-picture" type="file" name="profile_picture" accept="image/jpeg,image/png,image/webp">
            <label for="profile-bio">Bio (up to 500 characters)</label>
            <textarea id="profile-bio" name="bio" rows="3" maxlength="500"><?= e((string) ($user['bio'] ?? '')) ?></textarea>
        <?php endif; ?>
        <label for="profile-name">Name</label>
        <input id="profile-name" type="text" name="name" value="<?= e($user['name'] ?? '') ?>" required>

        <label for="profile-email">Email</label>
        <input id="profile-email" type="email" value="<?= e($user['email'] ?? '') ?>" disabled>

        <label for="profile-role">Account type</label>
        <input id="profile-role" type="text" value="<?= e($isEducationProfile ? ucfirst((string) ($user['role'] ?? '')) : 'Personal') ?>" disabled>

        <p><strong>User ID:</strong> #<?= (int) $userId ?></p>

        <h2>Accessibility</h2>
        <label for="font-scale">Text size</label>
        <select id="font-scale" name="font_scale">
            <option value="normal" <?= ($user['font_scale'] ?? 'normal') === 'normal' ? 'selected' : '' ?>>Normal</option>
            <option value="large" <?= ($user['font_scale'] ?? '') === 'large' ? 'selected' : '' ?>>Large</option>
            <option value="x-large" <?= ($user['font_scale'] ?? '') === 'x-large' ? 'selected' : '' ?>>Extra large</option>
        </select>
        <label class="checkbox-label"><input type="checkbox" name="high_contrast" value="1" <?= !empty($user['high_contrast']) ? 'checked' : '' ?>> High contrast</label>
        <label class="checkbox-label"><input type="checkbox" name="reduced_motion" value="1" <?= !empty($user['reduced_motion']) ? 'checked' : '' ?>> Reduce motion</label>
        <button class="button" type="submit">Save profile</button>
    </form>

    <hr>
    <h2>Delete account</h2>
    <p>This permanently removes your account, classes, submissions, and feedback.</p>
    <form method="post" onsubmit="return confirm('Delete your account permanently?');">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="action" value="delete_account">
        <label for="delete-confirmation">Type DELETE to confirm</label>
        <input id="delete-confirmation" type="text" name="delete_confirmation" autocomplete="off" required>
        <button class="button danger" type="submit">Delete account</button>
    </form>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
