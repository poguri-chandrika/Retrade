-- Add escrow support to transactions
ALTER TABLE transactions
ADD COLUMN escrow_status ENUM('pending', 'funds_held', 'released_to_seller', 'refunded_to_buyer') DEFAULT 'pending' AFTER status,
ADD COLUMN buyer_confirmed BOOLEAN DEFAULT FALSE AFTER escrow_status,
ADD COLUMN admin_approved BOOLEAN DEFAULT FALSE AFTER buyer_confirmed,
ADD COLUMN auto_release_date TIMESTAMP NULL AFTER admin_approved,
ADD COLUMN released_at TIMESTAMP NULL AFTER auto_release_date,
ADD COLUMN released_by INT NULL AFTER released_at,
ADD FOREIGN KEY (released_by) REFERENCES users(id) ON DELETE SET NULL;

-- Add order status tracking
CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT NOT NULL,
    status ENUM('pending', 'processing', 'shipped', 'delivered', 'completed', 'cancelled', 'disputed') DEFAULT 'pending',
    tracking_number VARCHAR(100) NULL,
    shipping_address TEXT NOT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE
);

-- Add order items table
CREATE TABLE IF NOT EXISTS order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    price DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);

-- Add escrow release settings
CREATE TABLE IF NOT EXISTS escrow_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    auto_release_days INT NOT NULL DEFAULT 3,
    admin_commission_rate DECIMAL(5,2) NOT NULL DEFAULT 5.00,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Insert default escrow settings
INSERT INTO escrow_settings (auto_release_days, admin_commission_rate) VALUES (3, 5.00);
