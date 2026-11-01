<?php
require_once 'config.php';
requireRole('admin');

$page_title = 'Admin Dashboard';

// Stats
$stats = [
    'total_users' => 0,
    'total_buyers' => 0,
    'total_sellers' => 0,
    'total_products' => 0,
    
    'total_transactions' => 0,
    'pending_payments' => 0,
    'approved_payments' => 0,
    'disputes' => 0,
    'released_payments' => 0,
    'refunded_payments' => 0,
    'commission_earned' => 0.00,
    'open_complaints' => 0
];

// Fetch aggregated stats
$stmt = $pdo->query("SELECT COUNT(*) FROM users");
$stats['total_users'] = (int)$stmt->fetchColumn();
$stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role='buyer'");
$stats['total_buyers'] = (int)$stmt->fetchColumn();
$stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role='seller'");
$stats['total_sellers'] = (int)$stmt->fetchColumn();
$stmt = $pdo->query("SELECT COUNT(*) FROM products");
$stats['total_products'] = (int)$stmt->fetchColumn();
$stmt = $pdo->query("SELECT COUNT(*) FROM products");
$stats['total_products'] = (int)$stmt->fetchColumn();
$stmt = $pdo->query("SELECT COUNT(*) FROM transactions");
$stats['total_transactions'] = (int)$stmt->fetchColumn();
$stmt = $pdo->query("SELECT COUNT(*) FROM transactions WHERE status='pending'");
$stats['pending_payments'] = (int)$stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM transactions WHERE delivery_status='disputed'");
$stats['disputes'] = (int)$stmt->fetchColumn();
$stmt = $pdo->query("SELECT COUNT(*) FROM transactions WHERE status='released'");
$stats['released_payments'] = (int)$stmt->fetchColumn();
$stmt = $pdo->query("SELECT COUNT(*) FROM transactions WHERE status='refunded'");
$stats['refunded_payments'] = (int)$stmt->fetchColumn();
$stmt = $pdo->query("SELECT COALESCE(SUM(admin_commission),0) FROM transactions WHERE status='released'");
$stats['commission_earned'] = (float)$stmt->fetchColumn();

// Complaints stats
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM complaints WHERE status='open'");
    $stats['open_complaints'] = (int)$stmt->fetchColumn();
} catch (Throwable $e) {
    $stats['open_complaints'] = 0;
}

// Recent items
$recent_products = $pdo->query("SELECT p.*, u.name AS seller_name FROM products p JOIN users u ON p.seller_id=u.id ORDER BY p.created_at DESC LIMIT 5")->fetchAll();
$recent_tx = $pdo->query("SELECT t.*, p.title, u1.name AS buyer_name, u2.name AS seller_name FROM transactions t JOIN products p ON t.product_id=p.id JOIN users u1 ON t.buyer_id=u1.id JOIN users u2 ON t.seller_id=u2.id ORDER BY t.created_at DESC LIMIT 8")->fetchAll();

include 'includes/header.php';
?>

<div class="container my-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="fas fa-tools text-warning"></i> Admin Dashboard</h2>
        <a href="index.php" class="btn btn-outline-secondary"><i class="fas fa-home"></i> View Site</a>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h5 class="mb-0"><i class="fas fa-bolt"></i> Quick Actions</h5>
            </div>
            <div class="quick-actions">
                <a class="qa-item text-decoration-none" href="manage_payments.php" title="Manage Payments">
                    <i class="fas fa-credit-card"></i>
                    <div>
                        <div class="fw-semibold">Manage Payments</div>
                        <small class="text-muted-2">Release or refund escrow</small>
                    </div>
                </a>
                <a class="qa-item text-decoration-none" href="manage_users.php" title="Manage Users">
                    <i class="fas fa-users"></i>
                    <div>
                        <div class="fw-semibold">Manage Users</div>
                        <small class="text-muted-2">Sellers and buyers</small>
                    </div>
                </a>
                <a class="qa-item text-decoration-none" href="manage_complaints.php" title="Manage Complaints">
                    <i class="fas fa-flag"></i>
                    <div>
                        <div class="fw-semibold">Manage Complaints</div>
                        <small class="text-muted-2">Resolve disputes</small>
                    </div>
                </a>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card stat-card">
                <div class="card-body text-center">
                    <i class="fas fa-users fa-2x mb-2"></i>
                    <h3><?php echo number_format($stats['total_users']); ?></h3>
                    <div>Total Users</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card">
                <div class="card-body text-center">
                    <i class="fas fa-box fa-2x mb-2"></i>
                    <h3><?php echo number_format($stats['total_products']); ?></h3>
                    <div>Products</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card">
                <div class="card-body text-center">
                    <i class="fas fa-handshake fa-2x mb-2"></i>
                    <h3><?php echo number_format($stats['total_transactions']); ?></h3>
                    <div>Transactions</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card">
                <div class="card-body text-center">
                    <i class="fas fa-coins fa-2x mb-2"></i>
                    <h3><?php echo formatPrice($stats['commission_earned']); ?></h3>
                    <div>Commission Earned</div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card dashboard-card text-center">
                <div class="card-body">
                    <div class="fw-bold text-info"><?php echo $stats['pending_payments']; ?></div>
                    <small class="text-muted">Pending Payments</small>
                    <div class="mt-2"><a href="manage_payments.php" class="btn btn-sm btn-outline-info">Manage</a></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card dashboard-card text-center">
                <div class="card-body">
                    <div class="fw-bold text-danger"><?php echo $stats['disputes']; ?></div>
                    <small class="text-muted">Disputes</small>
                    <div class="mt-2"><a href="manage_payments.php?filter=disputed" class="btn btn-sm btn-outline-danger">Review</a></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card dashboard-card text-center">
                <div class="card-body">
                    <div class="fw-bold text-danger"><?php echo $stats['open_complaints']; ?></div>
                    <small class="text-muted">Open Complaints</small>
                    <div class="mt-2"><a href="manage_complaints.php" class="btn btn-sm btn-outline-danger">Review</a></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-clock"></i> Recent Products</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table mb-0">
                            <thead>
                                <tr>
                                    <th>Title</th>
                                    <th>Seller</th>
                                    <th>Price</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_products as $rp): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($rp['title']); ?></td>
                                        <td><?php echo htmlspecialchars($rp['seller_name']); ?></td>
                                        <td><?php echo formatPrice($rp['price']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-history"></i> Recent Transactions</h5>
                    <a class="btn btn-sm btn-primary" href="manage_payments.php">Manage</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table mb-0">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Buyer</th>
                                    <th>Seller</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_tx as $tx): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($tx['title']); ?></td>
                                        <td><?php echo htmlspecialchars($tx['buyer_name']); ?></td>
                                        <td><?php echo htmlspecialchars($tx['seller_name']); ?></td>
                                        <td><?php echo formatPrice($tx['amount']); ?></td>
                                        <td><span class="badge bg-<?php echo $tx['status']==='released'?'info':($tx['status']==='pending'?'warning':($tx['status']==='approved'?'success':($tx['status']==='refunded'?'secondary':'danger'))); ?>"><?php echo ucfirst($tx['status']); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
