<?php
try {
    // Add buyer confirmation fields
    $pdo->exec("ALTER TABLE transactions 
        ADD COLUMN buyer_confirmed BOOLEAN DEFAULT FALSE,
        ADD COLUMN buyer_confirmed_at TIMESTAMP NULL,
        ADD COLUMN admin_release_required BOOLEAN DEFAULT TRUE,
        ADD COLUMN admin_released_by INT NULL,
        ADD COLUMN admin_released_at TIMESTAMP NULL,
        ADD FOREIGN KEY (admin_released_by) REFERENCES users(id) ON DELETE SET NULL");

    echo "Migration completed successfully.\n";
} catch (PDOException $e) {
    die("Migration failed: " . $e->getMessage() . "\n");
}
?>
