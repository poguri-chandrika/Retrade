<?php
try {
    // Add admin_release_required flag to transactions table
    $pdo->exec("ALTER TABLE transactions 
        ADD COLUMN admin_release_required BOOLEAN DEFAULT TRUE,
        ADD COLUMN admin_released_by INT NULL,
        ADD COLUMN admin_released_at TIMESTAMP NULL,
        ADD FOREIGN KEY (admin_released_by) REFERENCES users(id) ON DELETE SET NULL");

    echo "Migration completed successfully.\n";
} catch (PDOException $e) {
    die("Migration failed: " . $e->getMessage() . "\n");
}
?>
