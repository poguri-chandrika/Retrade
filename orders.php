<?php
require_once 'config.php';
requireLogin();

$page_title = 'My Orders';
$user_id = $_SESSION['user_id'];

// Get user's orders
$stmt = $pdo->prepare("
    SELECT t.*, p.title as product_title, p.image, 
           seller.name as seller_name, buyer.name as buyer_name
    FROM transactions t
    JOIN products p ON t.product_id = p.id
    JOIN users seller ON t.seller_id = seller.id
    JOIN users buyer ON t.buyer_id = buyer.id
    WHERE t.buyer_id = ? OR t.seller_id = ?
    ORDER BY t.created_at DESC
");
$stmt->execute([$user_id, $user_id]);
$orders = $stmt->fetchAll();

include 'includes/header.php';
?>

<div class="container my-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="fas fa-shopping-bag"></i> My Orders</h2>
        <div class="btn-group" role="group">
            <a href="?filter=all" class="btn btn-outline-secondary">All</a>
            <a href="?filter=pending" class="btn btn-outline-warning">Pending</a>
            <a href="?filter=shipped" class="btn btn-outline-info">Shipped</a>
            <a href="?filter=delivered" class="btn btn-outline-success">Delivered</a>
        </div>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <?php if (empty($orders)): ?>
                <div class="text-center p-5">
                    <i class="fas fa-inbox fa-4x text-muted mb-3"></i>
                    <h4>No orders found</h4>
                    <p class="text-muted">Your orders will appear here</p>
                    <a href="index.php" class="btn btn-primary">Browse Products</a>
                </div>
            <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($orders as $order): 
                        $is_seller = $order['seller_id'] == $user_id;
                        $status_class = [
                            'pending' => 'warning',
                            'approved' => 'info',
                            'shipped' => 'primary',
                            'delivered' => 'success',
                            'rejected' => 'danger',
                            'refunded' => 'secondary'
                        ][$order['status']] ?? 'secondary';
                    ?>
                    <div class="list-group-item">
                        <div class="row align-items-center">
                            <div class="col-md-2">
                                <img src="assets/img/<?= htmlspecialchars($order['image']) ?>" 
                                     class="img-fluid rounded" alt="<?= htmlspecialchars($order['product_title']) ?>">
                            </div>
                            <div class="col-md-4">
                                <h5 class="mb-1"><?= htmlspecialchars($order['product_title']) ?></h5>
                                <p class="mb-1 text-muted">
                                    <?= $is_seller ? 'Buyer' : 'Seller' ?>: 
                                    <?= htmlspecialchars($is_seller ? $order['buyer_name'] : $order['seller_name']) ?>
                                </p>
                                <p class="mb-0">
                                    <span class="badge bg-<?= $status_class ?>">
                                        <?= ucfirst($order['status']) ?>
                                    </span>
                                </p>
                            </div>
                            <div class="col-md-3">
                                <p class="mb-1">
                                    <strong>₹<?= number_format($order['amount'], 2) ?></strong>
                                </p>
                                <p class="text-muted small mb-0">
                                    Ordered on <?= date('M d, Y', strtotime($order['created_at'])) ?>
                                </p>
                                <?php if ($order['shipped_at']): ?>
                                <p class="text-muted small mb-0">
                                    Shipped on <?= date('M d, Y', strtotime($order['shipped_at'])) ?>
                                </p>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-3 text-end">
                                <a href="order_details.php?id=<?= $order['id'] ?>" 
                                   class="btn btn-sm btn-outline-primary">
                                    View Details
                                </a>
                                <?php if (!$is_seller && $order['status'] === 'delivered'): ?>
                                <button class="btn btn-sm btn-success" 
                                        onclick="confirmDelivery(<?= $order['id'] ?>)">
                                    Confirm Delivery
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Confirm Delivery Modal -->
<div class="modal fade" id="confirmDeliveryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm Delivery</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Have you received the product in good condition?</p>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="productCondition">
                    <label class="form-check-label" for="productCondition">
                        I confirm the product is as described
                    </label>
                </div>
                <textarea id="deliveryNotes" class="form-control mb-3" 
                          placeholder="Any additional notes (optional)"></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="confirmDeliveryBtn">Confirm Delivery</button>
            </div>
        </div>
    </div>
</div>

<script>
function confirmDelivery(orderId) {
    $('#confirmDeliveryModal').modal('show');
    
    $('#confirmDeliveryBtn').off('click').on('click', function() {
        if (!$('#productCondition').is(':checked')) {
            alert('Please confirm that the product is as described');
            return;
        }
        
        const notes = $('#deliveryNotes').val();
        
        // Send AJAX request to confirm delivery
        $.post('api/confirm_delivery.php', {
            order_id: orderId,
            notes: notes
        }, function(response) {
            if (response.success) {
                location.reload();
            } else {
                alert(response.message || 'Failed to confirm delivery');
            }
        }).fail(function() {
            alert('An error occurred. Please try again.');
        });
    });
}
</script>

<?php include 'includes/footer.php'; ?>
