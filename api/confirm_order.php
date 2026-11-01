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
        WHERE t.id = ? AND t.buyer_id = ? AND t.status = 'processing' AND t.buyer_confirmed = FALSE
        FOR UPDATE
    ");
    $stmt->execute([$order_id, $_SESSION['user_id']]);
    $order = $stmt->fetch();
    
    if (!$order) {
        throw new Exception('Order not found or cannot be confirmed');
    }
    
    // Update order status to confirmed by buyer
    $update = $pdo->prepare("
        UPDATE transactions 
        SET buyer_confirmed = TRUE,
            buyer_confirmed_at = NOW(),
            status = 'awaiting_release',
            buyer_notes = ?
        WHERE id = ?
    ");
    $update->execute([$notes, $order_id]);
    
    // Create notification for admin
    $notification = $pdo->prepare("
        INSERT INTO notifications (user_id, title, message, type, related_id)
        SELECT id, 'Order Confirmed', 'Order #{$order_id} has been confirmed by buyer and is awaiting fund release.', 'order', ?
        FROM users 
        WHERE role = 'admin'
    ");
    $notification->execute([$order_id]);
    
    // Create notification for seller
    $notification = $pdo->prepare("
        INSERT INTO notifications (user_id, title, message, type, related_id)
        VALUES (?, 'Order Confirmed', 'Order #{$order_id} has been confirmed by buyer and is awaiting admin release.', 'order', ?)
    ");
    $notification->execute([$order['seller_id'], $order_id]);
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Order confirmed successfully. Waiting for admin to release funds.'
    ]);
    
} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to confirm order: ' . $e->getMessage()
    ]);
}
