<?php
require_once 'config.php';

$page_title = 'Register';
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = sanitizeInput($_POST['name']);
    $email = sanitizeInput($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $role = sanitizeInput($_POST['role']);
    $phone = sanitizeInput($_POST['phone']);
    $address = sanitizeInput($_POST['address']);
    
    // Validation
    if (empty($name) || empty($email) || empty($password) || empty($role)) {
        $error = 'All required fields must be filled.';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters long.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email format.';
    } elseif (!in_array($role, ['buyer', 'seller'])) {
        $error = 'Invalid role selected.';
    } else {
        try {
            // Check if email already exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            
            if ($stmt->fetch()) {
                $error = 'Email already registered. Please use a different email.';
            } else {
                // Hash password and insert user
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $initial_balance = ($role === 'buyer') ? 1000.00 : 0.00; // Give buyers initial balance
                
                $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, phone, address, balance) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$name, $email, $hashed_password, $role, $phone, $address, $initial_balance]);
                
                $success = 'Registration successful! You can now login.';
            }
        } catch (PDOException $e) {
            $error = 'Registration failed. Please try again.';
        }
    }
}

include 'includes/header.php';
?>

<div class="container my-5">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="form-container">
                <div class="text-center mb-4">
                    <h2><i class="fas fa-user-plus text-primary"></i> Create Account</h2>
                    <p class="text-muted">Join Retrade marketplace today</p>
                </div>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                        <div class="mt-2">
                            <a href="login.php" class="btn btn-success btn-sm">Login Now</a>
                        </div>
                    </div>
                <?php endif; ?>
                
                <form method="POST" class="needs-validation" novalidate>
                    <div class="mb-3">
                        <label for="name" class="form-label">Full Name *</label>
                        <input type="text" class="form-control" id="name" name="name" 
                               value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>" required>
                        <div class="invalid-feedback">Please provide your full name.</div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="email" class="form-label">Email Address *</label>
                        <input type="email" class="form-control" id="email" name="email" 
                               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
                        <div class="invalid-feedback">Please provide a valid email address.</div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="phone" class="form-label">Phone Number</label>
                        <input type="tel" class="form-control" id="phone" name="phone" 
                               value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
                    </div>
                    
                    <div class="mb-3">
                        <label for="address" class="form-label">Address</label>
                        <textarea class="form-control" id="address" name="address" rows="2"><?php echo htmlspecialchars($_POST['address'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label for="role" class="form-label">Account Type *</label>
                        <select class="form-select" id="role" name="role" required>
                            <option value="">Choose your role</option>
                            <option value="buyer" <?php echo ($_POST['role'] ?? '') === 'buyer' ? 'selected' : ''; ?>>
                                Buyer - I want to buy products
                            </option>
                            <option value="seller" <?php echo ($_POST['role'] ?? '') === 'seller' ? 'selected' : ''; ?>>
                                Seller - I want to sell products
                            </option>
                        </select>
                        <div class="invalid-feedback">Please select your account type.</div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="password" class="form-label">Password *</label>
                        <input type="password" class="form-control" id="password" name="password" 
                               minlength="6" required>
                        <div class="invalid-feedback">Password must be at least 6 characters long.</div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="confirm_password" class="form-label">Confirm Password *</label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" 
                               minlength="6" required>
                        <div class="invalid-feedback">Please confirm your password.</div>
                    </div>
                    
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary btn-lg">
                            <i class="fas fa-user-plus"></i> Create Account
                        </button>
                    </div>
                </form>
                
                <div class="text-center mt-4">
                    <p>Already have an account? <a href="login.php" class="text-decoration-none">Login here</a></p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
