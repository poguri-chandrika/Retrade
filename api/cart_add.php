<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

if (!isLoggedIn() || getUserRole() !== 'buyer') {
  http_response_code(401);
  echo json_encode(['error' => 'Login as buyer required']);
  exit;
}

$buyerId = (int)($_SESSION['user_id'] ?? 0);
$pid = (int)($_POST['product_id'] ?? 0);
if ($pid <= 0) { http_response_code(400); echo json_encode(['error' => 'Invalid product_id']); exit; }

// Ensure table exists
$pdo->exec("CREATE TABLE IF NOT EXISTS cart (
  id INT AUTO_INCREMENT PRIMARY KEY,
  buyer_id INT NOT NULL,
  product_id INT NOT NULL,
  quantity INT NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_buyer_product (buyer_id, product_id),
  FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// If exists, keep quantity = 1 (single item listing). Otherwise insert.
$stmt = $pdo->prepare('SELECT id FROM cart WHERE buyer_id = ? AND product_id = ?');
$stmt->execute([$buyerId, $pid]);
$row = $stmt->fetch();

if ($row) {
  echo json_encode(['status' => 'exists']);
} else {
  $ins = $pdo->prepare('INSERT INTO cart (buyer_id, product_id, quantity) VALUES (?, ?, 1)');
  $ins->execute([$buyerId, $pid]);
  echo json_encode(['status' => 'added']);
}
