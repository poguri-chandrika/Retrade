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
$user_id = $_SESSION['user_id'];

if (!$order_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid order ID']);
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

    // Update order status to confirmed and set delivery status to processing
    $update = $pdo->prepare("
        UPDATE transactions 
        SET status = 'confirmed',
            delivery_status = 'processing',
            updated_at = NOW(),
            notes = CONCAT(COALESCE(notes, ''), '\n\n', 'Order confirmed by buyer on ', NOW(), '. Status updated to Processing.')
        WHERE id = ? AND buyer_id = ? AND status = 'pending' AND delivery_status = 'pending'
    ");
    $update->execute([$order_id, $user_id]);
    
    if ($update->rowCount() === 0) {
        throw new Exception('Order could not be confirmed. It may have already been processed.');
    }
    
    // Add notification for the seller
    $notification = "Your order #{$order_id} has been confirmed by the buyer. Please prepare the item for shipping.";
    $notifStmt = $pdo->prepare("INSERT INTO notifications (user_id, message, type, related_id, created_at) VALUES (?, ?, 'order_confirmed', ?, NOW())");
    $notifStmt->execute([$order['seller_id'], $notification, $order_id]);
    
    // Commit transaction
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Order confirmed successfully! The seller has been notified.'
    ]);
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => 'Failed to confirm order: ' . $e->getMessage()
    ]);
}
