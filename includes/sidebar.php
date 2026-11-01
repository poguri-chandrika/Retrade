<?php
if (!isset($pdo)) { require_once __DIR__ . '/../config.php'; }
$role = getUserRole();
?>
<aside class="sidebar" id="appSidebar">
  <div class="brand">
    <i class="fas fa-layer-group" aria-hidden="true"></i>
    <span>Retrade</span>
  </div>

  <div class="search">
    <input type="text" placeholder="Search..." aria-label="Search">
    <i class="fas fa-search"></i>
  </div>

  <div class="nav-section">
    <div class="nav-section-title">Main</div>
    <ul class="nav-list">
      <?php $homeActive = ($page_title ?? '') === 'Home'; ?>
      <li><a href="index.php" class="<?php echo $homeActive ? 'active' : ''; ?>" <?php echo $homeActive ? 'aria-current="page"' : ''; ?>><i class="fas fa-home" aria-hidden="true"></i> <span>Home</span></a></li>
      <?php if (isLoggedIn() && $role !== 'admin'): ?>
        <?php $dashActive = ($page_title ?? '') === 'Dashboard'; ?>
        <li><a href="dashboard.php" class="<?php echo $dashActive ? 'active' : ''; ?>" <?php echo $dashActive ? 'aria-current="page"' : ''; ?>><i class="fas fa-gauge" aria-hidden="true"></i> <span>Dashboard</span></a></li>
      <?php endif; ?>
    </ul>
  </div>

  <?php if (isLoggedIn() && $role === 'seller'): ?>
  <div class="nav-section">
    <div class="nav-section-title">Seller</div>
    <ul class="nav-list">
      <?php $addActive = ($page_title ?? '') === 'Add Product'; ?>
      <li><a href="add_product.php" class="<?php echo $addActive ? 'active' : ''; ?>" <?php echo $addActive ? 'aria-current="page"' : ''; ?>><i class="fas fa-plus" aria-hidden="true"></i> <span>Add Product</span></a></li>
    </ul>
  </div>
  <?php endif; ?>

  <?php if (isLoggedIn() && $role === 'admin'): ?>
  <div class="nav-section">
    <div class="nav-section-title">Admin</div>
    <ul class="nav-list">
      <?php $adminActive = ($page_title ?? '') === 'Admin Dashboard'; ?>
      <li><a href="admin_panel.php" class="<?php echo $adminActive ? 'active' : ''; ?>" <?php echo $adminActive ? 'aria-current="page"' : ''; ?>><i class="fas fa-sliders-h" aria-hidden="true"></i> <span>Admin Dashboard</span></a></li>
      <li><a href="manage_users.php"><i class="fas fa-users" aria-hidden="true"></i> <span>Manage Users</span></a></li>
      <li><a href="manage_payments.php"><i class="fas fa-credit-card" aria-hidden="true"></i> <span>Manage Payments</span></a></li>
      <li><a href="manage_complaints.php"><i class="fas fa-flag" aria-hidden="true"></i> <span>Manage Complaints</span></a></li>
    </ul>
  </div>
  <?php endif; ?>

  <div class="nav-section">
    <div class="nav-section-title">Account</div>
    <ul class="nav-list">
      <?php if (isLoggedIn()): ?>
        <li><a href="logout.php"><i class="fas fa-sign-out-alt" aria-hidden="true"></i> <span>Logout</span></a></li>
      <?php else: ?>
        <li><a href="login.php"><i class="fas fa-sign-in-alt" aria-hidden="true"></i> <span>Login</span></a></li>
        <li><a href="register.php"><i class="fas fa-user-plus" aria-hidden="true"></i> <span>Register</span></a></li>
      <?php endif; ?>
    </ul>
  </div>
</aside>
<div class="overlay" id="sidebarOverlay"></div>
