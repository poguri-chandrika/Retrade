<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

if (!isLoggedIn()) {
  http_response_code(401);
  echo json_encode(['error' => 'Login required']);
  exit;
}

$userId = (int)$_SESSION['user_id'];

// Ensure table exists
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

$limit = max(1, min(50, (int)($_GET['limit'] ?? 20)));

// Fetch latest notifications (unread first)
$sql = "SELECT id, type, title, message, link, is_read, created_at
        FROM notifications
        WHERE user_id = ?
        ORDER BY is_read ASC, created_at DESC
        LIMIT $limit";
$stmt = $pdo->prepare($sql);
$stmt->execute([$userId]);
$rows = $stmt->fetchAll();

$unread = (int)$pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0')
  ->execute([$userId]) ?: 0;
// PDO execute returns bool; fetch count separately
$countStmt = $pdo->prepare('SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND is_read = 0');
$countStmt->execute([$userId]);
$unreadCount = (int)$countStmt->fetchColumn();

echo json_encode([
  'unread' => $unreadCount,
  'items' => $rows,
]);
