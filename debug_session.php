<?php
require_once __DIR__ . '/config.php';
$page_title = 'Auth Debug';
include __DIR__ . '/includes/header.php';
?>
<div class="container my-4">
  <div class="row">
    <div class="col-lg-8">
      <div class="card">
        <div class="card-header"><strong>Session Debug</strong></div>
        <div class="card-body">
          <p><strong>Session ID:</strong> <code><?php echo session_id(); ?></code></p>
          <p><strong>isLoggedIn():</strong> <?php echo isLoggedIn() ? '<span class="text-success">true</span>' : '<span class="text-danger">false</span>'; ?></p>
          <p><strong>User ID:</strong> <code><?php echo $_SESSION['user_id'] ?? 'null'; ?></code></p>
          <p><strong>User Name:</strong> <code><?php echo $_SESSION['user_name'] ?? 'null'; ?></code></p>
          <p><strong>User Email:</strong> <code><?php echo $_SESSION['user_email'] ?? 'null'; ?></code></p>
          <p><strong>User Role:</strong> <code><?php echo $_SESSION['user_role'] ?? 'null'; ?></code></p>
          <hr>
          <div class="d-flex gap-2">
            <a class="btn btn-primary" href="login.php">Go to Login</a>
            <a class="btn btn-secondary" href="dashboard.php">Go to Dashboard</a>
            <a class="btn btn-danger" href="logout.php">Logout</a>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
