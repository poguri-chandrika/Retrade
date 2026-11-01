-- Update transactions table for the new flow
ALTER TABLE transactions
MODIFY COLUMN status ENUM('pending', 'confirmed', 'shipped', 'delivered', 'cancelled', 'completed') NOT NULL DEFAULT 'pending',
MODIFY COLUMN delivery_status ENUM('pending', 'processing', 'shipped', 'delivered', 'cancelled') NOT NULL DEFAULT 'pending',
ADD COLUMN payment_released BOOLEAN DEFAULT FALSE,
ADD COLUMN payment_released_at TIMESTAMP NULL,
ADD COLUMN admin_released_by INT NULL,
ADD CONSTRAINT fk_admin_released_by FOREIGN KEY (admin_released_by) REFERENCES users(id) ON DELETE SET NULL;
