<?php
// Create a local account from the command line (handy for trying teacher/admin features).
//
//   php scripts/create-user.php <education|personal> <student|teacher|admin> "<Name>" <email> <password>
//
// With Docker:
//   docker compose exec app php scripts/create-user.php education teacher "Demo Teacher" teacher@example.test password123

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

[$script, $scope, $role, $name, $email, $password] = array_pad($argv, 6, '');

if (!in_array($scope, ['education', 'personal'], true)
    || !in_array($role, ['student', 'teacher', 'admin'], true)
    || $name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8
) {
    fwrite(STDERR, "Usage: php scripts/create-user.php <education|personal> <student|teacher|admin> \"<Name>\" <email> <password (8+ chars)>\n");
    exit(2);
}
if ($scope === 'personal' && $role !== 'student' && $role !== 'admin') {
    fwrite(STDERR, "Personal accounts can only be 'student' or 'admin'.\n");
    exit(2);
}

$_SERVER['HTTP_HOST'] = 'localhost';
require dirname(__DIR__) . '/config/database.php';

$stmt = $pdo->prepare(
    'INSERT INTO users (name, email, password_hash, account_scope, role) VALUES (?, ?, ?, ?, ?)'
);
try {
    $stmt->execute([$name, strtolower($email), password_hash($password, PASSWORD_DEFAULT), $scope, $role]);
} catch (PDOException $e) {
    fwrite(STDERR, "Could not create the account (does that email already exist for this site?).\n");
    exit(1);
}

echo "Created {$scope} {$role} account for {$email} (id {$pdo->lastInsertId()}).\n";
