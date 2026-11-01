-- Buyer complaints against sellers
-- This table stores buyer-submitted complaints about sellers, optionally linked to a product/transaction

CREATE TABLE IF NOT EXISTS complaints (
  id INT AUTO_INCREMENT PRIMARY KEY,
  buyer_id INT NOT NULL,
  seller_id INT NOT NULL,
  product_id INT NULL,
  transaction_id INT NULL,
  subject VARCHAR(150) NOT NULL,
  description TEXT NOT NULL,
  attachment_path VARCHAR(255) NULL,
  status ENUM('open','in_review','resolved','dismissed') NOT NULL DEFAULT 'open',
  priority ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
  admin_notes TEXT NULL,
  resolution TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_status (status),
  INDEX idx_seller (seller_id),
  INDEX idx_buyer (buyer_id),
  INDEX idx_product (product_id),
  INDEX idx_transaction (transaction_id),
  CONSTRAINT fk_complaints_buyer FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_complaints_seller FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_complaints_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
  CONSTRAINT fk_complaints_tx FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE SET NULL
);
