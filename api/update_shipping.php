<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$order_id = intval($_POST['order_id'] ?? 0);
$tracking_number = trim($_POST['tracking_number'] ?? '');
$shipping_provider = trim($_POST['shipping_provider'] ?? '');
$tracking_url = trim($_POST['tracking_url'] ?? '');
$notes = trim($_POST['notes'] ?? '');

if (!$order_id || !$tracking_number || !$shipping_provider) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

try {
    $pdo->beginTransaction();
    
    // Verify the order exists and belongs to the current user as seller
    $stmt = $pdo->prepare("
        SELECT t.*, p.title as product_title, buyer.id as buyer_id, buyer.name as buyer_name
        FROM transactions t
        JOIN products p ON t.product_id = p.id
        JOIN users buyer ON t.buyer_id = buyer.id
        WHERE t.id = ? AND t.seller_id = ? AND t.status IN ('pending', 'processing')
        FOR UPDATE
    ");
    $stmt->execute([$order_id, $_SESSION['user_id']]);
    $order = $stmt->fetch();
    
    if (!$order) {
        throw new Exception('Order not found or cannot be updated');
    }
    
    // Update order status and shipping info
    $update = $pdo->prepare("
        UPDATE transactions 
        SET status = 'shipped',
            tracking_number = ?,
            shipping_provider = ?,
            tracking_url = ?,
            seller_notes = ?,
            shipped_at = NOW(),
            updated_at = NOW()
        WHERE id = ?
    ");
    $update->execute([
        $tracking_number,
        $shipping_provider,
        $tracking_url,
        $notes,
        $order_id
    ]);
    
    // Create notification for buyer
    $notification = $pdo->prepare("
        INSERT INTO notifications (user_id, title, message, type, related_id)
        VALUES (?, 'Order Shipped', 'Your order #{$order_id} has been shipped. Tracking #: {$tracking_number}', 'shipping', ?)
    ");
    $notification->execute([$order['buyer_id'], $order_id]);
    
    // Send email notification to buyer
    $to = $order['buyer_email'];
    $subject = "Your Order #{$order_id} Has Been Shipped";
    $message = "
    <h2>Your Order Has Been Shipped!</h2>
    <p>Hello {$order['buyer_name']},</p>
    <p>Your order #{$order_id} for <strong>{$order['product_title']}</strong> has been shipped.</p>
    <p><strong>Shipping Details:</strong></p>
    <ul>
        <li>Carrier: {$shipping_provider}</li>
        <li>Tracking #: {$tracking_number}" . 
        ($tracking_url ? "<li>Track your package: <a href=\"{$tracking_url}\">Click here</a></li>" : "") . "
    </ul>
    <p>You can track your order status at any time by visiting <a href=\"" . SITE_URL . "/order_details.php?id={$order_id}\">your order page</a>.</p>
    <p>Thank you for shopping with us!</p>
    ";
    
    // Send email (implement your email sending function here)
    // sendEmail($to, $subject, $message);
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Shipping information updated successfully'
    ]);
    
} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to update shipping information: ' . $e->getMessage()
    ]);
}
