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
$notes = trim($_POST['notes'] ?? '');

if (!$order_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid order ID']);
    exit;
}

try {
    $pdo->beginTransaction();
    
    // Verify the order exists and belongs to the current user as buyer
    $stmt = $pdo->prepare("
        SELECT t.*, p.title as product_title, seller.id as seller_id, seller.name as seller_name
        FROM transactions t
        JOIN products p ON t.product_id = p.id
        JOIN users seller ON t.seller_id = seller.id
        WHERE t.id = ? AND t.buyer_id = ? AND t.status = 'delivered'
        FOR UPDATE
    ");
    $stmt->execute([$order_id, $_SESSION['user_id']]);
    $order = $stmt->fetch();
    
    if (!$order) {
        throw new Exception('Order not found or cannot be confirmed');
    }
    
    // Update order status to completed
    $update = $pdo->prepare("
        UPDATE transactions 
        SET status = 'completed',
            delivered_at = NOW(),
            buyer_notes = ?
        WHERE id = ?
    ");
    $update->execute([$notes, $order_id]);
    
    // Release funds to seller
    $release = $pdo->prepare("
        UPDATE users 
        SET balance = balance + ? 
        WHERE id = ?
    ");
    $release->execute([$order['seller_amount'], $order['seller_id']]);
    
    // Create transaction record for the seller
    $transaction = $pdo->prepare("
        INSERT INTO transactions (user_id, amount, type, description, status)
        VALUES (?, ?, 'credit', 'Payment for order #{$order_id}: {$order['product_title']}', 'completed')
    ");
    $transaction->execute([$order['seller_id'], $order['seller_amount']]);
    
    // Create notification for seller
    $notification = $pdo->prepare("
        INSERT INTO notifications (user_id, title, message, type, related_id)
        VALUES (?, 'Payment Released', 'Your payment of ₹{$order['seller_amount']} for order #{$order_id} has been released.', 'payment', ?)
    ");
    $notification->execute([$order['seller_id'], $order_id]);
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Delivery confirmed successfully. Thank you for your purchase!'
    ]);
    
} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to confirm delivery: ' . $e->getMessage()
    ]);
}
