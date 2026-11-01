<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!isLoggedIn() || getUserRole() !== 'seller') {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$sellerId = (int)($_SESSION['user_id'] ?? 0);
$productId = (int)($_POST['product_id'] ?? 0);
$buyerId = (int)($_POST['buyer_id'] ?? 0);

if (!$productId || !$buyerId) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing product_id or buyer_id']);
    exit;
}

// Validate product belongs to seller
try {
    $stmt = $pdo->prepare('SELECT seller_id FROM products WHERE id = ?');
    $stmt->execute([$productId]);
    $row = $stmt->fetch();
    if (!$row || (int)$row['seller_id'] !== $sellerId) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB error']);
    exit;
}

// Ensure negotiation exists
try {
    $pdo->beginTransaction();
    $pdo->exec("SET NAMES utf8mb4");
    $stmt = $pdo->prepare("INSERT INTO negotiations (product_id, seller_id, buyer_id) VALUES (?,?,?)
        ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP");
    $stmt->execute([$productId, $sellerId, $buyerId]);
    $stmt = $pdo->prepare('SELECT id FROM negotiations WHERE product_id = ? AND seller_id = ? AND buyer_id = ?');
    $stmt->execute([$productId, $sellerId, $buyerId]);
    $neg = $stmt->fetch();
    $negotiationId = (int)($neg['id'] ?? 0);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'DB error']);
    exit;
}

// Build room and token
$room = 'product_' . $productId . '_seller_' . $sellerId . '_buyer_' . $buyerId;
$now = time();
$exp = $now + 3600;

$sellerName = (string)($_SESSION['user_name'] ?? 'Seller');
$payload = [
    'sub' => $sellerId,
    'name' => $sellerName,
    'role' => 'seller',
    'product_id' => $productId,
    'seller_id' => $sellerId,
    'buyer_id' => $buyerId,
    'rooms' => [$room],
    'iat' => $now,
    'exp' => $exp,
];
$payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
$payloadB64 = rtrim(strtr(base64_encode($payloadJson), '+/', '-_'), '=');
$sig = hash_hmac('sha256', $payloadB64, WS_SHARED_SECRET);

echo json_encode([
    'token' => $payloadB64 . '.' . $sig,
    'ws_url' => WS_SERVER_URL,
    'room' => $room,
    'negotiation_id' => $negotiationId,
    'user' => [
        'id' => $sellerId,
        'name' => $sellerName,
        'role' => 'seller',
    ],
]);
