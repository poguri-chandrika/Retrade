<?php
require_once 'config.php';
requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

$transaction_id = intval($_POST['transaction_id'] ?? 0);
$user_id = $_SESSION['user_id'];

if (!$transaction_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid transaction ID']);
    exit();
}

try {
    // Start transaction
    $pdo->beginTransaction();
    
    // Verify the transaction exists and belongs to the buyer
    $stmt = $pdo->prepare("
        SELECT t.*, p.title as product_title, u.name as seller_name 
        FROM transactions t
        JOIN products p ON t.product_id = p.id
        JOIN users u ON t.seller_id = u.id
        WHERE t.id = ? AND t.buyer_id = ? AND t.status = 'pending' AND t.buyer_confirmed = FALSE
    ");
    $stmt->execute([$transaction_id, $user_id]);
    $transaction = $stmt->fetch();

    if (!$transaction) {
        throw new Exception('Transaction not found or already confirmed');
    }

    // Update transaction to mark as buyer confirmed
    $update = $pdo->prepare("
        UPDATE transactions 
        SET buyer_confirmed = TRUE,
            buyer_confirmed_at = NOW(),
            status = 'processing',
            notes = CONCAT(COALESCE(notes, ''), '\\n\\n', 'Buyer confirmed payment on ', NOW())
        WHERE id = ? AND buyer_id = ?
    ");
    $update->execute([$transaction_id, $user_id]);
    
    // If this is an immediate release (no admin approval needed)
    if (!$transaction['admin_release_required']) {
        // Release funds to seller
        $update = $pdo->prepare("
            UPDATE transactions 
            SET status = 'released',
                admin_released_by = NULL,
                admin_released_at = NOW(),
                notes = CONCAT(COALESCE(notes, ''), '\\n\\n', 'Funds automatically released to seller on ', NOW())
            WHERE id = ?
        ");
        $update->execute([$transaction_id]);
        
        // Update seller's balance
        $update_balance = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
        $update_balance->execute([$transaction['seller_amount'], $transaction['seller_id']]);
    }
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => $transaction['admin_release_required'] 
            ? 'Payment confirmed! The funds will be released to the seller after admin approval.' 
            : 'Payment confirmed and funds have been released to the seller!',
        'requires_admin_release' => $transaction['admin_release_required']
    ]);
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => 'Failed to confirm payment: ' . $e->getMessage()
    ]);
}
