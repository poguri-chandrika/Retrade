<?php
require_once 'config.php';
require_once 'payment_config.php';
requireRole('admin');

$page_title = 'Manage Payments';

// Handle actions: release, refund (no manual approval in escrow flow)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tx_id = intval($_POST['tx_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($tx_id && in_array($action, ['release','refund'])) {
        try {
            // Load transaction
            $stmt = $pdo->prepare("SELECT t.* FROM transactions t WHERE t.id = ?");
            $stmt->execute([$tx_id]);
            $tx = $stmt->fetch();
            if (!$tx) throw new Exception('Transaction not found');

            // State machine rules
            // release: (pending|disputed) -> released (move seller_amount from admin to seller)
            // refund: (pending|approved|disputed) -> refunded (move amount from admin to buyer)

            if ($action === 'release') {
                if (!in_array($tx['status'], ['pending']) && $tx['delivery_status'] !== 'disputed') {
                    throw new Exception('Cannot release in current state');
                }
                // Move escrow to seller balance and mark released
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("UPDATE transactions SET status = 'released', delivery_status = IF(delivery_status='pending','confirmed',delivery_status), notes = CONCAT(notes, '\n\nAdmin released payment on ', NOW()) WHERE id = ? AND status IN ('pending')");
                $stmt->execute([$tx_id]);
                if ($stmt->rowCount() > 0) {
                    $u1 = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
                    $u1->execute([(float)$tx['seller_amount'], (int)$tx['seller_id']]);
                    $u2 = $pdo->prepare("UPDATE users SET balance = balance - ? WHERE role = 'admin'");
                    $u2->execute([(float)$tx['seller_amount']]);
                }
                $pdo->commit();
            }

            if ($action === 'refund') {
                if (!in_array($tx['status'], ['pending','approved']) && $tx['delivery_status'] !== 'disputed') {
                    throw new Exception('Cannot refund in current state');
                }
                // Attempt Razorpay refund when applicable
                $refunded = false;
                if (RAZORPAY_ENABLED && !empty($tx['razorpay_payment_id']) && ($tx['payment_status'] === 'paid')) {
                    $ch = curl_init('https://api.razorpay.com/v1/payments/' . urlencode($tx['razorpay_payment_id']) . '/refund');
                    $payload = http_build_query([
                        'amount' => (int) round(((float)$tx['amount']) * 100)
                    ]);
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => $payload,
                        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
                        CURLOPT_USERPWD => RAZORPAY_KEY_ID . ':' . RAZORPAY_KEY_SECRET,
                    ]);
                    $response = curl_exec($ch);
                    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $err = curl_error($ch);
                    curl_close($ch);
                    if (!$err && $http_code < 400) {
                        $refunded = true;
                    }
                }
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("UPDATE transactions SET status = 'refunded', notes = CONCAT(notes, '\n\nAdmin refunded on ', NOW()), payment_status = IF(payment_status='paid','refunded',payment_status) WHERE id = ? AND status IN ('pending','approved')");
                $stmt->execute([$tx_id]);
                $pdo->commit();
            }

            header('Location: manage_payments.php?success=1');
            exit();
        } catch (Exception $ex) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            header('Location: manage_payments.php?error=' . urlencode($ex->getMessage()));
            exit();
        }
    }
}

// Filters
$status = $_GET["status"] ?? "pending"; // pending by default
$delivery = $_GET["delivery"] ?? ""; // optional: disputed
$allowed_status = ['pending','released','refunded','rejected','all'];
if (!in_array($status, $allowed_status)) { $status = 'pending'; }

$query = "SELECT t.*, p.title, u1.name AS buyer_name, u2.name AS seller_name
          FROM transactions t
          JOIN products p ON t.product_id = p.id
          JOIN users u1 ON t.buyer_id = u1.id
          JOIN users u2 ON t.seller_id = u2.id
          WHERE 1=1";
$params = [];

if ($status !== 'all') {
    $query .= " AND t.status = ?";
    $params[] = $status;
}
if ($delivery === 'disputed') {
    $query .= " AND t.delivery_status = 'disputed'";
}

$query .= " ORDER BY t.created_at DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$transactions = $stmt->fetchAll();

include 'includes/header.php';
?>

<div class="container my-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2><i class="fas fa-cash-register text-info"></i> Manage Escrow Payments</h2>
        <a href="admin_panel.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> Back</a>
    </div>

    <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_GET['error']); ?></div>
    <?php elseif (isset($_GET['success'])): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> Action completed successfully.</div>
    <?php endif; ?>

    <div class="card mb-3">
        <div class="card-body">
            <form class="row g-2">
                <div class="col-md-3">
                    <label class="form-label">Transaction Status</label>
                    <select class="form-select" name="status" onchange="this.form.submit()">
                        <option value="pending" <?php echo $status==='pending'?'selected':''; ?>>Pending</option>
                        <option value="released" <?php echo $status==='released'?'selected':''; ?>>Released</option>
                        <option value="refunded" <?php echo $status==='refunded'?'selected':''; ?>>Refunded</option>
                        <option value="rejected" <?php echo $status==='rejected'?'selected':''; ?>>Rejected</option>
                        <option value="all" <?php echo $status==='all'?'selected':''; ?>>All</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Delivery Status</label>
                    <select class="form-select" name="delivery" onchange="this.form.submit()">
                        <option value="" <?php echo $delivery===''?'selected':''; ?>>Any</option>
                        <option value="disputed" <?php echo $delivery==='disputed'?'selected':''; ?>>Disputed Only</option>
                    </select>
                </div>
                <div class="col-md-6 d-flex align-items-end justify-content-end">
                    <span class="badge bg-secondary"><?php echo count($transactions); ?> transactions</span>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Product</th>
                            <th>Buyer</th>
                            <th>Seller</th>
                            <th>Amount</th>
                            <th>Seller 90%</th>
                            <th>Admin 10%</th>
                            <th>Status</th>
                            <th>Delivery</th>
                            <th>Date</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($transactions)): ?>
                            <tr><td colspan="11" class="text-center py-4 text-muted">No transactions found</td></tr>
                        <?php else: ?>
                            <?php foreach ($transactions as $t): ?>
                                <tr class="transaction-<?php echo htmlspecialchars($t['status']); ?>">
                                    <td>#<?php echo $t['id']; ?></td>
                                    <td><?php echo htmlspecialchars($t['title']); ?></td>
                                    <td><?php echo htmlspecialchars($t['buyer_name']); ?></td>
                                    <td><?php echo htmlspecialchars($t['seller_name']); ?></td>
                                    <td class="fw-bold text-success"><?php echo formatPrice($t['amount']); ?></td>
                                    <td><?php echo formatPrice($t['seller_amount']); ?></td>
                                    <td><?php echo formatPrice($t['admin_commission']); ?></td>
                                    <td><span class="badge bg-<?php echo $t['status']==='released'?'info':($t['status']==='approved'?'success':($t['status']==='pending'?'warning':($t['status']==='refunded'?'secondary':'danger'))); ?>"><?php echo ucfirst($t['status']); ?></span></td>
                                    <td><span class="badge bg-<?php echo $t['delivery_status']==='disputed'?'danger':($t['delivery_status']==='confirmed'?'success':'warning'); ?>"><?php echo ucfirst($t['delivery_status']); ?></span></td>
                                    <td><?php echo date('M j, Y', strtotime($t['created_at'])); ?></td>
                                    <td class="text-end">
                                        <div class="btn-group">
                                            <form method="POST" class="me-1" onsubmit="return confirm('Release funds to seller?');">
                                                <input type="hidden" name="tx_id" value="<?php echo $t['id']; ?>">
                                                <input type="hidden" name="action" value="release">
                                                <button class="btn btn-sm btn-info" <?php echo in_array($t['status'],['released','refunded'])?'disabled':''; ?>>
                                                    <i class="fas fa-unlock"></i>
                                                </button>
                                            </form>
                                            <form method="POST" onsubmit="return confirm('Refund buyer full amount?');">
                                                <input type="hidden" name="tx_id" value="<?php echo $t['id']; ?>">
                                                <input type="hidden" name="action" value="refund">
                                                <button class="btn btn-sm btn-secondary" <?php echo in_array($t['status'],['released','refunded'])?'disabled':''; ?>>
                                                    <i class="fas fa-undo"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
