<?php
require_once 'config.php';
require_once 'payment_config.php';

$page_title = 'Product Details';
$product_id = intval($_GET['id'] ?? 0);

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
function check_csrf() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $t = $_POST['csrf_token'] ?? '';
        if (!$t || !hash_equals($_SESSION['csrf_token'] ?? '', $t)) {
            http_response_code(400);
            exit('Invalid CSRF token');
        }
    }
}

if (!$product_id) {
    header('Location: index.php');
    exit();
}

// Get product details with seller info
$stmt = $pdo->prepare("
    SELECT p.*, u.name as seller_name, u.email as seller_email, u.phone as seller_phone 
    FROM products p 
    JOIN users u ON p.seller_id = u.id 
    WHERE p.id = ?
");
$stmt->execute([$product_id]);
$product = $stmt->fetch();

if (!$product) {
    header('Location: index.php');
    exit();
}

// Check if product is approved or if user is the seller/admin
$can_view = $product['status'] === 'approved' || 
            (isLoggedIn() && $_SESSION['user_id'] == $product['seller_id']) ||
            (isLoggedIn() && getUserRole() === 'admin');

if (!$can_view) {
    header('Location: index.php');
    exit();
}

// Check if current user can buy this product
$can_buy = isLoggedIn() && 
           getUserRole() === 'buyer' && 
           $_SESSION['user_id'] != $product['seller_id'] &&
           $product['status'] === 'approved';

// Trusted seller computation
$seller_trust = getSellerTrust($pdo, (int)$product['seller_id']);


// Check if buyer has already purchased this product
$already_purchased = false;
if ($can_buy) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE buyer_id = ? AND product_id = ?");
    $stmt->execute([$_SESSION['user_id'], $product_id]);
    $already_purchased = $stmt->fetchColumn() > 0;
}

// Reviews: compute averages and list
$avg_rating = 0.0; $reviews_count = 0; $user_has_reviewed = false; $can_review = false; $review_error = '';
try {
    $stmt = $pdo->prepare("SELECT ROUND(AVG(rating),1) avg_rating, COUNT(*) cnt FROM reviews WHERE product_id = ? AND status='approved'");
    $stmt->execute([$product_id]);
    $row = $stmt->fetch();
    if ($row) { $avg_rating = (float)($row['avg_rating'] ?: 0); $reviews_count = (int)$row['cnt']; }
} catch (Throwable $e) { /* ignore */ }

// Determine if current user can write a review (once) 
if (isLoggedIn() && getUserRole()==='buyer') {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM reviews WHERE product_id=? AND user_id=?");
        $stmt->execute([$product_id, (int)($_SESSION['user_id'] ?? 0)]);
        $user_has_reviewed = ((int)$stmt->fetchColumn()) > 0;
    } catch (Throwable $e) { $user_has_reviewed = false; }
    $can_review = $already_purchased && !$user_has_reviewed;
}

// Handle review create
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['review_submit']) && $can_review) {
    check_csrf();
    $rating = max(1, min(5, (int)($_POST['rating'] ?? 0)));
    $text = trim($_POST['review_text'] ?? '');
    // Handle up to 3 images
    $savedImages = [];
    if (!empty($_FILES['review_images']) && is_array($_FILES['review_images']['name'])) {
        $names = $_FILES['review_images']['name'];
        $tmps  = $_FILES['review_images']['tmp_name'];
        $sizes = $_FILES['review_images']['size'];
        $errs  = $_FILES['review_images']['error'];
        $count = min(3, count($names));
        $dir = __DIR__ . '/assets/img/reviews';
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        for ($i=0; $i<$count; $i++) {
            if (($errs[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
            $size = (int)($sizes[$i] ?? 0);
            if ($size <= 0 || $size > 2*1024*1024) continue; // 2MB limit
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            $mime = $finfo ? @finfo_file($finfo, $tmps[$i]) : '';
            if ($finfo) @finfo_close($finfo);
            $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
            if (!isset($allowed[$mime])) continue;
            $ext = $allowed[$mime];
            $basename = 'rev_' . $product_id . '_' . (int)$_SESSION['user_id'] . '_' . substr(bin2hex(random_bytes(6)),0,12) . '.' . $ext;
            $dest = $dir . '/' . $basename;
            if (@move_uploaded_file($tmps[$i], $dest)) {
                $savedImages[] = $basename;
            }
        }
    }
    if ($rating < 1 || $rating > 5) {
        $review_error = 'Please select a rating between 1 and 5.';
    } else {
        try {
            $imagesCsv = empty($savedImages) ? null : implode(',', $savedImages);
            $stmt = $pdo->prepare("INSERT INTO reviews (product_id, user_id, rating, review_text, images, status) VALUES (?,?,?,?, ?, 'approved')");
            $stmt->execute([$product_id, (int)$_SESSION['user_id'], $rating, $text, $imagesCsv]);
            // Refresh to avoid form resubmission, and scroll to reviews
            header('Location: product_details.php?id=' . $product_id . '#reviews');
            exit();
        } catch (Throwable $e) {
            $review_error = 'Unable to submit review. You may have already reviewed this product.';
        }
    }
}

// Handle review edit/delete within 24h of creation
$user_review = null; $can_edit_delete = false;
if (isLoggedIn() && getUserRole()==='buyer') {
    try {
        $sr = $pdo->prepare("SELECT * FROM reviews WHERE product_id=? AND user_id=? ORDER BY created_at DESC LIMIT 1");
        $sr->execute([$product_id, (int)($_SESSION['user_id'] ?? 0)]);
        $user_review = $sr->fetch();
        if ($user_review) {
            $can_edit_delete = (time() - strtotime($user_review['created_at'])) <= 24*3600;
        }
    } catch (Throwable $e) { /* ignore */ }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['review_update']) && $user_review && $can_edit_delete) {
    check_csrf();
    $rating = max(1, min(5, (int)($_POST['rating'] ?? 0)));
    $text = trim($_POST['review_text'] ?? '');
    try {
        $up = $pdo->prepare("UPDATE reviews SET rating=?, review_text=?, status='approved', updated_at=NOW() WHERE id=? AND user_id=?");
        $up->execute([$rating, $text, (int)$user_review['id'], (int)$_SESSION['user_id']]);
        header('Location: product_details.php?id=' . $product_id . '#reviews');
        exit();
    } catch (Throwable $e) { $review_error = 'Failed to update review.'; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['review_delete']) && $user_review && $can_edit_delete) {
    check_csrf();
    try {
        $dl = $pdo->prepare("DELETE FROM reviews WHERE id=? AND user_id=?");
        $dl->execute([(int)$user_review['id'], (int)$_SESSION['user_id']]);
        header('Location: product_details.php?id=' . $product_id . '&msg=review_deleted#reviews');
        exit();
    } catch (Throwable $e) { $review_error = 'Failed to delete review.'; }
}

// Helpful vote
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['review_vote']) && isLoggedIn()) {
    check_csrf();
    $rid = (int)($_POST['review_id'] ?? 0);
    if ($rid>0) {
        try {
            $ins = $pdo->prepare("INSERT INTO review_votes (review_id, user_id, helpful) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE helpful=VALUES(helpful)");
            $ins->execute([$rid, (int)$_SESSION['user_id']]);
            header('Location: product_details.php?id=' . $product_id . '#reviews');
            exit();
        } catch (Throwable $e) { /* ignore */ }
    }
}

// Report abuse
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['review_report']) && isLoggedIn()) {
    check_csrf();
    $rid = (int)($_POST['review_id'] ?? 0);
    $reason = substr(trim($_POST['reason'] ?? ''), 0, 255);
    if ($rid>0) {
        try {
            $ins = $pdo->prepare("INSERT INTO review_reports (review_id, reporter_id, reason) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE reason=VALUES(reason)");
            $ins->execute([$rid, (int)$_SESSION['user_id'], $reason]);
            header('Location: product_details.php?id=' . $product_id . '&msg=report_received#reviews');
            exit();
        } catch (Throwable $e) { /* ignore */ }
    }
}

// Complaint submission (buyer -> seller)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complaint_submit']) && isLoggedIn() && getUserRole()==='buyer') {
    check_csrf();
    $buyer_id = (int)($_SESSION['user_id'] ?? 0);
    $seller_id = (int)$product['seller_id'];
    if ($buyer_id && $seller_id && $buyer_id !== $seller_id) {
        $subject = trim(substr($_POST['complaint_subject'] ?? '', 0, 150));
        $description = trim($_POST['complaint_description'] ?? '');
        $priority = $_POST['complaint_priority'] ?? 'medium';
        if (!in_array($priority, ['low','medium','high'], true)) { $priority = 'medium'; }

        $attachRelPath = null;
        // Optional single attachment (image/pdf up to 5MB)
        if (!empty($_FILES['complaint_attachment']) && ($_FILES['complaint_attachment']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $size = (int)($_FILES['complaint_attachment']['size'] ?? 0);
            $tmp = $_FILES['complaint_attachment']['tmp_name'] ?? '';
            if ($size > 0 && $size <= 5*1024*1024 && $tmp) {
                $finfo = @finfo_open(FILEINFO_MIME_TYPE);
                $mime = $finfo ? @finfo_file($finfo, $tmp) : '';
                if ($finfo) @finfo_close($finfo);
                $allowed = [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/webp' => 'webp',
                    'application/pdf' => 'pdf'
                ];
                if (isset($allowed[$mime])) {
                    $ext = $allowed[$mime];
                    $dir = __DIR__ . '/assets/complaints';
                    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
                    $fname = 'cmp_' . $product_id . '_' . $buyer_id . '_' . substr(bin2hex(random_bytes(6)),0,12) . '.' . $ext;
                    $dest = $dir . '/' . $fname;
                    if (@move_uploaded_file($tmp, $dest)) {
                        $attachRelPath = 'assets/complaints/' . $fname;
                    }
                }
            }
        }

        if ($subject !== '' && $description !== '') {
            try {
                $stmt = $pdo->prepare("INSERT INTO complaints (buyer_id, seller_id, product_id, transaction_id, subject, description, attachment_path, status, priority) VALUES (?,?,?,?,?,?,?, 'open', ?)");
                $stmt->execute([$buyer_id, $seller_id, (int)$product_id, null, $subject, $description, $attachRelPath, $priority]);
                header('Location: product_details.php?id=' . $product_id . '&msg=complaint_submitted#complaint');
                exit();
            } catch (Throwable $e) {
                // Swallow error but continue render with message
                $_GET['error'] = 'Unable to submit complaint right now.';
            }
        } else {
            $_GET['error'] = 'Please provide a subject and description for the complaint.';
        }
    }
}

include 'includes/header.php';
?>

<div class="container my-4">
    <div class="row">
        <div class="col-md-6">
            <div class="card">
                <div class="card-body p-0">
                    <?php if ($product['image'] && file_exists('assets/img/' . $product['image'])): ?>
                        <img src="assets/img/<?php echo htmlspecialchars($product['image']); ?>" 
                             class="card-img-top" style="height: 400px; object-fit: cover;" 
                             alt="<?php echo htmlspecialchars($product['title']); ?>" loading="lazy">
                    <?php else: ?>
                        <div class="d-flex align-items-center justify-content-center bg-light" 
                             style="height: 400px;">
                            <i class="fas fa-image fa-5x text-muted"></i>
                        </div>
                    <?php endif; ?>

<!-- Chat Modal -->
<?php if ($can_buy && !$already_purchased): ?>
<div class="modal fade chat-modal" id="chatModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-scrollable modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <div class="chat-header-title">
          <span class="presence-dot" id="chatPresence"></span>
          <strong>Negotiation Chat</strong>
          <small class="text-muted ms-2" id="chatPresenceText">Offline</small>
          <small class="text-muted ms-2"> <span id="chatProductTitle">for: <?php echo htmlspecialchars($product['title']); ?></span></small>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="chatMessages" class="chat-messages"></div>
        <div class="typing-indicator" id="typingIndicator" style="display:none;">Seller is typing…</div>
      </div>
      <div class="modal-footer">
        <div class="input-group">
          <button class="btn btn-outline-secondary" id="attachBtn" type="button" title="Attach file"><i class="fas fa-paperclip"></i></button>
          <input type="file" id="chatAttachment" class="d-none" accept="image/*,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document">
          <textarea class="form-control chat-input" id="chatInput" rows="1" placeholder="Type your message… (Enter to send, Shift+Enter for newline)"></textarea>
          <button class="btn btn-send" id="sendMsgBtn"><i class="fas fa-paper-plane"></i></button>
        </div>
      </div>
    </div>
  </div>
  
</div>
<?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div>
                            <h2 class="me-2 mb-1"><?php echo htmlspecialchars($product['title']); ?></h2>
                            <div class="d-flex align-items-center gap-2 mb-1" aria-label="Average rating" title="Average rating">
                              <?php 
                                $full = (int)floor($avg_rating); $half = ($avg_rating - $full) >= 0.5 ? 1 : 0; $empty = 5 - $full - $half;
                                for ($i=0;$i<$full;$i++) echo '<i class="fas fa-star text-warning"></i>';
                                if ($half) echo '<i class="fas fa-star-half-alt text-warning"></i>';
                                for ($i=0;$i<$empty;$i++) echo '<i class="far fa-star text-warning"></i>';
                              ?>
                              <small class="text-muted">(<?php echo number_format($avg_rating,1); ?> · <?php echo (int)$reviews_count; ?>)</small>
                            </div>
                            <div class="small text-muted"><i class="fas fa-calendar"></i> Listed on <?php echo date('F j, Y', strtotime($product['created_at'])); ?></div>
                        </div>
                        <div class="text-end">
                            <button id="copyProductLink" class="btn btn-ghost btn-sm"><i class="fas fa-link"></i> Copy Link</button>
                            <div>
                                <span class="badge bg-<?php echo $product['status'] === 'pending' ? 'warning' : ($product['status'] === 'approved' ? 'success' : 'danger'); ?> fs-6">
                                    <?php echo ucfirst($product['status']); ?>
                                </span>
                            </div>
                        </div>
                    </div>

    <?php if (isLoggedIn() && getUserRole()==='buyer' && (int)($_SESSION['user_id'] ?? 0) !== (int)$product['seller_id']): ?>
    <!-- Complaint Modal -->
    <div class="modal fade" id="complaintModal" tabindex="-1" aria-labelledby="complaintModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="complaintModalLabel"><i class="fas fa-flag text-danger"></i> Report a Problem</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <form method="POST" enctype="multipart/form-data">
            <div class="modal-body">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
              <div class="mb-3">
                <label class="form-label">Subject</label>
                <input type="text" class="form-control" name="complaint_subject" maxlength="150" placeholder="Short summary (e.g., Seller not responding)" required aria-describedby="cmpSubjectHelp">
                <div id="cmpSubjectHelp" class="form-text">Be concise and specific. You can add details below.</div>
              </div>
              <div class="mb-3">
                <label class="form-label">Describe the issue</label>
                <textarea class="form-control" name="complaint_description" rows="4" placeholder="Provide details, timelines, order IDs, and any relevant info." required aria-describedby="cmpDescHelp"></textarea>
                <div id="cmpDescHelp" class="form-text">Keep it factual and polite. This helps us resolve faster.</div>
              </div>
              <div class="row g-2">
                <div class="col-md-6">
                  <label class="form-label">Priority</label>
                  <select class="form-select" name="complaint_priority">
                    <option value="low">Low</option>
                    <option value="medium" selected>Medium</option>
                    <option value="high">High</option>
                  </select>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Attachment (optional)</label>
                  <input type="file" class="form-control" name="complaint_attachment" accept="image/jpeg,image/png,image/webp,application/pdf">
                  <div class="form-text">JPG/PNG/WebP/PDF up to 5MB. Attach screenshots, receipts, or evidence.</div>
                </div>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" aria-label="Cancel complaint">Cancel</button>
              <button type="submit" name="complaint_submit" class="btn btn-danger"><i class="fas fa-paper-plane"></i> Submit Complaint</button>
            </div>
          </form>
        </div>
      </div>
    </div>
    <?php endif; ?>

                    <div class="card mb-3">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="text-muted">Price</div>
                                    <div class="h3 mb-0 price-value text-nowrap"><?php echo formatPrice($product['price']); ?></div>
                                </div>
                                <?php if ($product['category']): ?>
                                <span class="badge bg-primary"><i class="fas fa-tag"></i> <?php echo htmlspecialchars($product['category']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="mb-4">
                        <h5>Description</h5>
                        <p class="text-muted mb-0"><?php echo nl2br(htmlspecialchars($product['description'])); ?></p>
                    </div>

                    <?php 
                      $defectPhotos = [];
                      if (!empty($product['defect_photos'])) {
                        $defectPhotos = array_values(array_filter(array_map('trim', explode(',', $product['defect_photos']))));
                      }
                    ?>

                    <div class="mb-4">
                        <h5>Condition</h5>
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <div>
                                        <span class="badge bg-secondary me-2">Grade</span>
                                        <strong><?php echo htmlspecialchars($product['condition_grade'] ?? 'Good'); ?></strong>
                                    </div>
                                </div>
                                <?php if (!empty($product['condition_notes'])): ?>
                                    <p class="mb-2"><?php echo nl2br(htmlspecialchars($product['condition_notes'])); ?></p>
                                <?php else: ?>
                                    <p class="text-muted mb-2">No additional notes provided.</p>
                                <?php endif; ?>

                                <?php if (!empty($defectPhotos)): ?>
                                    <div class="mt-3">
                                        <div class="d-flex flex-wrap gap-2">
                                            <?php foreach ($defectPhotos as $dp): 
                                                $src = 'assets/img/' . $dp;
                                                if (!file_exists($src)) continue; ?>
                                                <a href="<?php echo htmlspecialchars($src); ?>" target="_blank" rel="noopener">
                                                    <img src="<?php echo htmlspecialchars($src); ?>" alt="Defect photo" style="width:90px;height:90px;object-fit:cover;border-radius:8px;border:1px solid var(--og-border)">
                                                </a>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="mb-4">
                        <h5>Seller</h5>
                        <div class="card">
                            <div class="card-body py-2">
                                <div class="d-flex align-items-center">
                                    <i class="fas fa-user-circle fa-2x text-primary me-3"></i>
                                    <div>
                                        <div class="fw-bold d-flex align-items-center gap-2">
                                            <span><?php echo htmlspecialchars($product['seller_name']); ?></span>
                                            <?php if (!empty($seller_trust['trusted'])): ?>
                                              <span class="badge bg-success" title="Trusted Seller · Score: <?php echo (float)($seller_trust['score'] ?? 0); ?>">
                                                <i class="fas fa-shield-alt"></i> Trusted
                                              </span>
                                            <?php endif; ?>
                                        </div>
                                        <small class="text-muted d-block"><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($product['seller_email']); ?></small>
                                        <?php if ($product['seller_phone']): ?>
                                            <small class="text-muted d-block"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($product['seller_phone']); ?></small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-body">
                            <h5 class="mb-3"><i class="fas fa-bolt me-2"></i>Actions</h5>
                            <div class="d-grid gap-2">
                                <?php if ($can_buy && !$already_purchased): ?>
                                    <button id="openChatBtn" class="btn btn-primary"
                                            data-product-id="<?php echo (int)$product['id']; ?>"
                                            data-seller-id="<?php echo (int)$product['seller_id']; ?>"
                                            data-buyer-id="<?php echo (int)($_SESSION['user_id'] ?? 0); ?>"
                                            data-user-name="<?php echo htmlspecialchars($_SESSION['user_name'] ?? ''); ?>">
                                        <i class="fas fa-comments"></i> Negotiate with Seller
                                    </button>
                                    <?php if (defined('RAZORPAY_ENABLED') && RAZORPAY_ENABLED): ?>
                                        <button id="rzpPayBtn" class="btn btn-success">
                                            <i class="fas fa-credit-card"></i> Pay with Razorpay - <?php echo formatPrice($product['price']); ?>
                                        </button>
                                        <small class="text-muted text-center"><i class="fas fa-shield-alt"></i> Secure payment via Razorpay. Funds held until delivery.</small>
                                    <?php else: ?>
                                        <a href="buy_product.php?id=<?php echo $product['id']; ?>" class="btn btn-success">
                                            <i class="fas fa-shopping-cart"></i> Proceed to Buy - <?php echo formatPrice($product['price']); ?>
                                        </a>
                                        <small class="text-muted text-center"><i class="fas fa-shield-alt"></i> Escrow protection</small>
                                    <?php endif; ?>
                                <?php elseif ($already_purchased): ?>
                                    <button class="btn btn-info" disabled><i class="fas fa-check-circle"></i> Already Purchased</button>
                                    <small class="text-muted text-center">You have already purchased this product</small>
                                <?php elseif (isLoggedIn() && $_SESSION['user_id'] == $product['seller_id']): ?>
                                    <div class="d-grid gap-2">
                                        <a href="edit_product.php?id=<?php echo $product['id']; ?>" class="btn btn-secondary"><i class="fas fa-edit"></i> Edit Product</a>
                                        <a href="delete_product.php?id=<?php echo $product['id']; ?>" class="btn btn-outline-danger"><i class="fas fa-trash"></i> Delete Product</a>
                                    </div>
                                <?php elseif (!isLoggedIn()): ?>
                                    <a href="login.php" class="btn btn-primary"><i class="fas fa-sign-in-alt"></i> Login to Buy</a>
                                <?php elseif (getUserRole() === 'seller'): ?>
                                    <button class="btn btn-secondary" disabled><i class="fas fa-info-circle"></i> Sellers Cannot Buy</button>
                                    <small class="text-muted text-center">Switch to a buyer account to purchase products</small>
                                <?php elseif ($product['status'] !== 'approved'): ?>
                                    <button class="btn btn-warning" disabled><i class="fas fa-clock"></i> Product Not Available</button>
                                    <small class="text-muted text-center">This product is not currently available for purchase</small>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <?php if (isLoggedIn() && getUserRole()==='buyer' && (int)($_SESSION['user_id'] ?? 0) !== (int)$product['seller_id']): ?>
                    <div class="card mt-3">
                        <div class="card-body d-flex justify-content-between align-items-center">
                            <div>
                                <div class="fw-semibold">Having an issue with this seller?</div>
                                <div class="text-muted small">Tell us what went wrong. We'll notify the admin.</div>
                            </div>
                            <button class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#complaintModal"><i class="fas fa-flag"></i> Report a Problem</button>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <!-- Sticky bottom action bar (mobile-first) -->
                <div class="sticky-buybar d-md-none mt-3">
                  <div class="d-flex align-items-center justify-content-between p-3">
                    <div>
                      <div class="small text-muted">Price</div>
                      <div class="price"><?php echo formatPrice($product['price']); ?></div>
                    </div>
                    <div class="d-flex gap-2">
                      <?php if ($can_buy && !$already_purchased): ?>
                        <button id="openChatBtnSticky" class="btn btn-primary openChatBtn"
                                data-product-id="<?php echo (int)$product['id']; ?>"
                                data-seller-id="<?php echo (int)$product['seller_id']; ?>"
                                data-buyer-id="<?php echo (int)($_SESSION['user_id'] ?? 0); ?>">
                          <i class="fas fa-comments"></i> Chat
                        </button>
                      <?php else: ?>
                        <a href="#" class="btn btn-secondary disabled">Unavailable</a>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row mt-4" id="complaint">
        <div class="col-12">
            <div class="text-center">
                <a href="index.php" class="btn btn-outline-primary">
                    <i class="fas fa-arrow-left"></i> Back to Products
                </a>
                <?php if (isLoggedIn() && getUserRole() === 'buyer'): ?>
                    <a href="dashboard.php" class="btn btn-outline-secondary ms-2">
                        <i class="fas fa-tachometer-alt"></i> My Dashboard
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if (isset($_GET['msg']) && $_GET['msg']==='complaint_submitted'): ?>
        <div class="row mt-3"><div class="col-12"><div class="alert alert-success"><i class="fas fa-check-circle"></i> Your complaint has been submitted. Our admin team will review it shortly.</div></div></div>
    <?php endif; ?>
    <?php if (isset($_GET['error'])): ?>
        <div class="row mt-3"><div class="col-12"><div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($_GET['error']); ?></div></div></div>
    <?php endif; ?>
    
    <!-- Reviews -->
    <div class="row mt-4" id="reviews">
      <div class="col-12">
        <div class="card">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <h5 class="mb-0"><i class="fas fa-star text-warning me-2"></i>Ratings & Reviews</h5>
              <div class="text-muted small"><?php echo number_format($avg_rating,1); ?> average · <?php echo (int)$reviews_count; ?> reviews</div>
            </div>
            <?php if ($can_review): ?>
              <form method="POST" class="mb-4" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <?php if (!empty($review_error)): ?><div class="alert alert-danger"><?php echo htmlspecialchars($review_error); ?></div><?php endif; ?>
                <div class="row g-2 align-items-center">
                  <div class="col-md-3">
                    <label for="rating" class="form-label">Your Rating</label>
                    <select id="rating" name="rating" class="form-select" required>
                      <option value="">Select…</option>
                      <?php for($r=5;$r>=1;$r--): ?>
                        <option value="<?php echo $r; ?>"><?php echo $r; ?> star<?php echo $r>1?'s':''; ?></option>
                      <?php endfor; ?>
                    </select>
                  </div>
                  <div class="col-md-9">
                    <label for="review_text" class="form-label">Your Review <span class="text-muted small">(optional)</span></label>
                    <textarea id="review_text" name="review_text" class="form-control" rows="3" maxlength="2000" placeholder="Share details about quality, condition, delivery, etc."></textarea>
                    <div class="mt-2">
                      <label class="form-label" for="review_images">Add photos (up to 3, JPG/PNG/WebP, max 2MB each)</label>
                      <input type="file" id="review_images" name="review_images[]" class="form-control" accept="image/jpeg,image/png,image/webp" multiple>
                    </div>
                  </div>
                </div>
                <div class="mt-3">
                  <button type="submit" name="review_submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Submit Review</button>
                </div>
              </form>
            <?php elseif ($user_review): ?>
              <div class="mb-3">
                <div class="alert alert-info mb-2">
                  <?php if ($can_edit_delete): ?>
                    You can edit or delete your review within 24 hours. Changes will go to moderation.
                  <?php else: ?>
                    Your review was submitted. Edits are no longer allowed.
                  <?php endif; ?>
                </div>
                <?php if ($can_edit_delete): ?>
                <form method="POST" class="mb-3">
                  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                  <div class="row g-2 align-items-center">
                    <div class="col-md-3">
                      <label for="rating_edit" class="form-label">Update Rating</label>
                      <select id="rating_edit" name="rating" class="form-select" required>
                        <?php for($r=5;$r>=1;$r--): ?>
                          <option value="<?php echo $r; ?>" <?php echo (int)$user_review['rating']===$r?'selected':''; ?>><?php echo $r; ?> star<?php echo $r>1?'s':''; ?></option>
                        <?php endfor; ?>
                      </select>
                    </div>
                    <div class="col-md-9">
                      <label for="review_text_edit" class="form-label">Update Review</label>
                      <textarea id="review_text_edit" name="review_text" class="form-control" rows="3" maxlength="2000"><?php echo htmlspecialchars($user_review['review_text'] ?? ''); ?></textarea>
                    </div>
                  </div>
                  <div class="mt-2 d-flex gap-2">
                    <button type="submit" name="review_update" class="btn btn-warning"><i class="fas fa-edit"></i> Update</button>
                    <button type="submit" name="review_delete" class="btn btn-outline-danger" onclick="return confirm('Delete your review?');"><i class="fas fa-trash"></i> Delete</button>
                  </div>
                </form>
                <?php endif; ?>
              </div>
            <?php elseif (isLoggedIn() && getUserRole()==='buyer' && $user_has_reviewed): ?>
              <div class="alert alert-info">You already reviewed this product. Thank you!</div>
            <?php elseif (isLoggedIn() && getUserRole()==='buyer' && !$already_purchased): ?>
              <div class="alert alert-warning">Buy the product to leave a review.</div>
            <?php endif; ?>

            <?php
              // Pagination for reviews
              $rpage = max(1, (int)($_GET['rpage'] ?? 1));
              $rpp = 5; $rOffset = ($rpage-1)*$rpp;
              $totalReviews = (int)$reviews_count;
              $rTotalPages = max(1, (int)ceil($totalReviews / $rpp));
              $rev = [];
              try {
                $rs = $pdo->prepare("SELECT r.*, u.name,
                    (SELECT COUNT(*) FROM review_votes v WHERE v.review_id=r.id AND v.helpful=1) AS helpful_count,
                    (SELECT COUNT(*) FROM review_reports rp WHERE rp.review_id=r.id AND rp.status='pending') AS report_count
                  FROM reviews r 
                  JOIN users u ON r.user_id=u.id 
                  WHERE r.product_id=? AND r.status='approved' 
                  ORDER BY r.created_at DESC LIMIT ? OFFSET ?");
                $rs->bindValue(1, $product_id, PDO::PARAM_INT);
                $rs->bindValue(2, $rpp, PDO::PARAM_INT);
                $rs->bindValue(3, $rOffset, PDO::PARAM_INT);
                $rs->execute();
                $rev = $rs->fetchAll();
              } catch (Throwable $e) { $rev = []; }
            ?>
            <?php if ($user_review && strtolower($user_review['status']) !== 'approved'): ?>
              <div class="list-group mb-3">
                <div class="list-group-item border-0 mb-2 rounded bg-dark-subtle">
                  <div class="d-flex align-items-center justify-content-between">
                    <div class="fw-semibold">Your review <span class="badge bg-warning text-dark">Pending moderation</span></div>
                    <div class="text-nowrap">
                      <?php $fr=(int)$user_review['rating']; for($i=0;$i<5;$i++){ echo $i<$fr?'<i class=\"fas fa-star text-warning\"></i>':'<i class=\"far fa-star text-warning\"></i>'; } ?>
                    </div>
                  </div>
                  <?php if (!empty($user_review['review_text'])): ?>
                    <div class="mt-1 text-muted"><?php echo nl2br(htmlspecialchars($user_review['review_text'])); ?></div>
                  <?php endif; ?>
                  <?php 
                    $uimgs = [];
                    if (!empty($user_review['images'])) { $uimgs = array_values(array_filter(array_map('trim', explode(',', $user_review['images'])))); }
                  ?>
                  <?php if (!empty($uimgs)): ?>
                    <div class="mt-2 d-flex flex-wrap gap-2">
                      <?php foreach ($uimgs as $im): $src = 'assets/img/reviews/' . $im; if (!file_exists(__DIR__ . '/' . $src)) continue; ?>
                        <a href="<?php echo htmlspecialchars($src); ?>" target="_blank" rel="noopener">
                          <img src="<?php echo htmlspecialchars($src); ?>" alt="review image" style="width:80px;height:80px;object-fit:cover;border-radius:6px;border:1px solid var(--og-border)">
                        </a>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                  <div class="small text-muted mt-1"><?php echo date('M j, Y g:i a', strtotime($user_review['created_at'])); ?></div>
                </div>
              </div>
            <?php endif; ?>

            <?php if (empty($rev)): ?>
              <div class="text-muted">No reviews yet.</div>
            <?php else: ?>
              <div class="list-group">
                <?php foreach ($rev as $r): ?>
                  <div class="list-group-item bg-dark-subtle border-0 mb-2 rounded">
                    <div class="d-flex align-items-center justify-content-between">
                      <div class="fw-semibold"><?php echo htmlspecialchars($r['name']); ?></div>
                      <div class="text-nowrap">
                        <?php $fr=(int)$r['rating']; for($i=0;$i<5;$i++){ echo $i<$fr?'<i class=\"fas fa-star text-warning\"></i>':'<i class=\"far fa-star text-warning\"></i>'; } ?>
                      </div>
                    </div>
                    <?php if (!empty($r['review_text'])): ?>
                      <div class="mt-1 text-muted"><?php echo nl2br(htmlspecialchars($r['review_text'])); ?></div>
                    <?php endif; ?>
                    <?php 
                      $imgs = [];
                      if (!empty($r['images'])) {
                        $imgs = array_values(array_filter(array_map('trim', explode(',', $r['images']))));
                      }
                    ?>
                    <?php if (!empty($imgs)): ?>
                      <div class="mt-2 d-flex flex-wrap gap-2">
                        <?php foreach ($imgs as $im): $src = 'assets/img/reviews/' . $im; if (!file_exists(__DIR__ . '/' . $src)) continue; ?>
                          <a href="<?php echo htmlspecialchars($src); ?>" target="_blank" rel="noopener">
                            <img src="<?php echo htmlspecialchars($src); ?>" alt="review image" style="width:80px;height:80px;object-fit:cover;border-radius:6px;border:1px solid var(--og-border)">
                          </a>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>
                    <div class="small text-muted mt-1 d-flex justify-content-between align-items-center">
                      <span><?php echo date('M j, Y g:i a', strtotime($r['created_at'])); ?></span>
                      <span class="d-inline-flex align-items-center gap-2">
                        <form method="POST" class="d-inline">
                          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                          <input type="hidden" name="review_id" value="<?php echo (int)$r['id']; ?>">
                          <button class="btn btn-sm btn-outline-success" name="review_vote" <?php echo (!isLoggedIn() || (isset($_SESSION['user_id']) && (int)$_SESSION['user_id']===(int)$r['user_id'])) ? 'disabled' : '';?> title="Mark helpful"><i class="fas fa-thumbs-up"></i> Helpful (<?php echo (int)($r['helpful_count'] ?? 0); ?>)</button>
                        </form>
                        <button class="btn btn-sm btn-outline-danger" data-bs-toggle="collapse" data-bs-target="#rep<?php echo (int)$r['id']; ?>" aria-expanded="false" aria-controls="rep<?php echo (int)$r['id']; ?>" <?php echo !isLoggedIn() ? 'disabled' : '';?>><i class="fas fa-flag"></i> Report</button>
                      </span>
                    </div>
                    <div class="collapse mt-2" id="rep<?php echo (int)$r['id']; ?>">
                      <form method="POST" class="border rounded p-2">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="review_id" value="<?php echo (int)$r['id']; ?>">
                        <div class="input-group">
                          <input type="text" name="reason" maxlength="255" class="form-control" placeholder="Reason (spam, abuse, off-topic, etc.)">
                          <button class="btn btn-danger" name="review_report"><i class="fas fa-paper-plane"></i> Submit</button>
                        </div>
                      </form>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
              <?php if ($rTotalPages>1): ?>
              <nav class="mt-2" aria-label="Reviews pagination">
                <ul class="pagination pagination-sm justify-content-center">
                  <?php 
                    $qs = $_GET; unset($qs['rpage']); $qs['id']=$product_id; 
                    $rlink = function($p) use ($qs){ $qs['rpage']=$p; return 'product_details.php?'.http_build_query($qs).'#reviews'; };
                  ?>
                  <li class="page-item <?php echo $rpage<=1?'disabled':''; ?>"><a class="page-link" href="<?php echo $rlink(max(1,$rpage-1)); ?>">Prev</a></li>
                  <?php for($p=max(1,$rpage-2); $p<=min($rTotalPages,$rpage+2); $p++): ?>
                    <li class="page-item <?php echo $p===$rpage?'active':''; ?>"><a class="page-link" href="<?php echo $rlink($p); ?>"><?php echo $p; ?></a></li>
                  <?php endfor; ?>
                  <li class="page-item <?php echo $rpage>=$rTotalPages?'disabled':''; ?>"><a class="page-link" href="<?php echo $rlink(min($rTotalPages,$rpage+1)); ?>">Next</a></li>
                </ul>
              </nav>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Escrow Information -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <h5><i class="fas fa-shield-alt text-success"></i> Escrow Protection</h5>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="text-center">
                                <i class="fas fa-lock fa-2x text-primary mb-2"></i>
                                <h6>Secure Payment</h6>
                                <small class="text-muted">Your payment is held safely in escrow</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-center">
                                <i class="fas fa-handshake fa-2x text-success mb-2"></i>
                                <h6>Delivery Confirmation</h6>
                                <small class="text-muted">Confirm receipt before payment is released</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-center">
                                <i class="fas fa-undo fa-2x text-warning mb-2"></i>
                                <h6>Refund Protection</h6>
                                <small class="text-muted">Get refund if item is not as described</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

<!-- Lightbox Modal -->
<div class="modal fade" id="pdLightbox" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-body p-0 bg-black">
        <img id="pdLightboxImg" src="" class="w-100 lightbox-img" alt="preview">
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  const main = document.getElementById('pdMainImg');
  const thumbs = document.querySelectorAll('.pd-thumb');
  const lb = document.getElementById('pdLightbox');
  const lbImg = document.getElementById('pdLightboxImg');
  if (main && thumbs.length) {
    thumbs.forEach(t=>{
      t.addEventListener('click', ()=>{
        const src = t.getAttribute('data-src');
        if (!src) return;
        main.src = src;
        document.querySelectorAll('.pd-thumb.active').forEach(a=>a.classList.remove('active'));
        t.classList.add('active');
      });
    });
    // Open lightbox on main image click
    main.style.cursor = 'zoom-in';
    main.addEventListener('click', ()=>{
      if (!lb || !lbImg) return;
      lbImg.src = main.src;
      const m = bootstrap.Modal.getOrCreateInstance(lb);
      m.show();
    });
  }
})();
</script>

<?php if ($can_buy && defined('RAZORPAY_ENABLED') && RAZORPAY_ENABLED): ?>
<!-- Razorpay Checkout -->
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script>
(function(){
  const btn = document.getElementById('rzpPayBtn');
  if (!btn) return;
  btn.addEventListener('click', async function(){
    try {
      const form = new FormData();
      form.append('product_id', '<?php echo (int)$product['id']; ?>');
      const res = await fetch('api/razorpay_create_order.php', { method: 'POST', body: form });
      const data = await res.json();
      if (!res.ok) { alert(data.error || 'Failed to create order'); return; }
      const options = {
        key: data.key_id,
        amount: data.amount,
        currency: data.currency,
        name: 'Retrade',
        description: data.product.title,
        image: data.product.image ? 'assets/img/' + data.product.image : undefined,
        order_id: data.order_id,
        prefill: { name: data.buyer.name, email: data.buyer.email },
        theme: { color: '#3f51b5' },
        handler: async function (response) {
          try {
            const verifyForm = new FormData();
            verifyForm.append('product_id', data.product.id);
            verifyForm.append('razorpay_order_id', response.razorpay_order_id);
            verifyForm.append('razorpay_payment_id', response.razorpay_payment_id);
            verifyForm.append('razorpay_signature', response.razorpay_signature);
            const vres = await fetch('api/razorpay_verify.php', { method: 'POST', body: verifyForm });
            const vjson = await vres.json();
            if (!vres.ok) { alert(vjson.error || 'Verification failed'); return; }
            window.location.href = 'dashboard.php?success=purchase_completed&transaction_id=' + encodeURIComponent(vjson.transaction_id);
          } catch (e) {
            alert('Verification error');
          }
        }
      };
      const rzp = new Razorpay(options);
      rzp.open();
    } catch (e) {
      alert('Unable to initiate payment');
    }
  });
})();
</script>
<?php endif; ?>
