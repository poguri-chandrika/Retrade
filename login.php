<?php
require_once 'config.php';

$page_title = 'Login';
$error = '';
$debug = !empty($_GET['debug']);

// Redirect if already logged in
if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit();
}

// Local dev-only autologin helper
if (!empty($_GET['dev_autologin']) && (($_SERVER['HTTP_HOST'] ?? '') === 'localhost')) {
    try {
        $stmt = $pdo->query("SELECT id, name, email, role FROM users WHERE role='admin' ORDER BY id LIMIT 1");
        if ($u = $stmt->fetch()) {
            if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
            $_SESSION['user_id'] = $u['id'];
            $_SESSION['user_name'] = $u['name'];
            $_SESSION['user_email'] = $u['email'];
            $_SESSION['user_role'] = $u['role'];
            header('Location: admin_panel.php');
            exit();
        }
    } catch (Throwable $e) {}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Normalize email to avoid whitespace/case issues
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'];
    
    if (empty($email) || empty($password)) {
        $error = 'Please enter both email and password.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id, name, email, password, role FROM users WHERE email = ? AND is_active = 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            
            if ($debug) {
                // Show raw user and input status
                echo '<pre>DEBUG: POST email=' . htmlspecialchars($email) . "\n";
                echo 'User fetched? ' . ($user ? 'yes' : 'no') . "\n";
                if ($user) {
                    echo 'Hash prefix: ' . substr($user['password'], 0, 7) . "...\n";
                    echo 'password_verify: ' . (password_verify($password, $user['password']) ? 'true' : 'false') . "\n";
                }
                echo "</pre>";
            }

            if ($user && password_verify($password, $user['password'])) {
                // Login successful
                // Regenerate session ID to avoid fixation and improve reliability
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_regenerate_id(true);
                }
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_role'] = $user['role'];
                
                // Redirect based on role
                if ($debug) {
                    echo '<pre>DEBUG: Login success. Session ID=' . session_id() . "\n";
                    print_r($_SESSION);
                    echo "\n(Intentionally not redirecting in debug mode)";
                    echo '</pre>';
                    exit();
                } else {
                    switch ($user['role']) {
                        case 'admin':
                            header('Location: admin_panel.php');
                            break;
                        case 'seller':
                        case 'buyer':
                            header('Location: dashboard.php');
                            break;
                        default:
                            header('Location: index.php');
                    }
                    exit();
                }
            } else {
                $error = 'Invalid email or password.';
                // Optional debug: visit login.php?debug=1 to see why on a local dev machine
                if (!empty($_GET['debug'])) {
                    if (!$user) {
                        $error .= ' (user not found)';
                    } else {
                        $error .= ' (password mismatch; hash prefix ' . substr($user['password'], 0, 7) . '...)';
                    }
                }
            }
        } catch (PDOException $e) {
            $error = 'Login failed. Please try again.';
        }
    }
}

include 'includes/header.php';
?>

<div class="container my-5">
    <div class="row justify-content-center">
        <div class="col-md-5">
            <div class="form-container">
                <div class="text-center mb-4">
                    <h2><i class="fas fa-sign-in-alt text-primary"></i> Welcome Back</h2>
                    <p class="text-muted">Sign in to your Retrade account</p>
                </div>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" class="needs-validation" novalidate>
                    <div class="mb-3">
                        <label for="email" class="form-label">Email Address</label>
                        <input type="email" class="form-control" id="email" name="email" 
                               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
                        <div class="invalid-feedback">Please provide a valid email address.</div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="password" class="form-label">Password</label>
                        <input type="password" class="form-control" id="password" name="password" required>
                        <div class="invalid-feedback">Please provide your password.</div>
                    </div>
                    
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary btn-lg">
                            <i class="fas fa-sign-in-alt"></i> Sign In
                        </button>
                    </div>
                </form>
                
                <div class="text-center mt-4">
                    <p>Don't have an account? <a href="register.php" class="text-decoration-none">Register here</a></p>
                </div>
                
                <hr>
                
                <div class="text-center">
                    <h6 class="text-muted mb-3">Demo Accounts</h6>
                    <div class="row">
                        <div class="col-4">
                            <small class="text-muted d-block">Admin</small>
                            <small>admin@retrade.com</small>
                        </div>
                        <div class="col-4">
                            <small class="text-muted d-block">Seller</small>
                            <small>seller@retrade.com</small>
                        </div>
                        <div class="col-4">
                            <small class="text-muted d-block">Buyer</small>
                            <small>buyer@retrade.com</small>
                        </div>
                    </div>
                    <small class="text-muted">Password: <code>password</code></small>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
