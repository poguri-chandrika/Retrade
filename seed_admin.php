<?php
require_once 'config.php';

// This script seeds a default admin user if not present.
// Run once by visiting: http://localhost/Retrade-WT/seed_admin.php
// Then delete this file for security.

try {
    $pdo->beginTransaction();

    // Ensure database exists (skip here because DSN already requires DB). Just proceed.

    // Check if admin exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $stmt->execute(['admin@retrade.com']);
    $exists = $stmt->fetchColumn();

    if ($exists) {
        $pdo->commit();
        echo '<h3>Admin already exists. No changes made.</h3>';
        exit;
    }

    // Insert default admin with password 'password'
    $hash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
    $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, balance) VALUES (?, ?, ?, 'admin', 10000.00)");
    $stmt->execute(['Admin', 'admin@retrade.com', $hash]);

    $pdo->commit();
    echo '<h3>Default admin user created successfully.</h3>';
    echo '<p>Email: <code>admin@retrade.com</code> | Password: <code>password</code></p>';
    echo '<p>Please <strong>delete seed_admin.php</strong> from your server after confirming login.</p>';
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    http_response_code(500);
    echo '<h3>Seeding failed</h3>';
    echo '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
}
