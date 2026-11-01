<?php
require_once __DIR__ . '/config.php';
requireLogin();

$page_title = 'Inbox';
$user_id = (int)($_SESSION['user_id'] ?? 0);
$role = getUserRole();

// Pagination
$limit = max(1, min(20, (int)($_GET['limit'] ?? 10)));
$offset = max(0, (int)($_GET['offset'] ?? 0));

// Fetch threads (buyer or seller) ordered by last message time
$sql = "SELECT n.id AS negotiation_id, n.product_id, n.buyer_id, n.seller_id,
               p.title AS product_title,
               CASE WHEN n.buyer_id = :uid THEN su.name ELSE bu.name END AS other_name,
               (SELECT m.message FROM messages m WHERE m.negotiation_id = n.id ORDER BY m.id DESC LIMIT 1) AS last_message,
               (SELECT m.created_at FROM messages m WHERE m.negotiation_id = n.id ORDER BY m.id DESC LIMIT 1) AS last_time
        FROM negotiations n
        JOIN products p ON p.id = n.product_id
        JOIN users bu ON bu.id = n.buyer_id
        JOIN users su ON su.id = n.seller_id
        WHERE n.buyer_id = :uid OR n.seller_id = :uid
        ORDER BY COALESCE((SELECT m.created_at FROM messages m WHERE m.negotiation_id = n.id ORDER BY m.id DESC LIMIT 1), n.id) DESC
        LIMIT :lim OFFSET :off";
$stmt = $pdo->prepare($sql);
$stmt->bindValue(':uid', $user_id, PDO::PARAM_INT);
$stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
$stmt->execute();
$threads = $stmt->fetchAll();

include __DIR__ . '/includes/header.php';
?>
<div class="container my-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0"><i class="fas fa-inbox text-primary"></i> Inbox</h2>
    <div>
      <a href="dashboard.php" class="btn btn-ghost btn-sm"><i class="fas fa-home"></i> Dashboard</a>
    </div>
  </div>

  <?php if (empty($threads)): ?>
    <div class="card">
      <div class="card-body text-center py-5">
        <i class="fas fa-comments fa-3x text-muted mb-3"></i>
        <h5>No conversations yet</h5>
        <p class="text-muted">Start a chat from any product page to see it here.</p>
        <a href="index.php" class="btn btn-primary"><i class="fas fa-store"></i> Browse Products</a>
      </div>
    </div>
  <?php else: ?>
    <div class="card">
      <div class="card-body p-0">
        <div class="list-group list-group-flush">
          <?php foreach ($threads as $t):
            $threadRole = ((int)$t['seller_id'] === $user_id) ? 'seller' : 'buyer';
            $avatar = 'https://ui-avatars.com/api/?name=' . urlencode($t['other_name'] ?? 'U') . '&background=3f51b5&color=fff';
          ?>
            <button type="button"
                    class="list-group-item list-group-item-action d-flex align-items-start gap-3 openChatBtn"
                    data-role="<?php echo htmlspecialchars($threadRole); ?>"
                    data-product-id="<?php echo (int)$t['product_id']; ?>"
                    data-seller-id="<?php echo (int)$t['seller_id']; ?>"
                    data-buyer-id="<?php echo (int)$t['buyer_id']; ?>">
              <img src="<?php echo $avatar; ?>" class="rounded-circle" width="36" height="36" alt="">
              <div class="flex-grow-1">
                <div class="d-flex justify-content-between align-items-center">
                  <div class="fw-semibold text-truncate">
                    <?php echo htmlspecialchars($t['other_name'] ?? 'User'); ?> •
                    <span class="text-muted"><?php echo htmlspecialchars($t['product_title'] ?? ''); ?></span>
                  </div>
                  <small class="text-muted flex-shrink-0"><?php echo $t['last_time'] ? date('M j, Y H:i', strtotime($t['last_time'])) : ''; ?></small>
                </div>
                <div class="small text-muted text-truncate"><?php echo htmlspecialchars($t['last_message'] ?? ''); ?></div>
              </div>
            </button>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="card-footer d-flex justify-content-between">
        <?php $prev = max(0, $offset - $limit); $next = $offset + $limit; ?>
        <a class="btn btn-outline-secondary btn-sm<?php echo $offset <= 0 ? ' disabled' : ''; ?>" href="?offset=<?php echo $prev; ?>&limit=<?php echo $limit; ?>"><i class="fas fa-chevron-left"></i> Prev</a>
        <a class="btn btn-outline-secondary btn-sm" href="?offset=<?php echo $next; ?>&limit=<?php echo $limit; ?>">Next <i class="fas fa-chevron-right"></i></a>
      </div>
    </div>
  <?php endif; ?>
</div>

<!-- Footer includes (adds global modals and scripts) -->
<?php include __DIR__ . '/includes/footer.php'; ?>
