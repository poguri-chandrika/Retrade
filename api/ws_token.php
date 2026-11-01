<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$userName = (string)($_SESSION['user_name'] ?? 'User');
$userRole = (string)($_SESSION['user_role'] ?? '');
$productId = (int)($_POST['product_id'] ?? 0);

if (!$productId) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing product_id']);
    exit;
}

// Fetch seller id for the product
try {
    $stmt = $pdo->prepare('SELECT seller_id FROM products WHERE id = ?');
    $stmt->execute([$productId]);
    $row = $stmt->fetch();
    if (!$row) {
        http_response_code(404);
        echo json_encode(['error' => 'Product not found']);
        exit;
    }
    $sellerId = (int)$row['seller_id'];
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB error']);
    exit;
}

if ($userId === $sellerId) {
    http_response_code(403);
    echo json_encode(['error' => 'Sellers cannot negotiate with themselves']);
    exit;
}

// Only buyers initiate chat for now
if ($userRole !== 'buyer') {
    http_response_code(403);
    echo json_encode(['error' => 'Only buyers can initiate chat']);
    exit;
}

$room = 'product_' . $productId . '_seller_' . $sellerId . '_buyer_' . $userId;
$now = time();
$exp = $now + 3600; // 1 hour validity

$payload = [
    'sub' => $userId,
    'name' => $userName,
    'role' => $userRole,
    'product_id' => $productId,
    'seller_id' => $sellerId,
    'buyer_id' => $userId,
    'rooms' => [$room],
    'iat' => $now,
    'exp' => $exp,
];

// Ensure negotiation exists (create if not)
try {
    $pdo->beginTransaction();
    $pdo->exec("SET NAMES utf8mb4");
    // Create negotiations table if not existing is handled by migration; here we just upsert
    $stmt = $pdo->prepare("INSERT INTO negotiations (product_id, seller_id, buyer_id) VALUES (?,?,?)
        ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP");
    $stmt->execute([$productId, $sellerId, $userId]);
    // Get negotiation id
    $stmt = $pdo->prepare("SELECT id FROM negotiations WHERE product_id = ? AND seller_id = ? AND buyer_id = ?");
    $stmt->execute([$productId, $sellerId, $userId]);
    $neg = $stmt->fetch();
    $negotiationId = (int)($neg['id'] ?? 0);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    // Non-fatal for token, but we won't be able to persist messages
    $negotiationId = 0;
}

$payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
$payloadB64 = rtrim(strtr(base64_encode($payloadJson), '+/', '-_'), '=');
$sig = hash_hmac('sha256', $payloadB64, WS_SHARED_SECRET);

echo json_encode([
    'token' => $payloadB64 . '.' . $sig,
    'ws_url' => WS_SERVER_URL,
    'room' => $room,
    'negotiation_id' => $negotiationId,
    'user' => [
        'id' => $userId,
        'name' => $userName,
        'role' => $userRole,
    ],
]);
