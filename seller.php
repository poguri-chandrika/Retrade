<?php
require_once 'config.php';

$seller_id = (int)($_GET['id'] ?? 0);
if ($seller_id <= 0) { header('Location: index.php'); exit; }

// Fetch seller
$stmt = $pdo->prepare("SELECT id, name, email, phone, address, role, created_at,
  COALESCE(tagline,'') AS tagline,
  COALESCE(avatar_url,'') AS avatar_url,
  COALESCE(social_twitter,'') AS social_twitter,
  COALESCE(social_instagram,'') AS social_instagram,
  COALESCE(social_website,'') AS social_website,
  COALESCE(is_verified,0) AS is_verified,
  COALESCE(response_time_min,NULL) AS response_time_min
  FROM users WHERE id = ? AND role = 'seller'");
$stmt->execute([$seller_id]);
$seller = $stmt->fetch();
if (!$seller) { header('Location: index.php'); exit; }

$page_title = 'Seller: ' . $seller['name'];

// Seller rating
$rating = ['avg'=>0.0,'cnt'=>0];
try {
  $rs = $pdo->prepare("SELECT ROUND(AVG(r.rating),1) AS avg_rating, COUNT(*) AS reviews_count
                        FROM reviews r JOIN products p ON p.id=r.product_id
                        WHERE p.seller_id = ? AND r.status='approved'");
  $rs->execute([$seller_id]);
  $row = $rs->fetch();
  $rating['avg'] = (float)($row['avg_rating'] ?? 0);
  $rating['cnt'] = (int)($row['reviews_count'] ?? 0);
} catch (Throwable $e) {}

// Trusted seller computation
$seller_trust = getSellerTrust($pdo, (int)$seller_id);

// Recent products by seller (approved)
$products = [];
try {
  $ps = $pdo->prepare("SELECT p.*, 
    (SELECT ROUND(AVG(r.rating),1) FROM reviews r WHERE r.product_id=p.id AND r.status='approved') AS avg_rating,
    (SELECT COUNT(*) FROM reviews r2 WHERE r2.product_id=p.id AND r2.status='approved') AS reviews_count
    FROM products p WHERE p.seller_id=? AND p.status='approved' AND p.image IS NOT NULL AND p.image<>''
    ORDER BY p.created_at DESC LIMIT 12");
  $ps->execute([$seller_id]);
  $products = $ps->fetchAll();
} catch (Throwable $e) {}

// Choose a product for Contact Seller CTA (chat is product-threaded)
$ctaProductId = null; $ctaProductTitle = '';
if (!empty($products)) { $ctaProductId = (int)$products[0]['id']; $ctaProductTitle = $products[0]['title']; }

include 'includes/header.php';
?>
<div class="container my-4">
  <div class="row">
    <div class="col-12">
      <div class="card mb-4">
        <div class="card-body">
          <div class="d-flex flex-wrap align-items-center gap-3">
            <img src="<?php echo htmlspecialchars($seller['avatar_url'] ?: ('https://ui-avatars.com/api/?name=' . urlencode($seller['name']) . '&background=3f51b5&color=fff')); ?>" alt="avatar" class="rounded-circle shadow" style="width:96px;height:96px;object-fit:cover">
            <div class="flex-grow-1">
              <div class="d-flex align-items-center gap-2">
                <h3 class="mb-0"><?php echo htmlspecialchars($seller['name']); ?></h3>
                <?php if (!empty($seller_trust['trusted'])): ?>
                  <span class="badge bg-success" title="Trusted Seller · Score: <?php echo (float)($seller_trust['score'] ?? 0); ?>">
                    <i class="fas fa-shield-alt"></i> Trusted
                  </span>
                <?php endif; ?>
                <?php if ((int)$seller['is_verified'] === 1): ?>
                  <span class="badge bg-info"><i class="fas fa-badge-check"></i> Verified</span>
                <?php endif; ?>
              </div>
              <?php if (!empty($seller['tagline'])): ?>
                <div class="text-muted"><?php echo htmlspecialchars($seller['tagline']); ?></div>
              <?php endif; ?>
              <div class="d-flex align-items-center gap-2 mt-1" title="Seller rating">
                <?php 
                  $ar = $rating['avg']; $full=(int)floor($ar); $half=($ar-$full)>=0.5?1:0; $empty=5-$full-$half;
                  for($i=0;$i<$full;$i++) echo '<i class="fas fa-star text-warning"></i>';
                  if ($half) echo '<i class="fas fa-star-half-alt text-warning"></i>';
                  for($i=0;$i<$empty;$i++) echo '<i class="far fa-star text-warning"></i>';
                ?>
                <small class="text-muted">(<?php echo number_format($rating['avg'],1); ?> · <?php echo (int)$rating['cnt']; ?>)</small>
              </div>
              <div class="small text-muted mt-1">Member since <?php echo date('F Y', strtotime($seller['created_at'])); ?><?php if ($seller['response_time_min']): ?> • Avg response ~ <?php echo (int)$seller['response_time_min']; ?> min<?php endif; ?></div>
              <div class="mt-2 d-flex flex-wrap gap-2">
                <?php if ($seller['social_twitter']): ?><a class="btn btn-ghost btn-sm" target="_blank" href="https://twitter.com/<?php echo ltrim($seller['social_twitter'],'@'); ?>"><i class="fab fa-twitter"></i> Twitter</a><?php endif; ?>
                <?php if ($seller['social_instagram']): ?><a class="btn btn-ghost btn-sm" target="_blank" href="https://instagram.com/<?php echo ltrim($seller['social_instagram'],'@'); ?>"><i class="fab fa-instagram"></i> Instagram</a><?php endif; ?>
                <?php if ($seller['social_website']): ?><a class="btn btn-ghost btn-sm" target="_blank" href="<?php echo htmlspecialchars($seller['social_website']); ?>"><i class="fas fa-globe"></i> Website</a><?php endif; ?>
              </div>
            </div>
            <div class="ms-auto d-flex flex-column gap-2">
              <?php if (isLoggedIn() && getUserRole()==='buyer' && $ctaProductId): ?>
                <button class="btn btn-primary openChatBtn"
                  data-role="buyer"
                  data-product-id="<?php echo $ctaProductId; ?>"
                  data-seller-id="<?php echo (int)$seller['id']; ?>"
                  data-buyer-id="<?php echo (int)($_SESSION['user_id'] ?? 0); ?>"
                  data-product-title="<?php echo htmlspecialchars($ctaProductTitle); ?>">
                  <i class="fas fa-comments"></i> Contact Seller
                </button>
              <?php else: ?>
                <button class="btn btn-secondary" disabled><i class="fas fa-comments"></i> Contact Seller</button>
                <small class="text-muted">No active listings</small>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="row mb-3">
    <div class="col-12 d-flex justify-content-between align-items-center">
      <h4 class="mb-0"><i class="fas fa-box-open text-primary"></i> Recent Listings</h4>
      <a href="index.php?search=&category=&condition_min=&max_price=&sort=newest#products" class="btn btn-outline-secondary btn-sm">Browse Marketplace</a>
    </div>
  </div>

  <div class="row">
    <?php if (empty($products)): ?>
      <div class="col-12">
        <div class="alert alert-info">This seller has no approved listings yet.</div>
      </div>
    <?php else: ?>
      <?php foreach ($products as $p): if (!($p['image'] && file_exists("assets/img/" . $p['image']))) continue; ?>
      <div class="col-lg-3 col-md-4 col-sm-6 mb-4">
        <div class="card product-card h-100">
          <div class="position-relative">
            <img src="assets/img/<?php echo htmlspecialchars($p['image']); ?>" class="card-img-top aspect-4x3 rounded-12" alt="<?php echo htmlspecialchars($p['title']); ?>" loading="lazy">
            <?php if (!empty($p['category'])): ?><span class="badge bg-primary position-absolute top-0 start-0 m-2"><?php echo htmlspecialchars($p['category']); ?></span><?php endif; ?>
          </div>
          <div class="card-body d-flex flex-column">
            <h6 class="card-title mb-1 text-truncate" title="<?php echo htmlspecialchars($p['title']); ?>"><?php echo htmlspecialchars($p['title']); ?></h6>
            <?php if (!empty($p['reviews_count'])): ?>
              <div class="mb-2 d-flex align-items-center gap-1" title="Average rating">
                <?php 
                  $ar=(float)($p['avg_rating'] ?? 0); $full=(int)floor($ar); $half=($ar-$full)>=0.5?1:0; $empty=5-$full-$half;
                  for($i=0;$i<$full;$i++) echo '<i class="fas fa-star text-warning"></i>';
                  if ($half) echo '<i class="fas fa-star-half-alt text-warning"></i>';
                  for($i=0;$i<$empty;$i++) echo '<i class="far fa-star text-warning"></i>';
                ?>
                <small class="text-muted">(<?php echo number_format((float)$p['avg_rating'],1); ?> · <?php echo (int)$p['reviews_count']; ?>)</small>
              </div>
            <?php endif; ?>
            <div class="d-flex justify-content-between align-items-center mt-auto">
              <span class="product-price"><?php echo formatPrice($p['price']); ?></span>
              <a href="product_details.php?id=<?php echo (int)$p['id']; ?>" class="btn btn-outline-primary btn-sm">View</a>
            </div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
<?php include 'includes/footer.php'; ?>
