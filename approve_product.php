<?php
require_once 'config.php';
requireRole('admin');

$page_title = 'Approve Products';

// Handle approve/reject actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $product_id = intval($_POST['product_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    if ($product_id && in_array($action, ['approve', 'reject'])) {
        try {
            $new_status = $action === 'approve' ? 'approved' : 'rejected';
            $stmt = $pdo->prepare("UPDATE products SET status = ? WHERE id = ?");
            $stmt->execute([$new_status, $product_id]);
            header('Location: approve_product.php?success=1');
            exit();
        } catch (PDOException $e) {
            header('Location: approve_product.php?error=1');
            exit();
        }
    } elseif ($product_id && $action === 'set_category') {
        try {
            $category_id = intval($_POST['category_id'] ?? 0);
            $catName = null;
            if ($category_id > 0) {
                $st = $pdo->prepare("SELECT name FROM categories WHERE id=?");
                $st->execute([$category_id]);
                $catName = $st->fetchColumn() ?: null;
            }
            $u = $pdo->prepare("UPDATE products SET category_id = ?, category = ? WHERE id = ?");
            $u->execute([$category_id ?: null, $catName, $product_id]);
            header('Location: approve_product.php?success=1');
            exit();
        } catch (PDOException $e) {
            header('Location: approve_product.php?error=1');
            exit();
        }
    }
}

// Filtering
$filter = $_GET['filter'] ?? 'pending';
$allowed = ['pending','approved','rejected','all'];
if (!in_array($filter, $allowed)) { $filter = 'pending'; }

$query = "SELECT p.*, u.name AS seller_name, u.email AS seller_email FROM products p JOIN users u ON p.seller_id=u.id";
$params = [];
if ($filter !== 'all') {
    $query .= " WHERE p.status = ?";
    $params[] = $filter;
}
$query .= " ORDER BY p.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$products = $stmt->fetchAll();

$catStmt = $pdo->query("SELECT id, name FROM categories ORDER BY name");
$allCategories = $catStmt->fetchAll();

include 'includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-center mb-4">
        <div class="d-flex align-items-center mb-3 mb-md-0">
            <div class="icon icon-shape icon-lg bg-gradient-primary text-white rounded-circle shadow text-center me-3">
                <i class="fas fa-clipboard-check"></i>
            </div>
            <h2 class="mb-0">Product Approvals</h2>
        </div>
        <div class="d-flex
            <a href="admin_panel.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
            </a>
        </div>
    </div>

    <!-- Filter Card -->
    <div class="card shadow-sm mb-4">
        <div class="card-body p-3">
            <form class="row g-3 align-items-center">
                <input type="hidden" name="page" value="approve_product">
                <div class="col-md-3">
                    <label class="form-label fw-bold text-muted mb-1">Filter by Status</label>
                    <select name="filter" class="form-select" onchange="this.form.submit()">
                        <option value="pending" <?php echo $filter==='pending'?'selected':''; ?>><i class="fas fa-clock me-2"></i>Pending Review</option>
                        <option value="approved" <?php echo $filter==='approved'?'selected':''; ?>><i class="fas fa-check-circle me-2"></i>Approved</option>
                        <option value="rejected" <?php echo $filter==='rejected'?'selected':''; ?>><i class="fas fa-times-circle me-2"></i>Rejected</option>
                        <option value="all" <?php echo $filter==='all'?'selected':''; ?>><i class="fas fa-list me-2"></i>All Products</option>
                    </select>
                </div>
            </form>
        </div>
    </div>

    <!-- Products Grid -->
    <div class="row g-4">
        <?php if (empty($products)): ?>
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-body text-center py-5">
                        <div class="text-muted mb-3">
                            <i class="fas fa-box-open fa-3x opacity-25"></i>
                        </div>
                        <h5 class="text-muted">No products found</h5>
                        <p class="text-muted mb-0">There are no products matching your current filter.</p>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($products as $p): 
                $statusClass = [
                    'pending' => ['bg-warning', 'fa-clock'],
                    'approved' => ['bg-success', 'fa-check-circle'],
                    'rejected' => ['bg-danger', 'fa-times-circle']
                ][$p['status']] ?? ['bg-secondary', 'fa-question-circle'];
            ?>
            <div class="col-12">
                <div class="card shadow-sm h-100">
                    <div class="card-body p-0">
                        <div class="d-flex flex-column flex-md-row">
                            <!-- Product Image -->
                            <div class="col-md-2 p-3 text-center border-end">
                                <?php if ($p['image'] && file_exists('assets/img/' . $p['image'])): ?>
                                    <img src="assets/img/<?php echo htmlspecialchars($p['image']); ?>" 
                                         class="img-fluid rounded" 
                                         style="max-height: 120px; width: auto; object-fit: contain;"
                                         alt="<?php echo htmlspecialchars($p['title']); ?>">
                                <?php else: ?>
                                    <div class="bg-light rounded d-flex align-items-center justify-content-center" 
                                         style="height: 120px;">
                                        <i class="fas fa-image text-muted fa-3x"></i>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Product Details -->
                            <div class="col-md-6 p-3">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <h5 class="card-title mb-1">
                                        <?php echo htmlspecialchars($p['title']); ?>
                                    </h5>
                                    <span class="badge <?php echo $statusClass[0]; ?> text-white">
                                        <i class="fas <?php echo $statusClass[1]; ?> me-1"></i>
                                        <?php echo ucfirst($p['status']); ?>
                                    </span>
                                </div>
                                
                                <div class="mb-2">
                                    <span class="text-muted">
                                        <i class="fas fa-tag me-1"></i>
                                        <?php echo htmlspecialchars($p['category'] ?? 'Uncategorized'); ?>
                                        <button class="btn btn-link p-0 ms-2 text-primary" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#catModal<?php echo $p['id']; ?>"
                                                title="Change Category">
                                            <i class="fas fa-edit fa-sm"></i>
                                        </button>
                                    </span>
                                </div>
                                
                                <p class="text-muted small mb-2">
                                    <i class="fas fa-info-circle me-1"></i>
                                    <?php echo htmlspecialchars(substr($p['description'] ?? 'No description available', 0, 150)) . '...'; ?>
                                </p>
                                
                                <div class="d-flex align-items-center text-muted small">
                                    <div class="me-3">
                                        <i class="far fa-calendar-alt me-1"></i>
                                        <?php echo date('M j, Y', strtotime($p['created_at'])); ?>
                                    </div>
                                    <div>
                                        <i class="fas fa-tag me-1"></i>
                                        <?php echo formatPrice($p['price']); ?>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Seller & Actions -->
                            <div class="col-md-4 p-3 bg-light">
                                <div class="mb-3">
                                    <h6 class="text-muted text-uppercase small mb-2">Seller</h6>
                                    <div class="d-flex align-items-center">
                                        <div class="avatar avatar-sm bg-primary bg-opacity-10 text-primary rounded-circle me-2">
                                            <i class="fas fa-user"></i>
                                        </div>
                                        <div>
                                            <div class="fw-bold"><?php echo htmlspecialchars($p['seller_name']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($p['seller_email']); ?></small>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="d-flex flex-column gap-2 mt-4">
                                    <a href="product_details.php?id=<?php echo $p['id']; ?>" 
                                       target="_blank" 
                                       class="btn btn-sm btn-outline-primary w-100">
                                        <i class="fas fa-eye me-1"></i> View Details
                                    </a>
                                    
                                    <div class="btn-group w-100">
                                        <form method="POST" class="w-50" onsubmit="return confirm('Approve this product?');">
                                            <input type="hidden" name="product_id" value="<?php echo $p['id']; ?>">
                                            <input type="hidden" name="action" value="approve">
                                            <button type="submit" 
                                                    class="btn btn-sm btn-success w-100 rounded-end-0" 
                                                    <?php echo $p['status']==='approved'?'disabled':''; ?>>
                                                <i class="fas fa-check me-1"></i> Approve
                                            </button>
                                        </form>
                                        
                                        <form method="POST" class="w-50" onsubmit="return confirm('Reject this product?');">
                                            <input type="hidden" name="product_id" value="<?php echo $p['id']; ?>">
                                            <input type="hidden" name="action" value="reject">
                                            <button type="submit" 
                                                    class="btn btn-sm btn-outline-danger w-100 rounded-start-0" 
                                                    <?php echo $p['status']==='rejected'?'disabled':''; ?>>
                                                <i class="fas fa-times me-1"></i> Reject
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
                                <div class="modal fade" id="catModal<?php echo $p['id']; ?>" tabindex="-1" aria-hidden="true">
                                  <div class="modal-dialog">
                                    <div class="modal-content">
                                      <div class="modal-header">
                                        <h5 class="modal-title"><i class="fas fa-tags"></i> Update Category</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                      </div>
                                      <form method="POST">
                                        <div class="modal-body">
                                          <input type="hidden" name="action" value="set_category">
                                          <input type="hidden" name="product_id" value="<?php echo $p['id']; ?>">
                                          <label class="form-label">Category</label>
                                          <select class="form-select" name="category_id">
                                            <option value="0">Select category</option>
                                            <?php 
                                              $selId = (int)($p['category_id'] ?? 0);
                                              if ($selId === 0 && !empty($p['category'])) {
                                                foreach ($allCategories as $c) { if (strcasecmp($c['name'], $p['category']) === 0) { $selId = (int)$c['id']; break; } }
                                              }
                                              foreach ($allCategories as $c): ?>
                                              <option value="<?php echo (int)$c['id']; ?>" <?php echo $selId === (int)$c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
                                            <?php endforeach; ?>
                                          </select>
                                        </div>
                                        <div class="modal-footer">
                                          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                          <button type="submit" class="btn btn-primary">Save</button>
                                        </div>
                                      </form>
                                    </div>
                                  </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
