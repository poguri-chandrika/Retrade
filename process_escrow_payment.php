<?php
require_once 'config.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit();
}

// Get form data
$product_id = intval($_POST['product_id'] ?? 0);
$shipping_address = trim($_POST['shipping_address'] ?? '');
$payment_method = trim($_POST['payment_method'] ?? 'wallet');

// Validate input
if ($product_id <= 0 || empty($shipping_address)) {
    $_SESSION['error'] = 'Invalid product or shipping address';
    header('Location: product_details.php?id=' . $product_id);
    exit();
}

try {
    $pdo->beginTransaction();

    // Get product details
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? AND status = 'approved'");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch();

    if (!$product) {
        throw new Exception('Product not found or not approved');
    }

    // Get escrow settings
    $settings = $pdo->query("SELECT * FROM escrow_settings LIMIT 1")->fetch();
    if (!$settings) {
        throw new Exception('Escrow settings not found');
    }

    // Calculate amounts
    $admin_commission = ($product['price'] * $settings['admin_commission_rate']) / 100;
    $seller_amount = $product['price'] - $admin_commission;
    $auto_release_date = date('Y-m-d H:i:s', strtotime("+{$settings['auto_release_days']} days"));

    // Create transaction
    $stmt = $pdo->prepare("
        INSERT INTO transactions 
        (buyer_id, seller_id, product_id, amount, admin_commission, seller_amount, 
         status, payment_method, escrow_status, auto_release_date)
        VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, 'pending', ?)
    ");
    
    $stmt->execute([
        $_SESSION['user_id'],
        $product['seller_id'],
        $product_id,
        $product['price'],
        $admin_commission,
        $seller_amount,
        $payment_method,
        $auto_release_date
    ]);
    
    $transaction_id = $pdo->lastInsertId();
    
    // Create order
    $stmt = $pdo->prepare("
        INSERT INTO orders 
        (transaction_id, status, shipping_address)
        VALUES (?, 'pending', ?)
    ");
    
    $stmt->execute([$transaction_id, $shipping_address]);
    $order_id = $pdo->lastInsertId();
    
    // Add order item
    $stmt = $pdo->prepare("
        INSERT INTO order_items 
        (order_id, product_id, quantity, price)
        VALUES (?, ?, 1, ?)
    ");
    
    $stmt->execute([$order_id, $product_id, $product['price']]);
    
    // Process payment based on method
    if ($payment_method === 'wallet') {
        // Deduct from buyer's wallet
        $stmt = $pdo->prepare("
            UPDATE users 
            SET balance = balance - ? 
            WHERE id = ? AND balance >= ?
        ");
        
        $stmt->execute([$product['price'], $_SESSION['user_id'], $product['price']]);
        
        if ($stmt->rowCount() === 0) {
            throw new Exception('Insufficient wallet balance');
        }
        
        // Update transaction status to hold funds
        $pdo->prepare("UPDATE transactions SET status = 'approved', escrow_status = 'funds_held' WHERE id = ?")
            ->execute([$transaction_id]);
    } else {
        // For other payment methods, you would integrate with payment gateways here
        // For now, we'll just mark it as pending
        $pdo->prepare("UPDATE transactions SET status = 'pending' WHERE id = ?")
            ->execute([$transaction_id]);
    }
    
    $pdo->commit();
    
    // Redirect to order confirmation
    $_SESSION['success'] = 'Order placed successfully! The funds are held in escrow until you confirm delivery.';
    header('Location: order_details.php?id=' . $order_id);
    
} catch (Exception $e) {
    $pdo->rollBack();
    $_SESSION['error'] = 'Payment failed: ' . $e->getMessage();
    header('Location: product_details.php?id=' . $product_id);
}

exit();
