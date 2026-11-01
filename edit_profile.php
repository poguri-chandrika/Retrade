<?php
require_once 'config.php';
requireLogin();

$page_title = 'Edit Profile';

$uid = (int)($_SESSION['user_id'] ?? 0);
$stmt = $pdo->prepare("SELECT id, name, phone, address FROM users WHERE id = ?");
$stmt->execute([$uid]);
$user = $stmt->fetch() ?: ['name'=>'','phone'=>'','address'=>''];

include 'includes/header.php';
?>
<div class="container my-4">
  <div class="row justify-content-center">
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h5 class="mb-0"><i class="fas fa-user-edit me-2"></i>Edit Profile</h5>
          <a href="dashboard.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-arrow-left"></i> Back</a>
        </div>
        <div class="card-body">
          <div id="editProfileAlert" class="alert d-none" role="alert"></div>
          <form id="editProfileForm" class="needs-validation" novalidate>
            <div class="mb-3">
              <label class="form-label">Name</label>
              <input type="text" name="name" class="form-control" required value="<?php echo htmlspecialchars($user['name'] ?? ''); ?>">
            </div>
            <div class="mb-3">
              <label class="form-label">Phone</label>
              <input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
            </div>
            <div class="mb-3">
              <label class="form-label">Address</label>
              <textarea name="address" class="form-control" rows="2"><?php echo htmlspecialchars($user['address'] ?? ''); ?></textarea>
            </div>
            <div class="d-grid">
              <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include 'includes/footer.php'; ?>
