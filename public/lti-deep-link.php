<?php

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/lti.php';

requireTeacher();

$classId = (int) ($_SESSION['lti_class_id'] ?? 0);
$settings = $_SESSION['lti_deep_link_settings'] ?? null;
if (
    empty($_SESSION['lti_issuer'])
    || empty($_SESSION['lti_deployment_id'])
    || ($_SESSION['lti_message_type'] ?? '') !== 'LtiDeepLinkingRequest'
    || $classId <= 0
    || !is_array($settings)
    || !is_string($settings['deep_link_return_url'] ?? null)
    || !ltiIsHttpsUrl($settings['deep_link_return_url'])
    || !ltiSameHttpsOrigin($settings['deep_link_return_url'], LTI_TOKEN_URL)
    || !is_array($settings['accept_types'] ?? null)
    || !in_array('ltiResourceLink', $settings['accept_types'] ?? [], true)
) {
    http_response_code(400);
    exit('Canvas assignment linking is not available for this launch.');
}

$class = getClassForTeacher($pdo, $classId, currentUserId());
if (!$class) {
    http_response_code(403);
    exit('This Canvas course is not connected to your InsQuire account.');
}

$assignmentStmt = $pdo->prepare(
    'SELECT id, title
     FROM assignments
     WHERE class_id = ? AND status = "published"
     ORDER BY created_at DESC, id DESC'
);
$assignmentStmt->execute([$classId]);
$assignments = $assignmentStmt->fetchAll(PDO::FETCH_ASSOC);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $assignmentId = filter_input(INPUT_POST, 'assignment_id', FILTER_VALIDATE_INT);
    $selected = null;
    foreach ($assignments as $assignment) {
        if ((int) $assignment['id'] === (int) $assignmentId) {
            $selected = $assignment;
            break;
        }
    }

    if (!$selected) {
        $error = 'Choose a published assignment from this Canvas course.';
    } else {
        try {
            $now = time();
            $item = [
                'type' => 'ltiResourceLink',
                'title' => (string) $selected['title'],
                'url' => EDUCATION_APP_URL . '/lti-launch.php',
                'custom' => ['insquire_assignment_id' => (string) $selected['id']]
            ];
            if (!empty($settings['accept_lineitem'])) {
                $item['lineItem'] = [
                    'scoreMaximum' => 100,
                    'label' => (string) $selected['title'],
                    'resourceId' => 'insquire-assignment-' . (int) $selected['id']
                ];
            }
            $claims = [
                'iss' => LTI_CLIENT_ID,
                'aud' => LTI_ISSUER,
                'iat' => $now,
                'exp' => $now + 300,
                'nonce' => bin2hex(random_bytes(16)),
                LTI_CLAIM_PREFIX . 'deployment_id' => (string) ($_SESSION['lti_deployment_id'] ?? ''),
                LTI_CLAIM_PREFIX . 'version' => '1.3.0',
                LTI_CLAIM_PREFIX . 'message_type' => 'LtiDeepLinkingResponse',
                LTI_DEEP_LINK_CLAIM_PREFIX . 'content_items' => [$item]
            ];
            if (is_string($settings['data'] ?? null)) {
                $claims[LTI_DEEP_LINK_CLAIM_PREFIX . 'data'] = $settings['data'];
            }
            $jwt = ltiSignJwt($claims);
            unset($_SESSION['lti_deep_link_settings'], $_SESSION['lti_message_type']);
            $title = 'Assignment linked';
            $action = $settings['deep_link_return_url'];
            $pageNoIndex = true;
            require dirname(__DIR__) . '/includes/header.php';
            ?>
            <main class="card small-card">
                <h1><?= e($title) ?></h1>
                <p>Returning <strong><?= e((string) $selected['title']) ?></strong> to Canvas.</p>
                <form id="lti-deep-link-return" method="post" action="<?= e($action) ?>">
                    <input type="hidden" name="JWT" value="<?= e($jwt) ?>">
                    <button class="button" type="submit">Return to Canvas</button>
                </form>
            </main>
            <script>
                document.getElementById('lti-deep-link-return').submit();
            </script>
            <?php
            require dirname(__DIR__) . '/includes/footer.php';
            exit;
        } catch (Throwable $exception) {
            error_log('LTI deep link response failed: ' . $exception->getMessage());
            $error = 'Unable to send this assignment to Canvas. Check the Canvas signing-key configuration.';
        }
    }
}

$title = 'Link Canvas Assignment';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<main class="card small-card">
    <h1>Link an InsQuire assignment</h1>
    <p>Choose a published assignment in <strong><?= e((string) $class['name']) ?></strong> to add it to Canvas.</p>
    <?php if ($error !== ''): ?><div class="error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <?php if (!$assignments): ?>
        <p>Create and publish an assignment in this InsQuire course first, then return to Canvas and open the assignment picker again.</p>
    <?php else: ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <label for="lti-assignment">Published assignment</label>
            <select id="lti-assignment" name="assignment_id" required>
                <option value="">Select an assignment</option>
                <?php foreach ($assignments as $assignment): ?>
                    <option value="<?= (int) $assignment['id'] ?>"><?= e((string) $assignment['title']) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="button" type="submit">Add to Canvas</button>
        </form>
    <?php endif; ?>
</main>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
