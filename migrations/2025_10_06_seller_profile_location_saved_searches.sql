-- Add seller profile fields, product location/shipping flags, and saved searches

-- Users: seller profile enhancements
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS tagline VARCHAR(160) NULL AFTER name,
  ADD COLUMN IF NOT EXISTS avatar_url VARCHAR(255) NULL AFTER phone,
  ADD COLUMN IF NOT EXISTS social_twitter VARCHAR(100) NULL AFTER address,
  ADD COLUMN IF NOT EXISTS social_instagram VARCHAR(100) NULL AFTER social_twitter,
  ADD COLUMN IF NOT EXISTS social_website VARCHAR(150) NULL AFTER social_instagram,
  ADD COLUMN IF NOT EXISTS is_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER role,
  ADD COLUMN IF NOT EXISTS response_time_min INT NULL AFTER is_verified;

-- Products: location & shipping
ALTER TABLE products
  ADD COLUMN IF NOT EXISTS city VARCHAR(80) NULL AFTER category,
  ADD COLUMN IF NOT EXISTS zip VARCHAR(20) NULL AFTER city,
  ADD COLUMN IF NOT EXISTS shipping_available TINYINT(1) NOT NULL DEFAULT 0 AFTER zip,
  ADD COLUMN IF NOT EXISTS pickup_available TINYINT(1) NOT NULL DEFAULT 0 AFTER shipping_available;

-- Saved searches
CREATE TABLE IF NOT EXISTS saved_searches (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  name VARCHAR(100) NOT NULL,
  query TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_saved_search_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Indexes
CREATE INDEX IF NOT EXISTS idx_products_city ON products(city);
CREATE INDEX IF NOT EXISTS idx_products_zip ON products(zip);
