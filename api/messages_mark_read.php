<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['error' => 'Method not allowed']);
  exit;
}

try {
  if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
  }
  $userId = (int)($_SESSION['user_id'] ?? 0);
  $negotiationId = (int)($_POST['negotiation_id'] ?? 0);
  if (!$negotiationId) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing negotiation_id']);
    exit;
  }
  // Ensure table exists (idempotent)
  $pdo->exec("CREATE TABLE IF NOT EXISTS negotiation_reads (
    negotiation_id INT NOT NULL,
    user_id INT NOT NULL,
    last_read_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
    PRIMARY KEY (negotiation_id, user_id),
    FOREIGN KEY (negotiation_id) REFERENCES negotiations(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

  // Verify membership
  $s = $pdo->prepare('SELECT buyer_id, seller_id FROM negotiations WHERE id=?');
  $s->execute([$negotiationId]);
  $n = $s->fetch(PDO::FETCH_ASSOC);
  if (!$n) { http_response_code(404); echo json_encode(['error'=>'Not found']); exit; }
  if ($userId !== (int)$n['buyer_id'] && $userId !== (int)$n['seller_id']) { http_response_code(403); echo json_encode(['error'=>'Forbidden']); exit; }

  // Upsert last_read_at
  $u = $pdo->prepare('INSERT INTO negotiation_reads (negotiation_id, user_id, last_read_at) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE last_read_at = NOW()');
  $u->execute([$negotiationId, $userId]);

  echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error' => 'Server error']);
}
