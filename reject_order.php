<?php
require_once 'config.php';
requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

$order_id = intval($_POST['order_id'] ?? 0);
$reason = trim($_POST['reason'] ?? '');
$user_id = $_SESSION['user_id'];

if (!$order_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid order ID']);
    exit();
}

if (empty($reason)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Please provide a reason for rejecting this order']);
    exit();
}

try {
    // Start transaction
    $pdo->beginTransaction();
    
    // Verify the order exists and belongs to the buyer
    $stmt = $pdo->prepare("
        SELECT t.*, p.title as product_title, u.name as seller_name 
        FROM transactions t
        JOIN products p ON t.product_id = p.id
        JOIN users u ON t.seller_id = u.id
        WHERE t.id = ? AND t.buyer_id = ? AND t.status = 'pending' AND t.delivery_status = 'pending'
    ");
    $stmt->execute([$order_id, $user_id]);
    $order = $stmt->fetch();

    if (!$order) {
        throw new Exception('Order not found or already processed');
    }

    // Update order status to rejected
    $update = $pdo->prepare("
        UPDATE transactions 
        SET status = 'rejected',
            delivery_status = 'cancelled',
            updated_at = NOW(),
            notes = CONCAT(COALESCE(notes, ''), '\\\\n\\\\n', 'Order rejected by buyer on ', NOW(), '\\nReason: ', ?)
        WHERE id = ? AND buyer_id = ?
    ");
    $update->execute([$reason, $order_id, $user_id]);
    
    // Refund the payment if it was already processed
    if ($order['payment_status'] === 'completed') {
        // Add refund logic here (e.g., update user's balance)
        $refund_note = "Refund for rejected order #$order_id";
        $refund_amount = $order['amount'];
        
        // Update buyer's balance
        $update_balance = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
        $update_balance->execute([$refund_amount, $user_id]);
        
        // Record the refund transaction
        $insert_refund = $pdo->prepare("
            INSERT INTO transactions (buyer_id, seller_id, product_id, amount, status, payment_status, delivery_status, type, notes)
            VALUES (?, ?, ?, ?, 'completed', 'refunded', 'cancelled', 'refund', ?)
        ");
        $insert_refund->execute([$user_id, $order['seller_id'], $order['product_id'], $refund_amount, $refund_note]);
    }
    
    // Commit transaction
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Order has been rejected. Any payment will be refunded to your account.'
    ]);
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => 'Failed to reject order: ' . $e->getMessage()
    ]);
}
