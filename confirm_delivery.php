<?php
require_once 'config.php';
requireRole('buyer');

$page_title = 'Confirm Delivery';
$transaction_id = intval($_GET['id'] ?? 0);

if (!$transaction_id) {
    header('Location: dashboard.php');
    exit();
}

// Get transaction details
$stmt = $pdo->prepare("
    SELECT t.*, p.title, p.image, u.name as seller_name 
    FROM transactions t
    JOIN products p ON t.product_id = p.id
    JOIN users u ON t.seller_id = u.id
    WHERE t.id = ? AND t.buyer_id = ? AND t.delivery_status = 'pending'
");
$stmt->execute([$transaction_id, $_SESSION['user_id']]);
$transaction = $stmt->fetch();

if (!$transaction) {
    header('Location: dashboard.php');
    exit();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $feedback = sanitizeInput($_POST['feedback'] ?? '');
    
    if ($action === 'confirm') {
        try {
            $pdo->beginTransaction();
            
            // Update transaction status
            $stmt = $pdo->prepare("
                UPDATE transactions 
                SET delivery_status = 'confirmed', 
                    status = 'released',
                    notes = CONCAT(notes, '\n\nBuyer confirmed delivery: ', ?)
                WHERE id = ? AND buyer_id = ?
            ");
            $stmt->execute([$feedback, $transaction_id, $_SESSION['user_id']]);
            
            // Transfer seller amount to seller's balance
            $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
            $stmt->execute([$transaction['seller_amount'], $transaction['seller_id']]);
            
            // Deduct seller amount from admin's balance (escrow release)
            $stmt = $pdo->prepare("UPDATE users SET balance = balance - ? WHERE role = 'admin'");
            $stmt->execute([$transaction['seller_amount']]);
            
            $pdo->commit();
            
            header('Location: dashboard.php?success=delivery_confirmed');
            exit();
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = 'Failed to confirm delivery. Please try again.';
        }
    } elseif ($action === 'dispute') {
        try {
            // Update transaction to disputed status
            $stmt = $pdo->prepare("
                UPDATE transactions 
                SET delivery_status = 'disputed',
                    notes = CONCAT(notes, '\n\nBuyer disputed delivery: ', ?)
                WHERE id = ? AND buyer_id = ?
            ");
            $stmt->execute([$feedback, $transaction_id, $_SESSION['user_id']]);
            
            $success = 'Dispute reported successfully. Admin will review and contact you soon.';
            
            // Refresh transaction data
            $stmt = $pdo->prepare("
                SELECT t.*, p.title, p.image, u.name as seller_name 
                FROM transactions t
                JOIN products p ON t.product_id = p.id
                JOIN users u ON t.seller_id = u.id
                WHERE t.id = ? AND t.buyer_id = ?
            ");
            $stmt->execute([$transaction_id, $_SESSION['user_id']]);
            $transaction = $stmt->fetch();
            
        } catch (PDOException $e) {
            $error = 'Failed to report dispute. Please try again.';
        }
    }
}

include 'includes/header.php';
?>

<div class="container my-4">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header bg-info text-white">
                    <h4><i class="fas fa-clipboard-check"></i> Confirm Delivery</h4>
                </div>
                <div class="card-body">
                    <?php if ($error): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($success): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                            <div class="mt-2">
                                <a href="dashboard.php" class="btn btn-success btn-sm">Back to Dashboard</a>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($transaction['delivery_status'] === 'pending'): ?>
                        <!-- Transaction Details -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5><i class="fas fa-receipt"></i> Transaction Details</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-3">
                                        <?php if ($transaction['image'] && file_exists('assets/img/' . $transaction['image'])): ?>
                                            <img src="assets/img/<?php echo htmlspecialchars($transaction['image']); ?>" 
                                                 class="img-fluid rounded" alt="Product Image">
                                        <?php else: ?>
                                            <div class="bg-light rounded d-flex align-items-center justify-content-center" 
                                                 style="height: 100px;">
                                                <i class="fas fa-image fa-2x text-muted"></i>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-9">
                                        <h5><?php echo htmlspecialchars($transaction['title']); ?></h5>
                                        <p><strong>Seller:</strong> <?php echo htmlspecialchars($transaction['seller_name']); ?></p>
                                        <p><strong>Amount Paid:</strong> <?php echo formatPrice($transaction['amount']); ?></p>
                                        <p><strong>Purchase Date:</strong> <?php echo date('F j, Y g:i A', strtotime($transaction['created_at'])); ?></p>
                                        <p><strong>Transaction ID:</strong> #<?php echo $transaction['id']; ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Delivery Confirmation -->
                        <div class="alert alert-warning">
                            <h6><i class="fas fa-exclamation-triangle"></i> Important:</h6>
                            <p class="mb-0">Please confirm that you have received the product and it matches the description. 
                            Once confirmed, the payment will be released to the seller and cannot be reversed.</p>
                        </div>
                        
                        <form method="POST" class="needs-validation" novalidate>
                            <div class="mb-4">
                                <label for="feedback" class="form-label">Feedback (Optional)</label>
                                <textarea class="form-control" id="feedback" name="feedback" rows="3" 
                                          placeholder="Share your experience with this purchase..."></textarea>
                                <div class="form-text">Your feedback helps improve our marketplace</div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="card border-success">
                                        <div class="card-body text-center">
                                            <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                                            <h5>Confirm Delivery</h5>
                                            <p class="text-muted">I received the product and it's as described</p>
                                            <button type="submit" name="action" value="confirm" 
                                                    class="btn btn-success btn-lg w-100">
                                                <i class="fas fa-thumbs-up"></i> Confirm & Release Payment
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="card border-danger">
                                        <div class="card-body text-center">
                                            <i class="fas fa-exclamation-triangle fa-3x text-danger mb-3"></i>
                                            <h5>Report Issue</h5>
                                            <p class="text-muted">Product not received or not as described</p>
                                            <button type="submit" name="action" value="dispute" 
                                                    class="btn btn-danger btn-lg w-100"
                                                    onclick="return confirm('Are you sure you want to report an issue? This will notify the admin for review.')">
                                                <i class="fas fa-flag"></i> Report Issue
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </form>
                        
                        <div class="text-center mt-4">
                            <a href="dashboard.php" class="btn btn-secondary">
                                <i class="fas fa-arrow-left"></i> Back to Dashboard
                            </a>
                        </div>
                        
                    <?php elseif ($transaction['delivery_status'] === 'confirmed'): ?>
                        <div class="alert alert-success text-center">
                            <i class="fas fa-check-circle fa-3x mb-3"></i>
                            <h4>Delivery Confirmed</h4>
                            <p>You have already confirmed the delivery of this product. Payment has been released to the seller.</p>
                            <a href="dashboard.php" class="btn btn-success">Back to Dashboard</a>
                        </div>
                        
                    <?php elseif ($transaction['delivery_status'] === 'disputed'): ?>
                        <div class="alert alert-warning text-center">
                            <i class="fas fa-exclamation-triangle fa-3x mb-3"></i>
                            <h4>Dispute Reported</h4>
                            <p>You have reported an issue with this delivery. Our admin team will review and contact you soon.</p>
                            <a href="dashboard.php" class="btn btn-warning">Back to Dashboard</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
