<?php
try {
    $pdo->exec("ALTER TABLE transactions 
        ADD COLUMN tracking_number VARCHAR(100) NULL AFTER delivery_status,
        ADD COLUMN shipping_provider VARCHAR(50) NULL AFTER tracking_number,
        ADD COLUMN shipped_at TIMESTAMP NULL AFTER updated_at,
        ADD COLUMN delivered_at TIMESTAMP NULL AFTER shipped_at,
        ADD COLUMN buyer_notes TEXT NULL AFTER notes,
        ADD COLUMN seller_notes TEXT NULL AFTER buyer_notes");

    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        title VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        type ENUM('order', 'shipping', 'delivery', 'payment', 'system') NOT NULL,
        is_read BOOLEAN DEFAULT FALSE,
        related_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    // Add indexes for better performance
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_transactions_buyer_id ON transactions(buyer_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_transactions_seller_id ON transactions(seller_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_notifications_user_id ON notifications(user_id)");
    
    echo "Migration completed successfully.\n";
} catch (PDOException $e) {
    die("Migration failed: " . $e->getMessage() . "\n");
}
?>
