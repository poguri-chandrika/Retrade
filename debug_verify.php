<?php
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain');

$email = $_GET['email'] ?? 'admin@retrade.com';
$pw    = $_GET['pw'] ?? 'password';

$stm = $pdo->prepare('SELECT id, name, email, password, role FROM users WHERE email = ?');
$stm->execute([$email]);
$user = $stm->fetch();

if (!$user) {
  echo "User not found for email: $email\n";
  exit;
}

echo "Testing login for: {$user['email']} (role={$user['role']})\n";
$hash = $user['password'];
echo "Hash prefix: " . substr($hash, 0, 7) . "...\n";

$ok = password_verify($pw, $hash);
var_export(['password_verify' => $ok]);

echo "\nPassword needs rehash? " . (password_needs_rehash($hash, PASSWORD_DEFAULT) ? 'yes' : 'no') . "\n";

if ($ok) {
  echo "Setting session as this user...\n";
  if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
  $_SESSION['user_id'] = $user['id'];
  $_SESSION['user_name'] = $user['name'];
  $_SESSION['user_email'] = $user['email'];
  $_SESSION['user_role'] = $user['role'];
  echo "Done. Now open /debug_session.php to confirm.\n";
}
