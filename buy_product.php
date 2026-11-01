<?php
require_once 'config.php';
requireRole('buyer');
require_once 'payment_config.php';

$page_title = 'Buy Product';
$product_id = intval($_GET['id'] ?? 0);

if (!$product_id) {
    header('Location: index.php');
    exit();
}

// Get product details
$stmt = $pdo->prepare("
    SELECT p.*, u.name as seller_name, u.id as seller_id 
    FROM products p 
    JOIN users u ON p.seller_id = u.id 
    WHERE p.id = ? AND p.status = 'approved'
");
$stmt->execute([$product_id]);
$product = $stmt->fetch();

if (!$product || $product['seller_id'] == $_SESSION['user_id']) {
    header('Location: index.php');
    exit();
}

// Check if already purchased
$stmt = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE buyer_id = ? AND product_id = ?");
$stmt->execute([$_SESSION['user_id'], $product_id]);
if ($stmt->fetchColumn() > 0) {
    header('Location: product_details.php?id=' . $product_id);
    exit();
}

// If Razorpay flow is enabled, redirect buyers to product details to complete payment via Checkout
if (defined('RAZORPAY_ENABLED') && RAZORPAY_ENABLED) {
    header('Location: product_details.php?id=' . $product_id);
    exit();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_purchase'])) {
    try {
        $pdo->beginTransaction();

        // Calculate amounts (no wallet movements)
        $total_amount = $product['price'];
        $admin_commission = $total_amount * PLATFORM_COMMISSION_RATE; // commission
        $seller_amount = $total_amount - $admin_commission; // remainder to seller

        // Create transaction record as pending; payment will be captured via external gateway
        $stmt = $pdo->prepare("INSERT INTO transactions (buyer_id, seller_id, product_id, amount, admin_commission, seller_amount, status, delivery_status, notes, payment_status) VALUES (?, ?, ?, ?, ?, ?, 'pending', 'pending', 'Awaiting payment confirmation (external gateway)', 'created')");
        $stmt->execute([
            $_SESSION['user_id'],
            $product['seller_id'],
            $product_id,
            $total_amount,
            $admin_commission,
            $seller_amount
        ]);

        $transaction_id = $pdo->lastInsertId();

        $pdo->commit();

        header('Location: dashboard.php?success=purchase_initiated&transaction_id=' . $transaction_id);
        exit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = 'Purchase initiation failed. Please try again.';
    }
}

include 'includes/header.php';
?>

<div class="container my-4">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header bg-success text-white">
                    <h4><i class="fas fa-shopping-cart"></i> Complete Your Purchase</h4>
                </div>
                <div class="card-body">
                    <?php if ($error): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Product Summary -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5><i class="fas fa-box"></i> Product Details</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-3">
                                    <?php if ($product['image'] && file_exists('assets/img/' . $product['image'])): ?>
                                        <img src="assets/img/<?php echo htmlspecialchars($product['image']); ?>" 
                                             class="img-fluid rounded" alt="Product Image">
                                    <?php else: ?>
                                        <div class="bg-light rounded d-flex align-items-center justify-content-center" 
                                             style="height: 100px;">
                                            <i class="fas fa-image fa-2x text-muted"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-9">
                                    <h5><?php echo htmlspecialchars($product['title']); ?></h5>
                                    <p class="text-muted"><?php echo htmlspecialchars(substr($product['description'], 0, 150)) . '...'; ?></p>
                                    <p><strong>Seller:</strong> <?php echo htmlspecialchars($product['seller_name']); ?></p>
                                    <p><strong>Category:</strong> <?php echo htmlspecialchars($product['category'] ?? 'N/A'); ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Payment Summary -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5><i class="fas fa-calculator"></i> Payment Summary</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <table class="table table-borderless">
                                        <tr>
                                            <td>Product Price:</td>
                                            <td class="text-end fw-bold"><?php echo formatPrice($product['price']); ?></td>
                                        </tr>
                                        <tr>
                                            <td>Platform Fee:</td>
                                            <td class="text-end text-muted">Included</td>
                                        </tr>
                                        <tr class="border-top">
                                            <td><strong>Total Amount:</strong></td>
                                            <td class="text-end"><strong class="text-success fs-5"><?php echo formatPrice($product['price']); ?></strong></td>
                                        </tr>
                                    </table>
                                </div>
                                <div class="col-md-6">
                                    <div class="alert alert-info mb-0">
                                        <i class="fas fa-shield-alt"></i>
                                        This purchase will be processed via our secure payment gateway. Funds are held in escrow until delivery is confirmed.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Escrow Information -->
                    <div class="alert alert-info">
                        <h6><i class="fas fa-shield-alt"></i> How Escrow Protection Works:</h6>
                        <ol class="mb-0">
                            <li>Your payment is securely held by Retrade (not released to seller yet)</li>
                            <li>Seller will be notified of your purchase and will arrange delivery</li>
                            <li>Once you receive the item, confirm delivery in your dashboard</li>
                            <li>After confirmation, 90% goes to seller, 10% stays as platform commission</li>
                            <li>If there's an issue, you can dispute and get a full refund</li>
                        </ol>
                    </div>
                    
                    <form method="POST">
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="agree_terms" required>
                            <label class="form-check-label" for="agree_terms">
                                I agree to the <a href="#" class="text-decoration-none">Terms of Service</a> and understand the escrow process
                            </label>
                        </div>

                        <div class="d-flex justify-content-between">
                            <a href="product_details.php?id=<?php echo $product['id']; ?>" class="btn btn-secondary">
                                <i class="fas fa-arrow-left"></i> Back to Product
                            </a>
                            <button type="submit" name="confirm_purchase" class="btn btn-success btn-lg">
                                <i class="fas fa-lock"></i> Confirm Purchase - <?php echo formatPrice($product['price']); ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
