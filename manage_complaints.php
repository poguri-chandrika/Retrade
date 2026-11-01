<?php
require_once 'config.php';
requireRole('admin');

$page_title = 'Manage Complaints';

// Ensure CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}

// Handle updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $t = $_POST['csrf_token'] ?? '';
    if (!$t || !hash_equals($_SESSION['csrf_token'] ?? '', $t)) {
        http_response_code(400);
        exit('Invalid CSRF token');
    }

    $cid = (int)($_POST['complaint_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    try {
        if ($cid <= 0) throw new Exception('Invalid complaint');

        if ($action === 'update') {
            $status = $_POST['status'] ?? '';
            $priority = $_POST['priority'] ?? '';
            $admin_notes = trim($_POST['admin_notes'] ?? '');
            $resolution = trim($_POST['resolution'] ?? '');
            if (!in_array($status, ['open','in_review','resolved','dismissed'], true)) throw new Exception('Bad status');
            if (!in_array($priority, ['low','medium','high'], true)) throw new Exception('Bad priority');
            $stmt = $pdo->prepare("UPDATE complaints SET status=?, priority=?, admin_notes=?, resolution=? WHERE id=?");
            $stmt->execute([$status, $priority, $admin_notes ?: null, $resolution ?: null, $cid]);
        }

        header('Location: manage_complaints.php?success=1');
        exit();
    } catch (Throwable $e) {
        header('Location: manage_complaints.php?error=' . urlencode($e->getMessage()));
        exit();
    }
}

// Filters and search
$status = $_GET['status'] ?? 'open';
$priority = $_GET['priority'] ?? '';
$q = trim($_GET['q'] ?? '');

$sql = "SELECT c.*, 
               bu.name AS buyer_name, bu.email AS buyer_email,
               se.name AS seller_name, se.email AS seller_email,
               p.title AS product_title
        FROM complaints c
        JOIN users bu ON c.buyer_id = bu.id
        JOIN users se ON c.seller_id = se.id
        LEFT JOIN products p ON c.product_id = p.id
        WHERE 1=1";
$params = [];

if (in_array($status, ['open','in_review','resolved','dismissed'], true)) {
    $sql .= " AND c.status = ?"; $params[] = $status;
}
if (in_array($priority, ['low','medium','high'], true)) {
    $sql .= " AND c.priority = ?"; $params[] = $priority;
}
if ($q !== '') {
    $like = "%$q%";
    $sql .= " AND (c.subject LIKE ? OR c.description LIKE ? OR bu.name LIKE ? OR se.name LIKE ? OR COALESCE(p.title,'') LIKE ?)";
    array_push($params, $like, $like, $like, $like, $like);
}
$sql .= " ORDER BY c.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$complaints = $stmt->fetchAll();

include 'includes/header.php';
?>

<div class="container my-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2><i class="fas fa-exclamation-triangle text-danger"></i> Manage Complaints</h2>
    <a href="admin_panel.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> Back</a>
  </div>

  <?php if (isset($_GET['error'])): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_GET['error']); ?></div>
  <?php elseif (isset($_GET['success'])): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> Saved.</div>
  <?php endif; ?>

  <div class="card mb-3">
    <div class="card-body">
      <form class="row g-2">
        <div class="col-md-3">
          <label class="form-label">Status</label>
          <select name="status" class="form-select" onchange="this.form.submit()">
            <option value="open" <?php echo $status==='open'?'selected':''; ?>>Open</option>
            <option value="in_review" <?php echo $status==='in_review'?'selected':''; ?>>In Review</option>
            <option value="resolved" <?php echo $status==='resolved'?'selected':''; ?>>Resolved</option>
            <option value="dismissed" <?php echo $status==='dismissed'?'selected':''; ?>>Dismissed</option>
            <option value="" <?php echo $status===''?'selected':''; ?>>All</option>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Priority</label>
          <select name="priority" class="form-select" onchange="this.form.submit()">
            <option value="" <?php echo $priority===''?'selected':''; ?>>Any</option>
            <option value="low" <?php echo $priority==='low'?'selected':''; ?>>Low</option>
            <option value="medium" <?php echo $priority==='medium'?'selected':''; ?>>Medium</option>
            <option value="high" <?php echo $priority==='high'?'selected':''; ?>>High</option>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label">Search</label>
          <div class="input-group">
            <input type="text" class="form-control" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Subject, description, buyer/seller name or product">
            <button class="btn btn-primary" type="submit"><i class="fas fa-search"></i></button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
          <thead>
            <tr>
              <th>#</th>
              <th>Subject</th>
              <th>Buyer</th>
              <th>Seller</th>
              <th>Product</th>
              <th>Priority</th>
              <th>Status</th>
              <th>Date</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($complaints)): ?>
              <tr><td colspan="9" class="text-center py-4 text-muted">No complaints found</td></tr>
            <?php else: foreach ($complaints as $c): ?>
              <tr>
                <td>#<?php echo (int)$c['id']; ?></td>
                <td>
                  <div class="fw-semibold text-truncate" style="max-width: 280px;" title="<?php echo htmlspecialchars($c['subject']); ?>"><?php echo htmlspecialchars($c['subject']); ?></div>
                  <div class="small text-muted text-truncate" style="max-width: 280px;" title="<?php echo htmlspecialchars($c['description']); ?>"><?php echo htmlspecialchars($c['description']); ?></div>
                </td>
                <td><?php echo htmlspecialchars($c['buyer_name']); ?></td>
                <td><?php echo htmlspecialchars($c['seller_name']); ?></td>
                <td><?php echo htmlspecialchars($c['product_title'] ?? '-'); ?></td>
                <td><span class="badge bg-<?php echo $c['priority']==='high'?'danger':($c['priority']==='medium'?'warning':'secondary'); ?>"><?php echo ucfirst($c['priority']); ?></span></td>
                <td><span class="badge bg-<?php echo $c['status']==='open'?'danger':($c['status']==='in_review'?'warning':($c['status']==='resolved'?'success':'secondary')); ?>"><?php echo ucwords(str_replace('_',' ',$c['status'])); ?></span></td>
                <td><?php echo date('M j, Y', strtotime($c['created_at'])); ?></td>
                <td class="text-end">
                  <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#cModal<?php echo (int)$c['id']; ?>"><i class="fas fa-eye"></i></button>
                </td>
              </tr>

              <!-- Modal -->
              <div class="modal fade" id="cModal<?php echo (int)$c['id']; ?>" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                  <div class="modal-content">
                    <div class="modal-header">
                      <h5 class="modal-title"><i class="fas fa-clipboard-list"></i> Complaint #<?php echo (int)$c['id']; ?></h5>
                      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                      <div class="mb-3">
                        <div class="small text-muted">Subject</div>
                        <div class="fw-semibold"><?php echo htmlspecialchars($c['subject']); ?></div>
                      </div>
                      <div class="mb-3">
                        <div class="small text-muted">Description</div>
                        <div class="text-muted" style="white-space: pre-wrap;"><?php echo htmlspecialchars($c['description']); ?></div>
                      </div>
                      <div class="row g-3 mb-3">
                        <div class="col-md-4">
                          <div class="small text-muted">Buyer</div>
                          <div class="fw-semibold"><?php echo htmlspecialchars($c['buyer_name']); ?></div>
                          <div class="text-muted small"><?php echo htmlspecialchars($c['buyer_email']); ?></div>
                        </div>
                        <div class="col-md-4">
                          <div class="small text-muted">Seller</div>
                          <div class="fw-semibold"><?php echo htmlspecialchars($c['seller_name']); ?></div>
                          <div class="text-muted small"><?php echo htmlspecialchars($c['seller_email']); ?></div>
                        </div>
                        <div class="col-md-4">
                          <div class="small text-muted">Product</div>
                          <div class="fw-semibold"><?php echo htmlspecialchars($c['product_title'] ?? '-'); ?></div>
                        </div>
                      </div>
                      <?php if (!empty($c['attachment_path']) && file_exists($c['attachment_path'])): ?>
                        <div class="mb-3">
                          <div class="small text-muted">Attachment</div>
                          <a href="<?php echo htmlspecialchars($c['attachment_path']); ?>" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="fas fa-paperclip"></i> View</a>
                        </div>
                      <?php endif; ?>

                      <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="complaint_id" value="<?php echo (int)$c['id']; ?>">
                        <input type="hidden" name="action" value="update">
                        <div class="row g-3">
                          <div class="col-md-4">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                              <?php foreach (['open','in_review','resolved','dismissed'] as $st): ?>
                                <option value="<?php echo $st; ?>" <?php echo $c['status']===$st?'selected':''; ?>><?php echo ucwords(str_replace('_',' ',$st)); ?></option>
                              <?php endforeach; ?>
                            </select>
                          </div>
                          <div class="col-md-4">
                            <label class="form-label">Priority</label>
                            <select name="priority" class="form-select">
                              <?php foreach (['low','medium','high'] as $pr): ?>
                                <option value="<?php echo $pr; ?>" <?php echo $c['priority']===$pr?'selected':''; ?>><?php echo ucfirst($pr); ?></option>
                              <?php endforeach; ?>
                            </select>
                          </div>
                        </div>
                        <div class="mt-3">
                          <label class="form-label">Admin Notes</label>
                          <textarea name="admin_notes" class="form-control" rows="3" placeholder="Internal notes for audit (not visible to users)"><?php echo htmlspecialchars($c['admin_notes'] ?? ''); ?></textarea>
                        </div>
                        <div class="mt-3">
                          <label class="form-label">Resolution (optional)</label>
                          <textarea name="resolution" class="form-control" rows="3" placeholder="Describe the resolution shared with buyer/seller."><?php echo htmlspecialchars($c['resolution'] ?? ''); ?></textarea>
                        </div>
                        <div class="mt-3 text-end">
                          <button class="btn btn-primary"><i class="fas fa-save"></i> Save</button>
                        </div>
                      </form>
                    </div>
                  </div>
                </div>
              </div>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php include 'includes/footer.php'; ?>
