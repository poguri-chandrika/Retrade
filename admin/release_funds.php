<?php
require_once '../config.php';
requireRole('admin');

$page_title = 'Release Funds';

// Handle fund release
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'release_funds') {
    $order_id = intval($_POST['order_id'] ?? 0);
    
    try {
        $pdo->beginTransaction();
        
        // Get order details
        $stmt = $pdo->prepare("
            SELECT t.*, p.title as product_title, seller.id as seller_id, seller.name as seller_name,
                   seller.balance as seller_balance
            FROM transactions t
            JOIN products p ON t.product_id = p.id
            JOIN users seller ON t.seller_id = seller.id
            WHERE t.id = ? AND t.status = 'delivered' AND t.admin_release_required = TRUE
            FOR UPDATE
        ");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch();
        
        if (!$order) {
            throw new Exception('Order not found or not eligible for fund release');
        }
        
        // Update transaction status
        $update = $pdo->prepare("
            UPDATE transactions 
            SET status = 'completed',
                admin_release_required = FALSE,
                admin_released_by = ?,
                admin_released_at = NOW(),
                updated_at = NOW()
            WHERE id = ?
        ");
        $update->execute([$_SESSION['user_id'], $order_id]);
        
        // Update seller's balance
        $update_balance = $pdo->prepare("
            UPDATE users 
            SET balance = balance + ? 
            WHERE id = ?
        ");
        $update_balance->execute([$order['seller_amount'], $order['seller_id']]);
        
        // Create transaction record for the seller
        $transaction = $pdo->prepare("
            INSERT INTO transactions (user_id, amount, type, description, status)
            VALUES (?, ?, 'credit', 'Payment for order #{$order_id}: {$order['product_title']}', 'completed')
        ");
        $transaction->execute([$order['seller_id'], $order['seller_amount']]);
        
        // Create notification for seller
        $notification = $pdo->prepare("
            INSERT INTO notifications (user_id, title, message, type, related_id)
            VALUES (?, 'Payment Released', 'Your payment of ₹{$order['seller_amount']} for order #{$order_id} has been released by admin.', 'payment', ?)
        ");
        $notification->execute([$order['seller_id'], $order_id]);
        
        $pdo->commit();
        
        $_SESSION['success'] = 'Funds released successfully to the seller.';
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = 'Failed to release funds: ' . $e->getMessage();
    }
    
    header('Location: release_funds.php');
    exit;
}

// Get pending fund releases
$stmt = $pdo->prepare("
    SELECT t.*, p.title as product_title, 
           seller.name as seller_name, seller.email as seller_email,
           buyer.name as buyer_name, buyer.email as buyer_email,
           DATEDIFF(NOW(), t.delivered_at) as days_since_delivery
    FROM transactions t
    JOIN products p ON t.product_id = p.id
    JOIN users seller ON t.seller_id = seller.id
    JOIN users buyer ON t.buyer_id = buyer.id
    WHERE t.status = 'delivered' 
    AND t.admin_release_required = TRUE
    ORDER BY t.delivered_at ASC
");
$stmt->execute();
$pending_releases = $stmt->fetchAll();

include '../includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0"><i class="fas fa-money-bill-wave"></i> Release Funds</h1>
    </div>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success"><?= $_SESSION['success'] ?></div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger"><?= $_SESSION['error'] ?></div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">Pending Fund Releases</h5>
        </div>
        <div class="card-body">
            <?php if (empty($pending_releases)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-check-circle fa-4x text-success mb-3"></i>
                    <h4>No pending fund releases</h4>
                    <p class="text-muted">All delivered orders have been processed.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Order #</th>
                                <th>Product</th>
                                <th>Seller</th>
                                <th>Buyer</th>
                                <th>Amount</th>
                                <th>Delivered On</th>
                                <th>Days Waiting</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pending_releases as $order): ?>
                                <tr>
                                    <td>#<?= $order['id'] ?></td>
                                    <td>
                                        <a href="../product_details.php?id=<?= $order['product_id'] ?>">
                                            <?= htmlspecialchars($order['product_title']) ?>
                                        </a>
                                    </td>
                                    <td>
                                        <div><?= htmlspecialchars($order['seller_name']) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($order['seller_email']) ?></small>
                                    </td>
                                    <td>
                                        <div><?= htmlspecialchars($order['buyer_name']) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($order['buyer_email']) ?></small>
                                    </td>
                                    <td>₹<?= number_format($order['seller_amount'], 2) ?></td>
                                    <td><?= date('M d, Y', strtotime($order['delivered_at'])) ?></td>
                                    <td>
                                        <span class="badge bg-<?= $order['days_since_delivery'] > 3 ? 'warning' : 'info' ?>">
                                            <?= $order['days_since_delivery'] ?> days
                                        </span>
                                    </td>
                                    <td>
                                        <div class="btn-group">
                                            <a href="order_details.php?id=<?= $order['id'] ?>" 
                                               class="btn btn-sm btn-outline-primary"
                                               title="View Order Details">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <button type="button" 
                                                    class="btn btn-sm btn-success"
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#releaseModal"
                                                    data-order-id="<?= $order['id'] ?>"
                                                    data-seller-name="<?= htmlspecialchars($order['seller_name']) ?>"
                                                    data-amount="<?= $order['seller_amount'] ?>">
                                                <i class="fas fa-check"></i> Release
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Release Confirmation Modal -->
<div class="modal fade" id="releaseModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm Fund Release</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="releaseForm" method="POST">
                <input type="hidden" name="action" value="release_funds">
                <input type="hidden" name="order_id" id="releaseOrderId">
                
                <div class="modal-body">
                    <p>You are about to release funds to <strong id="sellerName"></strong> for order #<span id="orderNumber"></span>.</p>
                    <p>Amount: <strong>₹<span id="releaseAmount">0.00</span></strong></p>
                    
                    <div class="mb-3">
                        <label for="releaseNotes" class="form-label">Notes (optional)</label>
                        <textarea class="form-control" id="releaseNotes" name="notes" rows="3" 
                                  placeholder="Add any notes about this release"></textarea>
                    </div>
                    
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="confirmRelease" required>
                        <label class="form-check-label" for="confirmRelease">
                            I confirm that the buyer has received the item and is satisfied with the purchase.
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-check"></i> Release Funds
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Initialize modal with order data
document.getElementById('releaseModal').addEventListener('show.bs.modal', function (event) {
    const button = event.relatedTarget;
    const orderId = button.getAttribute('data-order-id');
    const sellerName = button.getAttribute('data-seller-name');
    const amount = parseFloat(button.getAttribute('data-amount'));
    
    document.getElementById('releaseOrderId').value = orderId;
    document.getElementById('sellerName').textContent = sellerName;
    document.getElementById('orderNumber').textContent = orderId;
    document.getElementById('releaseAmount').textContent = amount.toFixed(2);
    
    // Reset form
    document.getElementById('releaseForm').reset();
    document.getElementById('confirmRelease').checked = false;
});

// Handle form submission
document.getElementById('releaseForm').addEventListener('submit', function(e) {
    if (!document.getElementById('confirmRelease').checked) {
        e.preventDefault();
        alert('Please confirm the release by checking the confirmation box.');
    }
});
</script>

<?php include '../includes/footer.php'; ?>
