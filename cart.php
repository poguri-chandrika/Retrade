<?php
require_once __DIR__ . '/config.php';
requireLogin();
if (getUserRole() !== 'buyer') { header('Location: dashboard.php'); exit; }

$page_title = 'My Cart';
$buyerId = (int)$_SESSION['user_id'];

// Ensure cart table exists
$pdo->exec("CREATE TABLE IF NOT EXISTS cart (
  id INT AUTO_INCREMENT PRIMARY KEY,
  buyer_id INT NOT NULL,
  product_id INT NOT NULL,
  quantity INT NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_buyer_product (buyer_id, product_id),
  FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// Load cart items with product data
$sql = "SELECT c.id as cid, c.quantity, p.*, u.name AS seller_name
        FROM cart c
        JOIN products p ON c.product_id = p.id
        JOIN users u ON p.seller_id = u.id
        WHERE c.buyer_id = ?
        ORDER BY c.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$buyerId]);
$items = $stmt->fetchAll();

// Compute totals
$total = 0.0;
foreach ($items as $it) { $total += (float)$it['price'] * (int)$it['quantity']; }

include __DIR__ . '/includes/header.php';
?>
<div class="container my-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2><i class="fas fa-shopping-cart text-primary"></i> My Cart</h2>
    <span class="badge bg-secondary rounded-pill"><?php echo count($items); ?> items</span>
  </div>

  <?php if (empty($items)): ?>
    <div class="text-center py-5">
      <i class="fas fa-shopping-cart fa-3x text-muted mb-3"></i>
      <h5>Your cart is empty</h5>
      <p class="text-muted">Browse products and add them to your cart.</p>
      <a href="index.php" class="btn btn-primary"><i class="fas fa-search"></i> Browse Products</a>
    </div>
  <?php else: ?>
    <div class="row g-4">
      <div class="col-lg-8">
        <div class="card">
          <div class="card-body">
            <?php foreach ($items as $it): if (!($it['image'] && file_exists(__DIR__.'/assets/img/'.$it['image']))) continue; ?>
              <div class="d-flex align-items-center justify-content-between border-bottom py-3">
                <div class="d-flex align-items-center gap-3">
                  <img src="assets/img/<?php echo htmlspecialchars($it['image']); ?>" style="width:90px;height:90px;object-fit:cover;border-radius:10px" alt="<?php echo htmlspecialchars($it['title']); ?>">
                  <div>
                    <div class="fw-bold"><?php echo htmlspecialchars($it['title']); ?></div>
                    <small class="text-muted"><i class="fas fa-user"></i> <?php echo htmlspecialchars($it['seller_name']); ?></small>
                  </div>
                </div>
                <div class="text-end">
                  <div class="fw-bold mb-2"><?php echo formatPrice($it['price']); ?></div>
                  <div class="d-flex gap-2 justify-content-end">
                    <a href="product_details.php?id=<?php echo (int)$it['id']; ?>" class="btn btn-outline-primary btn-sm"><i class="fas fa-eye"></i> View</a>
                    <a href="buy_product.php?id=<?php echo (int)$it['id']; ?>" class="btn btn-success btn-sm"><i class="fas fa-bolt"></i> Buy Now</a>
                    <button class="btn btn-outline-danger btn-sm cartRemoveBtn" data-product-id="<?php echo (int)$it['id']; ?>"><i class="fas fa-trash"></i> Remove</button>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <div class="col-lg-4">
        <div class="card">
          <div class="card-body">
            <h5 class="mb-3"><i class="fas fa-receipt me-2"></i> Order Summary</h5>
            <div class="d-flex justify-content-between mb-2"><span>Items</span><span><?php echo count($items); ?></span></div>
            <div class="d-flex justify-content-between mb-2"><span>Subtotal</span><span><?php echo formatPrice($total); ?></span></div>
            <div class="d-flex justify-content-between mb-2"><span>Shipping</span><span>Free</span></div>
            <hr>
            <div class="d-flex justify-content-between mb-3 fw-bold"><span>Total</span><span><?php echo formatPrice($total); ?></span></div>
            <div class="d-grid gap-2">
              <a href="index.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> Continue Shopping</a>
              <button class="btn btn-success" disabled><i class="fas fa-credit-card"></i> Checkout (Coming Soon)</button>
            </div>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
