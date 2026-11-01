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

// Expect product_id in POST
$product_id = intval($_POST['product_id'] ?? 0);
if (!$product_id || !isLoggedIn() || getUserRole() !== 'buyer') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request']);
    exit;
}

// Fetch product and ensure approved and not seller-owned
$stmt = $pdo->prepare("SELECT p.*, u.id AS seller_id FROM products p JOIN users u ON p.seller_id=u.id WHERE p.id=? AND p.status='approved'");
$stmt->execute([$product_id]);
$product = $stmt->fetch();
if (!$product || $product['seller_id'] == $_SESSION['user_id']) {
    http_response_code(404);
    echo json_encode(['error' => 'Product not found']);
    exit;
}

$amount_in_paise = (int) round($product['price'] * 100);
$receipt = 'prod_' . $product_id . '_buyer_' . $_SESSION['user_id'] . '_' . time();

// Create order via Razorpay REST API (basic auth)
$ch = curl_init('https://api.razorpay.com/v1/orders');
$payload = http_build_query([
    'amount' => $amount_in_paise,
    'currency' => RAZORPAY_CURRENCY,
    'receipt' => $receipt,
    'payment_capture' => 1
]);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
    CURLOPT_USERPWD => RAZORPAY_KEY_ID . ':' . RAZORPAY_KEY_SECRET,
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

if ($err || $http_code >= 400) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to create order', 'details' => $err ?: $response]);
    exit;
}

$order = json_decode($response, true);
if (!isset($order['id'])) {
    http_response_code(500);
    echo json_encode(['error' => 'Invalid response from Razorpay']);
    exit;
}

echo json_encode([
    'order_id' => $order['id'],
    'amount' => $order['amount'],
    'currency' => $order['currency'],
    'receipt' => $order['receipt'],
    'key_id' => RAZORPAY_KEY_ID,
    'buyer' => [
        'name' => $_SESSION['user_name'] ?? '',
        'email' => $_SESSION['user_email'] ?? ''
    ],
    'product' => [
        'id' => $product_id,
        'title' => $product['title'],
        'image' => $product['image'] ?? null,
        'price' => $product['price']
    ]
]);
