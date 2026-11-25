<?php

require_once dirname(__DIR__) . '/_bootstrap.php';
apiRequireMethod('POST');
apiVerifyCsrf();
$user = apiRequireUser(['student']);
$input = apiReadJson();
$code = strtoupper(trim((string) ($input['join_code'] ?? '')));

if ($code === '' || strlen($code) > 32) {
    apiRespond(['error' => 'Enter a valid class join code.'], 422);
}

$stmt = $pdo->prepare(
    'SELECT id, institution_id
     FROM classes
     WHERE join_code = ? AND status = "active"
     LIMIT 1'
);
$stmt->execute([$code]);
$class = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$class) {
    apiRespond(['error' => 'Class code not found. Check the code and try again.'], 404);
}

$pdo->beginTransaction();
try {
    $member = $pdo->prepare(
        'INSERT IGNORE INTO class_members (class_id, user_id) VALUES (?, ?)'
    );
    $member->execute([(int) $class['id'], $user['id']]);
    if ($class['institution_id'] !== null) {
        $institutionMember = $pdo->prepare(
            'INSERT INTO institution_members (institution_id, user_id, role)
             VALUES (?, ?, "student")
             ON DUPLICATE KEY UPDATE
                role = IF(role IN ("admin", "teacher"), role, "student")'
        );
        $institutionMember->execute([(int) $class['institution_id'], $user['id']]);
    }
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $exception;
}

apiRespond(['success' => true, 'class_id' => (int) $class['id']]);
