<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

if (!isLoggedIn()) {
  http_response_code(401);
  echo json_encode(['error' => 'Login required']);
  exit;
}

$userId = (int)$_SESSION['user_id'];
$ids = isset($_POST['ids']) ? $_POST['ids'] : null; // comma-separated or array
$all = (string)($_POST['all'] ?? '0') === '1';

// Ensure table exists (no-op if exists)
$pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  type VARCHAR(50) DEFAULT 'info',
  title VARCHAR(150) NOT NULL,
  message TEXT,
  link VARCHAR(255) DEFAULT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user_read (user_id, is_read, created_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

try {
  if ($all) {
    $st = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0');
    $st->execute([$userId]);
    echo json_encode(['status' => 'ok', 'updated' => $st->rowCount()]);
    exit;
  }
  $idList = [];
  if (is_array($ids)) { $idList = array_map('intval', $ids); }
  else if (is_string($ids)) {
    foreach (explode(',', $ids) as $p) { $p = trim($p); if ($p !== '') $idList[] = (int)$p; }
  }
  if (empty($idList)) { echo json_encode(['status' => 'ok', 'updated' => 0]); exit; }
  // Prepare IN clause safely
  $ph = implode(',', array_fill(0, count($idList), '?'));
  $params = $idList; array_unshift($params, $userId);
  $st = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND id IN ($ph)");
  $st->execute($params);
  echo json_encode(['status' => 'ok', 'updated' => $st->rowCount()]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error' => 'Failed to update notifications']);
}
