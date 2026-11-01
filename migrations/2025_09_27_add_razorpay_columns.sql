-- Migration: Add Razorpay payment columns to transactions table
-- Safe to run multiple times on MySQL 8+ (uses IF NOT EXISTS)

USE retrade_db;

ALTER TABLE transactions
  ADD COLUMN IF NOT EXISTS razorpay_order_id VARCHAR(64) NULL AFTER notes,
  ADD COLUMN IF NOT EXISTS razorpay_payment_id VARCHAR(64) NULL AFTER razorpay_order_id,
  ADD COLUMN IF NOT EXISTS razorpay_signature VARCHAR(128) NULL AFTER razorpay_payment_id,
  ADD COLUMN IF NOT EXISTS payment_status ENUM('created','paid','failed','refunded') NOT NULL DEFAULT 'created' AFTER razorpay_signature;

-- Helpful indexes for lookups/verification
ALTER TABLE transactions
  ADD INDEX IF NOT EXISTS idx_tx_razorpay_order_id (razorpay_order_id),
  ADD INDEX IF NOT EXISTS idx_tx_razorpay_payment_id (razorpay_payment_id);
