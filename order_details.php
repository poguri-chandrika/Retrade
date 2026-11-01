<?php
require_once 'config.php';
requireLogin();

$order_id = intval($_GET['id'] ?? 0);
$user_id = $_SESSION['user_id'];

// Handle order actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        
        // Get current order status
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch();
        
        if (!$order) {
            throw new Exception('Order not found');
        }
        
        // Get transaction details
        $stmt = $pdo->prepare("SELECT * FROM transactions WHERE id = ?");
        $stmt->execute([$order['transaction_id']]);
        $transaction = $stmt->fetch();
        
        if (isset($_POST['confirm_delivery'])) {
            // Buyer confirms delivery
            if ($transaction['buyer_id'] != $user_id) {
                throw new Exception('Only the buyer can confirm delivery');
            }
            
            // Update transaction
            $pdo->prepare("
                UPDATE transactions 
                SET buyer_confirmed = 1, 
                    escrow_status = 'released_to_seller',
                    released_at = NOW(),
                    released_by = ?
                WHERE id = ? AND escrow_status = 'funds_held'
            ")->execute([$user_id, $transaction['id']]);
            
            // Release funds to seller
            $pdo->prepare("
                UPDATE users 
                SET balance = balance + ? 
                WHERE id = ?
            ")->execute([$transaction['seller_amount'], $transaction['seller_id']]);
            
            // Update order status
            $pdo->prepare("
                UPDATE orders 
                SET status = 'completed',
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$order_id]);
            
            $_SESSION['success'] = 'Thank you for confirming delivery! The funds have been released to the seller.';
            
        } elseif (isset($_POST['dispute_order'])) {
            // Handle order dispute
            $reason = trim($_POST['dispute_reason'] ?? '');
            
            if (empty($reason)) {
                throw new Exception('Please provide a reason for the dispute');
            }
            
            // Update order status
            $pdo->prepare("
                UPDATE orders 
                SET status = 'disputed',
                    notes = CONCAT(IFNULL(notes, ''), '\nDispute: ', ?),
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$reason, $order_id]);
            
            // Notify admin
            // (You can implement notification system here)
            
            $_SESSION['success'] = 'Your dispute has been submitted. Our team will review it shortly.';
        }
        
        $pdo->commit();
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = 'Action failed: ' . $e->getMessage();
    }
    
    header('Location: order_details.php?id=' . $order_id);
    exit;
}

// Get order details with escrow information
$stmt = $pdo->prepare("
    SELECT t.*, o.*, p.*, 
           seller.name as seller_name, seller.email as seller_email, seller.phone as seller_phone,
           buyer.name as buyer_name, buyer.email as buyer_email, buyer.phone as buyer_phone,
           es.auto_release_days, es.admin_commission_rate
    FROM transactions t
    JOIN orders o ON o.transaction_id = t.id
    JOIN products p ON t.product_id = p.id
    JOIN users seller ON t.seller_id = seller.id
    JOIN users buyer ON t.buyer_id = buyer.id
    CROSS JOIN (SELECT * FROM escrow_settings LIMIT 1) es
    WHERE o.id = ? AND (t.buyer_id = ? OR t.seller_id = ?)
");
$stmt->execute([$order_id, $user_id, $user_id]);
$order = $stmt->fetch();

if (!$order) {
    header('Location: orders.php');
    exit;
}

$is_seller = $order['seller_id'] == $user_id;
$is_buyer = $order['buyer_id'] == $user_id;
$is_admin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';

// Calculate remaining time for auto-release
$auto_release_date = new DateTime($order['auto_release_date']);
$now = new DateTime();
$time_remaining = $now->diff($auto_release_date);
$auto_release_in = $now < $auto_release_date ? $time_remaining->format('%a days, %h hours') : 'Time expired';

$page_title = 'Order #' . $order_id;
include 'includes/header.php';
?>

<div class="container my-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="fas fa-receipt"></i> Order #<?= $order_id ?></h2>
        <a href="orders.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left"></i> Back to Orders
        </a>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <!-- Order Status Timeline with Escrow Info -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Order & Escrow Status</h5>
                    <?php if ($order['escrow_status'] === 'funds_held'): ?>
                        <span class="badge bg-warning">Funds in Escrow</span>
                    <?php elseif ($order['escrow_status'] === 'released_to_seller'): ?>
                        <span class="badge bg-success">Funds Released</span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if ($order['escrow_status'] === 'funds_held'): ?>
                        <div class="alert alert-info mb-4">
                            <h6><i class="fas fa-lock me-2"></i>Escrow Protection Active</h6>
                            <p class="mb-2">
                                Your payment of <strong>₹<?= number_format($order['amount'], 2) ?></strong> is securely held in escrow.
                                <?php if ($is_buyer): ?>
                                    Once you confirm delivery, the funds will be released to the seller.
                                <?php else: ?>
                                    The buyer has up to <?= $order['auto_release_days'] ?> days to confirm delivery.
                                <?php endif; ?>
                            </p>
                            <?php if ($now < $auto_release_date): ?>
                                <p class="mb-0">
                                    <i class="far fa-clock me-1"></i> Auto-release in: <strong><?= $auto_release_in ?></strong>
                                </p>
                            <?php else: ?>
                                <p class="mb-0 text-success">
                                    <i class="fas fa-check-circle me-1"></i> Funds will be released soon
                                </p>
                            <?php endif; ?>
                        </div>
                    <?php elseif ($order['escrow_status'] === 'released_to_seller'): ?>
                        <div class="alert alert-success mb-4">
                            <h6><i class="fas fa-check-circle me-2"></i>Funds Released</h6>
                            <p class="mb-0">
                                The payment of <strong>₹<?= number_format($order['amount'], 2) ?></strong> has been released to the seller.
                                <?php if ($order['released_by'] == $user_id): ?>
                                    (You confirmed the delivery)
                                <?php elseif ($order['released_by']): ?>
                                    (Confirmed by buyer)
                                <?php else: ?>
                                    (Auto-released after <?= $order['auto_release_days'] ?> days)
                                <?php endif; ?>
                            </p>
                        </div>
                    <?php endif; ?>
                    
                    <div class="timeline">
                        <?php
                        $statuses = [
                            'pending' => ['icon' => 'clock', 'label' => 'Order Placed', 'time' => $order['created_at']],
                            'processing' => ['icon' => 'cog', 'label' => 'Processing', 'time' => $order['status'] === 'processing' ? $order['updated_at'] : null],
                            'shipped' => ['icon' => 'truck', 'label' => 'Shipped', 'time' => $order['shipped_at']],
                            'delivered' => ['icon' => 'check-circle', 'label' => 'Delivered', 'time' => $order['delivered_at']],
                            'completed' => ['icon' => 'check-double', 'label' => 'Completed', 'time' => $order['status'] === 'completed' ? $order['updated_at'] : null]
                        ];
                        
                        $current_status = $order['status'];
                        $completed = false;
                        
                        foreach ($statuses as $status => $data):
                            $active = $current_status === $status;
                            $done = $completed || $active;
                            $completed = $completed || $active;
                            
                            $icon = $done ? 'check-circle' : $data['icon'];
                            $text_class = $done ? 'text-primary' : 'text-muted';
                            $icon_class = $active ? 'bg-primary text-white' : ($done ? 'bg-light' : 'bg-light text-muted');
                            $time = $data['time'] ? date('M d, Y h:i A', strtotime($data['time'])) : null;
                        ?>
                        <div class="timeline-item <?= $done ? 'done' : '' ?>">
                            <div class="timeline-item">
                                <div class="timeline-marker">
                                    <i class="fas fa-check"></i>
                                </div>
                                <div class="timeline-content">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h6 class="mb-1">Order Placed</h6>
                                            <p class="text-muted small mb-0">Your order has been received</p>
                                            <small class="text-muted"><?= date('M j, Y g:i A', strtotime($order['created_at'])) ?></small>
                                        </div>
                                        <?php if ($order['escrow_status'] === 'funds_held'): ?>
                                            <span class="badge bg-info">Payment Secured</span>
                                            <?php if ($order['shipping_provider']): ?>
                                                (<?= htmlspecialchars($order['shipping_provider']) ?>)
                                            <?php endif; ?>
                                        </p>
                                        <?php if ($order['tracking_url']): ?>
                                            <a href="<?= htmlspecialchars($order['tracking_url']) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                                Track Package
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Order Items -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Order Details</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th class="text-end">Price</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <img src="assets/img/<?= htmlspecialchars($order['image']) ?>" 
                                                 class="img-thumbnail me-3" style="width: 80px; height: 80px; object-fit: cover;"
                                                 alt="<?= htmlspecialchars($order['title']) ?>">
                                            <div>
                                                <h6 class="mb-1"><?= htmlspecialchars($order['title']) ?></h6>
                                                <p class="text-muted small mb-0">
                                                    <?= htmlspecialchars($order['category'] ?? 'Uncategorized') ?>
                                                </p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="text-end">₹<?= number_format($order['price'], 2) ?></td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th>Subtotal</th>
                                    <td class="text-end">₹<?= number_format($order['price'], 2) ?></td>
                                </tr>
                                <tr>
                                    <th>Service Fee (<?= number_format(($order['admin_commission'] / $order['price']) * 100, 2) ?>%)</th>
                                    <td class="text-end">₹<?= number_format($order['admin_commission'], 2) ?></td>
                                </tr>
                                <tr class="table-active">
                                    <th>Total</th>
                                    <td class="text-end"><strong>₹<?= number_format($order['amount'], 2) ?></strong></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Order Notes -->
            <?php if ($order['buyer_notes'] || $order['seller_notes']): ?>
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Order Notes</h5>
                </div>
                <div class="card-body">
                    <?php if ($order['seller_notes'] && $is_seller): ?>
                        <div class="mb-3">
                            <h6>Your Notes</h6>
                            <p class="text-muted"><?= nl2br(htmlspecialchars($order['seller_notes'])) ?></p>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($order['buyer_notes'] && !$is_seller): ?>
                        <div class="mb-3">
                            <h6>Your Notes</h6>
                            <p class="text-muted"><?= nl2br(htmlspecialchars($order['buyer_notes'])) ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="col-lg-4">
            <!-- Order Summary -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Order Summary</h5>
                </div>
                <div class="card-body">
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span>Order #</span>
                            <span><?= $order_id ?></span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span>Order Date</span>
                            <span><?= date('M d, Y h:i A', strtotime($order['created_at'])) ?></span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span>Status</span>
                            <span class="badge bg-<?= 
                                $order['status'] === 'completed' ? 'success' : 
                                ($order['status'] === 'cancelled' ? 'danger' : 'primary') 
                            ?>">
                                <?= ucfirst($order['status']) ?>
                            </span>
                        </li>
                        <?php if ($order['shipped_at']): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span>Shipped On</span>
                            <span><?= date('M d, Y', strtotime($order['shipped_at'])) ?></span>
                        </li>
                        <?php endif; ?>
                        <?php if ($order['delivered_at']): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span>Delivered On</span>
                            <span><?= date('M d, Y', strtotime($order['delivered_at'])) ?></span>
                        </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>

            <!-- Contact Information -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><?= $is_seller ? 'Buyer' : 'Seller' ?> Information</h5>
                </div>
                <div class="card-body">
                    <h6><?= htmlspecialchars($other_party) ?></h6>
                    <p class="mb-1">
                        <i class="fas fa-envelope me-2"></i>
                        <a href="mailto:<?= htmlspecialchars($other_party_email) ?>">
                            <?= htmlspecialchars($other_party_email) ?>
                        </a>
                    </p>
                    <?php if ($other_party_phone): ?>
                    <p class="mb-0">
                        <i class="fas fa-phone me-2"></i>
                        <a href="tel:<?= htmlspecialchars($other_party_phone) ?>">
                            <?= htmlspecialchars($other_party_phone) ?>
                        </a>
                    </p>
                    <?php endif; ?>
                    
                    <div class="mt-3">
                        <a href="inbox.php?user_id=<?= $is_seller ? $order['buyer_id'] : $order['seller_id'] ?>" 
                           class="btn btn-outline-primary btn-sm w-100">
                            <i class="fas fa-comment-dots me-1"></i> Send Message
                        </a>
                    </div>
                </div>
            </div>

            <!-- Order Actions with Escrow Controls -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Order Actions</h5>
                </div>
                <div class="card-body">
                    <?php if ($is_buyer && $order['escrow_status'] === 'funds_held' && $order['status'] === 'shipped'): ?>
                        <div class="mb-4">
                            <h6 class="mb-3"><i class="fas fa-check-circle me-2"></i>Confirm Order Received</h6>
                            <p class="text-muted small mb-3">
                                Please confirm that you have received the item and it matches the description.
                            </p>
                            <form method="post" class="mb-3">
                                <button type="submit" name="confirm_delivery" class="btn btn-success w-100 mb-2 confirm-payment" 
                                    data-order-id="<?= $order_id ?>"
                                    onclick="return confirm('Are you sure you want to confirm receipt and release payment to the seller?');">
                                    <i class="fas fa-check-circle me-2"></i> Confirm & Release Payment
                                </button>
                            </form>
                            <button type="button" class="btn btn-outline-danger w-100" data-bs-toggle="modal" data-bs-target="#reportIssueModal">
                                <i class="fas fa-exclamation-triangle me-2"></i> Report an Issue
                            </button>
                        </div>
                        <hr>
                    <?php elseif ($is_buyer && $order['buyer_confirmed']): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-check-circle"></i> You've confirmed receipt of this order.
                            <?php if ($order['escrow_status'] === 'funds_held'): ?>
                                <div class="mt-2">
                                    <i class="fas fa-info-circle"></i> Payment is being processed and will be released to the seller shortly.
                                </div>
                            <?php endif; ?>
                        </div>
                        <hr>
                    <?php endif; ?>
                    
                    <?php if ($is_seller && $order['escrow_status'] === 'funds_held'): ?>
                        <div class="alert alert-warning">
                            <h6><i class="fas fa-info-circle me-2"></i>Pending Buyer Confirmation</h6>
                            <p class="mb-2">
                                The buyer has up to <?= $order['auto_release_days'] ?> days to confirm delivery.
                                <?php if ($now < $auto_release_date): ?>
                                    Funds will be automatically released in: <strong><?= $auto_release_in ?></strong>
                                <?php else: ?>
                                    Funds will be released shortly.
                                <?php endif; ?>
                            </p>
                        </div>
                        <hr>
                    <?php endif; ?>
                    <?php if ($is_seller): ?>
                        <?php if (in_array($order['status'], ['pending', 'processing'])): ?>
                            <button class="btn btn-primary w-100 mb-2" data-bs-toggle="modal" data-bs-target="#shippingModal">
                                <i class="fas fa-truck me-1"></i> Mark as Shipped
                            </button>
                        <?php endif; ?>
                        
                        <?php if ($order['status'] === 'shipped'): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> Waiting for buyer to confirm delivery.
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($order['status'] === 'completed'): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle"></i> Order completed successfully!
                            </div>
                        <?php endif; ?>
                    <?php else: // Buyer actions ?>
                        <?php if ($order['status'] === 'shipped'): ?>
                            <?php if (empty($order['buyer_confirmed'])): ?>
                                <div class="mb-4">
                                    <h6><i class="fas fa-check-circle me-2"></i>Confirm Order Received</h6>
                                    <p class="text-muted small mb-3">
                                        Please confirm that you have received the item and it matches the description.
                                    </p>
                                    <form method="post" class="mb-3">
                                        <button type="submit" name="confirm_delivery" class="btn btn-success w-100 mb-2 confirm-payment" 
                                            data-order-id="<?= $order_id ?>"
                                            onclick="return confirm('Are you sure you want to confirm receipt and release payment to the seller?');">
                                            <i class="fas fa-check-circle me-2"></i> Confirm & Release Payment
                                        </button>
                                    </form>
                                    <button type="button" class="btn btn-outline-danger w-100" data-bs-toggle="modal" data-bs-target="#reportIssueModal">
                                        <i class="fas fa-exclamation-triangle me-2"></i> Report an Issue
                                    </button>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-info">
                                    <i class="fas fa-check-circle"></i> You've confirmed receipt of this order.
                                    <?php if ($order['escrow_status'] === 'funds_held'): ?>
                                        <div class="mt-2">
                                            <i class="fas fa-info-circle"></i> Payment is being processed and will be released to the seller shortly.
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        <?php elseif ($order['status'] === 'completed'): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle"></i> Thank you for your purchase!
                                <?php if ($order['escrow_status'] === 'released_to_seller'): ?>
                                    <div class="mt-2">
                                        <i class="fas fa-check text-success"></i> Payment has been released to the seller.
                                    </div>
                                <?php endif; ?>
                            </div>
                            <a href="product_review.php?product_id=<?= $order['product_id'] ?>" 
                               class="btn btn-outline-primary w-100 mb-2">
                                <i class="fas fa-star me-1"></i> Write a Review
                            </a>
                            <a href="chat.php?seller_id=<?= $order['seller_id'] ?>" class="btn btn-outline-secondary w-100">
                                <i class="fas fa-comment me-1"></i> Contact Seller
                            </a>
                        <?php endif; ?>
                    <?php endif; ?>
                    
                    <div class="mt-3">
                        <a href="#" class="text-danger" data-bs-toggle="modal" data-bs-target="#reportIssueModal">
                            <i class="fas fa-flag"></i> Report an Issue
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Shipping Modal -->
<div class="modal fade" id="shippingModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Mark as Shipped</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="shippingForm" action="api/update_shipping.php" method="POST">
                <div class="modal-body">
                    <input type="hidden" name="order_id" value="<?= $order_id ?>">
                    
                    <div class="mb-3">
                        <label class="form-label">Shipping Carrier</label>
                        <select name="shipping_provider" class="form-select" required>
                            <option value="">Select carrier</option>
                            <option value="India Post">India Post</option>
                            <option value="DTDC">DTDC</n                            <option value="Delhivery">Delhivery</option>
                            <option value="Blue Dart">Blue Dart</option>
                            <option value="Ecom Express">Ecom Express</option>
                            <option value="XpressBees">XpressBees</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Tracking Number</label>
                        <input type="text" name="tracking_number" class="form-control" required
                               placeholder="Enter tracking number">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Tracking URL (optional)</label>
                        <input type="url" name="tracking_url" class="form-control"
                               placeholder="https://">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes (optional)</label>
                        <textarea name="notes" class="form-control" rows="3"
                                 placeholder="Any additional information for the buyer"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Confirm Shipping</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Report Issue Modal -->
<div class="modal fade" id="reportIssueModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Report an Issue</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="reportForm" action="api/report_issue.php" method="POST">
                <div class="modal-body">
                    <input type="hidden" name="order_id" value="<?= $order_id ?>">
                    
                    <div class="mb-3">
                        <label class="form-label">Issue Type</label>
                        <select name="issue_type" class="form-select" required>
                            <option value="">Select issue type</option>
                            <option value="item_not_received">Item not received</option>
                            <option value="item_not_as_described">Item not as described</option>
                            <option value="damaged_item">Damaged item</option>
                            <option value="seller_unresponsive">Seller is not responding</option>
                            <option value="other">Other issue</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="4" required
                                 placeholder="Please describe the issue in detail"></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Upload Photos (optional, max 3MB each)</label>
                        <input type="file" name="photos[]" class="form-control" multiple accept="image/*">
                        <div class="form-text">You can upload up to 5 photos</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Submit Report</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Handle shipping form submission
$('#shippingForm').on('submit', function(e) {
    e.preventDefault();
    
    $.ajax({
        url: $(this).attr('action'),
        type: 'POST',
        data: $(this).serialize(),
        success: function(response) {
            if (response.success) {
                location.reload();
            } else {
                alert(response.message || 'Failed to update shipping information');
            }
        },
        error: function() {
            alert('An error occurred. Please try again.');
        }
    });
});

// Handle report form submission
$('#reportForm').on('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    
    $.ajax({
        url: $(this).attr('action'),
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function(response) {
            if (response.success) {
                alert('Your report has been submitted. We will contact you shortly.');
                $('#reportIssueModal').modal('hide');
            } else {
                alert(response.message || 'Failed to submit report');
            }
        },
        error: function() {
            alert('An error occurred. Please try again.');
        }
    });
});

// Confirm delivery function
function confirmDelivery(orderId) {
    if (confirm('Have you received the item in good condition?')) {
        $.post('api/confirm_delivery.php', {
            order_id: orderId
        }, function(response) {
            if (response.success) {
                location.reload();
            } else {
                alert(response.message || 'Failed to confirm delivery');
            }
        }).fail(function() {
            alert('An error occurred. Please try again.');
        });
    }
}
</script>

<style>
.timeline {
    position: relative;
    padding-left: 30px;
}

.timeline-item {
    position: relative;
    padding-bottom: 20px;
    padding-left: 20px;
    border-left: 2px solid #e9ecef;
}

.timeline-item:last-child {
    padding-bottom: 0;
}

.timeline-item.done {
    border-left-color: #0d6efd;
}

.timeline-icon {
    position: absolute;
    left: -15px;
    top: 0;
    width: 30px;
    height: 30px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1;
}

.timeline-content {
    padding: 10px 0;
}
</style>

<?php include 'includes/footer.php'; ?>
