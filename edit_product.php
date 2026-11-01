<?php
require_once 'config.php';
requireRole('seller');

$page_title = 'Edit Product';
$error = '';
$success = '';

// Get product ID
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

// Get categories (id + name)
$stmt = $pdo->query("SELECT id, name FROM categories ORDER BY name");
$categories = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = sanitizeInput($_POST['title']);
    $description = sanitizeInput($_POST['description']);
    $price = floatval($_POST['price']);
    $category_id = (int)($_POST['category_id'] ?? 0);
    $category_name = null;
    
    // Validation
    if (empty($title) || empty($description) || $price <= 0) {
        $error = 'Please fill all required fields with valid data.';
    } elseif (strlen($title) < 3) {
        $error = 'Product title must be at least 3 characters long.';
    } elseif (strlen($description) < 10) {
        $error = 'Product description must be at least 10 characters long.';
    } elseif ($price > 1000000) {
        $error = 'Product price cannot exceed ₹10,00,000.';
    } else {
        // Resolve category name from id for saving
        if ($category_id > 0) {
            try {
                $st = $pdo->prepare("SELECT name FROM categories WHERE id=?");
                $st->execute([$category_id]);
                $category_name = $st->fetchColumn() ?: null;
            } catch (Throwable $e) { $category_name = null; }
        }
        $image_name = $product['image']; // Keep existing image by default
        
        // Handle image upload
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $file_type = $_FILES['image']['type'];
            $file_size = $_FILES['image']['size'];
            
            if (!in_array($file_type, $allowed_types)) {
                $error = 'Invalid image type. Please upload JPEG, PNG, GIF, or WebP images only.';
            } elseif ($file_size > 5 * 1024 * 1024) { // 5MB limit
                $error = 'Image size must be less than 5MB.';
            } else {
                // Create uploads directory if it doesn't exist
                $upload_dir = 'assets/img/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                // Generate unique filename
                $file_extension = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
                $new_image_name = 'product_' . uniqid() . '.' . $file_extension;
                $upload_path = $upload_dir . $new_image_name;
                
                if (move_uploaded_file($_FILES['image']['tmp_name'], $upload_path)) {
                    // Delete old image if it exists
                    if ($product['image'] && file_exists($upload_dir . $product['image'])) {
                        unlink($upload_dir . $product['image']);
                    }
                    $image_name = $new_image_name;
                } else {
                    $error = 'Failed to upload image. Please try again.';
                }
            }
        }
        
        if (empty($error)) {
            try {
                // Reset status to pending if product was previously rejected
                $new_status = ($product['status'] === 'rejected') ? 'pending' : $product['status'];
                
                $stmt = $pdo->prepare("UPDATE products SET title = ?, description = ?, price = ?, category = ?, category_id = ?, image = ?, status = ? WHERE id = ? AND seller_id = ?");
                $stmt->execute([$title, $description, $price, $category_name, ($category_id ?: null), $image_name, $new_status, $product_id, $_SESSION['user_id']]);
                
                $success = 'Product updated successfully!';
                
                // Refresh product data
                $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? AND seller_id = ?");
                $stmt->execute([$product_id, $_SESSION['user_id']]);
                $product = $stmt->fetch();
            } catch (PDOException $e) {
                $error = 'Failed to update product. Please try again.';
            }
        }
    }
}

include 'includes/header.php';
?>

<div class="container my-4">
    <div class="row">
        <div class="col-md-8 mx-auto">
            <div class="card">
                <div class="card-header">
                    <h4><i class="fas fa-edit text-warning"></i> Edit Product</h4>
                    <p class="mb-0 text-muted">Update your product information</p>
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
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST" enctype="multipart/form-data" class="needs-validation" novalidate>
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label for="title" class="form-label">Product Title *</label>
                                <input type="text" class="form-control" id="title" name="title" 
                                       value="<?php echo htmlspecialchars($_POST['title'] ?? $product['title']); ?>" 
                                       placeholder="Enter product title" required>
                                <div class="invalid-feedback">Please provide a product title.</div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="price" class="form-label">Price (₹) *</label>
                                <input type="number" class="form-control" id="price" name="price" 
                                       value="<?php echo htmlspecialchars($_POST['price'] ?? $product['price']); ?>" 
                                       placeholder="0.00" step="0.01" min="1" max="1000000" required>
                                <div class="invalid-feedback">Please provide a valid price.</div>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="category_id" class="form-label">Category</label>
                                <select class="form-select" id="category_id" name="category_id">
                                    <option value="0">Select category (optional)</option>
                                    <?php 
                                        $selCatId = (int)($_POST['category_id'] ?? ($product['category_id'] ?? 0));
                                        // Fallback: if no category_id but product has a category name, try to match
                                        if ($selCatId === 0 && !empty($product['category'])) {
                                            foreach ($categories as $c) { if (strcasecmp($c['name'], $product['category']) === 0) { $selCatId = (int)$c['id']; break; } }
                                        }
                                        foreach ($categories as $cat): 
                                    ?>
                                        <option value="<?php echo (int)$cat['id']; ?>" 
                                                <?php echo $selCatId === (int)$cat['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cat['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="description" class="form-label">Description *</label>
                            <textarea class="form-control" id="description" name="description" rows="4" 
                                      placeholder="Describe your product in detail..." required><?php echo htmlspecialchars($_POST['description'] ?? $product['description']); ?></textarea>
                            <div class="invalid-feedback">Please provide a product description.</div>
                            <div class="form-text">Minimum 10 characters required</div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="image" class="form-label">Product Image</label>
                            <?php if ($product['image'] && file_exists('assets/img/' . $product['image'])): ?>
                                <div class="mb-2">
                                    <img src="assets/img/<?php echo htmlspecialchars($product['image']); ?>" 
                                         class="img-thumbnail" style="max-width: 200px; max-height: 200px;">
                                    <div class="form-text">Current image</div>
                                </div>
                            <?php endif; ?>
                            <input type="file" class="form-control" id="image" name="image" 
                                   accept="image/jpeg,image/png,image/gif,image/webp">
                            <div class="form-text">
                                Leave empty to keep current image. Supported formats: JPEG, PNG, GIF, WebP. Maximum size: 5MB.
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <div class="alert alert-<?php echo $product['status'] === 'approved' ? 'success' : ($product['status'] === 'pending' ? 'warning' : 'danger'); ?>">
                                <i class="fas fa-info-circle"></i>
                                <strong>Current Status:</strong> <?php echo ucfirst($product['status']); ?>
                                <?php if ($product['status'] === 'pending'): ?>
                                    - Your product is awaiting admin approval.
                                <?php elseif ($product['status'] === 'approved'): ?>
                                    - Your product is live and visible to buyers.
                                <?php elseif ($product['status'] === 'rejected'): ?>
                                    - Your product was rejected. Editing will reset status to pending for re-review.
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="d-flex justify-content-between">
                            <a href="dashboard.php" class="btn btn-secondary">
                                <i class="fas fa-arrow-left"></i> Back to Dashboard
                            </a>
                            <div>
                                <a href="product_details.php?id=<?php echo $product['id']; ?>" 
                                   class="btn btn-info me-2">
                                    <i class="fas fa-eye"></i> View Product
                                </a>
                                <button type="submit" class="btn btn-warning">
                                    <i class="fas fa-save"></i> Update Product
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
