<?php
require_once 'config.php';
requireRole('seller');

$page_title = 'Add Product';
$error = '';
$success = '';

// Get categories (id + name)
$stmt = $pdo->query("SELECT id, name FROM categories ORDER BY name");
$categories = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = sanitizeInput($_POST['title']);
    $description = sanitizeInput($_POST['description']);
    $price = floatval($_POST['price']);
    $category_id = (int)($_POST['category_id'] ?? 0);
    $category_name = null;
    $condition_grade = sanitizeInput($_POST['condition_grade'] ?? '');
    $condition_notes = trim($_POST['condition_notes'] ?? '');
    
    // Validation
    if (empty($title) || empty($description) || $price <= 0) {
        $error = 'Please fill all required fields with valid data.';
    } elseif (strlen($title) < 3) {
        $error = 'Product title must be at least 3 characters long.';
    } elseif (strlen($description) < 10) {
        $error = 'Product description must be at least 10 characters long.';
    } elseif ($price > 1000000) {
        $error = 'Product price cannot exceed ₹10,00,000.';
    } elseif (!in_array($condition_grade, ['New','Like New','Excellent','Good','Fair','Poor'])) {
        $error = 'Please select a valid product condition.';
    } else {
        // Resolve category name from category_id if provided
        if ($category_id > 0) {
            try {
                $st = $pdo->prepare("SELECT name FROM categories WHERE id=?");
                $st->execute([$category_id]);
                $category_name = $st->fetchColumn() ?: null;
            } catch (Throwable $e) { $category_name = null; }
        }
        $image_name = null;
        $defect_photos = [];
        
        // Require main image upload
        if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            $error = 'Product image is required.';
        } elseif (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
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
                $image_name = 'product_' . uniqid() . '.' . $file_extension;
                $upload_path = $upload_dir . $image_name;
                
                if (!move_uploaded_file($_FILES['image']['tmp_name'], $upload_path)) {
                    $image_name = null;
                    $error = 'Failed to upload image. Please try again.';
                }
            }
        }

        // Handle defect photos multi-upload (optional)
        $defect_photos = [];
        if (isset($_FILES['defect_photos']) && is_array($_FILES['defect_photos']['name'])) {
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $upload_dir_def = 'assets/img/defects/';
            if (!is_dir($upload_dir_def)) { mkdir($upload_dir_def, 0755, true); }
            $count = count($_FILES['defect_photos']['name']);
            for ($i = 0; $i < $count; $i++) {
                if (($_FILES['defect_photos']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { continue; }
                $file_type = $_FILES['defect_photos']['type'][$i] ?? '';
                $file_size = $_FILES['defect_photos']['size'][$i] ?? 0;
                if (!in_array($file_type, $allowed_types) || $file_size > 5 * 1024 * 1024) { continue; }
                $ext = pathinfo($_FILES['defect_photos']['name'][$i], PATHINFO_EXTENSION);
                $fname = 'defect_' . uniqid() . '.' . $ext;
                if (move_uploaded_file($_FILES['defect_photos']['tmp_name'][$i], $upload_dir_def . $fname)) {
                    $defect_photos[] = 'defects/' . $fname;
                }
            }
        }

        // Finalize insert if no errors
        if (empty($error)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO products (seller_id, title, description, price, category, category_id, image, status, condition_grade, condition_notes, defect_photos) VALUES (?, ?, ?, ?, ?, ?, ?, 'approved', ?, ?, ?)");
                $stmt->execute([
                    $_SESSION['user_id'],
                    $title,
                    $description,
                    $price,
                    $category_name,
                    ($category_id ?: null),
                    $image_name,
                    $condition_grade,
                    $condition_notes ?: null,
                    empty($defect_photos) ? null : implode(',', $defect_photos)
                ]);
                $success = 'Product added successfully! Your product is now live.';
                $_POST = [];
            } catch (PDOException $e) {
                $error = 'Failed to add product. Please try again.';
                if (isset($upload_dir) && $image_name && file_exists($upload_dir . $image_name)) {
                    unlink($upload_dir . $image_name);
                }
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
                    <h4><i class="fas fa-plus text-primary"></i> Add New Product</h4>
                    <p class="mb-0 text-muted">List your product for sale on Retrade marketplace</p>
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
                                <a href="dashboard.php" class="btn btn-success btn-sm me-2">View Dashboard</a>
                                <a href="add_product.php" class="btn btn-primary btn-sm">Add Another Product</a>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST" enctype="multipart/form-data" class="needs-validation" novalidate>
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label for="title" class="form-label">Product Title *</label>
                                <input type="text" class="form-control" id="title" name="title" 
                                       value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>" 
                                       placeholder="Enter product title" required>
                                <div class="invalid-feedback">Please provide a product title.</div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="price" class="form-label">Price (₹) *</label>
                                <input type="number" class="form-control" id="price" name="price" 
                                       value="<?php echo htmlspecialchars($_POST['price'] ?? ''); ?>" 
                                       placeholder="0.00" step="0.01" min="1" max="1000000" required>
                                <div class="invalid-feedback">Please provide a valid price.</div>
                                <div class="form-text">Include a fair, all-inclusive price. You can adjust later if needed.</div>
                                <div id="pricePreview" class="small text-muted mt-1"></div>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="category_id" class="form-label">Category</label>
                                <select class="form-select" id="category_id" name="category_id">
                                    <option value="0">Select category (optional)</option>
                                    <?php $selCatId = (int)($_POST['category_id'] ?? 0); foreach ($categories as $cat): ?>
                                        <option value="<?php echo (int)$cat['id']; ?>" 
                                                <?php echo $selCatId === (int)$cat['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cat['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="condition_grade" class="form-label">Product Condition *</label>
                                <select id="condition_grade" name="condition_grade" class="form-select" required>
                                    <?php $grades = ['New','Like New','Excellent','Good','Fair','Poor']; $sel = $_POST['condition_grade'] ?? 'Good'; foreach ($grades as $g): ?>
                                        <option value="<?php echo $g; ?>" <?php echo $sel === $g ? 'selected' : ''; ?>><?php echo $g; ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">New: unopened · Like New: no marks · Excellent: hairline marks · Good: visible wear · Fair: noticeable wear · Poor: heavy wear/defect disclosed.</div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="defect_photos" class="form-label">Defect / Close-up Photos (optional)</label>
                                <input type="file" id="defect_photos" name="defect_photos[]" class="form-control" multiple accept="image/jpeg,image/png,image/gif,image/webp">
                                <div class="form-text">Add close-ups of scratches, dents, or issues. Max 5MB each.</div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="condition_notes" class="form-label">Condition Notes (optional)</label>
                            <textarea id="condition_notes" name="condition_notes" class="form-control" rows="3" placeholder="e.g., Minor scratch on back; battery ~90%; original box included."><?php echo htmlspecialchars($_POST['condition_notes'] ?? ''); ?></textarea>
                        </div>

                        <div class="mb-3">
                            <label for="description" class="form-label">Description *</label>
                            <textarea class="form-control" id="description" name="description" rows="4" 
                                      placeholder="Describe your product in detail..." required><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                            <div class="invalid-feedback">Please provide a product description.</div>
                            <div class="form-text">Minimum 10 characters required</div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="image" class="form-label">Product Image *</label>
                            <input type="file" class="form-control" id="image" name="image" 
                                   accept="image/jpeg,image/png,image/gif,image/webp" required>
                            <div class="invalid-feedback">Please upload a product image.</div>
                            <div class="form-text">
                                Required. Supported formats: JPEG, PNG, GIF, WebP. Maximum size: 5MB.
                            </div>
                        </div>
                        
                        
                        
                        <div class="d-flex justify-content-between">
                            <a href="dashboard.php" class="btn btn-secondary">
                                <i class="fas fa-arrow-left"></i> Back to Dashboard
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-plus"></i> Add Product
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
