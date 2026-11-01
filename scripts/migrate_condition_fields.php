<?php
require_once __DIR__ . '/../config.php';

try {
    // Add columns if not exist (MySQL 8.0+ supports IF NOT EXISTS)
    $pdo->exec("ALTER TABLE products ADD COLUMN IF NOT EXISTS condition_grade ENUM('New','Like New','Excellent','Good','Fair','Poor') NOT NULL DEFAULT 'Good'");
    $pdo->exec("ALTER TABLE products ADD COLUMN IF NOT EXISTS condition_notes TEXT NULL");
    $pdo->exec("ALTER TABLE products ADD COLUMN IF NOT EXISTS defect_photos TEXT NULL");

    // Backfill any NULLs to default values
    $pdo->exec("UPDATE products SET condition_grade = 'Good' WHERE condition_grade IS NULL");

    echo "Migration completed.\n";
} catch (PDOException $e) {
    // Fallback for MariaDB/MySQL versions without IF NOT EXISTS for ADD COLUMN
    if (strpos($e->getMessage(), 'IF NOT EXISTS') !== false) {
        // Try adding columns one-by-one with detection
        $columns = $pdo->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN, 0);
        if (!in_array('condition_grade', $columns)) {
            $pdo->exec("ALTER TABLE products ADD COLUMN condition_grade ENUM('New','Like New','Excellent','Good','Fair','Poor') NOT NULL DEFAULT 'Good'");
        }
        if (!in_array('condition_notes', $columns)) {
            $pdo->exec("ALTER TABLE products ADD COLUMN condition_notes TEXT NULL");
        }
        if (!in_array('defect_photos', $columns)) {
            $pdo->exec("ALTER TABLE products ADD COLUMN defect_photos TEXT NULL");
        }
        $pdo->exec("UPDATE products SET condition_grade = 'Good' WHERE condition_grade IS NULL");
        echo "Migration completed (fallback).\n";
    } else {
        echo "Migration failed: " . $e->getMessage() . "\n";
    }
}
