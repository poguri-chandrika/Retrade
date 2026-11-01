<?php
require_once '../config.php';
requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

$order_id = intval($_POST['order_id'] ?? 0);
$status = $_POST['status'] ?? '';
$user_id = $_SESSION['user_id'];

if (!$order_id || !in_array($status, ['shipped', 'delivered'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit();
}

try {
    $pdo->beginTransaction();
    
    // Verify the order exists and belongs to the seller
    $stmt = $pdo->prepare("SELECT * FROM transactions WHERE id = ? AND seller_id = ?");
    $stmt->execute([$order_id, $user_id]);
    $order = $stmt->fetch();

    if (!$order) {
        throw new Exception('Order not found or not eligible for status update');
    }

    // Update order status
    $update = $pdo->prepare("UPDATE transactions SET delivery_status = ? WHERE id = ?");
    $update->execute([$status, $order_id]);
    
    // If status is delivered, set admin_release_required
    if ($status === 'delivered') {
        $update = $pdo->prepare("UPDATE transactions SET admin_release_required = 1 WHERE id = ?");
        $update->execute([$order_id]);
    }
    
    $pdo->commit();
    
    echo json_encode(['success' => true, 'message' => "Order marked as $status"]);
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
