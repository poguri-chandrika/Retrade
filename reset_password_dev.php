<?php
// Dev-only: reset a user's password. Must be logged in as admin.
require_once __DIR__ . '/config.php';
if (!isLoggedIn() || getUserRole() !== 'admin') {
  http_response_code(403);
  echo 'Forbidden: admin only';
  exit;
}

$email = $_GET['email'] ?? 'admin@retrade.com';
$new  = $_GET['new'] ?? 'password';

try {
  $stmt = $pdo->prepare('UPDATE users SET password=? WHERE email=?');
  $stmt->execute([password_hash($new, PASSWORD_DEFAULT), strtolower(trim($email))]);
  echo "Reset password for $email to '$new' (hashed).\n";
  echo "Now try logging in via /login.php";
} catch (Throwable $e) {
  echo 'Error: ' . $e->getMessage();
}
