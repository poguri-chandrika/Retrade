<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../payment_config.php';

header('Content-Type: application/json');

if (!RAZORPAY_ENABLED) {
    http_response_code(503);
    echo json_encode(['error' => 'Razorpay is disabled']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$buyer_id = $_SESSION['user_id'] ?? 0;
if (!$buyer_id || getUserRole() !== 'buyer') {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$product_id = intval($_POST['product_id'] ?? 0);
$order_id = $_POST['razorpay_order_id'] ?? '';
preg_match('/^order_/', $order_id) || $order_id = '';
$payment_id = $_POST['razorpay_payment_id'] ?? '';
$signature = $_POST['razorpay_signature'] ?? '';

if (!$product_id || !$order_id || !$payment_id || !$signature) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing parameters']);
    exit;
}

// Verify signature
$payload = $order_id . '|' . $payment_id;
$expected_sig = hash_hmac('sha256', $payload, RAZORPAY_KEY_SECRET);
if (!hash_equals($expected_sig, $signature)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}

// Fetch product to create transaction
$stm = $pdo->prepare("SELECT p.*, u.id AS seller_id FROM products p JOIN users u ON p.seller_id=u.id WHERE p.id=?");
$stm->execute([$product_id]);
$product = $stm->fetch();
if (!$product || $product['seller_id'] == $buyer_id) {
    http_response_code(404);
    echo json_encode(['error' => 'Product not found']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Create transaction as pending (escrow held externally)
    $total_amount = $product['price'];
    $admin_commission = $total_amount * PLATFORM_COMMISSION_RATE;
    $seller_amount = $total_amount - $admin_commission;

    $ins = $pdo->prepare("INSERT INTO transactions (buyer_id, seller_id, product_id, amount, admin_commission, seller_amount, status, delivery_status, notes, razorpay_order_id, razorpay_payment_id, razorpay_signature, payment_status) VALUES (?, ?, ?, ?, ?, ?, 'pending', 'pending', 'Paid via Razorpay; funds in escrow', ?, ?, ?, 'paid')");
    $ins->execute([$buyer_id, $product['seller_id'], $product_id, $total_amount, $admin_commission, $seller_amount, $order_id, $payment_id, $signature]);

    $tx_id = $pdo->lastInsertId();
    $pdo->commit();

    echo json_encode(['ok' => true, 'transaction_id' => $tx_id]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'Failed to record transaction']);
}
