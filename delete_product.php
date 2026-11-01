<?php
require_once 'config.php';
requireRole('seller');

$product_id = intval($_GET['id'] ?? 0);

if (!$product_id) {
    header('Location: dashboard.php');
    exit();
}

// Get product details and verify ownership
$stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? AND seller_id = ?");
$stmt->execute([$product_id, $_SESSION['user_id']]);
$product = $stmt->fetch();

if (!$product) {
    header('Location: dashboard.php');
    exit();
}

// Check if product has any transactions
$stmt = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE product_id = ?");
$stmt->execute([$product_id]);
$transaction_count = $stmt->fetchColumn();

if ($transaction_count > 0) {
    header('Location: dashboard.php?error=cannot_delete_product_with_transactions');
    exit();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_delete'])) {
    try {
        // Delete the product
        $stmt = $pdo->prepare("DELETE FROM products WHERE id = ? AND seller_id = ?");
        $stmt->execute([$product_id, $_SESSION['user_id']]);
        
        // Delete associated image file
        if ($product['image'] && file_exists('assets/img/' . $product['image'])) {
            unlink('assets/img/' . $product['image']);
        }
        
        header('Location: dashboard.php?success=product_deleted');
        exit();
    } catch (PDOException $e) {
        $error = 'Failed to delete product. Please try again.';
    }
}

$page_title = 'Delete Product';
include 'includes/header.php';
?>

<div class="container my-4">
    <div class="row">
        <div class="col-md-6 mx-auto">
            <div class="card">
                <div class="card-header bg-danger text-white">
                    <h4><i class="fas fa-trash"></i> Delete Product</h4>
                </div>
                <div class="card-body">
                    <?php if ($error): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>Warning!</strong> This action cannot be undone.
                    </div>
                    
                    <div class="card mb-3">
                        <div class="card-body">
                            <div class="row">
                                <?php if ($product['image'] && file_exists('assets/img/' . $product['image'])): ?>
                                    <div class="col-md-4">
                                        <img src="assets/img/<?php echo htmlspecialchars($product['image']); ?>" 
                                             class="img-fluid rounded" alt="Product Image">
                                    </div>
                                    <div class="col-md-8">
                                <?php else: ?>
                                    <div class="col-md-12">
                                <?php endif; ?>
                                    <h5><?php echo htmlspecialchars($product['title']); ?></h5>
                                    <p class="text-muted"><?php echo htmlspecialchars($product['description']); ?></p>
                                    <p><strong>Price:</strong> <?php echo formatPrice($product['price']); ?></p>
                                    <p><strong>Category:</strong> <?php echo htmlspecialchars($product['category'] ?? 'N/A'); ?></p>
                                    <p><strong>Status:</strong> 
                                        <span class="badge bg-<?php 
                                            echo $product['status'] === 'pending' ? 'warning' : 
                                                ($product['status'] === 'approved' ? 'success' : 'danger'); 
                                        ?>">
                                            <?php echo ucfirst($product['status']); ?>
                                        </span>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <p class="text-center">
                        Are you sure you want to delete this product? This action cannot be undone.
                    </p>
                    
                    <form method="POST" class="text-center">
                        <div class="d-flex justify-content-center gap-3">
                            <a href="dashboard.php" class="btn btn-secondary">
                                <i class="fas fa-arrow-left"></i> Cancel
                            </a>
                            <button type="submit" name="confirm_delete" class="btn btn-danger">
                                <i class="fas fa-trash"></i> Yes, Delete Product
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
